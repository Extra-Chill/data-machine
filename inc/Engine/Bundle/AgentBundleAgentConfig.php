<?php
/**
 * Agent bundle agent-config helpers.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Agents\AgentConfigFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes agent config for bundle artifact comparisons.
 */
final class AgentBundleAgentConfig {

	/**
	 * Return the bundle-owned agent config payload tracked by package upgrades.
	 *
	 * Projection policy is centralized in AgentConfigArtifactProjector so plugin
	 * namespaces can opt out of core drift ownership without special cases here.
	 *
	 * @param array<string,mixed> $config Agent config.
	 * @return array<string,mixed>
	 */
	public static function tracked_payload( array $config ): array {
		return AgentConfigArtifactProjector::tracked_payload( AgentConfigFactory::normalize( $config ) );
	}
}
