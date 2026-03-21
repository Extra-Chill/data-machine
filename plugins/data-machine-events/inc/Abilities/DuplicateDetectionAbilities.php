<?php
/**
 * Duplicate Detection Abilities
 *
 * Event-domain duplicate detection abilities. Venue comparison and the
 * combined find-duplicate-event search remain event-specific. Title
 * comparison delegates to the core SimilarityEngine.
 *
 * Also registers an event strategy on the `datamachine_duplicate_strategies`
 * filter so the unified `datamachine/check-duplicate` ability can find
 * event duplicates using venue + date + title matching.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.15.0
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Core\Similarity\SimilarityEngine;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DuplicateDetectionAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			$this->registerStrategy();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerVenuesMatchAbility();
			$this->registerFindDuplicateEventAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register event duplicate strategy on the unified filter.
	 *
	 * When the PostIdentityIndex is available, the strategy is registered
	 * by EventDuplicateStrategy (priority 5) instead. This legacy strategy
	 * only registers as a fallback when the identity index doesn't exist yet.
	 */
	private function registerStrategy(): void {
		// Only register the legacy postmeta-based strategy if the new identity
		// index strategy is NOT available. EventDuplicateStrategy::register()
		// adds itself at priority 5, so if both are present, the new one wins.
		if ( class_exists( 'DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {
			return; // EventDuplicateStrategy handles this now.
		}
		add_filter( 'datamachine_duplicate_strategies', array( $this, 'addEventStrategy' ) );
	}

	/**
	 * Add legacy event duplicate strategy to the strategy registry.
	 *
	 * @deprecated 0.18.0 Replaced by EventDuplicateStrategy using PostIdentityIndex.
	 *
	 * @param array $strategies Existing strategies.
	 * @return array Strategies with event strategy appended.
	 */
	public function addEventStrategy( array $strategies ): array {
		$strategies[] = array(
			'id'        => 'event_venue_date_title',
			'post_type' => Event_Post_Type::POST_TYPE,
			'callback'  => array( $this, 'executeEventStrategy' ),
			'priority'  => 10,
		);
		return $strategies;
	}

	/**
	 * Legacy event duplicate strategy callback.
	 *
	 * @deprecated 0.18.0 Replaced by EventDuplicateStrategy using PostIdentityIndex.
	 *
	 * @param array $input { title: string, context: { venue?: string, startDate?: string } }
	 * @return array Result with verdict key.
	 */
	public function executeEventStrategy( array $input ): array {
		$title     = $input['title'] ?? '';
		$context   = $input['context'] ?? array();
		$venue     = $context['venue'] ?? '';
		$startDate = $context['startDate'] ?? '';

		if ( empty( $title ) || empty( $startDate ) ) {
			return array( 'verdict' => 'clear' );
		}

		$result = $this->executeFindDuplicateEvent(
			array(
				'title'     => $title,
				'venue'     => $venue,
				'startDate' => $startDate,
			)
		);

		if ( ! empty( $result['found'] ) ) {
			return array(
				'verdict'  => 'duplicate',
				'source'   => 'event_' . ( $result['match_strategy'] ?? 'fuzzy' ),
				'match'    => array(
					'post_id' => $result['post_id'] ?? 0,
					'title'   => $result['matched_title'] ?? '',
					'venue'   => $result['matched_venue'] ?? '',
				),
				'reason'   => sprintf(
					'Rejected: "%s" matches existing event "%s" (ID %d) via %s.',
					$title,
					$result['matched_title'] ?? '',
					$result['post_id'] ?? 0,
					$result['match_strategy'] ?? 'fuzzy'
				),
				'strategy' => 'event_venue_date_title',
			);
		}

		return array( 'verdict' => 'clear' );
	}

	// -----------------------------------------------------------------------
	// Ability: venues-match
	// -----------------------------------------------------------------------

	private function registerVenuesMatchAbility(): void {
		wp_register_ability(
			'data-machine-events/venues-match',
			array(
				'label'               => __( 'Venues Match', 'data-machine-events' ),
				'description'         => __( 'Compare two venue names for semantic equivalence. Handles HTML entities, parenthetical stage names, dash-separated qualifiers, and article removal.', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'venue1', 'venue2' ),
					'properties' => array(
						'venue1' => array(
							'type'        => 'string',
							'description' => 'First venue name',
						),
						'venue2' => array(
							'type'        => 'string',
							'description' => 'Second venue name',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'match' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'executeVenuesMatch' ),
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Compare two venue names for semantic match.
	 *
	 * @param array $input { venue1: string, venue2: string }
	 * @return array { match: bool }
	 */
	public function executeVenuesMatch( array $input ): array {
		$venue1 = $input['venue1'] ?? '';
		$venue2 = $input['venue2'] ?? '';

		return array(
			'match' => EventIdentifierGenerator::venuesMatch( $venue1, $venue2 ),
		);
	}

	// -----------------------------------------------------------------------
	// Ability: find-duplicate-event
	// -----------------------------------------------------------------------

	private function registerFindDuplicateEventAbility(): void {
		wp_register_ability(
			'data-machine-events/find-duplicate-event',
			array(
				'label'               => __( 'Find Duplicate Event', 'data-machine-events' ),
				'description'         => __( 'Search for an existing event that matches the given title, venue, and date using fuzzy matching. Returns the matching post ID or null.', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title', 'startDate' ),
					'properties' => array(
						'title'     => array(
							'type'        => 'string',
							'description' => 'Event title to search for',
						),
						'venue'     => array(
							'type'        => 'string',
							'description' => 'Venue name (optional but improves accuracy)',
						),
						'startDate' => array(
							'type'        => 'string',
							'description' => 'Event start date (YYYY-MM-DD)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'found'          => array( 'type' => 'boolean' ),
						'post_id'        => array( 'type' => 'integer' ),
						'matched_title'  => array( 'type' => 'string' ),
						'matched_venue'  => array( 'type' => 'string' ),
						'match_strategy' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeFindDuplicateEvent' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Find an existing event matching the given identity fields.
	 *
	 * When the PostIdentityIndex is available, delegates to EventDuplicateStrategy
	 * for fast indexed lookups. Otherwise falls back to legacy postmeta queries.
	 *
	 * @param array $input { title: string, venue?: string, startDate: string }
	 * @return array { found: bool, post_id?: int, matched_title?: string, matched_venue?: string, match_strategy?: string }
	 */
	public function executeFindDuplicateEvent( array $input ): array {
		$title     = $input['title'] ?? '';
		$venue     = $input['venue'] ?? '';
		$startDate = $input['startDate'] ?? '';

		if ( empty( $title ) || empty( $startDate ) ) {
			return array( 'found' => false );
		}

		// Fast path: use the identity index when available.
		if ( class_exists( 'DataMachineEvents\\Core\\DuplicateDetection\\EventDuplicateStrategy' )
			&& class_exists( 'DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {

			$result = \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::check(
				array(
					'title'   => $title,
					'context' => array(
						'venue'     => $venue,
						'startDate' => $startDate,
					),
				)
			);

			if ( is_array( $result ) && 'duplicate' === ( $result['verdict'] ?? '' ) ) {
				$match = $result['match'] ?? array();
				return array(
					'found'          => true,
					'post_id'        => $match['post_id'] ?? 0,
					'matched_title'  => $match['title'] ?? '',
					'matched_venue'  => '',
					'match_strategy' => $result['strategy'] ?? 'identity_index',
				);
			}

			return array( 'found' => false );
		}

		// Legacy fallback: postmeta LIKE queries.
		return $this->executeFindDuplicateEventLegacy( $title, $venue, $startDate );
	}

	/**
	 * Legacy duplicate event search using postmeta LIKE queries.
	 *
	 * @deprecated 0.18.0 Replaced by EventDuplicateStrategy using PostIdentityIndex.
	 *
	 * @param string $title     Event title.
	 * @param string $venue     Venue name.
	 * @param string $startDate Start date.
	 * @return array Result array.
	 */
	private function executeFindDuplicateEventLegacy( string $title, string $venue, string $startDate ): array {
		// Strategy 1: venue-scoped fuzzy title match.
		if ( ! empty( $venue ) ) {
			$venue_term = get_term_by( 'name', $venue, 'venue' );
			if ( ! $venue_term ) {
				$venue_term = get_term_by( 'slug', sanitize_title( $venue ), 'venue' );
			}

			if ( $venue_term ) {
				$candidates = get_posts(
					array(
						'post_type'      => Event_Post_Type::POST_TYPE,
						'posts_per_page' => 10,
						'post_status'    => array( 'publish', 'draft', 'pending' ),
						'tax_query'      => array(
							array(
								'taxonomy' => 'venue',
								'field'    => 'term_id',
								'terms'    => $venue_term->term_id,
							),
						),
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy fallback, replaced by PostIdentityIndex.
						'meta_query'     => array(
							array(
								'key'     => EVENT_DATETIME_META_KEY,
								'value'   => $startDate,
								'compare' => 'LIKE',
							),
						),
					)
				);

				foreach ( $candidates as $candidate ) {
					if ( EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
						return array(
							'found'          => true,
							'post_id'        => $candidate->ID,
							'matched_title'  => $candidate->post_title,
							'matched_venue'  => $venue_term->name,
							'match_strategy' => 'venue_date_fuzzy_title',
						);
					}
				}
			}
		}

		// Strategy 2: date-scoped fuzzy title + venue confirmation.
		$candidates = get_posts(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'posts_per_page' => 20,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy fallback, replaced by PostIdentityIndex.
				'meta_query'     => array(
					array(
						'key'     => EVENT_DATETIME_META_KEY,
						'value'   => $startDate,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $candidates as $candidate ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
				continue;
			}

			if ( ! empty( $venue ) ) {
				$candidate_venues = wp_get_post_terms( $candidate->ID, 'venue', array( 'fields' => 'names' ) );
				$candidate_venue  = ( ! is_wp_error( $candidate_venues ) && ! empty( $candidate_venues ) ) ? $candidate_venues[0] : '';

				if ( ! empty( $candidate_venue ) && ! EventIdentifierGenerator::venuesMatch( $venue, $candidate_venue ) ) {
					continue;
				}
			}

			$match_venues = wp_get_post_terms( $candidate->ID, 'venue', array( 'fields' => 'names' ) );

			return array(
				'found'          => true,
				'post_id'        => $candidate->ID,
				'matched_title'  => $candidate->post_title,
				'matched_venue'  => ( ! is_wp_error( $match_venues ) && ! empty( $match_venues ) ) ? $match_venues[0] : '',
				'match_strategy' => 'date_fuzzy_title_venue_confirm',
			);
		}

		return array( 'found' => false );
	}
}
