<?php
/**
 * Pure-PHP smoke test for agent prune semantics.
 *
 * Run with: php tests/agent-prune-smoke.php
 *
 * Covers the prune ability and CLI without requiring a live WordPress install
 * by mirroring the production reference-checking algorithm from
 * AgentAbilities::pruneAgents() / collect_referenced_agent_ids() in a
 * dependency-injected harness.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Harness mirroring AgentAbilities prune logic ───────────────────

/**
 * Determine which agents are referenced and therefore ineligible for prune.
 *
 * Mirrors AgentAbilities::collect_referenced_agent_ids().
 *
 * @param array $agents          List of agent rows.
 * @param int   $default_user_id Install default agent owner user ID.
 * @param array $refs            Reference sets: 'sessions', 'jobs', 'grants',
 *                               'directories', 'bundles' as arrays of agent_ids.
 * @return array<int,true> Referenced agent IDs.
 */
function collect_referenced_agent_ids_for_test( array $agents, int $default_user_id, array $refs ): array {
	$referenced = array();

	// Default agent owner is always referenced.
	if ( $default_user_id > 0 ) {
		foreach ( $agents as $agent ) {
			if ( (int) $agent['owner_id'] === $default_user_id ) {
				$referenced[ (int) $agent['agent_id'] ] = true;
			}
		}
	}

	// Bundle-installed agents.
	foreach ( $agents as $agent ) {
		if ( in_array( (int) $agent['agent_id'], $refs['bundles'] ?? array(), true ) ) {
			$referenced[ (int) $agent['agent_id'] ] = true;
		}
	}

	// On-disk directories.
	foreach ( $agents as $agent ) {
		if ( in_array( (int) $agent['agent_id'], $refs['directories'] ?? array(), true ) ) {
			$referenced[ (int) $agent['agent_id'] ] = true;
		}
	}

	// Chat sessions, jobs, access grants.
	foreach ( array( 'sessions', 'jobs', 'grants' ) as $key ) {
		foreach ( $refs[ $key ] ?? array() as $id ) {
			$referenced[ (int) $id ] = true;
		}
	}

	return $referenced;
}

/**
 * Compute prune candidates.
 *
 * Mirrors AgentAbilities::pruneAgents() dry-run path.
 *
 * @param array $agents          List of agent rows.
 * @param int   $default_user_id Install default agent owner user ID.
 * @param array $refs            Reference sets.
 * @return array Candidate agent rows.
 */
function prune_candidates_for_test( array $agents, int $default_user_id, array $refs ): array {
	$referenced = collect_referenced_agent_ids_for_test( $agents, $default_user_id, $refs );

	$candidates = array();
	foreach ( $agents as $agent ) {
		$agent_id = (int) $agent['agent_id'];
		if ( isset( $referenced[ $agent_id ] ) ) {
			continue;
		}
		$candidates[] = $agent;
	}

	return $candidates;
}

// ─── Tiny assertion helpers ─────────────────────────────────────────

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $cond ) use ( &$failures, &$total ): void {
	$total++;
	if ( $cond ) {
		echo "  [PASS] {$label}\n";
	} else {
		$failures++;
		echo "  [FAIL] {$label}\n";
	}
};

$assert_same_set = function ( string $label, array $expected, array $actual ) use ( $assert ): void {
	$e = array_values( array_unique( $expected ) );
	$a = array_values( array_unique( $actual ) );
	sort( $e );
	sort( $a );
	$assert( $label, $e === $a );
};

// ─── Shared fixture builders ────────────────────────────────────────

function make_agent( int $agent_id, int $owner_id, string $slug ): array {
	return array(
		'agent_id'   => $agent_id,
		'agent_slug' => $slug,
		'agent_name' => $slug,
		'owner_id'   => $owner_id,
	);
}

$default_user_id = 1;

// ─── Test cases ─────────────────────────────────────────────────────

echo "\n[1] Prune identifies only zero-reference rows\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 3, 6, 'stray-commenter' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array() );
$assert_same_set(
	'only zero-reference agents are candidates',
	array( 2, 3 ),
	array_column( $candidates, 'agent_id' )
);

echo "\n[2] Prune spares the install default agent\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array() );
$assert( 'default agent (1) is not a candidate', ! in_array( 1, array_column( $candidates, 'agent_id' ), true ) );
$assert( 'stray agent (2) is a candidate', in_array( 2, array_column( $candidates, 'agent_id' ), true ) );

