<?php
/**
 * Internal Linking Task for System Agent.
 *
 * Semantically weaves internal links into post content by finding related
 * posts via shared taxonomy terms and using AI to insert anchor tags
 * naturally into individual paragraphs via block-level editing.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Content\GetPostBlocksAbility;
use DataMachine\Abilities\Content\ReplacePostBlocksAbility;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;

class InternalLinkingTask extends SystemTask {

	/**
	 * Execute internal linking for a specific post.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$post_id        = absint( $params['post_id'] ?? 0 );
		$links_per_post = absint( $params['links_per_post'] ?? 3 );
		$force          = ! empty( $params['force'] );

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			$this->failJob( $jobId, "Post #{$post_id} does not exist or is not published" );
			return;
		}

		// Check if already processed.
		if ( ! $force ) {
			$existing_links = get_post_meta( $post_id, '_dm_internal_links', true );
			if ( ! empty( $existing_links ) ) {
				$this->completeJob( $jobId, array(
					'skipped' => true,
					'post_id' => $post_id,
					'reason'  => 'Already processed (use force to re-run)',
				) );
				return;
			}
		}

		// Get post taxonomies.
		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

		if ( empty( $categories ) && empty( $tags ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'Post has no categories or tags',
			) );
			return;
		}

		// Parse post into paragraph blocks.
		$blocks_result = GetPostBlocksAbility::execute( array(
			'post_id'     => $post_id,
			'block_types' => array( 'core/paragraph' ),
		) );

		if ( empty( $blocks_result['success'] ) || empty( $blocks_result['blocks'] ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'No paragraph blocks found in post',
			) );
			return;
		}

		$paragraph_blocks = $blocks_result['blocks'];

		// Find related posts scored by taxonomy overlap.
		$related = $this->findRelatedPosts( $post_id, $categories, $tags, $links_per_post );

		// Filter out posts already linked in any paragraph block.
		$all_block_html = implode( "\n", array_column( $paragraph_blocks, 'inner_html' ) );
		$related        = $this->filterAlreadyLinked( $related, $all_block_html );

		if ( empty( $related ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'No unlinked related posts found',
			) );
			return;
		}

		// Build AI request config.
		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		// For each related post, find a candidate paragraph and send to AI.
		$replacements   = array();
		$inserted_links = array();

		foreach ( $related as $related_post ) {
			$candidate = $this->findCandidateParagraph( $paragraph_blocks, $related_post, $replacements );

			if ( null === $candidate ) {
				continue;
			}

			$prompt   = $this->buildBlockPrompt( $candidate['inner_html'], $related_post );
			$response = RequestBuilder::build(
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				$provider,
				$model,
				array(),
				'system',
				array( 'post_id' => $post_id )
			);

			if ( empty( $response['success'] ) ) {
				continue;
			}

			$new_html = trim( $response['data']['content'] ?? '' );

			if ( empty( $new_html ) || $new_html === $candidate['inner_html'] ) {
				continue;
			}

			// Validate a link was actually inserted.
			if ( ! $this->detectInsertedLink( $new_html, $related_post['url'] ) ) {
				continue;
			}

			$replacements[] = array(
				'block_index' => $candidate['index'],
				'new_content' => $new_html,
			);

			$inserted_links[] = array(
				'url'     => $related_post['url'],
				'post_id' => $related_post['id'],
				'title'   => $related_post['title'],
			);

			// Update inner_html in our working copy so subsequent candidates see the change.
			foreach ( $paragraph_blocks as &$block ) {
				if ( $block['index'] === $candidate['index'] ) {
					$block['inner_html'] = $new_html;
					break;
				}
			}
			unset( $block );
		}

		if ( empty( $inserted_links ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'AI found no natural insertion points',
			) );
			return;
		}

		// Apply all block replacements at once.
		$replace_result = ReplacePostBlocksAbility::execute( array(
			'post_id'      => $post_id,
			'replacements' => $replacements,
		) );

		if ( empty( $replace_result['success'] ) ) {
			$this->failJob( $jobId, 'Failed to save block replacements: ' . ( $replace_result['error'] ?? 'Unknown error' ) );
			return;
		}

		// Track which links were added.
		$link_tracking = array(
			'processed_at' => current_time( 'mysql' ),
			'links'        => $inserted_links,
			'job_id'       => $jobId,
		);
		update_post_meta( $post_id, '_dm_internal_links', $link_tracking );

		$this->completeJob( $jobId, array(
			'post_id'        => $post_id,
			'links_inserted' => count( $inserted_links ),
			'links'          => $inserted_links,
			'completed_at'   => current_time( 'mysql' ),
		) );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'internal_linking';
	}

	/**
	 * Find the best candidate paragraph block for a related post link.
	 *
	 * Scores paragraphs by title word matches, tag overlap, and keyword
	 * relevance. Skips blocks already targeted by a pending replacement.
	 *
	 * @param array $blocks       Paragraph blocks from GetPostBlocksAbility.
	 * @param array $related_post Related post data with id, url, title, tags.
	 * @param array $replacements Already-queued replacements (to avoid same block).
	 * @return array|null Best candidate block or null if none found.
	 */
	private function findCandidateParagraph( array $blocks, array $related_post, array $replacements ): ?array {
		$used_indices = array_column( $replacements, 'block_index' );

		// Get title words (3+ chars) for matching.
		$title_words = array_filter(
			preg_split( '/\s+/', strtolower( $related_post['title'] ) ),
			fn( $word ) => strlen( $word ) >= 3
		);

		// Get related post's tag names for matching.
		$related_tags = wp_get_post_tags( $related_post['id'], array( 'fields' => 'names' ) );
		$related_tags = array_map( 'strtolower', $related_tags );

		$best_block = null;
		$best_score = 0;

		foreach ( $blocks as $block ) {
			if ( in_array( $block['index'], $used_indices, true ) ) {
				continue;
			}

			// Skip blocks that already contain a link to this URL.
			if ( false !== stripos( $block['inner_html'], $related_post['url'] ) ) {
				continue;
			}

			$html_lower = strtolower( $block['inner_html'] );
			$score      = 0;

			// Score by title word matches.
			foreach ( $title_words as $word ) {
				if ( false !== strpos( $html_lower, $word ) ) {
					$score += 2;
				}
			}

			// Score by tag name matches.
			foreach ( $related_tags as $tag ) {
				if ( false !== strpos( $html_lower, $tag ) ) {
					$score += 3;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_block = $block;
			}
		}

		return $best_block;
	}

	/**
	 * Build AI prompt for a single paragraph + link insertion.
	 *
	 * @param string $paragraph_html The paragraph innerHTML.
	 * @param array  $related_post   Related post data with url and title.
	 * @return string Prompt text.
	 */
	private function buildBlockPrompt( string $paragraph_html, array $related_post ): string {
		return 'Here is a paragraph from a blog post. Weave in a link to the URL below by wrapping '
			. 'a relevant existing phrase in an anchor tag. Do NOT add new text or change meaning. '
			. 'Return ONLY the updated paragraph HTML. If no natural insertion point exists, '
			. "return the paragraph unchanged.\n\n"
			. 'URL: ' . $related_post['url'] . "\n"
			. 'Title: ' . $related_post['title'] . "\n\n"
			. "Paragraph:\n" . $paragraph_html;
	}

	/**
	 * Check if a specific URL was inserted as an anchor tag in the content.
	 *
	 * @param string $html The HTML content to check.
	 * @param string $url  The URL to look for.
	 * @return bool True if the URL is found in an anchor href.
	 */
	private function detectInsertedLink( string $html, string $url ): bool {
		$escaped_url = preg_quote( $url, '/' );
		return (bool) preg_match( '/<a\s[^>]*href=["\']' . $escaped_url . '["\'][^>]*>/', $html );
	}

	/**
	 * Find related posts by shared taxonomy terms, scored by overlap.
	 *
	 * @param int   $post_id    Current post ID to exclude.
	 * @param array $categories Category term IDs.
	 * @param array $tags       Tag term IDs.
	 * @param int   $limit      Maximum related posts to return.
	 * @return array Array of related post data [{id, url, title, excerpt, score}].
	 */
	private function findRelatedPosts( int $post_id, array $categories, array $tags, int $limit ): array {
		$tax_query = array( 'relation' => 'OR' );

		if ( ! empty( $categories ) ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $categories,
			);
		}

		if ( ! empty( $tags ) ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tags,
			);
		}

		$query = new \WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'post__not_in'   => array( $post_id ),
			'posts_per_page' => 50,
			'tax_query'      => $tax_query,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		if ( empty( $query->posts ) ) {
			return array();
		}

		// Score each candidate by taxonomy overlap.
		$scored = array();

		foreach ( $query->posts as $candidate_id ) {
			$score          = 0;
			$candidate_cats = wp_get_post_categories( $candidate_id, array( 'fields' => 'ids' ) );
			$candidate_tags = wp_get_post_tags( $candidate_id, array( 'fields' => 'ids' ) );

			$shared_cats = array_intersect( $categories, $candidate_cats );
			$shared_tags = array_intersect( $tags, $candidate_tags );

			$score += count( $shared_cats ) * 1;
			$score += count( $shared_tags ) * 2;

			if ( $score > 0 ) {
				$scored[ $candidate_id ] = $score;
			}
		}

		// Sort by score descending.
		arsort( $scored );

		// Pick top N.
		$top_ids = array_slice( array_keys( $scored ), 0, $limit, true );
		$related = array();

		foreach ( $top_ids as $rel_id ) {
			$rel_post  = get_post( $rel_id );
			$related[] = array(
				'id'      => $rel_id,
				'url'     => get_permalink( $rel_id ),
				'title'   => get_the_title( $rel_id ),
				'excerpt' => wp_trim_words( $rel_post->post_content, 30, '...' ),
				'score'   => $scored[ $rel_id ],
			);
		}

		return $related;
	}

	/**
	 * Filter out posts that are already linked in the content.
	 *
	 * @param array  $related      Related posts array.
	 * @param string $post_content Content to check for existing links.
	 * @return array Filtered related posts.
	 */
	private function filterAlreadyLinked( array $related, string $post_content ): array {
		return array_values( array_filter( $related, function ( $item ) use ( $post_content ) {
			$url = preg_quote( $item['url'], '/' );
			return ! preg_match( '/' . $url . '/', $post_content );
		} ) );
	}
}
