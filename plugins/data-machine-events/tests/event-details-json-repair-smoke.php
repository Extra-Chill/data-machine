<?php
/**
 * Smoke test: recovering Event Details attributes from unescaped comment JSON.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function add_action() {}
	function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}

namespace DataMachineEvents\Core {
	class Event_Post_Type { const EVENT_DETAILS_BLOCK_NAME = 'data-machine-events/event-details'; }
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/BlockAttributeJsonRepairAbilities.php';
	use DataMachineEvents\Abilities\BlockAttributeJsonRepairAbilities as R;

	$assert = static function ( $ok, $msg ) { if ( ! $ok ) { fwrite( STDERR, $msg . PHP_EOL ); exit( 1 ); } };

	$broken = '{"startDate":"2026-09-05","performer":"Christone "Kingfish" Ingram","showPrice":true,"lat":35.2}';
	$attrs  = R::recoverAttributes( $broken );
	$assert( is_array( $attrs ), 'recovers attrs' );
	$assert( 'Christone "Kingfish" Ingram' === $attrs['performer'], 'restores quoted value' );
	$assert( true === $attrs['showPrice'] && 35.2 === $attrs['lat'], 'keeps non-string values' );
	$assert( is_array( json_decode( json_encode( $attrs ), true ) ), 're-encodes to valid JSON' );
	$assert( null === R::recoverAttributes( 'not json' ), 'rejects non-object' );
	echo "event-details-json-repair-smoke: ok\n";
}
