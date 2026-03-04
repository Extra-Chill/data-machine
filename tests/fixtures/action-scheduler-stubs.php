<?php
/**
 * Action Scheduler function stubs for PHPUnit test environment.
 *
 * Action Scheduler is a WordPress plugin dependency that provides
 * background job scheduling. It's not available in the PHPUnit test
 * environment, so we stub its functions to allow testing code paths
 * that use scheduling without requiring the full AS infrastructure.
 *
 * @package DataMachine\Tests\Fixtures
 */

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Stub for as_schedule_single_action().
	 *
	 * @param int    $timestamp Unix timestamp for when to run the action.
	 * @param string $hook      Action hook name.
	 * @param array  $args      Arguments to pass to the hook callback.
	 * @param string $group     Action group name.
	 * @return int Fake action ID.
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		return 1;
	}
}

if ( ! function_exists( 'as_unschedule_action' ) ) {
	/**
	 * Stub for as_unschedule_action().
	 *
	 * @param string $hook  Action hook name.
	 * @param array  $args  Arguments to match.
	 * @param string $group Action group name.
	 * @return int|null Fake action ID or null.
	 */
	function as_unschedule_action( $hook, $args = array(), $group = '' ) {
		return 1;
	}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	/**
	 * Stub for as_next_scheduled_action().
	 *
	 * @param string $hook  Action hook name.
	 * @param array  $args  Arguments to match.
	 * @param string $group Action group name.
	 * @return int|false False (no action scheduled).
	 */
	function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
		return false;
	}
}
