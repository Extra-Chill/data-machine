<?php
/**
 * Create Flow Tool
 *
 * Focused tool for creating flow instances from existing pipelines.
 * Automatically syncs pipeline steps to the new flow.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Database\Flows\Flows as FlowsDB;
use DataMachine\Engine\AI\Tools\BaseTool;

class CreateFlow extends BaseTool {

	public function __construct() {
		$this->registerTool( 'create_flow', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Create a new flow for a pipeline with optional step configurations. Query existing flows first to learn established patterns. Supports bulk mode via flows array.',
			'parameters'  => array(
				'pipeline_id'        => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Pipeline ID (single mode - required unless using bulk mode)',
				),
				'flow_name'          => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Flow name (defaults to "Flow")',
				),
				'scheduling_config'  => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Schedule: {interval: value}. Valid intervals:' . "\n" . SchedulingDocumentation::getIntervalsJson(),
				),
				'step_configs'       => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Step configurations keyed by step_type: {handler_slug?, handler_config?, user_message?}',
				),
				'flows'              => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Bulk mode: create multiple flows. Each item: {pipeline_id, flow_name, step_configs?, scheduling_config?}. Uses shared_step_config as base.',
				),
				'shared_step_config' => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Shared step config for bulk mode (keyed by step_type). Per-flow step_configs override these.',
				),
				'validate_only'      => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Dry-run mode: validate configuration without creating. Returns what would be created.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		// Check for bulk mode
		if ( ! empty( $parameters['flows'] ) && is_array( $parameters['flows'] ) ) {
			return $this->handleBulkMode( $parameters );
		}

		$pipeline_id = $parameters['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success'   => false,
				'error'     => 'pipeline_id is required and must be a positive integer',
				'tool_name' => 'create_flow',
			);
		}

		$pipeline_id       = (int) $pipeline_id;
		$flow_name         = $parameters['flow_name'] ?? 'Flow';
		$scheduling_config = $parameters['scheduling_config'] ?? array( 'interval' => 'manual' );

		$validation = $this->validateSchedulingConfig( $scheduling_config );
		if ( true !== $validation ) {
			return array(
				'success'   => false,
				'error'     => $validation,
				'tool_name' => 'create_flow',
			);
		}

		$flows_db       = new FlowsDB();
		$existing_flows = $flows_db->get_flows_for_pipeline( $pipeline_id );

		foreach ( $existing_flows as $existing_flow ) {
			if ( strcasecmp( $existing_flow['flow_name'], $flow_name ) === 0 ) {
				$flow_config   = $existing_flow['flow_config'] ?? array();
				$flow_step_ids = array_keys( $flow_config );

				return array(
					'success'   => true,
					'data'      => array(
						'flow_id'        => $existing_flow['flow_id'],
						'flow_name'      => $existing_flow['flow_name'],
						'pipeline_id'    => $pipeline_id,
						'flow_step_ids'  => $flow_step_ids,
						'already_exists' => true,
						'message'        => "Flow '{$existing_flow['flow_name']}' already exists for this pipeline. Use configure_flow_steps to modify it, or specify a different flow_name to create a new flow.",
					),
					'tool_name' => 'create_flow',
				);
			}
		}

		$ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Create flow ability not available',
				'tool_name' => 'create_flow',
			);
		}

		$result = $ability->execute(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $flow_name,
				'scheduling_config' => $scheduling_config,
			)
		);

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to create flow. Verify the pipeline_id exists and you have sufficient permissions.' );
			return $this->buildErrorResponse( $error, 'create_flow' );
		}

		$flow_config   = $result['flow_data']['flow_config'] ?? array();
		$flow_step_ids = array_keys( $flow_config );

		$config_results = array(
			'applied' => array(),
			'errors'  => array(),
		);
		if ( ! empty( $parameters['step_configs'] ) ) {
			$config_results = $this->applyStepConfigs( $result['flow_id'], $parameters['step_configs'] );
		}

		$response_data = array(
			'flow_id'       => $result['flow_id'],
			'flow_name'     => $result['flow_name'],
			'pipeline_id'   => $result['pipeline_id'],
			'synced_steps'  => $result['synced_steps'],
			'flow_step_ids' => $flow_step_ids,
			'scheduling'    => $scheduling_config['interval'],
		);

		if ( ! empty( $config_results['applied'] ) ) {
			$response_data['configured_steps'] = $config_results['applied'];
		}

		if ( ! empty( $config_results['errors'] ) ) {
			$response_data['configuration_errors'] = $config_results['errors'];
		}

		$response_data['message'] = empty( $parameters['step_configs'] )
			? 'Flow created. Use configure_flow_steps to set handler configurations.'
			: ( empty( $config_results['errors'] )
				? 'Flow created and configured.'
				: 'Flow created with some configuration errors.' );

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'create_flow',
		);
	}

	/**
	 * Handle bulk flow creation mode.
	 *
	 * @param array $parameters Tool parameters including flows array.
	 * @return array Tool response.
	 */
	private function handleBulkMode( array $parameters ): array {
		$flows              = $parameters['flows'];
		$shared_step_config = $parameters['shared_step_config'] ?? array();
		$validate_only      = ! empty( $parameters['validate_only'] );

		$ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Create flow ability not available',
				'tool_name' => 'create_flow',
			);
		}

		$result = $ability->execute(
			array(
				'flows'              => $flows,
				'shared_step_config' => $shared_step_config,
				'validate_only'      => $validate_only,
			)
		);

		$result['tool_name'] = 'create_flow';

		if ( $result['success'] ?? false ) {
			if ( $validate_only ) {
				$result['data'] = array(
					'mode'         => 'validate_only',
					'would_create' => $result['would_create'] ?? array(),
					'message'      => $result['message'] ?? 'Validation passed.',
				);
				unset( $result['would_create'], $result['valid'], $result['mode'] );
			} else {
				$result['data'] = array(
					'created_count' => $result['created_count'],
					'failed_count'  => $result['failed_count'],
					'created'       => $result['created'],
					'errors'        => $result['errors'] ?? array(),
					'partial'       => $result['partial'] ?? false,
					'message'       => $result['message'] ?? 'Bulk creation completed.',
				);
				unset( $result['created_count'], $result['failed_count'], $result['created'], $result['errors'], $result['partial'], $result['creation_mode'] );
			}
		}

		return $result;
	}

	/**
	 * Apply step configurations to a newly created flow.
	 *
	 * @param int   $flow_id Flow ID
	 * @param array $step_configs Configs keyed by pipeline_step_id
	 * @return array{applied: array, errors: array}
	 */
	private function applyStepConfigs( int $flow_id, array $step_configs ): array {
		$handler_abilities   = new HandlerAbilities();
		$update_step_ability = wp_get_ability( 'datamachine/update-flow-step' );
		$applied             = array();
		$errors              = array();

		if ( ! $update_step_ability ) {
			return array(
				'applied' => array(),
				'errors'  => array(
					array(
						'pipeline_step_id' => 'all',
						'error'            => 'Update flow step ability not available',
					),
				),
			);
		}

		foreach ( $step_configs as $pipeline_step_id => $config ) {
			$flow_step_id = $pipeline_step_id . '_' . $flow_id;
			$step_applied = false;

			if ( ! empty( $config['handler_slug'] ) ) {
				$validation = $handler_abilities->validateHandler( $config['handler_slug'] );
				if ( ! $validation['valid'] ) {
					$errors[] = array(
						'pipeline_step_id' => $pipeline_step_id,
						'error'            => $validation['error'],
					);
					continue;
				}

				$result = $update_step_ability->execute(
					array(
						'flow_step_id'   => $flow_step_id,
						'handler_slug'   => $config['handler_slug'],
						'handler_config' => $config['handler_config'] ?? array(),
					)
				);

				if ( ! $result['success'] ) {
					$errors[] = array(
						'pipeline_step_id' => $pipeline_step_id,
						'error'            => $result['error'] ?? 'Failed to update handler',
					);
					continue;
				}
				$step_applied = true;
			}

			if ( ! empty( $config['user_message'] ) ) {
				$result = $update_step_ability->execute(
					array(
						'flow_step_id' => $flow_step_id,
						'user_message' => $config['user_message'],
					)
				);

				if ( ! $result['success'] ) {
					$errors[] = array(
						'pipeline_step_id' => $pipeline_step_id,
						'error'            => $result['error'] ?? 'Failed to update user_message',
					);
					continue;
				}
				$step_applied = true;
			}

			if ( $step_applied ) {
				$applied[] = $flow_step_id;
			}
		}

		return array(
			'applied' => $applied,
			'errors'  => $errors,
		);
	}

	private function validateSchedulingConfig( array $config ): bool|string {
		if ( empty( $config ) ) {
			return true;
		}

		$interval = $config['interval'] ?? null;

		if ( null === $interval ) {
			return 'scheduling_config requires an interval property';
		}

		$intervals       = array_keys( apply_filters( 'datamachine_scheduler_intervals', array() ) );
		$valid_intervals = array_merge( array( 'manual', 'one_time' ), $intervals );
		if ( ! in_array( $interval, $valid_intervals, true ) ) {
			return 'Invalid interval. Must be one of: ' . implode( ', ', $valid_intervals );
		}

		if ( 'one_time' === $interval ) {
			$timestamp = $config['timestamp'] ?? null;
			if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
				return 'one_time interval requires a valid unix timestamp';
			}
		}

		return true;
	}
}
