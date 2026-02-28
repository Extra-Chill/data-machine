<?php
/**
 * Duplicate Detection Abilities
 *
 * Universal primitives for event identity matching. Exposes fuzzy title
 * comparison, venue comparison, and combined duplicate-event search as
 * abilities that any part of the system can consume (CLI, REST, Chat,
 * import pipeline, MCP).
 *
 * @package DataMachineEvents\Abilities
 * @since   0.15.0
 */

namespace DataMachineEvents\Abilities;

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
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerTitlesMatchAbility();
			$this->registerVenuesMatchAbility();
			$this->registerFindDuplicateEventAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -----------------------------------------------------------------------
	// Ability: titles-match
	// -----------------------------------------------------------------------

	private function registerTitlesMatchAbility(): void {
		wp_register_ability(
			'datamachine-events/titles-match',
			array(
				'label'               => __( 'Titles Match', 'datamachine-events' ),
				'description'         => __( 'Compare two event titles for semantic equivalence. Strips tour names, supporting acts, and normalizes for fuzzy comparison.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title1', 'title2' ),
					'properties' => array(
						'title1' => array(
							'type'        => 'string',
							'description' => 'First event title',
						),
						'title2' => array(
							'type'        => 'string',
							'description' => 'Second event title',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'match'      => array( 'type' => 'boolean' ),
						'core1'      => array( 'type' => 'string' ),
						'core2'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeTitlesMatch' ),
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Compare two event titles for semantic match.
	 *
	 * @param array $input { title1: string, title2: string }
	 * @return array { match: bool, core1: string, core2: string }
	 */
	public function executeTitlesMatch( array $input ): array {
		$title1 = $input['title1'] ?? '';
		$title2 = $input['title2'] ?? '';

		return array(
			'match' => EventIdentifierGenerator::titlesMatch( $title1, $title2 ),
			'core1' => EventIdentifierGenerator::extractCoreTitle( $title1 ),
			'core2' => EventIdentifierGenerator::extractCoreTitle( $title2 ),
		);
	}

	// -----------------------------------------------------------------------
	// Ability: venues-match
	// -----------------------------------------------------------------------

	private function registerVenuesMatchAbility(): void {
		wp_register_ability(
			'datamachine-events/venues-match',
			array(
				'label'               => __( 'Venues Match', 'datamachine-events' ),
				'description'         => __( 'Compare two venue names for semantic equivalence. Handles HTML entities, parenthetical stage names, dash-separated qualifiers, and article removal.', 'datamachine-events' ),
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
			'datamachine-events/find-duplicate-event',
			array(
				'label'               => __( 'Find Duplicate Event', 'datamachine-events' ),
				'description'         => __( 'Search for an existing event that matches the given title, venue, and date using fuzzy matching. Returns the matching post ID or null.', 'datamachine-events' ),
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
	 * Uses fuzzy title + venue matching to find duplicates across sources.
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

			// Confirm venue if both sides have one.
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
