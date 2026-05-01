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
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Content\GetPostBlocksAbility;
use DataMachine\Abilities\Content\ReplacePostBlocksAbility;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;

class InternalLinkingTask extends SystemTask {

	private const MIN_RELEVANCE_SCORE   = 3;
	private const TITLE_WORD_MAX_WEIGHT = 5.0;

	/**
	 * Execute internal linking for a specific post.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$post_id        = absint( $params['post_id'] ?? 0 );
		$links_per_post = absint( $params['links_per_post'] ?? 3 );
		$force          = ! empty( $params['force'] );

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		$post = get_post( $post_id );

		$post_status = get_post_status( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post_status ) {
			$this->failJob( $jobId, "Post #{$post_id} does not exist or is not published" );
			return;
		}

		if ( ! $force ) {
			$existing_links = get_post_meta( $post_id, '_datamachine_internal_links', true );
			if ( ! empty( $existing_links ) ) {
				$this->completeJob( $jobId, array(
					'skipped' => true,
					'post_id' => $post_id,
					'reason'  => 'Already processed (use force to re-run)',
				) );
				return;
			}
		}

		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
		$categories = is_array( $categories ) ? $categories : array();
		$tags       = is_array( $tags ) ? $tags : array();

		if ( empty( $categories ) && empty( $tags ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'Post has no categories or tags',
			) );
			return;
		}

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

		$related = $this->findRelatedPosts( $post_id, get_the_title( $post_id ), $categories, $tags, $links_per_post );

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

		$system_defaults = $this->resolveSystemModel( $params );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		$replacements   = array();
		$inserted_links = array();

		foreach ( $related as $related_post ) {
			$candidate = $this->findCandidateParagraph( $paragraph_blocks, $related_post, $replacements );

			if ( null === $candidate ) {
				continue;
			}

			$prompt     = $this->buildBlockPrompt( $candidate['inner_html'], $related_post );
			$ai_payload = array( 'post_id' => $post_id );
			if ( ! empty( $params['agent_id'] ) ) {
				$ai_payload['agent_id'] = (int) $params['agent_id'];
			}
			if ( ! empty( $params['user_id'] ) ) {
				$ai_payload['user_id'] = (int) $params['user_id'];
			}
			$response = RequestBuilder::build(
				array(
					\DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'user', $prompt ),
				),
				$provider,
				$model,
				array(),
				'system',
				$ai_payload
			);

			if ( $response instanceof \WP_Error ) {
				continue;
			}

			$new_html = trim( RequestBuilder::resultText( $response ) );

			if ( empty( $new_html ) || $new_html === $candidate['inner_html'] ) {
				continue;
			}

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

		$revision_id = wp_save_post_revision( $post_id );

		$replace_result = ReplacePostBlocksAbility::execute( array(
			'post_id'      => $post_id,
			'replacements' => $replacements,
		) );

		if ( empty( $replace_result['success'] ) ) {
			$this->failJob( $jobId, 'Failed to save block replacements: ' . ( $replace_result['error'] ?? 'Unknown error' ) );
			return;
		}

		$existing_meta = get_post_meta( $post_id, '_datamachine_internal_links', true );
		$link_tracking = array(
			'processed_at' => current_time( 'mysql' ),
			'links'        => $inserted_links,
			'job_id'       => $jobId,
		);
		update_post_meta( $post_id, '_datamachine_internal_links', $link_tracking );

		$effects = array();

		if ( ! empty( $revision_id ) && ! is_wp_error( $revision_id ) ) {
			$effects[] = array(
				'type'        => 'post_content_modified',
				'target'      => array( 'post_id' => $post_id ),
				'revision_id' => $revision_id,
			);
		}

		$effects[] = array(
			'type'           => 'post_meta_set',
			'target'         => array(
				'post_id'  => $post_id,
				'meta_key' => '_datamachine_internal_links',
			),
			'previous_value' => ! empty( $existing_meta ) ? $existing_meta : null,
		);

		$this->completeJob( $jobId, array(
			'post_id'        => $post_id,
			'links_inserted' => count( $inserted_links ),
			'links'          => $inserted_links,
			'effects'        => $effects,
			'completed_at'   => current_time( 'mysql' ),
		) );
	}

	/**
	 * @return string
	 */
	public function getTaskType(): string {
		return 'internal_linking';
	}

