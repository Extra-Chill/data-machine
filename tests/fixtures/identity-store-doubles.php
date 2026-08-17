<?php
/**
 * Shared identity-store doubles used by isolated PHPUnit selections.
 *
 * @package DataMachine\Tests\Fixtures
 */

namespace DataMachine\Tests\Fixtures;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;

final class FailingProvisionAdapter extends AgentIdentityStoreAdapter {

	public bool $failed = false;

	protected function provision_identity( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		unset( $agent_id, $scope, $meta );
		$this->failed = true;
		throw new \RuntimeException( 'Injected provisioning failure.' );
	}
}

final class CountingProvisionAdapter extends AgentIdentityStoreAdapter {

	public int $provision_count = 0;

	protected function provision_identity( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		++$this->provision_count;
		parent::provision_identity( $agent_id, $scope, $meta );
	}
}

final class DuplicateLoserAgents extends Agents {

	public bool $duplicate_loser_exercised = false;

	protected function insert_identity_row( array $data, array $formats ): int|false {
		unset( $formats );
		$this->duplicate_loser_exercised = true;
		$config = json_decode( (string) $data['agent_config'], true );
		( new Agents() )->create_identity_if_missing(
			(string) $data['agent_slug'],
			(string) $data['agent_name'],
			(int) $data['owner_id'],
			(string) $data['instance_key'],
			is_array( $config ) ? $config : array()
		);
		return false;
	}
}
