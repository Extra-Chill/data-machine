<?php
/**
 * Regression smoke for runtime execution after ability bootstrap.
 *
 * Run with: php tests/runtime-ability-execution-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['datamachine_3054_state'] = (object) array(
		'actions'       => array(),
		'did'           => 0,
		'doing'         => false,
		'notices'       => 0,
		'registrations' => array(),
	);

	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['datamachine_3054_state']->actions[ $hook ][] = $callback;
	}

	function did_action( string $hook ): int {
		return 'wp_abilities_api_init' === $hook ? $GLOBALS['datamachine_3054_state']->did : 0;
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook && $GLOBALS['datamachine_3054_state']->doing;
	}

	function wp_register_ability( string $name, array $args ): object {
		unset( $args );
		$state = $GLOBALS['datamachine_3054_state'];
		if ( isset( $state->registrations[ $name ] ) ) {
			++$state->notices;
		}
		$state->registrations[ $name ] = ( $state->registrations[ $name ] ?? 0 ) + 1;

		return new \stdClass();
	}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function taxonomy_exists( string $taxonomy ): bool {
		unset( $taxonomy );
		return false;
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}

	function datamachine_get_engine_data( int $job_id ): array {
		unset( $job_id );
		return array();
	}

	function as_has_scheduled_action( string $hook, array $args, string $group ): int {
		unset( $hook, $args, $group );
		return 0;
	}

	function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
		static $action_id = 100;
		unset( $timestamp, $hook, $args, $group );
		return ++$action_id;
	}

	function do_action( string $hook, mixed ...$args ): void {
		unset( $hook, $args );
	}

	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public function get_job( int $job_id ): array {
			return array(
				'job_id'          => $job_id,
				'status'          => 'pending',
				'operation_state' => 'preparing',
			);
		}

		public function claim_operation_enqueue( int $job_id ): array {
			unset( $job_id );
			return array(
				'token'      => 'claim',
				'generation' => 1,
			);
		}

		public function finish_operation_enqueue( int $job_id, string $state, int $action_id, string $token, int $generation ): bool {
			unset( $job_id, $state, $action_id, $token, $generation );
			return true;
		}
	}
}

namespace DataMachine\Core\Database\Pipelines {
	class Pipelines {}
}

namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems {}
}

namespace DataMachine\Core {
	class EngineData {
		public function __construct( array $snapshot, int $job_id ) {
			unset( $snapshot, $job_id );
		}

		public function getFlowStepConfig( string $flow_step_id ): array {
			unset( $flow_step_id );
			return array();
		}

		public function getJobContext(): array {
			return array();
		}
	}
}

namespace DataMachine\Core\ActionScheduler {
	class GroupRegistrar {
		public const GROUP = 'data-machine';
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Engine/EngineHelpers.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Engine/ScheduleNextStepAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Engine/PipelineBatchScheduler.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Taxonomy/ResolveTermAbility.php';

	new \DataMachine\Abilities\Engine\ScheduleNextStepAbility();
	new \DataMachine\Abilities\Taxonomy\ResolveTermAbility();

	$state        = $GLOBALS['datamachine_3054_state'];
	$state->doing = true;
	$state->did   = 1;
	foreach ( $state->actions['wp_abilities_api_init'] as $callback ) {
		$callback();
	}
	$state->doing = false;

	$scheduler      = new \DataMachine\Abilities\Engine\PipelineBatchScheduler();
	$schedule_child = new \ReflectionMethod( $scheduler, 'ensureChildScheduled' );
	for ( $run = 0; $run < 2; ++$run ) {
		$schedule_child->invoke( $scheduler, 42, 'next-step', array() );
		\DataMachine\Abilities\Taxonomy\ResolveTermAbility::resolve( 'missing', 'missing' );
	}

	foreach ( array( 'datamachine/resolve-term', 'datamachine/schedule-next-step' ) as $name ) {
		if ( 1 !== ( $state->registrations[ $name ] ?? 0 ) ) {
			fwrite( STDERR, "FAIL: runtime execution re-registered {$name}\n" );
			exit( 1 );
		}
	}

	if ( 0 !== $state->notices ) {
		fwrite( STDERR, "FAIL: runtime execution emitted duplicate-registration notices\n" );
		exit( 1 );
	}

	$sync_source = file_get_contents( dirname( __DIR__ ) . '/inc/Engine/Debug/SyncRunner.php' ) ?: '';
	if ( ! str_contains( $sync_source, 'new ScheduleNextStepAbility( false )' ) ) {
		fwrite( STDERR, "FAIL: sync runtime path must construct schedule-next-step without registration\n" );
		exit( 1 );
	}

	echo "PASS: runtime ability execution does not register abilities\n";
}
