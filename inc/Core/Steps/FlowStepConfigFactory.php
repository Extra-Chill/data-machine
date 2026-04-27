<?php
/**
 * Flow step config factory.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Builds canonical flow step configuration rows from explicit inputs.
 */
class FlowStepConfigFactory {

	/**
	 * Build a canonical flow step config row from an ephemeral workflow step.
	 *
	 * @param array $step  Workflow step input.
	 * @param int   $index Zero-based workflow step index.
	 * @return array Flow step config row.
	 */
	public static function buildFromWorkflowStep( array $step, int $index ): array {
		$step_type = $step['type'];

		return self::build(
			array_merge(
				array(
					'flow_step_id'     => "ephemeral_step_{$index}",
					'pipeline_step_id' => "ephemeral_pipeline_{$index}",
					'step_type'        => $step_type,
					'execution_order'  => $index,
					'enabled_tools'    => ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
						? array_values( $step['enabled_tools'] )
						: array(),
				),
				self::promptQueueFromWorkflowStep( $step ),
				array(
					'queue_mode'       => 'static',
					'disabled_tools'   => $step['disabled_tools'] ?? array(),
					'pipeline_id'      => 'direct',
					'flow_id'          => 'direct',
					'handler_slug'     => $step['handler_slug'] ?? '',
					'handler_config'   => $step['handler_config'] ?? array(),
				)
			)
		);
	}

	/**
	 * Build a canonical flow step config row from a pipeline step for a flow.
	 *
	 * @param array      $step                 Pipeline step row.
	 * @param int|string $pipeline_id          Pipeline ID.
	 * @param int|string $flow_id              Flow ID.
	 * @param string     $flow_step_id         Generated flow step ID.
	 * @param array      $pipeline_step_config Pipeline step config keyed by pipeline step ID.
	 * @return array Flow step config row.
	 */
	public static function buildFromPipelineStep(
		array $step,
		$pipeline_id,
		$flow_id,
		string $flow_step_id,
		array $pipeline_step_config = array()
	): array {
		$step_type        = $step['step_type'] ?? '';
		$pipeline_step_id = $step['pipeline_step_id'] ?? '';

		return self::build(
			array_merge(
				array(
					'flow_step_id'     => $flow_step_id,
					'step_type'        => $step_type,
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'      => $pipeline_id,
					'flow_id'          => $flow_id,
					'execution_order'  => $step['execution_order'] ?? 0,
					'disabled_tools'   => $pipeline_step_config['disabled_tools'] ?? array(),
				),
				self::queueDefaultsForStepType( $step_type )
			)
		);
	}

	/**
	 * Build a canonical flow step configuration row.
	 *
	 * @param array $args Explicit config inputs.
	 * @return array Flow step config row.
	 */
	public static function build( array $args ): array {
		$step_config = array();
		$copy_fields = array(
			'flow_step_id'       => true,
			'pipeline_step_id'   => true,
			'step_type'          => true,
			'execution_order'    => true,
			'enabled_tools'      => true,
			'disabled_tools'     => true,
			'prompt_queue'       => true,
			'config_patch_queue' => true,
			'queue_mode'         => true,
			'pipeline_id'        => true,
			'flow_id'            => true,
			'handler'            => true,
		);

		foreach ( $args as $field => $value ) {
			if ( isset( $copy_fields[ $field ] ) ) {
				$step_config[ $field ] = $value;
			}
		}

		$handler_slug   = $args['handler_slug'] ?? '';
		$handler_config = $args['handler_config'] ?? array();

		if ( is_string( $handler_slug ) && '' !== $handler_slug ) {
			if ( FlowStepConfig::isMultiHandler( $step_config ) ) {
				$step_config['handler_slugs']   = array( $handler_slug );
				$step_config['handler_configs'] = array( $handler_slug => $handler_config );
			} else {
				$step_config['handler_slug']   = $handler_slug;
				$step_config['handler_config'] = $handler_config;
			}
		} elseif ( ! FlowStepConfig::usesHandler( $step_config ) && ! empty( $handler_config ) ) {
			$step_config['handler_config'] = $handler_config;
		}

		return $step_config;
	}

	/**
	 * Return queue defaults for a step type.
	 *
	 * @param string $step_type Step type slug.
	 * @return array Queue fields.
	 */
	private static function queueDefaultsForStepType( string $step_type ): array {
		$defaults = array( 'queue_mode' => 'static' );

		if ( 'fetch' === $step_type ) {
			$defaults['config_patch_queue'] = array();
		} else {
			$defaults['prompt_queue'] = array();
		}

		return $defaults;
	}

	/**
	 * Convert workflow user_message input into AIStep's prompt queue slot.
	 *
	 * @param array $step Workflow step input.
	 * @return array Prompt queue override or empty array.
	 */
	private static function promptQueueFromWorkflowStep( array $step ): array {
		$workflow_user_message = is_string( $step['user_message'] ?? null )
			? trim( $step['user_message'] )
			: '';

		if ( 'ai' !== ( $step['type'] ?? '' ) || '' === $workflow_user_message ) {
			return array( 'prompt_queue' => array() );
		}

		return array(
			'prompt_queue' => array(
				array(
					'prompt'   => $workflow_user_message,
					'added_at' => gmdate( 'c' ),
				),
			),
		);
	}
}
