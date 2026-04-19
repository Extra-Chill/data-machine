<?php
/**
 * Unified Duplicate Check Ability
 *
 * Single entry point for content-similarity duplicate detection.
 * Replaces the three prior systems (QueueValidator published-post check,
 * DuplicateDetection publish-time check, and extension-specific checks)
 * with one Ability that runs a strategy cascade.
 *
 * Core provides a default "title match against published posts" strategy.
 * Extensions register domain-specific strategies via the
 * `datamachine_duplicate_strategies` filter (e.g., events adds
 * venue + date + ticket URL matching).
 *
 * @package DataMachine\Abilities\DuplicateCheck
 * @since   0.39.0
 * @see     https://github.com/Extra-Chill/data-machine/issues/731
 */

namespace DataMachine\Abilities\DuplicateCheck;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\WordPress\PostTracking;
use DataMachine\Core\Similarity\SimilarityEngine;
use DataMachine\Core\Similarity\SimilarityResult;

defined( 'ABSPATH' ) || exit;

class DuplicateCheckAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerCheckDuplicate();
			$this->registerTitlesMatch();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -----------------------------------------------------------------------
	// Ability: datamachine/check-duplicate
	// -----------------------------------------------------------------------

	private function registerCheckDuplicate(): void {
		wp_register_ability(
			'datamachine/check-duplicate',
			array(
				'label'               => __( 'Check Duplicate', 'data-machine' ),
				'description'         => __( 'Check if similar content already exists as a published post, in a queue, or via extension-registered strategies. Returns match details or clear verdict.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title' ),
					'properties' => array(
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'Title or topic to check for duplicates', 'data-machine' ),
						),
						'post_type'     => array(
							'type'        => 'string',
							'description' => __( 'WordPress post type to check against (default: "post")', 'data-machine' ),
						),
						'lookback_days' => array(
							'type'        => 'integer',
							'description' => __( 'How many days back to search (default: 14)', 'data-machine' ),
						),
						'scope'         => array(
							'type'        => 'string',
							'enum'        => array( 'published', 'queue', 'both' ),
							'description' => __( 'What to check: published posts, queue items, or both (default: "published")', 'data-machine' ),
						),
						'flow_id'       => array(
							'type'        => 'integer',
							'description' => __( 'Flow ID for queue check (required when scope includes queue)', 'data-machine' ),
						),
						'flow_step_id'  => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID for queue check (required when scope includes queue)', 'data-machine' ),
						),
						'threshold'     => array(
							'type'        => 'number',
							'description' => __( 'Jaccard similarity threshold for queue checks (default: 0.65)', 'data-machine' ),
						),
						'source_url'    => array(
							'type'        => 'string',
							'description' => __( 'Canonical source URL to check for exact-match duplicates before title similarity.', 'data-machine' ),
						),
						'context'       => array(
							'type'        => 'object',
							'description' => __( 'Domain-specific context for extension strategies (e.g., venue, startDate, ticketUrl for events)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'verdict'  => array( 'type' => 'string' ),
						'source'   => array( 'type' => 'string' ),
						'match'    => array( 'type' => 'object' ),
						'reason'   => array( 'type' => 'string' ),
						'strategy' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeCheckDuplicate' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute the unified duplicate check.
	 *
	 * Runs the strategy cascade:
	 * 1. Extension strategies (via filter, highest priority first)
	 * 2. Core published-post title match
	 * 3. Core queue-item Jaccard match (if scope includes queue)
	 *
	 * @param array $input Input parameters.
	 * @return array Result with verdict, source, match details, reason.
	 */
	public function executeCheckDuplicate( array $input ): array {
		$title         = sanitize_text_field( $input['title'] ?? '' );
		$post_type     = ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post';
		$lookback_days = ! empty( $input['lookback_days'] ) ? (int) $input['lookback_days'] : 14;
		$scope         = ! empty( $input['scope'] ) ? sanitize_text_field( $input['scope'] ) : 'published';
		$threshold     = ! empty( $input['threshold'] ) ? (float) $input['threshold'] : SimilarityEngine::DEFAULT_JACCARD_THRESHOLD;
		$source_url    = ! empty( $input['source_url'] ) ? esc_url_raw( $input['source_url'] ) : '';
		$context       = $input['context'] ?? array();

		if ( empty( $title ) ) {
			return array(
				'verdict' => 'error',
				'reason'  => 'title is required',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'DuplicateCheck: checking',
			array(
				'title'     => $title,
				'post_type' => $post_type,
				'scope'     => $scope,
			)
		);

		// Phase 1: Run extension strategies (if any match the post_type).
		$strategies = $this->getStrategies( $post_type );
		foreach ( $strategies as $strategy ) {
			$strategy_input = array_merge(
				$input,
				array(
					'title'     => $title,
					'post_type' => $post_type,
					'context'   => $context,
				)
			);

			$result = call_user_func( $strategy['callback'], $strategy_input );

			if ( is_array( $result ) && ! empty( $result['verdict'] ) && 'duplicate' === $result['verdict'] ) {
				$result['strategy'] = $strategy['id'] ?? 'extension';
				return $result;
			}
		}

		// Phase 2: Core published-post title match.
		if ( in_array( $scope, array( 'published', 'both' ), true ) ) {
			$source_result = $this->checkPublishedPostsBySourceUrl( $source_url, $post_type, $lookback_days );
			if ( null !== $source_result ) {
				return $source_result;
			}

			$post_result = $this->checkPublishedPosts( $title, $post_type, $lookback_days );
			if ( null !== $post_result ) {
				return $post_result;
			}
		}

		// Phase 3: Core queue-item Jaccard match.
		if ( in_array( $scope, array( 'queue', 'both' ), true ) ) {
			$flow_id      = ! empty( $input['flow_id'] ) ? (int) $input['flow_id'] : 0;
			$flow_step_id = ! empty( $input['flow_step_id'] ) ? sanitize_text_field( $input['flow_step_id'] ) : '';

			if ( $flow_id > 0 && ! empty( $flow_step_id ) ) {
				$queue_result = $this->checkQueueItems( $title, $threshold, $flow_id, $flow_step_id );
				if ( null !== $queue_result ) {
					return $queue_result;
				}
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'DuplicateCheck: CLEAR',
			array( 'title' => $title )
		);

		return array(
			'verdict'  => 'clear',
			'title'    => $title,
			'reason'   => sprintf( 'No duplicates found for "%s".', $title ),
			'strategy' => 'none',
		);
	}

	// -----------------------------------------------------------------------
	// Ability: datamachine/titles-match
	// -----------------------------------------------------------------------

	private function registerTitlesMatch(): void {
		wp_register_ability(
			'datamachine/titles-match',
			array(
				'label'               => __( 'Titles Match', 'data-machine' ),
				'description'         => __( 'Compare two titles for semantic equivalence using the unified similarity engine. Returns match result with score and strategy.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title1', 'title2' ),
					'properties' => array(
						'title1' => array(
							'type'        => 'string',
							'description' => __( 'First title', 'data-machine' ),
						),
						'title2' => array(
							'type'        => 'string',
							'description' => __( 'Second title', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'match'        => array( 'type' => 'boolean' ),
						'score'        => array( 'type' => 'number' ),
						'strategy'     => array( 'type' => 'string' ),
						'normalized_a' => array( 'type' => 'string' ),
						'normalized_b' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeTitlesMatch' ),
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Compare two titles using the unified similarity engine.
	 *
	 * @param array $input { title1: string, title2: string }
	 * @return array SimilarityResult as array.
	 */
	public function executeTitlesMatch( array $input ): array {
		$title1 = $input['title1'] ?? '';
		$title2 = $input['title2'] ?? '';

		return SimilarityEngine::titlesMatch( $title1, $title2 )->toArray();
	}

	// -----------------------------------------------------------------------
	// Strategy registry
	// -----------------------------------------------------------------------

	/**
	 * Get registered duplicate detection strategies for a post type.
	 *
	 * Extensions register strategies via the `datamachine_duplicate_strategies` filter:
	 *
	 *     add_filter('datamachine_duplicate_strategies', function($strategies) {
	 *         $strategies[] = [
	 *             'id'        => 'event',
	 *             'post_type' => 'event',
	 *             'callback'  => [EventDuplicateStrategy::class, 'check'],
	 *             'priority'  => 10,
	 *         ];
	 *         return $strategies;
	 *     });
	 *
	 * Strategies with post_type '*' run for all post types.
	 * Sorted by priority (lower = runs first).
	 *
	 * @param string $post_type The post type being checked.
	 * @return array Filtered and sorted strategies.
	 */
	private function getStrategies( string $post_type ): array {
		/**
		 * Filter the registered duplicate detection strategies.
		 *
		 * Public extension point for registering domain-specific duplicate
		 * detection strategies. Strategies run before core's published-post
		 * title match and queue-item Jaccard match. First strategy to return
		 * a `duplicate` verdict short-circuits the cascade.
		 *
		 * ## Strategy definition shape
		 *
		 *     [
		 *         'id'        => 'event_identity_index', // string, required. Stable id, used in `strategy` field of result.
		 *         'post_type' => 'data_machine_events',  // string, required. Specific post type or '*' for all types.
		 *         'callback'  => [Strategy::class, 'check'], // callable, required. Signature: function(array $input): ?array
		 *         'priority'  => 5,                      // int, optional (default: 50). Lower runs first.
		 *     ]
		 *
		 * ## Callback contract
		 *
		 * The callback receives the full ability input merged with normalized
		 * `title`, `post_type`, and `context`:
		 *
		 *     function(array $input): ?array {
		 *         // $input['title']     string  — incoming title
		 *         // $input['post_type'] string  — resolved post type
		 *         // $input['context']   array   — domain-specific payload (venue, startDate, etc.)
		 *         // $input['source_url'] string — optional canonical source URL
		 *         // ...plus any other fields the caller passed
		 *     }
		 *
		 * Return `null` to pass (let the cascade continue), or an array with:
		 *
		 *     [
		 *         'verdict'  => 'duplicate',          // string, required — must be 'duplicate' to short-circuit
		 *         'source'   => 'identity_index',     // string, optional — origin of the match
		 *         'match'    => [                     // array, required — match details
		 *             'post_id' => 123,
		 *             'title'   => 'Existing Post',
		 *             'url'     => 'https://example.com/existing',
		 *             // ...strategy-specific fields allowed
		 *         ],
		 *         'reason'   => 'Matched existing ...', // string, optional — human-readable explanation
		 *         'strategy' => 'event_identity_index', // string, optional — overrides 'id' in the result
		 *     ]
		 *
		 * Any non-`duplicate` verdict (or missing `verdict`) is treated as a pass.
		 *
		 * ## Stability
		 *
		 * This filter and the strategy contract above are considered a public
		 * API as of 0.39.0. The callback signature, return shape, and the
		 * input fields documented above will not change in a backward-incompatible
		 * way without a deprecation cycle.
		 *
		 * @since 0.39.0 Public extension point.
		 *
		 * @param array  $strategies Array of strategy definitions (see shape above).
		 * @param string $post_type  The post type being checked.
		 */
		$strategies = apply_filters( 'datamachine_duplicate_strategies', array(), $post_type );

		if ( ! is_array( $strategies ) ) {
			return array();
		}

		// Filter to strategies that match this post type (or wildcard).
		$strategies = array_filter(
			$strategies,
			function ( $strategy ) use ( $post_type ) {
				if ( ! is_array( $strategy ) || ! is_callable( $strategy['callback'] ?? null ) ) {
					return false;
				}
				$strategy_type = $strategy['post_type'] ?? '*';
				return '*' === $strategy_type || $strategy_type === $post_type;
			}
		);

		// Sort by priority (lower = runs first).
		usort(
			$strategies,
			function ( $a, $b ) {
				return ( $a['priority'] ?? 50 ) - ( $b['priority'] ?? 50 );
			}
		);

		return $strategies;
	}

	// -----------------------------------------------------------------------
	// Core strategies
	// -----------------------------------------------------------------------

	/**
	 * Check published posts for matching titles using the similarity engine.
	 *
	 * @param string $title         Title to check.
	 * @param string $post_type     Post type to search.
	 * @param int    $lookback_days How many days back to search.
	 * @return array|null Duplicate result or null if clear.
	 */
	private function checkPublishedPosts( string $title, string $post_type, int $lookback_days ): ?array {
		if ( empty( $title ) || empty( $post_type ) ) {
			return null;
		}

		if ( $lookback_days <= 0 ) {
			$lookback_days = 14;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$lookback_days} days" ) );

		$candidates = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- intentional batch query
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'after'     => $cutoff_date,
						'inclusive' => true,
					),
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $candidate_id ) {
			$candidate_title = get_the_title( $candidate_id );
			$result          = SimilarityEngine::titlesMatch( $title, $candidate_title );

			if ( $result->match ) {
				do_action(
					'datamachine_log',
					'info',
					'DuplicateCheck: found matching published post',
					array(
						'incoming_title' => $title,
						'existing_id'    => $candidate_id,
						'existing_title' => $candidate_title,
						'score'          => $result->score,
						'strategy'       => $result->strategy,
					)
				);

				return array(
					'verdict'  => 'duplicate',
					'source'   => 'published_post',
					'match'    => array(
						'post_id'    => (int) $candidate_id,
						'title'      => $candidate_title,
						'url'        => get_permalink( $candidate_id ),
						'score'      => $result->score,
						'similarity' => $result->score,
					),
					'reason'   => sprintf(
						'Rejected: "%s" matches existing post "%s" (ID %d) via %s (score: %.2f).',
						$title,
						$candidate_title,
						$candidate_id,
						$result->strategy,
						$result->score
					),
					'strategy' => 'core_published_post',
				);
			}
		}

		return null;
	}

	/**
	 * Check published posts for exact source URL matches.
	 *
	 * Tries the PostIdentityIndex first (indexed lookup), then falls back
	 * to meta_query for posts not yet in the index.
	 *
	 * @param string $source_url    Canonical source URL.
	 * @param string $post_type     Post type to search.
	 * @param int    $lookback_days How many days back to search.
	 * @return array|null Duplicate result or null if clear.
	 */
	private function checkPublishedPostsBySourceUrl( string $source_url, string $post_type, int $lookback_days ): ?array {
		if ( empty( $source_url ) || empty( $post_type ) ) {
			return null;
		}

		// Fast path: check the identity index first (indexed column lookup).
		$index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
		$match = $index->find_by_source_url( $source_url );

		if ( $match ) {
			$candidate_id = (int) $match['post_id'];
			$post_status  = get_post_status( $candidate_id );

			if ( $post_status && in_array( $post_status, array( 'publish', 'draft', 'pending' ), true ) ) {
				$candidate_title = get_the_title( $candidate_id );

				do_action(
					'datamachine_log',
					'info',
					'DuplicateCheck: found matching post by source URL (identity index)',
					array(
						'source_url'     => $source_url,
						'existing_id'    => $candidate_id,
						'existing_title' => $candidate_title,
						'post_type'      => $post_type,
					)
				);

				return array(
					'verdict'  => 'duplicate',
					'source'   => 'published_post_source_url',
					'match'    => array(
						'post_id'    => $candidate_id,
						'title'      => $candidate_title,
						'url'        => get_permalink( $candidate_id ),
						'source_url' => $source_url,
					),
					'reason'   => sprintf(
						'Rejected: source URL already exists on post "%s" (ID %d).',
						$candidate_title,
						$candidate_id
					),
					'strategy' => 'core_published_source_url',
				);
			}
		}

		// Fallback: meta_query for posts not yet in the identity index.
		if ( $lookback_days <= 0 ) {
			$lookback_days = 14;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$lookback_days} days" ) );

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'after'     => $cutoff_date,
						'inclusive' => true,
					),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Fallback for pre-index posts.
				'meta_query'     => array(
					array(
						'key'   => PostTracking::SOURCE_URL_META_KEY,
						'value' => $source_url,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$candidate_id    = (int) $query->posts[0];
		$candidate_title = get_the_title( $candidate_id );

		do_action(
			'datamachine_log',
			'info',
			'DuplicateCheck: found matching published post by source URL (meta fallback)',
			array(
				'source_url'     => $source_url,
				'existing_id'    => $candidate_id,
				'existing_title' => $candidate_title,
				'post_type'      => $post_type,
			)
		);

		return array(
			'verdict'  => 'duplicate',
			'source'   => 'published_post_source_url',
			'match'    => array(
				'post_id'    => $candidate_id,
				'title'      => $candidate_title,
				'url'        => get_permalink( $candidate_id ),
				'source_url' => $source_url,
			),
			'reason'   => sprintf(
				'Rejected: source URL already exists on post "%s" (ID %d).',
				$candidate_title,
				$candidate_id
			),
			'strategy' => 'core_published_source_url',
		);
	}

	/**
	 * Check queue items for similar prompts using Jaccard similarity.
	 *
	 * @param string $title        Title to check.
	 * @param float  $threshold    Jaccard threshold.
	 * @param int    $flow_id      Flow ID.
	 * @param string $flow_step_id Flow step ID.
	 * @return array|null Duplicate result or null if clear.
	 */
	private function checkQueueItems( string $title, float $threshold, int $flow_id, string $flow_step_id ): ?array {
		$db_flows_class = 'DataMachine\\Core\\Database\\Flows\\Flows';
		if ( ! class_exists( $db_flows_class ) ) {
			return null;
		}

		$db_flows = new $db_flows_class();
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

		$best_match = null;
		$best_score = 0.0;

		foreach ( $prompt_queue as $index => $item ) {
			$prompt = $item['prompt'] ?? '';
			$result = SimilarityEngine::jaccardMatch( $title, $prompt, $threshold );

			if ( $result->match && $result->score > $best_score ) {
				$best_score = $result->score;
				$best_match = array(
					'index'      => (int) $index,
					'prompt'     => $prompt,
					'similarity' => round( $result->score, 3 ),
				);
			}
		}

		if ( null !== $best_match ) {
			do_action(
				'datamachine_log',
				'info',
				'DuplicateCheck: found matching queue item',
				array(
					'title' => $title,
					'match' => $best_match,
				)
			);

			return array(
				'verdict'  => 'duplicate',
				'source'   => 'queue',
				'match'    => $best_match,
				'reason'   => sprintf(
					'Rejected: "%s" is %.0f%% similar to queued item "%s" (index %d).',
					$title,
					$best_match['similarity'] * 100,
					$best_match['prompt'],
					$best_match['index']
				),
				'strategy' => 'core_queue_item',
			);
		}

		return null;
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
