<?php
/**
 * Copy Flow Tool
 *
 * Copy an existing flow to the same or different pipeline with optional
 * configuration overrides. Supports cross-pipeline copying with compatibility
 * validation.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.6.25
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class CopyFlow extends BaseTool {

	public function __construct() {
		$this->registerTool( 'copy_flow', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'ability' => 'datamachine/duplicate-flow' ) );
	}

	/**
	 * Get tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Copy a flow to the same or different pipeline. Cross-pipeline requires compatible step structures. Copies handlers, messages, and schedule.',
			'parameters'  => array(
				'source_flow_id'        => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Flow ID to copy',
				),
				'target_pipeline_id'    => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Destination pipeline ID',
				),
				'flow_name'             => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'New flow name',
				),
				'scheduling_config'     => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Override schedule (defaults to source). Format: {interval: value}. Valid intervals:' . "\n" . SchedulingDocumentation::getIntervalsJson(),
				),
				'step_config_overrides' => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Override steps by step_type or execution_order: {handler_slug?, handler_config?, user_message?}',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$source_flow_id     = $parameters['source_flow_id'] ?? null;
		$target_pipeline_id = $parameters['target_pipeline_id'] ?? null;
		$flow_name          = $parameters['flow_name'] ?? null;

		if ( ! is_numeric( $source_flow_id ) || (int) $source_flow_id <= 0 ) {
			return array(
				'success'   => false,
				'error'     => 'source_flow_id is required and must be a positive integer',
				'tool_name' => 'copy_flow',
			);
		}

		if ( ! is_numeric( $target_pipeline_id ) || (int) $target_pipeline_id <= 0 ) {
			return array(
				'success'   => false,
				'error'     => 'target_pipeline_id is required and must be a positive integer',
				'tool_name' => 'copy_flow',
			);
		}

		if ( empty( $flow_name ) ) {
			return array(
				'success'   => false,
				'error'     => 'flow_name is required',
				'tool_name' => 'copy_flow',
			);
		}

		$ability = wp_get_ability( 'datamachine/duplicate-flow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Duplicate flow ability not available',
				'tool_name' => 'copy_flow',
			);
		}

		$input = array(
			'source_flow_id'     => (int) $source_flow_id,
			'target_pipeline_id' => (int) $target_pipeline_id,
			'flow_name'          => sanitize_text_field( $flow_name ),
		);

		if ( ! empty( $parameters['scheduling_config'] ) ) {
			$input['scheduling_config'] = $parameters['scheduling_config'];
		}

		if ( ! empty( $parameters['step_config_overrides'] ) ) {
			$input['step_config_overrides'] = $parameters['step_config_overrides'];
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to copy flow' );
			return $this->buildErrorResponse( $error, 'copy_flow' );
		}

		$is_cross_pipeline = $result['source_pipeline_id'] !== $result['target_pipeline_id'];
		$has_overrides     = ! empty( $parameters['step_config_overrides'] );

		if ( $is_cross_pipeline && $has_overrides ) {
			$message = 'Flow copied to different pipeline and configured with overrides.';
		} elseif ( $is_cross_pipeline ) {
			$message = 'Flow copied to different pipeline.';
		} elseif ( $has_overrides ) {
			$message = 'Flow duplicated and configured with overrides.';
		} else {
			$message = 'Flow duplicated successfully.';
		}

		return array(
			'success'   => true,
			'data'      => array(
				'flow_id'            => $result['flow_id'],
				'flow_name'          => $result['flow_name'],
				'source_flow_id'     => $result['source_flow_id'],
				'source_pipeline_id' => $result['source_pipeline_id'],
				'target_pipeline_id' => $result['target_pipeline_id'],
				'flow_step_ids'      => $result['flow_step_ids'],
				'scheduling'         => $result['scheduling'],
				'message'            => $message,
			),
			'tool_name' => 'copy_flow',
		);
	}
}
