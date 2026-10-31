<?php
/**
 * Series End Repair Abilities
 *
 * Detects and repairs the issue #199 ingestion defect on already-published
 * events: a single occurrence whose Event Details block carries a
 * series-wide endDate (a recurring series' or tour's final date) with no
 * occurrenceDates. Repair strips the fabricated endDate/endTime attributes;
 * the save_post sync then rewrites the datamachine_event_dates row with a
 * NULL end, which the calendar already renders correctly.
 *
 * Repair policy — strip, never invent: no replacement end is synthesized
 * from the start (e.g. "start + N hours") because the source data does not
 * carry a real per-occurrence end; a missing end is strictly more honest
 * than a plausible-looking fabrication. Dry run by default; every change
 * requires an explicit execute flag.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.64.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSpanGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeriesEndRepairAbilities {

	private const DEFAULT_LIMIT          = -1;
	private const DEFAULT_MAX_SPAN_HOURS = 48;

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
				'data-machine-events/repair-series-ends',
				array(
					'label'               => __( 'Repair Series End Leaks', 'data-machine-events' ),
					'description'         => __( 'Strip fabricated series-range end dates from published event occurrences (dry run by default)', 'data-machine-events' ),
					'category'            => AbilityCategories::EVENTS,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'        => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
								'default'     => true,
							),
							'limit'          => array(
								'type'        => 'integer',
								'description' => 'Maximum events to process (default: -1 for all)',
								'default'     => -1,
							),
							'scope'          => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => 'Which published events to scan (default: upcoming)',
								'default'     => 'upcoming',
							),
							'max_span_hours' => array(
								'type'        => 'integer',
								'description' => 'Strip ends on occurrences spanning more than this many hours with no occurrenceDates (default: 48)',
								'default'     => self::DEFAULT_MAX_SPAN_HOURS,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'        => array( 'type' => 'boolean' ),
							'scanned'        => array( 'type' => 'integer' ),
							'repaired'       => array( 'type' => 'integer' ),
							'max_span_hours' => array( 'type' => 'integer' ),
							'changes'        => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'      => array( 'type' => 'integer' ),
										'title'        => array( 'type' => 'string' ),
										'stripped_end' => array( 'type' => 'string' ),
										'span_hours'   => array( 'type' => 'integer' ),
									),
								),
							),
							'message'        => array( 'type' => 'string' ),
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
	 * Execute the series-end leak repair.
	 *
	 * @param array $input Input parameters (`dry_run`, `limit`, `scope`, `max_span_hours`).
	 * @return array Scan summary with per-row changes.
	 */
	public function executeRepair( array $input ): array {
		$dry_run        = $input['dry_run'] ?? true;
		$limit          = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$scope          = $input['scope'] ?? 'upcoming';
		$max_span_hours = (int) ( $input['max_span_hours'] ?? self::DEFAULT_MAX_SPAN_HOURS );

		if ( $max_span_hours <= 0 ) {
			$max_span_hours = self::DEFAULT_MAX_SPAN_HOURS;
		}

		$event_query = new EventDateQueryAbilities();
		$result      = $event_query->executeQueryEvents(
			array(
				'scope'    => $scope,
				'per_page' => $limit,
				'status'   => 'publish',
			)
		);
		$events      = is_array( $result['posts'] ) ? $result['posts'] : array();

		$scanned = 0;
		$changes = array();

		foreach ( $events as $event ) {
			++$scanned;

			$post = get_post( $event->ID );
			if ( ! $post ) {
				continue;
			}

			$blocks      = parse_blocks( $post->post_content );
			$block_index = null;

			foreach ( $blocks as $index => $block ) {
				if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
					$block_index = $index;
					break;
				}
			}

			if ( null === $block_index || ! isset( $blocks[ $block_index ] ) ) {
				continue;
			}

			$attrs = (array) $blocks[ $block_index ]['attrs'];

			// occurrenceDates carries explicit per-occurrence dates; a long
			// envelope around them is the designed recurring representation,
			// not a leak. Only bare long spans are repaired.
			if ( ! empty( $attrs['occurrenceDates'] ) ) {
				continue;
			}

			$span_hours = EventSpanGuard::span_hours_from_block_attrs( $attrs );
			if ( null === $span_hours || $span_hours <= $max_span_hours ) {
				continue;
			}

			$stripped_end = $attrs['endDate'] . ( '' !== (string) ( $attrs['endTime'] ?? '' ) ? ' ' . $attrs['endTime'] : '' );

			if ( ! $dry_run ) {
				unset( $blocks[ $block_index ]['attrs']['endDate'], $blocks[ $block_index ]['attrs']['endTime'] );

				wp_update_post(
					array(
						'ID'           => $event->ID,
						'post_content' => serialize_blocks( $blocks ),
					)
				);
			}

			$changes[] = array(
				'post_id'      => $event->ID,
				'title'        => $event->post_title,
				'stripped_end' => $stripped_end,
				'span_hours'   => $span_hours,
			);
		}

		$repaired = count( $changes );

		return array(
			'dry_run'        => (bool) $dry_run,
			'scanned'        => $scanned,
			'repaired'       => $repaired,
			'max_span_hours' => $max_span_hours,
			'changes'        => $changes,
			'message'        => $dry_run
				? "{$repaired} occurrence(s) would have their fabricated series-range end stripped. Run with --execute to apply."
				: "Stripped fabricated series-range ends on {$repaired} occurrence(s).",
		);
	}
}
