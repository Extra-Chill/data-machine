<?php
/**
 * Smoke test for generic handler config validation lifecycle.
 *
 * Run with: php tests/handler-config-validation-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/smoke-wp-stubs.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

$GLOBALS['handler_config_validation_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['handler_config_validation_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['handler_config_validation_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['handler_config_validation_filters'][ $hook ], SORT_NUMERIC );
		foreach ( $GLOBALS['handler_config_validation_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted_args ] ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

require_once __DIR__ . '/../inc/Core/Steps/Handlers/HandlerConfigValidator.php';
require_once __DIR__ . '/../inc/Core/Steps/HandlerRegistrationTrait.php';

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Handlers\HandlerConfigValidator;

class HandlerConfigValidationSmokeRegistration {
	use HandlerRegistrationTrait;

	public static function register(): void {
		self::registerHandler(
			'smoke_source',
			'fetch',
			self::class,
			'Smoke Source',
			'Smoke source handler',
			false,
			null,
			null,
			null,
			null,
			array(),
			static function ( array $handler_config, array $context ): array {
				if ( (int) ( $handler_config['days'] ?? 0 ) > 365 ) {
					return array(
						'valid'   => false,
						'code'    => 'source_param_out_of_range',
						'message' => 'days must be <= 365',
						'data'    => array(
							'param'        => 'days',
							'context_seen' => $context['flow_step_id'] ?? null,
						),
					);
				}

				return array( 'valid' => true );
			}
		);
	}
}

$passes = 0;
$fails  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		++$passes;
		return;
	}

	echo "FAIL: {$label}\n";
	++$fails;
};

echo "\n[1] Handler registration installs config validator callback\n";
HandlerConfigValidationSmokeRegistration::register();

$valid = HandlerConfigValidator::validate(
	'smoke_source',
	'fetch',
	array( 'days' => 30 ),
	array( 'flow_step_id' => 'fetch-1' )
);

$assert( 'valid config returns true', true === $valid );

$invalid = HandlerConfigValidator::validate(
	'smoke_source',
	'fetch',
	array( 'days' => 366 ),
	array( 'flow_step_id' => 'fetch-1' )
);

$assert( 'invalid config returns WP_Error', is_wp_error( $invalid ) );
$assert( 'invalid config uses stable Data Machine error code', HandlerConfigValidator::ERROR_CODE === $invalid->get_error_code() );

$diagnostics = HandlerConfigValidator::diagnostics( $invalid );
$assert( 'diagnostics carry stable reason', HandlerConfigValidator::ERROR_CODE === ( $diagnostics['reason'] ?? null ) );
$assert( 'diagnostics preserve validator-specific code', 'source_param_out_of_range' === ( $diagnostics['validation_code'] ?? null ) );
$assert( 'diagnostics preserve actionable field metadata', 'days' === ( $diagnostics['param'] ?? null ) );
$assert( 'validator receives execution context', 'fetch-1' === ( $diagnostics['context_seen'] ?? null ) );

echo "\n[2] Non-matching handler is ignored\n";
$other = HandlerConfigValidator::validate( 'other_source', 'fetch', array( 'days' => 999 ), array() );
$assert( 'non-matching validator returns true', true === $other );

echo "\n[3] Runtime execution paths invoke validation before handlers\n";
$root            = dirname( __DIR__ );
$fetch_step      = (string) file_get_contents( $root . '/inc/Core/Steps/Fetch/FetchStep.php' );
$publish_handler = (string) file_get_contents( $root . '/inc/Core/Steps/Publish/Handlers/PublishHandler.php' );
$upsert_handler  = (string) file_get_contents( $root . '/inc/Core/Steps/Upsert/Handlers/UpsertHandler.php' );

$assert( 'fetch step validates handler config before execution', str_contains( $fetch_step, 'HandlerConfigValidator::validate' ) && str_contains( $fetch_step, 'HandlerConfigValidator::ERROR_CODE' ) );
$assert( 'publish handler validates handler config before execution', str_contains( $publish_handler, 'HandlerConfigValidator::validate' ) );
$assert( 'upsert handler validates handler config before execution', str_contains( $upsert_handler, 'HandlerConfigValidator::validate' ) );

echo "\n=== Results ===\n";
echo "Passed: {$passes}\n";
echo "Failed: {$fails}\n";

if ( $fails > 0 ) {
	exit( 1 );
}
