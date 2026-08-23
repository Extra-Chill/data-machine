<?php
/**
 * Duplicate Detection Abilities
 *
 * Event-domain duplicate detection abilities. Venue comparison and the
 * combined find-duplicate-event search remain event-specific. Title
 * comparison delegates to Data Machine's public title-match ability.
 *
 * Also registers an event strategy on the `datamachine_duplicate_strategies`
 * filter so the unified `datamachine/check-duplicate` ability can find
 * event duplicates using venue + date + title matching.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.15.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

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
			$this->registerVenuesMatchAbility();
			$this->registerFindDuplicateEventAbility();
		};

		add_action( 'wp_abilities_api_init', $register_callback );
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
				'category'            => 'datamachine-events-venues',
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
				'category'            => 'datamachine-events-venues',
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
	 * Delegates to Data Machine's canonical duplicate-check ability.
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

		$ability = function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/check-duplicate' )
			? wp_get_ability( 'datamachine/check-duplicate' )
			: null;
		if ( ! $ability ) {
			throw new \RuntimeException( 'Data Machine 0.39.0 or newer is required: datamachine/check-duplicate is unavailable.' );
		}

		$result = $ability->execute(
			array(
				'title'     => $title,
				'post_type' => Event_Post_Type::POST_TYPE,
				'scope'     => 'published',
				'context'   => array(
					'venue'     => $venue,
					'startDate' => $startDate,
				),
			)
		);

		if (
			! is_array( $result )
			|| 'duplicate' !== ( $result['verdict'] ?? '' )
			|| 'event_identity_index' !== ( $result['strategy'] ?? '' )
		) {
			return array( 'found' => false );
		}

		$match = $result['match'] ?? array();
		return array(
			'found'          => true,
			'post_id'        => $match['post_id'] ?? 0,
			'matched_title'  => $match['title'] ?? '',
			'matched_venue'  => '',
			'match_strategy' => $result['strategy'] ?? 'event_date_query',
		);
	}
}
