<?php
/**
 * Provider bootstrap isolation and discovery matrix.
 *
 * Run with: php tests/provider-bootstrap-smoke.php
 */

$root       = dirname( __DIR__ );
$failures   = array();
$passes     = 0;
$filters    = array();
$registered = array();

define( 'ABSPATH', $root . '/' );

function add_filter( string $hook, callable $callback ): void {
	global $filters;
	$filters[ $hook ][] = $callback;
}

function apply_filters( string $hook, $value ) {
	global $filters;
	foreach ( $filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

function assert_provider_bootstrap( bool $condition, string $name ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		return;
	}

	$failures[] = $name;
}

require_once $root . '/inc/Bootstrap/ProviderModule.php';
require_once $root . '/inc/Bootstrap/ProviderBootstrap.php';
require_once $root . '/inc/Bootstrap/ProviderModules.php';

use DataMachineBusiness\Bootstrap\ProviderBootstrap;
use DataMachineBusiness\Bootstrap\ProviderModule;
use DataMachineBusiness\Bootstrap\ProviderModules;

$module = static function ( string $id, callable $prerequisite, callable $initializer ) use ( &$registered ): ProviderModule {
	return new ProviderModule(
		$id,
		array( 'optional/dependency' => $prerequisite ),
		array( 'ability:' . $id ),
		static function () use ( $id, $initializer, &$registered ): void {
			$initializer();
			$registered[] = $id;
		}
	);
};

$bootstrap = new ProviderBootstrap(
	array(
		$module( 'missing', static fn(): bool => false, static function (): void {} ),
		$module(
			'prerequisite-throws',
			static function (): bool {
				throw new RuntimeException( 'Broken prerequisite check.' );
			},
			static function (): void {}
		),
		$module(
			'initializer-throws',
			static fn(): bool => true,
			static function (): void {
				throw new RuntimeException( 'Broken provider.' );
			}
		),
		new stdClass(),
		$module( 'healthy-first', static fn(): bool => true, static function (): void {} ),
		$module( 'healthy-second', static fn(): bool => true, static function (): void {} ),
		$module( 'healthy-first', static fn(): bool => true, static function (): void {} ),
	)
);
$bootstrap->register();
$bootstrap->register();
$availability = $bootstrap->availability();

assert_provider_bootstrap( 'missing_prerequisites' === $availability['missing']['reason'], 'absent optional dependency is reported locally' );
assert_provider_bootstrap( array( 'optional/dependency' ) === $availability['missing']['missing_prerequisites'], 'missing prerequisite is discoverable by name' );
assert_provider_bootstrap( 'initialization_failed' === $availability['prerequisite-throws']['reason'], 'throwing prerequisite is isolated' );
assert_provider_bootstrap( 'initialization_failed' === $availability['initializer-throws']['reason'], 'throwing initializer is isolated' );
assert_provider_bootstrap( 'invalid_module' === $availability['invalid-module-3']['reason'], 'misconfigured module is isolated' );
assert_provider_bootstrap( true === $availability['healthy-first']['available'] && true === $availability['healthy-second']['available'], 'unrelated providers remain available' );
assert_provider_bootstrap( array( 'healthy-first', 'healthy-second' ) === $registered, 'registration is deterministic and duplicate-safe' );
assert_provider_bootstrap( 6 === count( $availability ), 'duplicate provider declaration does not replace its first result' );

$actual_modules = ProviderModules::all();
$actual_ids     = array_map( static fn( ProviderModule $provider ): string => $provider->id(), $actual_modules );
$expected_ids   = array(
	'indexnow',
	'google-analytics',
	'mediavine',
	'content-insights',
	'google-sheets',
	'google-drive',
	'pagespeed',
	'google-search',
	'google-search-console',
	'slack',
	'discord',
	'bing-webmaster',
	'amazon-affiliate',
	'media-hygiene',
	'sendy',
);
assert_provider_bootstrap( $expected_ids === $actual_ids, 'production provider order is explicit and deterministic' );

$absent_dependency_bootstrap = new ProviderBootstrap( $actual_modules );
$absent_dependency_bootstrap->register();
$absent_dependency_matrix = $absent_dependency_bootstrap->availability();
foreach ( $expected_ids as $provider_id ) {
	assert_provider_bootstrap(
		'missing_prerequisites' === $absent_dependency_matrix[ $provider_id ]['reason'],
		"{$provider_id} declares its optional dependencies locally"
	);
}

$sendy = $actual_modules[14]->capabilities();
assert_provider_bootstrap(
	array(
		'datamachine/sendy-subscribe',
		'datamachine/sendy-push-campaign',
		'datamachine/sendy-list-campaigns',
		'datamachine/sendy-get-campaign',
		'datamachine/sendy-delete-campaign',
		'datamachine/sendy-metrics',
	) === $sendy,
	'Sendy public ability contract is unchanged'
);

$discovery_log = array();
$discovery     = array(
	new ProviderModule(
		'discovery-ready',
		array( 'ready' => static fn(): bool => true ),
		array( 'datamachine/discovery-ready' ),
		static function () use ( &$discovery_log ): void {
			$discovery_log[] = 'ready';
		}
	),
	new ProviderModule(
		'discovery-missing',
		array( 'missing-package' => static fn(): bool => false ),
		array( 'datamachine/discovery-missing' ),
		static function () use ( &$discovery_log ): void {
			$discovery_log[] = 'missing';
		}
	),
);
ProviderBootstrap::register_discovery();
ProviderBootstrap::register_discovery();
ProviderBootstrap::boot( $discovery );
ProviderBootstrap::boot( $discovery );

$provider_discovery   = apply_filters( 'datamachine_business_provider_availability', array() );
$capability_discovery = apply_filters( 'datamachine_business_capability_availability', array() );
assert_provider_bootstrap( array( 'ready' ) === $discovery_log, 'static bootstrap is idempotent' );
assert_provider_bootstrap( 2 === count( $provider_discovery ), 'provider availability is discoverable' );
assert_provider_bootstrap( true === $capability_discovery['datamachine/discovery-ready']['available'], 'available capability is discoverable' );
assert_provider_bootstrap( false === $capability_discovery['datamachine/discovery-missing']['available'], 'unavailable capability is discoverable' );
assert_provider_bootstrap( 1 === count( $filters['datamachine_business_provider_availability'] ), 'provider discovery registration is duplicate-safe' );
assert_provider_bootstrap( 1 === count( $filters['datamachine_business_capability_availability'] ), 'capability discovery registration is duplicate-safe' );

if ( ! empty( $failures ) ) {
	echo "FAILURES:\n" . implode( "\n", $failures ) . "\n";
	exit( 1 );
}

echo "{$passes} assertions passed.\n";
