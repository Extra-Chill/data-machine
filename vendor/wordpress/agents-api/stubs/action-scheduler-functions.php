<?php
/**
 * PHPStan stubs for the optional Action Scheduler dependency.
 *
 * Action Scheduler is an optional runtime dependency (the substrate detects it
 * and no-ops when absent). Its functions are not part of WordPress core stubs,
 * so they are stubbed here for static analysis.
 *
 * @see https://actionscheduler.org/
 */

/**
 * @param string                  $hook  Hook name.
 * @param array<array-key, mixed> $args  Arguments to pass to the hook.
 * @param string                  $group Group to assign the action to.
 */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {}

/**
 * @param int                     $timestamp When the first instance should run.
 * @param string                  $schedule  Cron expression.
 * @param string                  $hook      Hook name.
 * @param array<array-key, mixed> $args      Arguments to pass to the hook.
 * @param string                  $group     Group to assign the action to.
 */
function as_schedule_cron_action( int $timestamp, string $schedule, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	unset( $timestamp, $schedule, $hook, $args, $group, $unique, $priority );
	return 0;
}

/**
 * @param int                     $timestamp        When the first instance should run.
 * @param int                     $interval_seconds How long to wait between runs.
 * @param string                  $hook             Hook name.
 * @param array<array-key, mixed> $args             Arguments to pass to the hook.
 * @param string                  $group            Group to assign the action to.
 */
function as_schedule_recurring_action( int $timestamp, int $interval_seconds, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	unset( $timestamp, $interval_seconds, $hook, $args, $group, $unique, $priority );
	return 0;
}

/**
 * @param int                     $timestamp When the action should run.
 * @param string                  $hook      Hook name.
 * @param array<array-key, mixed> $args      Arguments to pass to the hook.
 * @param string                  $group     Group to assign the action to.
 */
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	unset( $timestamp, $hook, $args, $group, $unique, $priority );
	return 0;
}

/**
 * @param string                  $hook  Hook name.
 * @param array<array-key, mixed> $args  Arguments to pass to the hook.
 * @param string                  $group Group to assign the action to.
 */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int {
	unset( $hook, $args, $group, $unique, $priority );
	return 0;
}

/**
 * @param string                  $hook  Hook name.
 * @param array<array-key, mixed> $args  Arguments that were passed when scheduling.
 * @param string                  $group Group the action belongs to.
 */
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int|bool {
	unset( $args, $group );
	return '' === $hook ? false : 0;
}

/** @param array<array-key,mixed>|null $args */
function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool {
	unset( $hook, $args, $group );
	return false;
}

/**
 * @param array<string,mixed> $args          Query args.
 * @param string              $return_format Return format (ids|objects|count).
 * @return array<int,mixed>|int
 */
function as_get_scheduled_actions( array $args = array(), string $return_format = 'OBJECT' ) {
	unset( $args, $return_format );
	return array();
}

/**
 * @param string|null $date_string Date string, or null for "now".
 * @param string      $timezone    Timezone.
 */
function as_get_datetime_object( ?string $date_string = null, string $timezone = 'UTC' ): DateTime {
	unset( $date_string, $timezone );
	return new DateTime();
}
