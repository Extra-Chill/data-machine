<?php
/**
 * Internal Linking Task for System Agent.
 *
 * Semantically weaves internal links into post content by finding related
 * posts via shared taxonomy terms and using AI to insert anchor tags
 * naturally into existing sentences.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

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
				$this->completeJob( $jobId, [
					'skipped' => true,
					'post_id' => $post_id,
					'reason'  => 'Already processed (use force to re-run)',
				] );
				return;
			}
		}

		// Get post taxonomies.
		$categories = wp_get_post_categories( $post_id, [ 'fields' => 'ids' ] );
		$tags       = wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] );

		if ( empty( $categories ) && empty( $tags ) ) {
			$this->completeJob( $jobId, [
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'Post has no categories or tags',
			] );
			return;
		}

		// Find related posts scored by taxonomy overlap.
		$related = $this->findRelatedPosts( $post_id, $categories, $tags, $links_per_post );

		// Filter out posts already linked in content.
		$related = $this->filterAlreadyLinked( $related, $post->post_content );

		if ( empty( $related ) ) {
			$this->completeJob( $jobId, [
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'No unlinked related posts found',
			] );
			return;
		}

		// Build AI request.
		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No default AI provider/model configured' );
			return;
		}

		$prompt   = $this->buildPrompt( $post->post_content, $related );
		$messages = [
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			[],
			'system',
			[ 'post_id' => $post_id ]
		);

		if ( empty( $response['success'] ) ) {
			$this->failJob( $jobId, 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			return;
		}

		$new_content = $response['data']['content'] ?? '';

		if ( empty( $new_content ) ) {
			$this->failJob( $jobId, 'AI returned empty content' );
			return;
		}

		// Validate that at least one new link was inserted.
		$inserted_links = $this->detectInsertedLinks( $new_content, $related );

		if ( empty( $inserted_links ) ) {
			$this->completeJob( $jobId, [
				'skipped' => true,
				'post_id' => $post_id,
				'reason'  => 'AI found no natural insertion points',
			] );
			return;
		}

		// Update post content.
		$update_result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $new_content,
			],
			true
		);

		if ( is_wp_error( $update_result ) ) {
			$this->failJob( $jobId, 'Failed to update post: ' . $update_result->get_error_message() );
			return;
		}

		// Track which links were added.
		$link_tracking = [
			'processed_at' => current_time( 'mysql' ),
			'links'        => $inserted_links,
			'job_id'       => $jobId,
		];
		update_post_meta( $post_id, '_dm_internal_links', $link_tracking );

		$this->completeJob( $jobId, [
			'post_id'        => $post_id,
			'links_inserted' => count( $inserted_links ),
			'links'          => $inserted_links,
			'completed_at'   => current_time( 'mysql' ),
		] );
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
	 * Find related posts by shared taxonomy terms, scored by overlap.
	 *
	 * @param int   $post_id    Current post ID to exclude.
	 * @param array $categories Category term IDs.
	 * @param array $tags       Tag term IDs.
	 * @param int   $limit      Maximum related posts to return.
	 * @return array Array of related post data [{id, url, title, excerpt, score}].
	 */
	private function findRelatedPosts( int $post_id, array $categories, array $tags, int $limit ): array {
		$tax_query = [ 'relation' => 'OR' ];

		if ( ! empty( $categories ) ) {
			$tax_query[] = [
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $categories,
			];
		}

		if ( ! empty( $tags ) ) {
			$tax_query[] = [
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tags,
			];
		}

		$query = new \WP_Query( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'post__not_in'   => [ $post_id ],
			'posts_per_page' => 50,
			'tax_query'      => $tax_query,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		if ( empty( $query->posts ) ) {
			return [];
		}

		// Score each candidate by taxonomy overlap.
		$scored = [];

		foreach ( $query->posts as $candidate_id ) {
			$score          = 0;
			$candidate_cats = wp_get_post_categories( $candidate_id, [ 'fields' => 'ids' ] );
			$candidate_tags = wp_get_post_tags( $candidate_id, [ 'fields' => 'ids' ] );

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
		$related = [];

		foreach ( $top_ids as $rel_id ) {
			$rel_post  = get_post( $rel_id );
			$related[] = [
				'id'      => $rel_id,
				'url'     => get_permalink( $rel_id ),
				'title'   => get_the_title( $rel_id ),
				'excerpt' => wp_trim_words( $rel_post->post_content, 30, '...' ),
				'score'   => $scored[ $rel_id ],
			];
		}

		return $related;
	}

	/**
	 * Filter out posts that are already linked in the content.
	 *
	 * @param array  $related      Related posts array.
	 * @param string $post_content Current post content.
	 * @return array Filtered related posts.
	 */
	private function filterAlreadyLinked( array $related, string $post_content ): array {
		return array_values( array_filter( $related, function ( $item ) use ( $post_content ) {
			$url = preg_quote( $item['url'], '/' );
			return ! preg_match( '/' . $url . '/', $post_content );
		} ) );
	}

	/**
	 * Build the AI prompt for internal link insertion.
	 *
	 * @param string $post_content Full post content.
	 * @param array  $related      Related posts data.
	 * @return string Prompt text.
	 */
	private function buildPrompt( string $post_content, array $related ): string {
		$related_list = '';
		foreach ( $related as $item ) {
			$related_list .= sprintf(
				"- URL: %s\n  Title: %s\n  Excerpt: %s\n\n",
				$item['url'],
				$item['title'],
				$item['excerpt']
			);
		}

		return "Here is a blog post in Gutenberg block format. Below are related posts on this site. "
			. "Weave internal links to these related posts SEMANTICALLY into existing sentences — "
			. "find natural mentions of the topic and wrap the relevant phrase in an anchor tag. "
			. "Do NOT add a Related Posts section. Do NOT change tone, meaning, or Gutenberg block structure. "
			. "Return the FULL updated content with all blocks intact. "
			. "If no natural insertion point exists for a link, skip that link — do not force it.\n\n"
			. "=== POST CONTENT ===\n\n"
			. $post_content . "\n\n"
			. "=== RELATED POSTS TO LINK ===\n\n"
			. $related_list;
	}

	/**
	 * Detect which related post URLs were inserted into the new content.
	 *
	 * @param string $new_content Updated content from AI.
	 * @param array  $related     Related posts that were candidates.
	 * @return array URLs that were found in the new content.
	 */
	private function detectInsertedLinks( string $new_content, array $related ): array {
		$inserted = [];

		foreach ( $related as $item ) {
			$url = preg_quote( $item['url'], '/' );
			if ( preg_match( '/<a\s[^>]*href=["\']' . $url . '["\'][^>]*>/', $new_content ) ) {
				$inserted[] = [
					'url'     => $item['url'],
					'post_id' => $item['id'],
					'title'   => $item['title'],
				];
			}
		}

		return $inserted;
	}
}
