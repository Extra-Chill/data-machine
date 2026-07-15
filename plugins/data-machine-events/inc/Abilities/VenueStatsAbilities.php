<?php
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Venue Stats Abilities
 *
 * Exposes a tiny `data-machine-events/venue-stats` ability that returns
 * three counts: venue terms total, venue terms with empty
 * `_venue_address`, and venue terms with `wp_term_taxonomy.count = 0`.
 *
 * Kept deliberately small. This is a read-only stats surface; the actual
 * repair work lives in the two `check` CLI commands. Consumers can use it to
 * surface venue-data trends without adding reporting policy to this plugin.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.38.0
 */

namespace DataMachineEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueStatsAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/venue-stats',
				array(
					'label'               => __( 'Venue Stats', 'data-machine-events' ),
					'description'         => __( 'Network-cheap counts for the venue audit digest: total venues, terms with no _venue_address, and orphan terms (wp_term_taxonomy.count = 0).', 'data-machine-events' ),
					'category'            => 'datamachine-events-venues',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'no_address' => array(
								'type'        => 'integer',
								'description' => 'Count of venue terms whose _venue_address meta is empty or missing.',
							),
							'orphans'    => array(
								'type'        => 'integer',
								'description' => 'Count of venue terms whose wp_term_taxonomy.count = 0.',
							),
							'total'      => array(
								'type'        => 'integer',
								'description' => 'Total count of venue terms.',
							),
							'queried_at' => array(
								'type'        => 'integer',
								'description' => 'Unix timestamp at which the stats were computed.',
							),
						),
					),
					'execute_callback'    => array( $this, 'executeVenueStats' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' )
							|| ( defined( 'WP_CLI' ) && WP_CLI );
					},
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the venue-stats ability.
	 *
	 * Uses two cheap aggregate queries instead of pulling every term
	 * into PHP. The digest is expected to call this weekly across
	 * multiple sites, so we keep the cost bounded.
	 *
	 * @param array $input Unused — the ability takes no inputs.
	 * @return array{no_address:int,orphans:int,total:int,queried_at:int}
	 */
	public function executeVenueStats( array $input ): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'venue'"
		);

		$orphans = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy}
			 WHERE taxonomy = 'venue' AND count = 0"
		);

		// no_address: venue terms whose `_venue_address` meta is NULL,
		// missing, or an empty string. Left-join so terms with no row
		// in termmeta at all are still counted.
		$no_address = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
			 LEFT JOIN {$wpdb->termmeta} tm
			        ON tm.term_id = tt.term_id
			       AND tm.meta_key = '_venue_address'
			 WHERE tt.taxonomy = 'venue'
			   AND ( tm.meta_value IS NULL OR tm.meta_value = '' )"
		);

		return array(
			'no_address' => $no_address,
			'orphans'    => $orphans,
			'total'      => $total,
			'queried_at' => time(),
		);
	}
}
