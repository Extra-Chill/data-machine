<?php
/** Canonical setup/schema runtime smoke. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DATAMACHINE_VERSION', '1.0-test' );

$GLOBALS['setup_options']          = array();
$GLOBALS['setup_actions']          = array();
$GLOBALS['setup_calls']            = array();
$GLOBALS['setup_schema_succeeds']  = true;
$GLOBALS['identity_schema_valid']  = false;
$GLOBALS['identity_create_calls']  = 0;
$GLOBALS['identity_create_works']  = true;

function get_option( $key, $default = false ) { return $GLOBALS['setup_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['setup_options'][ $key ] = $value; return true; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['setup_actions'][] = compact( 'hook', 'callback', 'priority' ); }
function wp_installing() { return false; }
function datamachine_ensure_all_tables() { $GLOBALS['setup_calls'][] = 'schema'; return $GLOBALS['setup_schema_succeeds']; }
function datamachine_register_capabilities() { $GLOBALS['setup_calls'][] = 'capabilities'; }
function datamachine_activate_defaults_for_site() {
	$GLOBALS['setup_calls'][] = 'defaults';
	if ( ! isset( $GLOBALS['setup_options']['datamachine_settings'] ) ) {
		$GLOBALS['setup_options']['datamachine_settings'] = array( 'seeded' => true );
	}
}
function datamachine_mark_flow_schedule_reconciliation() { $GLOBALS['setup_calls'][] = 'flow-schedules'; }

eval(
	'namespace DataMachine\Core\Database\PostIdentityReservations;
	class PostIdentityReservations {
		public const SCHEMA_VERSION = 1;
		public function validate_schema() { return $GLOBALS["identity_schema_valid"]; }
		public static function create_table(): void {
			++$GLOBALS["identity_create_calls"];
			if ($GLOBALS["identity_create_works"]) { $GLOBALS["identity_schema_valid"] = true; }
		}
	}'
);

require_once dirname( __DIR__ ) . '/inc/setup/schema.php';

$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
};

$GLOBALS['setup_options']['datamachine_db_version'] = DATAMACHINE_VERSION;
datamachine_maybe_ensure_current_schema();
$assert( array() === $GLOBALS['setup_calls'], 'current version gate is a cheap no-op' );

$GLOBALS['setup_options']['datamachine_db_version'] = 'stale';
datamachine_maybe_ensure_current_schema();
$assert( DATAMACHINE_VERSION === $GLOBALS['setup_options']['datamachine_db_version'], 'successful setup advances version gate' );
$assert( array( 'schema', 'capabilities', 'defaults', 'flow-schedules' ) === $GLOBALS['setup_calls'], 'deferred setup converges schema, capabilities, defaults, and schedules' );

$GLOBALS['setup_calls']                           = array();
$GLOBALS['setup_schema_succeeds']                 = false;
$GLOBALS['setup_options']['datamachine_db_version'] = 'retry-me';
datamachine_maybe_ensure_current_schema();
$assert( 'retry-me' === $GLOBALS['setup_options']['datamachine_db_version'], 'failed data-preservation setup does not advance version gate' );
$assert( array( 'schema' ) === $GLOBALS['setup_calls'], 'failed schema setup does not run dependent defaults' );

$GLOBALS['setup_schema_succeeds']                    = true;
$GLOBALS['setup_options']['datamachine_settings']    = array( 'operator' => true );
$GLOBALS['setup_options']['datamachine_db_version']  = 'next-deploy';
datamachine_maybe_ensure_current_schema();
$assert( array( 'operator' => true ) === $GLOBALS['setup_options']['datamachine_settings'], 'rerun preserves operator defaults' );

$GLOBALS['identity_schema_valid'] = false;
$GLOBALS['identity_create_works'] = false;
$GLOBALS['setup_options']['datamachine_post_identity_reservations_schema'] = 1;
datamachine_maybe_install_post_identity_reservations();
$assert( 0 === $GLOBALS['setup_options']['datamachine_post_identity_reservations_schema'], 'failed identity reservation repair resets marker' );

$GLOBALS['identity_create_works'] = true;
datamachine_maybe_install_post_identity_reservations();
$assert( 1 === $GLOBALS['setup_options']['datamachine_post_identity_reservations_schema'], 'verified identity reservation repair advances marker' );
$assert( 2 === $GLOBALS['identity_create_calls'], 'identity reservation repair retries after failure' );

$schema_hook = array_values( array_filter( $GLOBALS['setup_actions'], static fn( array $hook ): bool => 'datamachine_maybe_ensure_current_schema' === $hook['callback'] ) );
$assert( 5 === ( $schema_hook[0]['priority'] ?? null ), 'canonical setup runs before runtime bootstrap' );

if ( $failures ) {
	exit( 1 );
}
echo "Setup schema runtime smoke passed.\n";
