<?php
/**
 * Recurring Schedule Registry — the schedule-to-task binding.
 *
 * A "schedule" binds a recurring cadence to a task type (handler). Schedules
 * are a first-class concept, registered via the `datamachine_recurring_schedules`
 * filter independently of task handlers. A task is a handler (what runs); a
 * schedule is a binding (when it runs). One task can have zero or many
 * schedules.
 *
 * Registration shape:
 *
 *     add_filter( 'datamachine_recurring_schedules', function ( $schedules ) {
 *         $schedules['daily_memory_generation'] = array(
 *             'task_type'          => 'daily_memory_generation',
 *             'interval'           => 'daily',
 *             'enabled_setting'    => 'daily_memory_enabled',
 *             'default_enabled'    => false,
 *             'task_params'        => array( 'date' => gmdate( 'Y-m-d' ) ),
 *             'first_run_callback' => 'strtotime',
 *             'first_run_arg'      => 'tomorrow midnight',
 *             'label'              => 'Daily at midnight UTC',
 *         );
 *         return $schedules;
 *     } );
 *
 * @package DataMachine\Engine\Tasks
 * @since   0.71.0
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;

class RecurringScheduleRegistry {

	/**
	 * Cached schedule definitions.
	 *
	 * @var array<string, array>|null
	 */
	private static ?array $schedules = null;

	/**
	 * Load and cache schedules from the filter.
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( null !== self::$schedules ) {
			return;
		}

		$registered = apply_filters( 'datamachine_recurring_schedules', array() );

		// Normalize each entry so downstream code can rely on the shape.
		self::$schedules = array();
		foreach ( $registered as $schedule_id => $def ) {
			if ( empty( $def['task_type'] ) ) {
				continue;
			}
			self::$schedules[ $schedule_id ] = array_merge(
				array(
					'schedule_id'        => $schedule_id,
					'task_type'          => $def['task_type'],
					'interval'           => 'daily',
					'cron_expression'    => null,
					'enabled_setting'    => null,
					'default_enabled'    => true,
					'task_params'        => array(),
					'first_run_callback' => null,
					'first_run_arg'      => null,
					'label'              => null,
				),
				$def
			);
		}
	}

	/**
	 * Get all registered schedules.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		self::load();
		return self::$schedules;
	}

	/**
	 * Get a schedule by id.
	 *
	 * @param string $schedule_id Schedule id.
	 * @return array|null
	 */
	public static function get( string $schedule_id ): ?array {
		self::load();
		return self::$schedules[ $schedule_id ] ?? null;
	}

	/**
	 * Resolve the current enabled state for a schedule.
	 *
	 * @param array $schedule Normalized schedule definition.
	 * @return bool
	 */
	public static function isEnabled( array $schedule ): bool {
		if ( empty( $schedule['enabled_setting'] ) ) {
			return (bool) $schedule['default_enabled'];
		}
		return (bool) PluginSettings::get(
			$schedule['enabled_setting'],
			$schedule['default_enabled']
		);
	}

	/**
	 * The Action Scheduler hook that fires for this schedule.
	 *
	 * All scheduled hooks share a single naming convention so external
	 * code can reliably target them.
	 *
	 * @param array $schedule Normalized schedule definition.
	 * @return string
	 */
	public static function hookFor( array $schedule ): string {
		return 'datamachine_recurring_' . $schedule['task_type'];
	}

	/**
	 * Reset the cached registry (for testing).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$schedules = null;
	}
}
