<?php
/**
 * Scheduler Intervals Filter
 *
 * Defines available scheduling intervals for flow execution.
 * Single source of truth for all recurring interval options.
 *
 * @package DataMachine\Engine\Filters
 * @since 0.8.9
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get default scheduler intervals.
 *
 * @return array Interval definitions with label and seconds.
 */
function datamachine_get_default_scheduler_intervals(): array {
	return array(
		'every_5_minutes' => array(
			'label'   => 'Every 5 Minutes',
			'seconds' => 300,
		),
		'hourly'          => array(
			'label'   => 'Hourly',
			'seconds' => HOUR_IN_SECONDS,
		),
		'every_2_hours'   => array(
			'label'   => 'Every 2 Hours',
			'seconds' => HOUR_IN_SECONDS * 2,
		),
		'every_4_hours'   => array(
			'label'   => 'Every 4 Hours',
			'seconds' => HOUR_IN_SECONDS * 4,
		),
		'qtrdaily'        => array(
			'label'   => 'Every 6 Hours',
			'seconds' => HOUR_IN_SECONDS * 6,
		),
		'twicedaily'      => array(
			'label'   => 'Twice Daily',
			'seconds' => HOUR_IN_SECONDS * 12,
		),
		'daily'           => array(
			'label'   => 'Daily',
			'seconds' => DAY_IN_SECONDS,
		),
		'every_3_days'    => array(
			'label'   => 'Every 3 Days',
			'seconds' => DAY_IN_SECONDS * 3,
		),
		'weekly'          => array(
			'label'   => 'Weekly',
			'seconds' => WEEK_IN_SECONDS,
		),
		'monthly'         => array(
			'label'   => 'Monthly',
			'seconds' => DAY_IN_SECONDS * 30,
		),
	);
}

/**
 * Aliases for interval keys that use non-obvious WordPress-era names.
 *
 * Callers can use either the canonical key or the alias — resolve_interval_alias()
 * normalizes before lookup.
 *
 * @return array<string, string> Alias → canonical key.
 */
function datamachine_get_interval_aliases(): array {
	return array(
		'every_6_hours'  => 'qtrdaily',
		'every_12_hours' => 'twicedaily',
	);
}

/**
 * Resolve an interval alias to its canonical key.
 *
 * Returns the input unchanged if it's already a canonical key or not a known alias.
 *
 * @param string $interval Interval key or alias.
 * @return string Canonical interval key.
 */
function datamachine_resolve_interval_alias( string $interval ): string {
	$aliases = datamachine_get_interval_aliases();
	return $aliases[ $interval ] ?? $interval;
}

/**
 * Validate that an interval key is known to the registry.
 *
 * Resolves aliases first, then checks against the live datamachine_scheduler_intervals filter.
 * Special keys (manual, one_time, cron) and cron expressions are always valid.
 *
 * @param string $interval Interval key, alias, or cron expression.
 * @return array{valid: bool, resolved: string, error?: string, available?: string[]}
 */
function datamachine_validate_interval( string $interval ): array {
	// Special scheduling types are always valid.
	if ( in_array( $interval, array( 'manual', 'one_time', 'cron' ), true ) ) {
		return array(
			'valid'    => true,
			'resolved' => $interval,
		);
	}

	// Cron expressions are valid (further validation happens in RecurringScheduler).
	if ( \DataMachine\Engine\Tasks\RecurringScheduler::looksLikeCronExpression( $interval ) ) {
		return array(
			'valid'    => true,
			'resolved' => $interval,
		);
	}

	$resolved  = datamachine_resolve_interval_alias( $interval );
	$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );

	if ( isset( $intervals[ $resolved ] ) ) {
		return array(
			'valid'    => true,
			'resolved' => $resolved,
		);
	}

	$aliases   = datamachine_get_interval_aliases();
	$available = array_unique( array_merge( array_keys( $intervals ), array_keys( $aliases ) ) );
	sort( $available );

	return array(
		'valid'     => false,
		'resolved'  => $resolved,
		'error'     => sprintf(
			"Invalid interval '%s'. Valid options: %s",
			$interval,
			implode( ', ', $available )
		),
		'available' => $available,
	);
}

/**
 * Register scheduler intervals filter.
 */
function datamachine_register_scheduler_intervals_filter(): void {
	add_filter(
		'datamachine_scheduler_intervals',
		function ( $intervals ) {
			return array_merge( datamachine_get_default_scheduler_intervals(), $intervals );
		},
		10
	);
}

datamachine_register_scheduler_intervals_filter();
