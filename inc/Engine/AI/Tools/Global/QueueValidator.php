<?php
/**
 * Queue Validator tool for duplicate detection.
 *
 * Checks whether a topic already exists as a published post or in a
 * Data Machine queue before content generation begins. Returns a clear
 * verdict with match details so agents (and humans in the dashboard)
 * can see exactly what was caught and why.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Flows\Flows as DB_Flows;
use DataMachine\Engine\AI\Tools\BaseTool;

class QueueValidator extends BaseTool {

	/**
	 * Default Jaccard similarity threshold.
	 *
	 * @var float
	 */
	const DEFAULT_THRESHOLD = 0.65;

	/**
	 * Stop words excluded from similarity comparison.
	 *
	 * @var array
	 */
	const STOP_WORDS = array(
		'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
		'for', 'of', 'with', 'by', 'from', 'is', 'it', 'are', 'was',
		'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do',
		'does', 'did', 'will', 'would', 'could', 'should', 'may',
		'might', 'shall', 'can', 'not', 'no', 'if', 'when', 'what',
		'why', 'how', 'who', 'where', 'which', 'that', 'this', 'you',
		'your', 'my', 'am', 'me', 'we', 'they', 'them', 'its',
	);

	public function __construct() {
		$this->registerGlobalTool( 'queue_validator', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Validate a topic against published posts and queue items.
	 *
	 * @param array $parameters Tool call parameters.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Validation result with verdict and match details.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		if ( empty( $parameters['topic'] ) ) {
			return $this->buildErrorResponse(
				'Queue validator requires a topic parameter.',
				'queue_validator'
			);
		}

		$topic     = sanitize_text_field( $parameters['topic'] );
		$threshold = $this->resolveThreshold( $parameters );

		do_action(
			'datamachine_log',
			'info',
			'Queue validator: checking topic',
			array(
				'topic'     => $topic,
				'threshold' => $threshold,
			)
		);

		// Check 1: Existing published posts.
		$post_match = $this->checkPublishedPosts( $topic, $threshold );

		if ( null !== $post_match ) {
			do_action(
				'datamachine_log',
				'info',
				'Queue validator: DUPLICATE — similar published post found',
				array(
					'topic'      => $topic,
					'match'      => $post_match,
				)
			);

			return array(
				'success'    => true,
				'verdict'    => 'duplicate',
				'source'     => 'published_post',
				'topic'      => $topic,
				'match'      => array(
					'title'      => $post_match['title'],
					'post_id'    => $post_match['post_id'],
					'url'        => $post_match['url'],
					'similarity' => $post_match['similarity'],
				),
				'reason'     => sprintf(
					'Rejected: "%s" is %.0f%% similar to existing post "%s" (ID %d). Threshold: %.0f%%.',
					$topic,
					$post_match['similarity'] * 100,
					$post_match['title'],
					$post_match['post_id'],
					$threshold * 100
				),
				'tool_name'  => 'queue_validator',
			);
		}

		// Check 2: Queue items (if flow_id and flow_step_id provided).
		if ( ! empty( $parameters['flow_id'] ) && ! empty( $parameters['flow_step_id'] ) ) {
			$queue_match = $this->checkQueueItems(
				$topic,
				$threshold,
				(int) $parameters['flow_id'],
				sanitize_text_field( $parameters['flow_step_id'] )
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
					'success'    => true,
					'verdict'    => 'duplicate',
					'source'     => 'queue',
					'topic'      => $topic,
					'match'      => array(
						'prompt'     => $queue_match['prompt'],
						'index'      => $queue_match['index'],
						'similarity' => $queue_match['similarity'],
					),
					'reason'     => sprintf(
						'Rejected: "%s" is %.0f%% similar to queued item "%s" (index %d). Threshold: %.0f%%.',
						$topic,
						$queue_match['similarity'] * 100,
						$queue_match['prompt'],
						$queue_match['index'],
						$threshold * 100
					),
					'tool_name'  => 'queue_validator',
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
			'success'   => true,
			'verdict'   => 'clear',
			'topic'     => $topic,
			'reason'    => sprintf( 'No duplicates found for "%s".', $topic ),
			'tool_name' => 'queue_validator',
		);
	}

	/**
	 * Check published posts for similar titles.
	 *
	 * Uses WP_Query with a keyword search for candidate fetch, then
	 * Jaccard similarity on tokenized words for accurate matching.
	 *
	 * @param string $topic     Topic to check.
	 * @param float  $threshold Similarity threshold.
	 * @return array|null Best match above threshold, or null.
	 */
	private function checkPublishedPosts( string $topic, float $threshold ): ?array {
		$search_word = $this->getBestSearchWord( $topic );

		if ( empty( $search_word ) ) {
			return null;
		}

		$query = new \WP_Query(
			array(
				's'              => $search_word,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return null;
		}

		$topic_words = $this->tokenize( $topic );
		$best_match  = null;
		$best_score  = 0.0;

		foreach ( $query->posts as $post_id ) {
			$title       = get_the_title( $post_id );
			$title_words = $this->tokenize( $title );
			$score       = $this->jaccard( $topic_words, $title_words );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_match = array(
					'post_id'    => (int) $post_id,
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

		$topic_words = $this->tokenize( $topic );
		$best_match  = null;
		$best_score  = 0.0;

		foreach ( $prompt_queue as $index => $item ) {
			$prompt      = $item['prompt'] ?? '';
			$item_words  = $this->tokenize( $prompt );
			$score       = $this->jaccard( $topic_words, $item_words );

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
	 * Tokenize text into a set of significant lowercase words.
	 *
	 * Strips stop words and short words (< 2 chars) to focus on
	 * content-bearing terms for similarity comparison.
	 *
	 * @param string $text Input text.
	 * @return array Set of significant words (unique).
	 */
	public function tokenize( string $text ): array {
		preg_match_all( '/[a-z0-9]+/', strtolower( $text ), $matches );

		$words = array();
		foreach ( $matches[0] as $word ) {
			if ( strlen( $word ) >= 2 && ! in_array( $word, self::STOP_WORDS, true ) ) {
				$words[ $word ] = true;
			}
		}

		return array_keys( $words );
	}

	/**
	 * Compute Jaccard similarity between two word sets.
	 *
	 * @param array $set_a First word set.
	 * @param array $set_b Second word set.
	 * @return float Similarity score between 0.0 and 1.0.
	 */
	public function jaccard( array $set_a, array $set_b ): float {
		if ( empty( $set_a ) || empty( $set_b ) ) {
			return 0.0;
		}

		$intersection = array_intersect( $set_a, $set_b );
		$union        = array_unique( array_merge( $set_a, $set_b ) );

		return count( $intersection ) / count( $union );
	}

	/**
	 * Get the best search word for WP_Query candidate fetch.
	 *
	 * Returns the longest significant word (3+ chars, not a stop word)
	 * to cast a wide net for potential matches.
	 *
	 * @param string $text Input text.
	 * @return string|null Best search word, or null if none found.
	 */
	private function getBestSearchWord( string $text ): ?string {
		preg_match_all( '/[a-z0-9]+/', strtolower( $text ), $matches );

		$candidates = array();
		foreach ( $matches[0] as $word ) {
			if ( strlen( $word ) >= 3 && ! in_array( $word, self::STOP_WORDS, true ) ) {
				$candidates[] = $word;
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort( $candidates, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		return $candidates[0];
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

		return self::DEFAULT_THRESHOLD;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handle_tool_call',
			'description' => 'Check if a topic already exists as a published post or in a Data Machine queue before generating content. Returns "clear" if no duplicates found, or "duplicate" with match details (title, similarity score, source). Always use this before adding topics to the queue or starting content generation to avoid duplicate work.',
			'parameters'  => array(
				'topic'                => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Topic or title to validate against existing content and queue items.',
				),
				'flow_id'              => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Data Machine flow ID to check queue against. Required together with flow_step_id to enable queue checking.',
				),
				'flow_step_id'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Flow step ID to check queue against. Required together with flow_id to enable queue checking.',
				),
				'similarity_threshold' => array(
					'type'        => 'number',
					'required'    => false,
					'description' => 'Jaccard similarity threshold between 0.0 and 1.0 (default: 0.65). Lower values catch more potential duplicates.',
				),
			),
		);
	}
}

// Self-register the tool.
new QueueValidator();
