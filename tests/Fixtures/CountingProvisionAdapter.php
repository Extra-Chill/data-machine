<?php
/**
 * Identity adapter double that counts provisioning attempts.
 *
 * @package DataMachine\Tests\Fixtures
 */

namespace DataMachine\Tests\Fixtures;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;

final class CountingProvisionAdapter extends AgentIdentityStoreAdapter {

	public int $provision_count = 0;

	protected function provision_identity( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		++$this->provision_count;
		parent::provision_identity( $agent_id, $scope, $meta );
	}
}
