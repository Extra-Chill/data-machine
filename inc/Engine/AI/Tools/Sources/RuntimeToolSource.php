<?php
/**
 * Run-scoped runtime tool source.
 *
 * Adapts client/transport-declared runtime tool definitions into the normal
 * tool source pipeline. These tools are visible to model requests only after
 * the existing ToolPolicyResolver/Agents API policy pass allows them; execution
 * remains outside PHP because declarations are marked with a client executor.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;

defined( 'ABSPATH' ) || exit;

final class RuntimeToolSource {

	/**
	 * Gather normalized runtime tool declarations from resolver context.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array $args  Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function __invoke( array $modes, array $args = array() ): array {
		$tools = array();

		foreach ( $this->declarationsFromContext( $args ) as $declaration ) {
			if ( ! is_array( $declaration ) ) {
				continue;
			}

			try {
				$normalized = WP_Agent_Tool_Declaration::normalize( $declaration );
			} catch ( \InvalidArgumentException ) {
				continue;
			}

			$name           = (string) $normalized['name'];
			$tools[ $name ] = array_merge(
				$normalized,
				array(
					'modes'             => $modes,
					'access_level'      => 'public',
					'runtime_tool'      => true,
					'external_executor' => true,
					'requires_opt_in'   => true,
				)
			);
		}

		return $tools;
	}

	/**
	 * Extract declarations from explicit resolver args and nested client context.
	 *
	 * @param array $args Full resolution arguments.
	 * @return array<int,array|string|mixed> Runtime declarations.
	 */
	private function declarationsFromContext( array $args ): array {
		$sets = array(
			$args['runtime_tool_declarations'] ?? null,
			$args['runtime_tools'] ?? null,
		);

		$client_context = is_array( $args['client_context'] ?? null ) ? $args['client_context'] : array();
		$sets[]         = $client_context['runtime_tool_declarations'] ?? null;
		$sets[]         = $client_context['runtime_tools'] ?? null;
		$sets[]         = $client_context['tool_declarations'] ?? null;

		$declarations = array();
		foreach ( $sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			foreach ( $set as $name => $declaration ) {
				if ( ! is_array( $declaration ) ) {
					continue;
				}

				if ( is_string( $name ) && '' !== $name && empty( $declaration['name'] ) ) {
					$declaration['name'] = $name;
				}

				$declarations[] = $declaration;
			}
		}

		return $declarations;
	}
}
