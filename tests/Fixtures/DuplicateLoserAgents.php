<?php
/**
 * Agents repository double for duplicate-key reconciliation.
 *
 * @package DataMachine\Tests\Fixtures
 */

namespace DataMachine\Tests\Fixtures;

use DataMachine\Core\Database\Agents\Agents;

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
