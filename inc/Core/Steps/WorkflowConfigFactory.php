<?php
/**
 * Workflow config factory.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Builds pipeline and flow config rows from workflow step definitions.
 */
class WorkflowConfigFactory {

	/**
	 * Build direct-execution configs from a workflow.
	 *
	 * @param array $workflow Workflow with steps.
	 * @return array{flow_config: array, pipeline_config: array}
	 */
	public static function buildEphemeralConfigs( array $workflow ): array {
		$flow_config     = array();
		$pipeline_config = array();

		foreach ( $workflow['steps'] as $index => $step ) {
			$step_id          = "ephemeral_step_{$index}";
			$pipeline_step_id = "ephemeral_pipeline_{$index}";

			$flow_config[ $step_id ]              = FlowStepConfigFactory::buildFromWorkflowStep( $step, $index );
			$pipeline_config[ $pipeline_step_id ] = self::pipelineStepFromWorkflowStep( $step, $pipeline_step_id, $index );
		}

		return array(
			'flow_config'     => $flow_config,
			'pipeline_config' => $pipeline_config,
		);
	}

	/**
	 * Build persistent pipeline config from a workflow.
	 *
	 * @param array      $workflow Workflow with steps.
	 * @param int|string $pipeline_id Pipeline ID.
	 * @return array<string,array<string,mixed>> Pipeline config keyed by generated pipeline step ID.
	 */
	public static function buildPersistentPipelineConfig( array $workflow, $pipeline_id ): array {
		$pipeline_config = array();

		foreach ( $workflow['steps'] as $index => $step ) {
			$pipeline_step_id                     = $pipeline_id . '_' . wp_generate_uuid4();
			$pipeline_config[ $pipeline_step_id ] = self::pipelineStepFromWorkflowStep( $step, $pipeline_step_id, $index );
		}

		return $pipeline_config;
	}

	/**
	 * Build persistent flow config from workflow steps and their pipeline rows.
	 *
	 * @param array      $workflow Workflow with steps.
	 * @param int|string $pipeline_id Pipeline ID.
	 * @param int|string $flow_id Flow ID.
	 * @param array      $pipeline_config Pipeline config keyed by pipeline step ID.
	 * @return array<string,array<string,mixed>> Flow config keyed by generated flow step ID.
	 */
	public static function buildPersistentFlowConfig( array $workflow, $pipeline_id, $flow_id, array $pipeline_config ): array {
		$pipeline_steps_by_order = array();
		foreach ( $pipeline_config as $pipeline_step_id => $pipeline_step ) {
			$order                             = (int) ( $pipeline_step['execution_order'] ?? 0 );
			$pipeline_steps_by_order[ $order ] = array_merge(
				$pipeline_step,
				array( 'pipeline_step_id' => $pipeline_step_id )
			);
		}

		$flow_config = array();
		foreach ( $workflow['steps'] as $index => $step ) {
			$pipeline_step = $pipeline_steps_by_order[ $index ] ?? null;
			if ( ! $pipeline_step ) {
				continue;
			}

			$pipeline_step_id = $pipeline_step['pipeline_step_id'];
			$flow_step_id     = apply_filters( 'datamachine_generate_flow_step_id', '', $pipeline_step_id, $flow_id );
			if ( empty( $flow_step_id ) ) {
				$flow_step_id = $pipeline_step_id . '_' . $flow_id;
			}

			$flow_step = FlowStepConfigFactory::buildFromPipelineStep(
				$pipeline_step,
				$pipeline_id,
				$flow_id,
				$flow_step_id,
				$pipeline_step
			);

			$workflow_step_config = FlowStepConfigFactory::buildFromWorkflowStep( $step, $index );
			$flow_step            = FlowStepConfigFactory::withHandlerFields( $flow_step, $workflow_step_config );
			$flow_step            = FlowStepConfigFactory::withQueueState( $flow_step, $workflow_step_config );

			if ( isset( $workflow_step_config['enabled_tools'] ) ) {
				$flow_step['enabled_tools'] = $workflow_step_config['enabled_tools'];
			}
			if ( isset( $workflow_step_config['disabled_tools'] ) ) {
				$flow_step['disabled_tools'] = $workflow_step_config['disabled_tools'];
			}

			$flow_config[ $flow_step_id ] = $flow_step;
		}

		return $flow_config;
	}

	/**
	 * Build a pipeline step row from a workflow step.
	 *
	 * @param array  $step Workflow step.
	 * @param string $pipeline_step_id Pipeline step ID.
	 * @param int    $index Execution order.
	 * @return array<string,mixed> Pipeline step config.
	 */
	private static function pipelineStepFromWorkflowStep( array $step, string $pipeline_step_id, int $index ): array {
		$step_type = (string) ( $step['type'] ?? '' );
		$label     = isset( $step['label'] ) && is_string( $step['label'] ) && '' !== trim( $step['label'] )
			? $step['label']
			: ucfirst( str_replace( '_', ' ', $step_type ) );

		$pipeline_step = array(
			'pipeline_step_id' => $pipeline_step_id,
			'step_type'        => $step_type,
			'execution_order'  => $index,
			'label'            => $label,
		);

		if ( 'ai' === $step_type ) {
			$pipeline_step['system_prompt']  = $step['system_prompt'] ?? '';
			$pipeline_step['disabled_tools'] = is_array( $step['disabled_tools'] ?? null ) ? array_values( $step['disabled_tools'] ) : array();
		}

		return $pipeline_step;
	}
}
