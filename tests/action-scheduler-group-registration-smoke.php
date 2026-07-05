<?php
/**
 * Pure-PHP smoke test for Action Scheduler group registration (#2528).
 *
 * Run with: php tests/action-scheduler-group-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}

if ( defined( 'WPINC' ) ) {
	echo "action-scheduler-group-registration-smoke: skipped under real WordPress; standalone stubs drive this contract.\n";
	exit( 0 );
}

class ActionSchedulerGroupRegistrationWpdb {
	public string $prefix = 'wp_';
	public string $actionscheduler_groups = 'wp_actionscheduler_groups';
	public bool $group_exists = false;
	public array $inserted = array();

	public function prepare( string $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function get_var( $prepared ) {
		$query = is_array( $prepared ) ? (string) $prepared['query'] : (string) $prepared;
		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
			return $this->actionscheduler_groups;
		}

		if ( false !== strpos( $query, 'SELECT group_id' ) ) {
			return $this->group_exists ? 7 : 0;
		}

		return null;
	}

	public function insert( string $table, array $data, array $format = array() ): bool {
		unset( $format );
		$this->inserted[] = array(
			'table' => $table,
			'data'  => $data,
		);
		$this->group_exists = true;

		return true;
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		return 'action-group' === $taxonomy;
}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( string $term, string $taxonomy ): bool {
		unset( $taxonomy );
		return in_array( $term, $GLOBALS['action_scheduler_group_terms'] ?? array(), true );
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( string $term, string $taxonomy, array $args = array() ): array {
		unset( $taxonomy, $args );
		$GLOBALS['action_scheduler_group_terms'][] = $term;

		return array( 'term_id' => count( $GLOBALS['action_scheduler_group_terms'] ) );
	}
}

$GLOBALS['wpdb']                         = new ActionSchedulerGroupRegistrationWpdb();
$GLOBALS['action_scheduler_group_terms'] = array();

$root       = dirname( __DIR__ );
$plugin_src = file_get_contents( $root . '/data-machine.php' ) ?: '';
$group_src  = file_get_contents( $root . '/inc/Core/ActionScheduler/GroupRegistrar.php' ) ?: '';
$drain_src  = file_get_contents( $root . '/inc/Cli/Commands/DrainCommand.php' ) ?: '';
require_once $root . '/inc/Core/ActionScheduler/GroupRegistrar.php';

$assertions = 0;

function assert_action_scheduler_group_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_action_scheduler_group_contains( string $needle, string $haystack, string $message ): void {
	assert_action_scheduler_group_true( false !== strpos( $haystack, $needle ), $message );
}

assert_action_scheduler_group_contains( 'action_scheduler_init', $plugin_src, 'plugin registers an Action Scheduler init hook' );
assert_action_scheduler_group_contains( 'GroupRegistrar::class', $plugin_src, 'plugin hooks the Data Machine group registrar' );
assert_action_scheduler_group_contains( 'ensureDataMachineGroup', $plugin_src, 'plugin ensures Data Machine group during AS init' );

assert_action_scheduler_group_contains( "public const GROUP = '" . \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP . "'", $group_src, 'group registrar owns the Data Machine group slug' );
assert_action_scheduler_group_contains( 'ensureCustomTableGroup( self::GROUP )', $group_src, 'group registrar ensures custom-table group storage' );
assert_action_scheduler_group_contains( 'ensurePostStoreGroup( self::GROUP )', $group_src, 'group registrar ensures legacy post-store taxonomy storage' );
assert_action_scheduler_group_contains( 'actionscheduler_groups', $group_src, 'custom-table group storage targets Action Scheduler groups table' );
assert_action_scheduler_group_contains( 'wp_insert_term( $group, $taxonomy', $group_src, 'legacy post-store group storage creates missing action-group term' );

$ensure_pos = strpos( $drain_src, 'GroupRegistrar::ensureDataMachineGroup()' );
$claim_pos  = strpos( $drain_src, 'stake_claim( $claim_size, null, $hooks ?? array(), self::GROUP )' );

assert_action_scheduler_group_true( false !== $ensure_pos, 'drain defensively ensures AS group before claiming' );
assert_action_scheduler_group_true( false !== $claim_pos, 'drain stakes a group-scoped claim' );
assert_action_scheduler_group_true( $ensure_pos < $claim_pos, 'drain ensures group before staking group claim' );

\DataMachine\Core\ActionScheduler\GroupRegistrar::ensureDataMachineGroup();

assert_action_scheduler_group_true(
	array( 'table' => 'wp_actionscheduler_groups', 'data' => array( 'slug' => \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP ) ) === $GLOBALS['wpdb']->inserted[0],
	'group registrar creates the custom-table group row when missing'
);
assert_action_scheduler_group_true(
	in_array( \DataMachine\Core\ActionScheduler\GroupRegistrar::GROUP, $GLOBALS['action_scheduler_group_terms'], true ),
	'group registrar creates the legacy action-group taxonomy term when missing'
);

$insert_count = count( $GLOBALS['wpdb']->inserted );
\DataMachine\Core\ActionScheduler\GroupRegistrar::ensureDataMachineGroup();
assert_action_scheduler_group_true(
	$insert_count === count( $GLOBALS['wpdb']->inserted ),
	'group registrar does not duplicate the custom-table group row'
);

echo "OK ({$assertions} assertions)\n";
