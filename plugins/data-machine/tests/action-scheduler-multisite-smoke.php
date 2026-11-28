<?php
/**
 * Behavioral multisite smoke for Action Scheduler lifecycle setup.
 *
 * Run with: php tests/action-scheduler-multisite-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( defined( 'WPINC' ) ) {
	fwrite( STDOUT, "action-scheduler-multisite-smoke: standalone harness skipped under real WordPress.\n" );
	return;
}

require __DIR__ . '/fixtures/action-scheduler-multisite-standalone.php';
