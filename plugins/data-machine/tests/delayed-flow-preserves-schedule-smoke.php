<?php
/**
 * Regression #3884: delayed runs preserve recurring schedules.
 * Run: php tests/delayed-flow-preserves-schedule-smoke.php
 * Real ability, scheduling adapter and completion handler; in-memory storage/AS.
 */
namespace DataMachine\Core\Database\Flows {
	class Flows {
		public static array $configs = array();
		public function get_flow( $id ) { return isset( self::$configs[$id] ) ? array( 'scheduling_config' => self::$configs[$id] ) : null; }
		public function get_flow_scheduling( $id ) { return self::$configs[$id] ?? null; }
		public function update_flow_scheduling( $id, $config ) { self::$configs[$id] = $config; return true; }
	}
}
namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public function get_job( $id ) { return array( 'flow_id' => $id ); }
		public function update_flow_health_cache( $id, $status ) {}
	}
}
namespace DataMachine\Core\Database\Pipelines { class Pipelines {} }
namespace DataMachine\Core\Database\ProcessedItems { class ProcessedItems {} }
namespace DataMachine\Core\ActionScheduler { class GroupRegistrar { const GROUP = 'data-machine'; } }
namespace AgentsAPI\AI\Routines {
	interface WP_Agent_Routine_Backend {}
	class WP_Agent_Routine_Registry {
		public static array $routines = array();
		public static function unregister( $id ) { unset( self::$routines[$id] ); return true; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error {
		public function __construct( private $code, private $message, private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
	function do_action( ...$args ) { $GLOBALS['logs'][] = $args; }
	function as_schedule_single_action( $timestamp, $hook, $args, $group ) {
		if ( ! empty( $GLOBALS['fail_enqueue'] ) ) { return 0; }
		$id = ++$GLOBALS['next_id'];
		$GLOBALS['actions'][$id] = compact( 'timestamp', 'hook', 'args', 'group' );
		return $id;
	}
	function as_unschedule_all_actions( $hook, $args, $group ) {
		foreach ( $GLOBALS['actions'] as $id => $action ) {
			if ( $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group ) { unset( $GLOBALS['actions'][$id] ); }
		}
	}
	function as_get_scheduled_actions( $query, $format ) {
		return array_keys( array_filter( $GLOBALS['actions'], static fn( $action ) => $action['hook'] === $query['hook'] && $action['args'] === $query['args'] && $action['group'] === $query['group'] ) );
	}
	function check( $condition, $message ) {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}
	require_once __DIR__ . '/../inc/Abilities/Engine/EngineHelpers.php';
	require_once __DIR__ . '/../inc/Abilities/Engine/ScheduleFlowAbility.php';
	require_once __DIR__ . '/../inc/Engine/Scheduling/FlowRoutines.php';
	require_once __DIR__ . '/../inc/Engine/Actions/Handlers/JobCompleteHandler.php';

	use DataMachine\Core\Database\Flows\Flows;
	use DataMachine\Engine\Scheduling\FlowRoutines;
	use DataMachine\Engine\Actions\Handlers\JobCompleteHandler;
	use AgentsAPI\AI\Routines\WP_Agent_Routine_Registry as Registry;

	// Skip registration only; execute the real public ability entry point.
	$ability = new class extends \DataMachine\Abilities\Engine\ScheduleFlowAbility {
		public function __construct() { $this->initDatabases(); }
	};
	$GLOBALS['actions'] = array();
	$GLOBALS['next_id'] = 0;
	$timestamp = time() + 3600;
	foreach ( array( array( 'interval' => 'every_3_days' ), array( 'interval' => 'cron', 'cron_expression' => '0 9 * * *' ) ) as $index => $config ) {
		$id = $index + 1;
		Flows::$configs[$id] = $config;
		$routine_id = 'flow-' . $id;
		Registry::$routines[$routine_id] = $config;
		$routine_action = as_schedule_single_action( $timestamp + 86400, FlowRoutines::ROUTINE_HOOK, array( 'routine_id' => $routine_id ), FlowRoutines::ROUTINE_GROUP );
		$before = $GLOBALS['actions'][$routine_action];
		$result = $ability->execute( array( 'flow_id' => $id, 'interval_or_timestamp' => (string) $timestamp ) );
		check( is_array( $result ) && $result['success'] && 'one_time' === $result['schedule_type'], 'Ability must report a one-time run' );
		check( Flows::$configs[$id] === $config, 'Delayed run must preserve all scheduling config' );
		check( $GLOBALS['actions'][$result['action_id']] === array( 'timestamp' => $timestamp, 'hook' => FlowRoutines::ONE_TIME_HOOK, 'args' => array( 'flow_id' => $id ), 'group' => FlowRoutines::LEGACY_GROUP ), 'Returned action must use the engine bridge contract at T' );
		unset( $GLOBALS['actions'][$result['action_id']] ); // Single action consumed.
		JobCompleteHandler::handle( $id, 'completed' );
		check( Flows::$configs[$id] === $config, 'Completion must preserve recurring config' );
		check( Registry::$routines[$routine_id] === $config && $GLOBALS['actions'][$routine_action] === $before, 'Routine and its pending action must remain untouched' );
	}
	foreach ( array( array( 'interval' => 'manual' ), array() ) as $index => $config ) {
		$id = $index + 10;
		Flows::$configs[$id] = $config;
		$result = $ability->execute( array( 'flow_id' => $id, 'interval_or_timestamp' => $timestamp ) );
		check( is_array( $result ) && $result['action_id'] > 0, 'Manual flow must report its new action ID' );
		check( Flows::$configs[$id]['interval'] === 'one_time', 'Manual flow must show one_time' );
		check( $GLOBALS['actions'][$result['action_id']]['timestamp'] === $timestamp, 'Manual action must run at T' );
		unset( $GLOBALS['actions'][$result['action_id']] );
		JobCompleteHandler::handle( $id, 'completed' );
		check( Flows::$configs[$id]['interval'] === 'manual', 'Completion must revert manual flow' );
	}
	$GLOBALS['fail_enqueue'] = true;
	$result = $ability->execute( array( 'flow_id' => 1, 'interval_or_timestamp' => $timestamp ) );
	check( is_wp_error( $result ) && 'schedule_failed' === $result->get_error_code(), 'Enqueue failure must not report success' );
	check( Flows::$configs[1]['interval'] === 'every_3_days', 'Failure must preserve recurring config' );
	echo "Delayed flow schedule regression passed.\n";
}
