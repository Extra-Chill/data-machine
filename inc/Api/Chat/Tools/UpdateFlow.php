<?php
/**
 * Update Flow Tool
 *
 * Tool for updating flow-level properties including title and scheduling configuration.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class UpdateFlow extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_flow', array( $this, 'getToolDefinition' ), array( 'chat' ) );
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
			'description' => 'Update flow title and/or scheduling.',
			'parameters'  => array(
				'flow_id'           => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Flow ID',
				),
				'flow_name'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New flow title',
				),
				'scheduling_config' => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Schedule: {interval: value}. Valid intervals:' . "\n" . SchedulingDocumentation::getIntervalsJson(),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$flow_id = $parameters['flow_id'] ?? null;

		if ( ! is_numeric( $flow_id ) || (int) $flow_id <= 0 ) {
			return array(
				'success'   => false,
				'error'     => 'flow_id is required and must be a positive integer',
				'tool_name' => 'update_flow',
			);
		}

		$flow_id           = (int) $flow_id;
		$flow_name         = $parameters['flow_name'] ?? null;
		$scheduling_config = $parameters['scheduling_config'] ?? null;

		if ( empty( $flow_name ) && empty( $scheduling_config ) ) {
			return array(
				'success'   => false,
				'error'     => 'At least one of flow_name or scheduling_config is required',
				'tool_name' => 'update_flow',
			);
		}

		if ( ! empty( $scheduling_config ) ) {
			$validation = $this->validateSchedulingConfig( $scheduling_config );
			if ( true !== $validation ) {
				return array(
					'success'   => false,
					'error'     => $validation,
					'tool_name' => 'update_flow',
				);
			}
		}

		$ability = wp_get_ability( 'datamachine/update-flow' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'Update flow ability not available',
				'tool_name' => 'update_flow',
			);
		}

		$input = array( 'flow_id' => $flow_id );
		if ( ! empty( $flow_name ) ) {
			$input['flow_name'] = $flow_name;
		}
		if ( ! empty( $scheduling_config ) ) {
			$input['scheduling_config'] = $scheduling_config;
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, 'Failed to update flow' );
			return $this->buildErrorResponse( $error, 'update_flow' );
		}

		$response_data = array(
			'flow_id' => $flow_id,
			'message' => 'Flow updated successfully.',
		);

		if ( ! empty( $flow_name ) ) {
			$response_data['flow_name'] = $result['flow_name'] ?? $flow_name;
		}
		if ( ! empty( $scheduling_config ) ) {
			$response_data['scheduling']        = $scheduling_config['interval'];
			$response_data['scheduling_config'] = $scheduling_config;
		}

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'update_flow',
		);
	}

	private function validateSchedulingConfig( array $config ): bool|string {
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
