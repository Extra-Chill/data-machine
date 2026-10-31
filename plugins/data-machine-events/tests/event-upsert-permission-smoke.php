<?php
/**
 * Standalone smoke test for event-upsert permission isolation.
 *
 * @package DataMachineEvents\Tests
 */

// phpcs:disable -- Standalone smoke doubles intentionally use global WordPress function names and state.

define( 'ABSPATH', __DIR__ );

$dme_test_capabilities = array();
$dme_test_filters      = array();

function add_action(): void {}

function current_user_can( string $capability ): bool {
	global $dme_test_capabilities;

	return ! empty( $dme_test_capabilities[ $capability ] );
}

function add_filter( string $hook, callable $callback ): void {
	global $dme_test_filters;

	$dme_test_filters[ $hook ][] = $callback;
}

function remove_all_filters( string $hook ): void {
	global $dme_test_filters;

	unset( $dme_test_filters[ $hook ] );
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	global $dme_test_filters;

	foreach ( $dme_test_filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function dme_assert_permission( bool $expected, bool $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityPermissions.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/EventUpsertAbilities.php';

use DataMachineEvents\Abilities\AbilityPermissions;
use DataMachineEvents\Abilities\EventUpsertAbilities;

$ability = new EventUpsertAbilities();
$input   = array(
	'source'    => 'booking',
	'source_id' => '42',
	'event'     => array( 'venue' => 'Scoped Hall' ),
);

dme_assert_permission( false, $ability->canUpsertEvent( $input ), 'default gate denies an unprivileged caller' );

$dme_test_capabilities['edit_others_posts'] = true;
dme_assert_permission( true, $ability->canUpsertEvent( $input ), 'default gate allows an editor' );

$dme_test_capabilities = array();
add_filter(
	'datamachine_events_upsert_event_permission',
	static fn( bool $allowed, array $candidate ): bool => $allowed || 'Scoped Hall' === ( $candidate['event']['venue'] ?? '' )
);
dme_assert_permission( true, $ability->canUpsertEvent( $input ), 'narrow filter grants the matching upsert' );

$other_write_gate = AbilityPermissions::canWrite();
dme_assert_permission( false, $other_write_gate(), 'narrow filter does not widen another write ability' );

remove_all_filters( 'datamachine_events_upsert_event_permission' );
$dme_test_capabilities['edit_others_posts'] = true;
add_filter( 'datamachine_events_upsert_event_permission', static fn(): bool => false );
dme_assert_permission( false, $ability->canUpsertEvent( $input ), 'narrow filter may explicitly deny an editor' );

fwrite( STDOUT, "Event upsert permission smoke passed (5 assertions).\n" );