	/**
	 * @return bool
	 */
	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Internal Linking',
			'description'     => 'Semantically weave internal links into published post content using AI.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On demand via CLI, chat, or pipeline',
			'trigger_type'    => 'manual',
			'supports_run'    => false,
		);
	}

	/**
	 * @return array
	 * @since 0.41.0
	 */
	public function getPromptDefinitions(): array {
		return array(
			'insert_link' => array(
				'label'       => __( 'Internal Link Insertion Prompt', 'data-machine' ),
				'description' => __( 'Prompt used to weave internal links into existing paragraph text.', 'data-machine' ),
				'default'     => "Weave an internal link into this paragraph by wrapping a relevant existing phrase in an anchor tag.\n\n"
					. "Rules:\n"
					. "- Do NOT add new text or change meaning\n"
					. "- Return ONLY the updated paragraph HTML\n"
					. "- If no natural insertion point exists, return the paragraph unchanged\n\n"
					. "URL: {{url}}\n"
					. "Title: {{title}}\n\n"
					. "Paragraph:\n{{paragraph}}",
				'variables'   => array(
					'url'       => 'URL of the related post to link to',
					'title'     => 'Title of the related post',
					'paragraph' => 'The paragraph HTML to insert the link into',
				),
			),
		);
	}

	// ─── Private helpers (unchanged from pre-migration) ───────────────

	private function findCandidateParagraph( array $blocks, array $related_post, array $replacements ): ?array {
		$used_indices = array_column( $replacements, 'block_index' );

		$split_title_words = preg_split( '/\s+/', strtolower( $related_post['title'] ) );
		$title_words       = array_filter(
			false !== $split_title_words ? $split_title_words : array(),
			fn( $word ) => strlen( $word ) >= 3
		);

		$related_tags = wp_get_post_tags( $related_post['id'], array( 'fields' => 'names' ) );
		$related_tags = is_array( $related_tags ) ? array_map( 'strtolower', $related_tags ) : array();

		$best_block = null;
		$best_score = 0;

		foreach ( $blocks as $block ) {
			if ( in_array( $block['index'], $used_indices, true ) ) {
				continue;
			}

			if ( false !== stripos( $block['inner_html'], $related_post['url'] ) ) {
				continue;
			}

			$html_lower = strtolower( $block['inner_html'] );
			$score      = 0;

			foreach ( $title_words as $word ) {
				if ( false !== strpos( $html_lower, $word ) ) {
					$score += 2;
				}
			}

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

	private function buildBlockPrompt( string $paragraph_html, array $related_post ): string {
		return $this->buildPromptFromTemplate(
			'insert_link',
			array(
				'url'       => $related_post['url'],
				'title'     => $related_post['title'],
				'paragraph' => $paragraph_html,
			)
		);
	}

	private function detectInsertedLink( string $html, string $url ): bool {
		$escaped_url = preg_quote( $url, '/' );
		return (bool) preg_match( '/<a\s[^>]*href=["\']' . $escaped_url . '["\'][^>]*>/', $html );
	}

	private function findRelatedPosts( int $post_id, string $source_title, array $categories, array $tags, int $limit ): array {
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

		$source_words = $this->extractTitleWords( $source_title );

		$candidate_titles = array();
		foreach ( $query->posts as $candidate_id ) {
			$candidate_titles[ $candidate_id ] = get_the_title( $candidate_id );
		}

		$idf_weights = $this->computeWordIDF( $candidate_titles );

		$scored = array();

		foreach ( $query->posts as $candidate_id ) {
			$score          = 0;
			$candidate_cats = wp_get_post_categories( $candidate_id, array( 'fields' => 'ids' ) );
			$candidate_tags = wp_get_post_tags( $candidate_id, array( 'fields' => 'ids' ) );
			$candidate_cats = is_array( $candidate_cats ) ? $candidate_cats : array();
			$candidate_tags = is_array( $candidate_tags ) ? $candidate_tags : array();

			$shared_cats = array_intersect( $categories, $candidate_cats );
			$shared_tags = array_intersect( $tags, $candidate_tags );

			$score += count( $shared_cats ) * 1;
			$score += count( $shared_tags ) * 3;

			$candidate_words = $this->extractTitleWords( $candidate_titles[ $candidate_id ] );
			$shared_words    = array_intersect( $source_words, $candidate_words );

			foreach ( $shared_words as $word ) {
				$weight = $idf_weights[ $word ] ?? 0;
				$score += $weight;
			}

			if ( $score >= self::MIN_RELEVANCE_SCORE ) {
				$scored[ $candidate_id ] = $score;
			}
		}

		arsort( $scored );

		$top_ids = array_slice( array_keys( $scored ), 0, $limit, true );
		$related = array();

		foreach ( $top_ids as $rel_id ) {
			$content   = (string) get_post_field( 'post_content', $rel_id );
			$related[] = array(
				'id'      => $rel_id,
				'url'     => get_permalink( $rel_id ),
				'title'   => get_the_title( $rel_id ),
				'excerpt' => wp_trim_words( $content, 30, '...' ),
				'score'   => $scored[ $rel_id ],
			);
		}

		return $related;
	}

	private function computeWordIDF( array $candidate_titles ): array {
		$total_docs = count( $candidate_titles );

		if ( $total_docs <= 1 ) {
			$all_words = array();
			foreach ( $candidate_titles as $title ) {
				foreach ( $this->extractTitleWords( $title ) as $word ) {
					$all_words[ $word ] = self::TITLE_WORD_MAX_WEIGHT;
				}
			}
			return $all_words;
		}

		$doc_frequency = array();
		foreach ( $candidate_titles as $title ) {
			$words = array_unique( $this->extractTitleWords( $title ) );
			foreach ( $words as $word ) {
				$doc_frequency[ $word ] = ( $doc_frequency[ $word ] ?? 0 ) + 1;
			}
		}

		$weights = array();
		$log_n   = log( $total_docs );

		foreach ( $doc_frequency as $word => $df ) {
			if ( $df >= $total_docs ) {
				$weights[ $word ] = 0.0;
			} else {
				$weights[ $word ] = round( self::TITLE_WORD_MAX_WEIGHT * log( $total_docs / $df ) / $log_n, 2 );
			}
		}

		return $weights;
	}

	private function extractTitleWords( string $title ): array {
		$stop_words = array(
			'the',
			'and',
			'for',
			'are',
			'but',
			'not',
			'you',
			'all',
			'can',
			'had',
			'her',
			'was',
			'one',
			'our',
			'out',
			'has',
			'his',
			'how',
			'its',
			'may',
			'new',
			'now',
			'old',
			'see',
			'way',
			'who',
			'did',
			'get',
			'let',
			'say',
			'she',
			'too',
			'use',
			'what',
			'when',
			'where',
			'which',
			'why',
			'will',
			'with',
			'this',
			'that',
			'from',
			'they',
			'been',
			'have',
			'many',
			'some',
			'them',
			'than',
			'each',
			'make',
			'like',
			'into',
			'over',
			'such',
			'your',
			'about',
			'their',
			'would',
			'could',
			'other',
			'these',
			'there',
			'after',
			'being',
		);

		$words = preg_split( '/[\s\-—:,.|]+/', strtolower( $title ) );
		$words = array_filter( false !== $words ? $words : array(), fn( $w ) => strlen( $w ) >= 3 );
		$words = array_diff( $words, $stop_words );

		return array_values( $words );
	}

	private function filterAlreadyLinked( array $related, string $post_content ): array {
		return array_values( array_filter( $related, function ( $item ) use ( $post_content ) {
			$url = preg_quote( $item['url'], '/' );
			return ! preg_match( '/' . $url . '/', $post_content );
		} ) );
	}
}
