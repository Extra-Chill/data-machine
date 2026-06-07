<?php
/**
 * Agent bundle agent-config helpers.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes agent config for bundle artifact comparisons.
 */
final class AgentBundleAgentConfig {

	/**
	 * Return the bundle-owned agent config payload tracked by package upgrades.
	 *
	 * Data Machine writes installation bookkeeping under `datamachine_bundle` at
	 * runtime. That metadata is not authored by bundles, so excluding it keeps
	 * agent config drift checks focused on operator/bundle-owned settings.
	 *
	 * @param array<string,mixed> $config Agent config.
	 * @return array<string,mixed>
	 */
	public static function tracked_payload( array $config ): array {
		unset( $config['datamachine_bundle'] );
		ksort( $config, SORT_STRING );
		return $config;
	}
}
