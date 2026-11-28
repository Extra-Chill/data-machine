<?php
/**
 * Pure-PHP smoke for extension health callback failures.
 *
 * Run with: php tests/system-health-extension-errors-smoke.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function apply_filters( string $hook, mixed $value ): mixed {
	if ( 'datamachine_system_health_checks' !== $hook ) {
		return $value;
	}

	return array_merge( $value, $GLOBALS['system_health_extension_checks'] ?? array() );
}

require_once __DIR__ . '/../inc/Abilities/SystemAbilities.php';

use DataMachine\Abilities\SystemAbilities;

$failed = 0;
$assert = static function ( string $label, bool $condition ) use ( &$failed ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
};

$ability = ( new ReflectionClass( SystemAbilities::class ) )->newInstanceWithoutConstructor();

$GLOBALS['system_health_extension_checks'] = array(
	'error-extension' => array(
		'label'    => 'Error Extension',
		'callback' => static fn(): WP_Error => new WP_Error(
			'extension_unavailable',
			'Extension service is unavailable.',
			array(
				'status'      => 503,
				'diagnostics' => array( 'provider' => 'smoke-provider' ),
			)
		),
		'default'  => false,
	),
);

$result = $ability->executeHealthCheck( array( 'types' => array( 'error-extension' ) ) );
$health = $result['results']['error-extension']['result'];
$assert( 'WP_Error callback remains scoped to extension result', false === $health['success'] && 'error-extension' === $health['check_type'] );
$assert( 'WP_Error callback preserves code and status', 'extension_unavailable' === $health['error_code'] && 503 === $health['status'] );
$assert( 'WP_Error callback preserves diagnostics', 'smoke-provider' === ( $health['diagnostics']['provider'] ?? null ) );

$GLOBALS['system_health_extension_checks'] = array(
	'throwing-extension' => array(
		'label'    => 'Throwing Extension',
		'callback' => static fn() => throw new RuntimeException( 'Extension callback exploded.' ),
		'default'  => false,
	),
);

$result = $ability->executeHealthCheck( array( 'types' => array( 'throwing-extension' ) ) );
$health = $result['results']['throwing-extension']['result'];
$assert( 'Throwable callback becomes failed extension result', false === $health['success'] && 'throwing-extension' === $health['check_type'] );
$assert( 'Throwable callback has stable code and status', 'health_check_callback_exception' === $health['error_code'] && 500 === $health['status'] );
$assert( 'Throwable callback preserves exception diagnostics', RuntimeException::class === ( $health['diagnostics']['exception'] ?? null ) );
$assert( 'Throwable callback contributes error summary', str_contains( $result['summary'], 'Throwing Extension: error' ) );

if ( $failed > 0 ) {
	exit( 1 );
}
