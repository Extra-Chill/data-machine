<?php
/**
 * Regression smoke test for agent prune resurrection (Extra-Chill/data-machine#2866).
 *
 * Run with: php tests/agent-prune-resurrection-smoke.php
 *
 * When executed inside a WordPress runtime, this exercises the actual ability
 * paths that caused pruned per-user agents to resurrect.
 *
 * @package DataMachine\Tests
 * @since   0.161.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

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

$root = dirname( __DIR__ );

if ( function_exists( 'wp_get_ability' ) ) {
	echo "\n[1] WordPress runtime regression: prune + active-agent read\n";

	require_once $root . '/vendor/autoload.php';

	$owner_id = wp_insert_user(
		array(
			'user_login' => 'prune-resurrection-owner-' . wp_rand(),
			'user_pass'  => wp_generate_password(),
			'role'       => 'author',
		)
	);
	if ( is_wp_error( $owner_id ) ) {
		echo "  [SKIP] Could not create test user: {$owner_id->get_error_message()}\n";
	} else {
		$meta_key = 'datamachine_active_agent_slug';

		$created = \DataMachine\Abilities\AgentAbilities::createAgent(
			array(
				'agent_slug' => 'resurrection-test-bot',
				'owner_id'   => $owner_id,
			)
		);
		$assert( 'test agent created', ! empty( $created['success'] ) );

		if ( ! empty( $created['success'] ) ) {
			\DataMachine\Abilities\AgentAbilities::setActiveAgent(
				array(
					'user_id' => $owner_id,
					'agent'   => $created['agent_slug'],
				)
			);
			$assert( 'active-agent meta persisted', get_user_meta( $owner_id, $meta_key, true ) === $created['agent_slug'] );

			$deleted = \DataMachine\Abilities\AgentAbilities::deleteAgent( array( 'agent_id' => $created['agent_id'] ) );
			$assert( 'test agent deleted', ! empty( $deleted['success'] ) );

			$active = \DataMachine\Abilities\AgentAbilities::getActiveAgent( array( 'user_id' => $owner_id ) );
			$assert( 'getActiveAgent returns null for stale preference', null === $active['agent'] );
			$assert( 'stale active-agent meta is cleared', '' === get_user_meta( $owner_id, $meta_key, true ) );

			$lookup = \DataMachine\Abilities\AgentAbilities::getAgent( array( 'agent_slug' => $created['agent_slug'] ) );
			$assert( 'pruned agent row stays deleted', empty( $lookup['success'] ) );

			$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
			$grants      = $access_repo->get_users_for_agent( (string) $created['agent_id'] );
			$assert( 'no grants exist for pruned agent ID', empty( $grants ) );
		}

		delete_user_meta( $owner_id, $meta_key );
		if ( is_numeric( $owner_id ) ) {
			wp_delete_user( (int) $owner_id );
		}
	}

	echo "\n[2] WordPress runtime regression: identity-store materialize guard\n";

	$adapter = new \DataMachine\Core\Identity\AgentIdentityStoreAdapter();
	$scope   = new \AgentsAPI\Core\Identity\WP_Agent_Identity_Scope( 'unregistered-pruned-bot', 1 );

	try {
		$adapter->materialize( $scope );
		$assert( 'materialize rejects unregistered stale scope', false );
	} catch ( \InvalidArgumentException $e ) {
		$assert( 'materialize rejects unregistered stale scope', true );
	}
}

echo "\n";
if ( $failures > 0 ) {
	echo "=== agent-prune-resurrection-smoke: {$failures}/{$total} FAILED ===\n";
	exit( 1 );
}
echo "=== agent-prune-resurrection-smoke: ALL PASS ({$total} assertions) ===\n";
