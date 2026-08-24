<?php
/**
 * Identity adapter double that fails during provisioning.
 *
 * @package DataMachine\Tests\Fixtures
 */

namespace DataMachine\Tests\Fixtures;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;

final class FailingProvisionAdapter extends AgentIdentityStoreAdapter {

	public bool $failed = false;

	protected function provision_identity( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		unset( $agent_id, $scope, $meta );
		$this->failed = true;
		throw new \RuntimeException( 'Injected provisioning failure.' );
	}
}
