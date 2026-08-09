<?php
/**
 * Regression smoke for idempotent ability provider bootstrap.
 *
 * Run with: php tests/ability-provider-bootstrap-idempotency-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( function_exists( 'wp_register_ability' ) ) {
		require_once dirname( __DIR__ ) . '/vendor/autoload.php';
		require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityCategories.php';
		\DataMachine\Abilities\AbilityCategories::ensure_registered();

		$notices = 0;
		$watch   = static function ( string $function_name, string $message ) use ( &$notices ): void {
			if ( 'WP_Abilities_Registry::register' === $function_name && str_contains( $message, 'datamachine/' ) ) {
				++$notices;
			}
		};
		add_action( 'doing_it_wrong_run', $watch, 10, 2 );

		$bootstrap = static function (): void {
			new \DataMachine\Abilities\Engine\ScheduleNextStepAbility();
			new \DataMachine\Abilities\Taxonomy\ResolveTermAbility();
		};

		$bootstrap();
		$bootstrap();
		$abilities = array(
			wp_get_ability( 'datamachine/resolve-term' ),
			wp_get_ability( 'datamachine/schedule-next-step' ),
		);
		$bootstrap();
		$bootstrap();
		remove_action( 'doing_it_wrong_run', $watch, 10 );

		if ( in_array( null, $abilities, true ) || 0 !== $notices ) {
			fwrite( STDERR, "FAIL: repeated WordPress bootstrap duplicated or missed affected abilities\n" );
			exit( 1 );
		}

		echo "PASS: WordPress ability provider bootstrap is idempotent\n";
		return;
	}

	define( 'ABSPATH', __DIR__ );

	$GLOBALS['datamachine_3066_state'] = (object) array(
		'actions'       => array(),
		'did'           => 0,
		'doing'         => false,
		'notices'       => 0,
		'registrations' => array(),
	);

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback ): void {
			$GLOBALS['datamachine_3066_state']->actions[ $hook ][] = $callback;
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook ): int {
			return 'wp_abilities_api_init' === $hook ? $GLOBALS['datamachine_3066_state']->did : 0;
		}
	}

	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( string $hook ): bool {
			return 'wp_abilities_api_init' === $hook && $GLOBALS['datamachine_3066_state']->doing;
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): object {
			unset( $args );
			$state = $GLOBALS['datamachine_3066_state'];
			if ( isset( $state->registrations[ $name ] ) ) {
				++$state->notices;
			}
			$state->registrations[ $name ] = ( $state->registrations[ $name ] ?? 0 ) + 1;

			return new \stdClass();
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	if ( ! class_exists( Flows::class ) ) {
		class Flows {}
	}
}

namespace DataMachine\Core\Database\Jobs {
	if ( ! class_exists( Jobs::class ) ) {
		class Jobs {}
	}
}

namespace DataMachine\Core\Database\Pipelines {
	if ( ! class_exists( Pipelines::class ) ) {
		class Pipelines {}
	}
}

namespace DataMachine\Core\Database\ProcessedItems {
	if ( ! class_exists( ProcessedItems::class ) ) {
		class ProcessedItems {}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Engine/EngineHelpers.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Engine/ScheduleNextStepAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Taxonomy/ResolveTermAbility.php';

	$bootstrap = static function (): void {
		new \DataMachine\Abilities\Engine\ScheduleNextStepAbility();
		new \DataMachine\Abilities\Taxonomy\ResolveTermAbility();
	};

	$bootstrap();
	$bootstrap();

	$state = $GLOBALS['datamachine_3066_state'];
	if ( 2 !== count( $state->actions['wp_abilities_api_init'] ?? array() ) ) {
		fwrite( STDERR, "FAIL: repeated bootstrap attached duplicate provider callbacks\n" );
		exit( 1 );
	}

	$state->doing = true;
	$state->did   = 1;
	foreach ( $state->actions['wp_abilities_api_init'] as $callback ) {
		$callback();
	}
	$state->doing = false;

	$bootstrap();
	$bootstrap();

	foreach ( array( 'datamachine/resolve-term', 'datamachine/schedule-next-step' ) as $name ) {
		if ( 1 !== ( $state->registrations[ $name ] ?? 0 ) ) {
			fwrite( STDERR, "FAIL: {$name} did not register exactly once\n" );
			exit( 1 );
		}
	}

	if ( 0 !== $state->notices ) {
		fwrite( STDERR, "FAIL: repeated bootstrap emitted duplicate-registration notices\n" );
		exit( 1 );
	}

	echo "PASS: ability provider bootstrap is idempotent\n";
}
