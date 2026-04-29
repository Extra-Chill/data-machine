<?php
/**
 * Pipeline tool policy argument builder.
 *
 * @package DataMachine\Core\Steps\AI\ToolPolicy
 */

namespace DataMachine\Core\Steps\AI\ToolPolicy;

use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

class PipelineToolPolicyArgs {

	/**
	 * Build snapshot-derived pipeline policy arguments.
	 *
	 * Pipeline execution must honor the flow/pipeline config captured in the
	 * current engine snapshot. Do not re-read persisted pipeline rows here:
	 * direct workflows use synthetic IDs, and historical jobs must inspect the
	 * policy that existed when they ran.
	 *
	 * @param array $flow_step_config     Current flow step config snapshot.
	 * @param array $pipeline_step_config Current pipeline step config snapshot.
	 * @return array Resolver args containing allow_only/deny when configured.
	 */
	public static function fromConfigs( array $flow_step_config, array $pipeline_step_config ): array {
		$args          = array();
		$enabled_tools = FlowStepConfig::getEnabledTools( $flow_step_config );

		if ( ! empty( $enabled_tools ) ) {
			$args['allow_only'] = self::sanitizeToolList( $enabled_tools );
		}

		$deny = array_merge(
			self::sanitizeToolList( is_array( $pipeline_step_config['disabled_tools'] ?? null ) ? $pipeline_step_config['disabled_tools'] : array() ),
			self::sanitizeToolList( is_array( $flow_step_config['disabled_tools'] ?? null ) ? $flow_step_config['disabled_tools'] : array() )
		);

		if ( ! empty( $deny ) ) {
			$args['deny'] = array_values( array_unique( $deny ) );
		}

		return $args;
	}

	/**
	 * Sanitize a list of tool slugs.
	 *
	 * @param array $tools Raw tool names.
	 * @return array<int, string> Clean tool names.
	 */
	private static function sanitizeToolList( array $tools ): array {
		$clean = array();
		foreach ( $tools as $tool ) {
			if ( ! is_string( $tool ) || '' === $tool ) {
				continue;
			}
			$clean[] = $tool;
		}

		return array_values( array_unique( $clean ) );
	}
}
