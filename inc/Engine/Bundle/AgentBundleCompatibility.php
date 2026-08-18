<?php
/**
 * Agent bundle host compatibility checks.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Engine\Agents\AgentSubagentGraph;

defined( 'ABSPATH' ) || exit;

final class AgentBundleCompatibility {

	public static function report( \WP_Agent_Package $package ): \WP_Agent_Package_Capability_Report {
		return \WP_Agent_Package_Capability_Checker::check( $package, self::host_capabilities() );
	}

	/** @return array<int,string> */
	public static function host_capabilities(): array {
		$capabilities = array(
			'datamachine',
			'datamachine/agent',
			'datamachine/agent-bundle',
			AgentSubagentGraph::CAPABILITY,
			'datamachine/bundle-schema-v1',
			'datamachine/pipeline',
			'datamachine/flow',
			'datamachine/prompt',
			'datamachine/rubric',
			'datamachine/tool-policy',
			'datamachine/auth-ref',
			'datamachine/queue-seed',
		);

		/**
		 * Extend host capability strings used for bundle compatibility checks.
		 *
		 * @param array<int,string> $capabilities Data Machine host capabilities.
		 */
		$capabilities = function_exists( 'apply_filters' ) ? apply_filters( 'datamachine_agent_bundle_host_capabilities', $capabilities ) : $capabilities;
		if ( ! is_array( $capabilities ) ) {
			$capabilities = array();
		}

		return AgentPackageProjection::normalize_capabilities( $capabilities );
	}
}
