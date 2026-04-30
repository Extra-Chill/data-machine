<?php
/**
 * Data Machine mandatory tool policy.
 *
 * Pipeline handler tools are required flow plumbing derived from adjacent
 * steps. Data Machine preserves them while generic optional tool policy filters
 * the rest of the tool set.
 *
 * @package DataMachine\Engine\AI\Tools\Policy
 */

namespace DataMachine\Engine\AI\Tools\Policy;

defined( 'ABSPATH' ) || exit;

final class DataMachineMandatoryToolPolicy {

	/**
	 * Return whether a tool is mandatory Data Machine pipeline plumbing.
	 *
	 * @param array $tool Tool definition.
	 * @return bool Whether the tool must survive optional policy filtering.
	 */
	public function isMandatory( array $tool ): bool {
		return isset( $tool['handler'] ) && ! isset( $tool['ability'] ) && ! isset( $tool['abilities'] );
	}

	/**
	 * Extract mandatory tools from a resolved tool set.
	 *
	 * @param array $tools Tool definitions keyed by tool name.
	 * @return array Mandatory tools keyed by tool name.
	 */
	public function extract( array $tools ): array {
		return array_filter(
			$tools,
			fn( $tool ) => is_array( $tool ) && $this->isMandatory( $tool )
		);
	}

	/**
	 * Split tools into mandatory and optional buckets.
	 *
	 * @param array $tools Tool definitions keyed by tool name.
	 * @return array{mandatory: array, optional: array} Split tool buckets.
	 */
	public function split( array $tools ): array {
		$mandatory = $this->extract( $tools );

		return array(
			'mandatory' => $mandatory,
			'optional'  => array_diff_key( $tools, $mandatory ),
		);
	}
}
