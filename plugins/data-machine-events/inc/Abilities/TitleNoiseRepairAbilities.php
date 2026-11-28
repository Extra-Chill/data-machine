<?php
/**
 * Title Noise Repair Abilities
 *
 * Backfills published events whose post_title carries schedule or price
 * noise ("Sean Smith 6-9pm", "David Peterson $20", "Say Uncle / Kook. Door
 * @ 7:30. Show @ 8:30. $10 21+"). See issue #932. The upsert path cleans new
 * imports through EventTitleCleaner; this repairs rows stored before that.
 *
 * Only post_title is rewritten. Structured fields (endTime, price) are left
 * alone on existing rows: their stored values came from the source or a
 * human, and the repair must not overwrite them with a guess parsed from the
 * title. The recovered values are reported so an operator can review them.
 *
 * The title hash used for date+title dedup changes with the title. That is
 * intended: the upsert cleans incoming titles before hashing, so the
 * repaired row now hashes the same as future imports of the same listing.
 *
 * Dry run by default; every change requires an explicit execute flag.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.68.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventTitleCleaner;
use DataMachineEvents\Core\TextNormalization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TitleNoiseRepairAbilities {

	private const DEFAULT_LIMIT = -1;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/repair-title-noise',
				array(
					'label'               => __( 'Repair Event Title Noise', 'data-machine-events' ),
					'description'         => __( 'Strip schedule and price noise from published event titles (dry run by default)', 'data-machine-events' ),
					'category'            => AbilityCategories::EVENTS,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
								'default'     => true,
							),
							'limit'   => array(
								'type'        => 'integer',
								'description' => 'Maximum events to scan (default: -1 for all)',
								'default'     => -1,
							),
							'scope'   => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => 'Which published events to scan (default: upcoming)',
								'default'     => 'upcoming',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'  => array( 'type' => 'boolean' ),
							'scanned'  => array( 'type' => 'integer' ),
							'repaired' => array( 'type' => 'integer' ),
							'changes'  => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'   => array( 'type' => 'integer' ),
										'old_title' => array( 'type' => 'string' ),
										'new_title' => array( 'type' => 'string' ),
										'end_time'  => array( 'type' => 'string' ),
										'price'     => array( 'type' => 'string' ),
									),
								),
							),
							'message'  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeRepair' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the title-noise repair.
	 *
	 * @param array $input Input parameters (`dry_run`, `limit`, `scope`).
	 * @return array Scan summary with per-row changes.
	 */
	public function executeRepair( array $input ): array {
		$dry_run = $input['dry_run'] ?? true;
		$limit   = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$scope   = $input['scope'] ?? 'upcoming';

		$event_query = new EventDateQueryAbilities();
		$result      = $event_query->executeQueryEvents(
			array(
				'scope'    => $scope,
				'per_page' => $limit,
				'status'   => 'publish',
			)
		);
		$events      = is_array( $result['posts'] ?? null ) ? $result['posts'] : array();

		$scanned = 0;
		$changes = array();

		foreach ( $events as $event ) {
			++$scanned;

			$post = get_post( $event->ID );
			if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
				continue;
			}

			$old_title = (string) $post->post_title;
			$cleaned   = EventTitleCleaner::clean( TextNormalization::decode_entities( $old_title ) );
			if ( ! $cleaned['changed'] ) {
				continue;
			}

			$new_title = $cleaned['title'];

			if ( ! $dry_run ) {
				TextNormalization::with_kses_suspended(
					static function () use ( $post, $new_title ): void {
						wp_update_post(
							array(
								'ID'         => $post->ID,
								'post_title' => $new_title,
							)
						);
					}
				);
			}

			$changes[] = array(
				'post_id'   => (int) $post->ID,
				'old_title' => $old_title,
				'new_title' => $new_title,
				'end_time'  => $cleaned['end_time'],
				'price'     => $cleaned['price'],
			);
		}

		$repaired = count( $changes );

		return array(
			'dry_run'  => (bool) $dry_run,
			'scanned'  => $scanned,
			'repaired' => $repaired,
			'changes'  => $changes,
			'message'  => $dry_run
				? "{$repaired} event title(s) would be cleaned. Run with --execute to apply."
				: "Cleaned {$repaired} event title(s).",
		);
	}
}
