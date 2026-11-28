<?php
/**
 * Queue topic validator for duplicate detection.
 *
 * Checks whether a topic already exists as a published post or in a Data
 * Machine flow queue. Backs `wp datamachine flows queue validate`.
 *
 * Delegates all similarity math to the unified SimilarityEngine. Strategy-
 * aware checks (extension strategies, lookback windows) live in the
 * `datamachine/check-duplicate` ability.
 *
 * @package DataMachine\Core\Similarity
 */

namespace DataMachine\Core\Similarity;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Flows\Flows as DB_Flows;

class QueueTopicValidator {

	/**
	 * Core duplicate-check logic. Returns a structured result used by
	 * `wp datamachine flows queue validate`.
	 *
	 * @param array $params {
	 *     @type string $topic          Topic to validate (required).
	 *     @type float  $threshold      Similarity threshold (optional, uses default).
	 *     @type string $post_type      Post type to check (default: 'post').
	 *     @type int    $flow_id        Flow ID for queue check (optional).
	 *     @type string $flow_step_id   Flow step ID for queue check (optional).
	 * }
	 * @return array {
	 *     @type string $verdict  'clear' or 'duplicate'.
	 *     @type string $topic    The checked topic.
	 *     @type string $source   'published_post', 'queue', or null if clear.
	 *     @type array  $match    Match details (if duplicate).
	 *     @type string $reason   Human-readable explanation.
	 * }
	 */
	public function validate( array $params ): array {
		$topic     = sanitize_text_field( $params['topic'] ?? '' );
		$threshold = $this->resolveThreshold( $params );
		$post_type = ! empty( $params['post_type'] ) ? sanitize_text_field( $params['post_type'] ) : 'post';

		if ( empty( $topic ) ) {
			return array(
				'verdict' => 'error',
				'topic'   => '',
				'reason'  => 'Queue validator requires a topic parameter.',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Queue validator: checking topic',
			array(
				'topic'     => $topic,
				'threshold' => $threshold,
				'post_type' => $post_type,
			)
		);

		// Check 1: Existing published posts — use SimilarityEngine title match.
		$post_match = $this->checkPublishedPosts( $topic, $threshold, $post_type );

		if ( null !== $post_match ) {
			do_action(
				'datamachine_log',
				'info',
				'Queue validator: DUPLICATE — similar published post found',
				array(
					'topic' => $topic,
					'match' => $post_match,
				)
			);

			return array(
				'verdict' => 'duplicate',
				'source'  => 'published_post',
				'topic'   => $topic,
				'match'   => array(
					'title'      => $post_match['title'],
					'post_id'    => $post_match['post_id'],
					'url'        => $post_match['url'],
					'similarity' => $post_match['similarity'],
				),
				'reason'  => sprintf(
					'Rejected: "%s" is %.0f%% similar to existing post "%s" (ID %d). Threshold: %.0f%%.',
					$topic,
					$post_match['similarity'] * 100,
					$post_match['title'],
					$post_match['post_id'],
					$threshold * 100
				),
			);
		}

		// Check 2: Queue items (if flow_id and flow_step_id provided).
		if ( ! empty( $params['flow_id'] ) && ! empty( $params['flow_step_id'] ) ) {
			$queue_match = $this->checkQueueItems(
				$topic,
				$threshold,
				(int) $params['flow_id'],
				sanitize_text_field( $params['flow_step_id'] )
			);

			if ( null !== $queue_match ) {
				do_action(
					'datamachine_log',
					'info',
					'Queue validator: DUPLICATE — similar item already in queue',
					array(
						'topic' => $topic,
						'match' => $queue_match,
					)
				);

				return array(
					'verdict' => 'duplicate',
					'source'  => 'queue',
					'topic'   => $topic,
					'match'   => array(
						'prompt'     => $queue_match['prompt'],
						'index'      => $queue_match['index'],
						'similarity' => $queue_match['similarity'],
					),
					'reason'  => sprintf(
						'Rejected: "%s" is %.0f%% similar to queued item "%s" (index %d). Threshold: %.0f%%.',
						$topic,
						$queue_match['similarity'] * 100,
						$queue_match['prompt'],
						$queue_match['index'],
						$threshold * 100
					),
				);
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Queue validator: CLEAR — no duplicates found',
			array( 'topic' => $topic )
		);

		return array(
			'verdict' => 'clear',
			'topic'   => $topic,
			'reason'  => sprintf( 'No duplicates found for "%s".', $topic ),
		);
	}

	/**
	 * Check published posts for similar titles.
	 *
	 * Uses WP_Query with a keyword search for candidate fetch, then
	 * Jaccard similarity via SimilarityEngine for accurate matching.
	 *
	 * @param string $topic     Topic to check.
	 * @param float  $threshold Similarity threshold.
	 * @param string $post_type Post type to check against.
	 * @return array|null Best match above threshold, or null.
	 */
	private function checkPublishedPosts( string $topic, float $threshold, string $post_type = 'post' ): ?array {
		$search_word = SimilarityEngine::getBestSearchWord( $topic );

		if ( empty( $search_word ) ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				's'              => $search_word,
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		$topic_words = SimilarityEngine::tokenize( $topic );
		$best_match  = null;
		$best_score  = 0.0;

		foreach ( $query->posts as $post ) {
			$post_id     = $post instanceof \WP_Post ? $post->ID : (int) $post;
			$title       = get_the_title( $post_id );
			$title_words = SimilarityEngine::tokenize( $title );
			$score       = SimilarityEngine::jaccard( $topic_words, $title_words );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = array(
					'post_id'    => $post_id,
					'title'      => $title,
					'url'        => get_permalink( $post_id ),
					'similarity' => round( $score, 3 ),
				);
			}
		}

		if ( null !== $best_match && $best_score >= $threshold ) {
			return $best_match;
		}

		return null;
	}

	/**
	 * Check queue items for similar prompts.
	 *
	 * @param string $topic        Topic to check.
	 * @param float  $threshold    Similarity threshold.
	 * @param int    $flow_id      Flow ID.
	 * @param string $flow_step_id Flow step ID.
	 * @return array|null Best match above threshold, or null.
	 */
	private function checkQueueItems( string $topic, float $threshold, int $flow_id, string $flow_step_id ): ?array {
		$db_flows = new DB_Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return null;
		}

		$flow_config = $flow['flow_config'] ?? array();

		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			return null;
		}

		$prompt_queue = $flow_config[ $flow_step_id ]['prompt_queue'] ?? array();

		if ( empty( $prompt_queue ) ) {
			return null;
		}

		$topic_words = SimilarityEngine::tokenize( $topic );
		$best_match  = null;
		$best_score  = 0.0;

		foreach ( $prompt_queue as $index => $item ) {
			$prompt     = $item['prompt'] ?? '';
			$item_words = SimilarityEngine::tokenize( $prompt );
			$score      = SimilarityEngine::jaccard( $topic_words, $item_words );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = array(
					'index'      => (int) $index,
					'prompt'     => $prompt,
					'similarity' => round( $score, 3 ),
				);
			}
		}

		if ( null !== $best_match && $best_score >= $threshold ) {
			return $best_match;
		}

		return null;
	}

	/**
	 * Resolve the similarity threshold from parameters.
	 *
	 * @param array $parameters Tool call parameters.
	 * @return float Resolved threshold.
	 */
	private function resolveThreshold( array $parameters ): float {
		if ( ! empty( $parameters['similarity_threshold'] ) ) {
			$threshold = (float) $parameters['similarity_threshold'];
			if ( $threshold > 0.0 && $threshold <= 1.0 ) {
				return $threshold;
			}
		}

		return SimilarityEngine::DEFAULT_JACCARD_THRESHOLD;
	}
}