echo "\n[3] Prune spares rows with a chat session reference\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 4, 7, 'session-bot' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array( 'sessions' => array( 4 ) ) );
$ids = array_column( $candidates, 'agent_id' );
$assert( 'session-bot (4) is spared', ! in_array( 4, $ids, true ) );
$assert( 'stray agent (2) remains a candidate', in_array( 2, $ids, true ) );

echo "\n[4] Prune spares rows with a job reference\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 5, 8, 'job-bot' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array( 'jobs' => array( 5 ) ) );
$ids = array_column( $candidates, 'agent_id' );
$assert( 'job-bot (5) is spared', ! in_array( 5, $ids, true ) );
$assert( 'stray agent (2) remains a candidate', in_array( 2, $ids, true ) );

echo "\n[5] Prune spares rows with an access grant reference\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 6, 9, 'grant-bot' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array( 'grants' => array( 6 ) ) );
$ids = array_column( $candidates, 'agent_id' );
$assert( 'grant-bot (6) is spared', ! in_array( 6, $ids, true ) );
$assert( 'stray agent (2) remains a candidate', in_array( 2, $ids, true ) );

echo "\n[6] Prune spares rows with an on-disk directory\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 7, 10, 'file-bot' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array( 'directories' => array( 7 ) ) );
$ids = array_column( $candidates, 'agent_id' );
$assert( 'file-bot (7) is spared', ! in_array( 7, $ids, true ) );
$assert( 'stray agent (2) remains a candidate', in_array( 2, $ids, true ) );

echo "\n[7] Prune spares bundle-installed agents\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 8, 11, 'bundle-bot' ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, array( 'bundles' => array( 8 ) ) );
$ids = array_column( $candidates, 'agent_id' );
$assert( 'bundle-bot (8) is spared', ! in_array( 8, $ids, true ) );
$assert( 'stray agent (2) remains a candidate', in_array( 2, $ids, true ) );

echo "\n[8] Multiple references do not create duplicate candidates\n";
$agents = array(
	make_agent( 1, $default_user_id, 'default-bot' ),
	make_agent( 2, 5, 'stray-uploader' ),
	make_agent( 3, 6, 'stray-commenter' ),
	make_agent( 4, 7, 'session-bot' ),
	make_agent( 5, 8, 'job-bot' ),
	make_agent( 6, 9, 'grant-bot' ),
	make_agent( 7, 10, 'file-bot' ),
	make_agent( 8, 11, 'bundle-bot' ),
);
$refs = array(
	'sessions'    => array( 4 ),
	'jobs'        => array( 5 ),
	'grants'      => array( 6 ),
	'directories' => array( 7 ),
	'bundles'     => array( 8 ),
);
$candidates = prune_candidates_for_test( $agents, $default_user_id, $refs );
$assert_same_set(
	'only zero-reference rows are candidates when every reference type is present',
	array( 2, 3 ),
	array_column( $candidates, 'agent_id' )
);

echo "\n[9] Production prune ability and CLI exist\n";
$root        = dirname( __DIR__ );
$ability_src = (string) file_get_contents( $root . '/inc/Abilities/AgentAbilities.php' );
$cli_src     = (string) file_get_contents( $root . '/inc/Cli/Commands/AgentsCommand.php' );

$assert( 'AgentAbilities::pruneAgents() is defined', str_contains( $ability_src, 'public static function pruneAgents' ) );
$assert( 'datamachine/prune-agents ability is registered', str_contains( $ability_src, "'datamachine/prune-agents'" ) );
$assert( 'collect_referenced_agent_ids() helper exists', str_contains( $ability_src, 'private static function collect_referenced_agent_ids' ) );
$assert( 'AgentsCommand::prune() subcommand exists', str_contains( $cli_src, 'public function prune' ) );
$assert( 'CLI default is dry-run (requires --yes to delete)', str_contains( $cli_src, "\$apply = isset( \$assoc_args['yes'] )" ) );

// ─── Summary ────────────────────────────────────────────────────────

echo "\n";
if ( $failures > 0 ) {
	echo "=== agent-prune-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== agent-prune-smoke: ALL PASS ({$total} assertions) ===\n";
