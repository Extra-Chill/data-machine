<?php
/**
 * Create Flow Ability
 *
 * Handles flow creation including single mode and bulk mode.
 *
 * @package DataMachine\Abilities\Flow
 * @since 0.15.3
 */

namespace DataMachine\Abilities\Flow;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\Database\BaseRepository;
use DataMachine\Engine\Tasks\RecurringScheduler;

defined( 'ABSPATH' ) || exit;

class CreateFlowAbility {

	use FlowHelpers;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/create-flow',
				array(
					'label'               => __( 'Create Flow', 'data-machine' ),
					'description'         => __( 'Create a new flow for a pipeline. Supports bulk mode via flows array.', 'data-machine' ),
					'category'            => 'datamachine-flow',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'pipeline_id'        => array(
								'type'        => 'integer',
								'description' => __( 'Pipeline ID to create flow for (single mode)', 'data-machine' ),
							),
							'agent_id'           => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Agent ID to scope the flow to. When provided, the flow is owned by this agent.', 'data-machine' ),
							),
							'flow_name'          => array(
								'type'        => 'string',
								'default'     => 'Flow',
								'description' => __( 'Name for the new flow', 'data-machine' ),
							),
							'scheduling_config'  => array(
								'type'        => 'object',
								'description' => __( 'Scheduling configuration with interval property', 'data-machine' ),
								'properties'  => array(
									'interval' => array(
										'type'    => 'string',
										'default' => 'manual',
									),
								),
							),
							'flow_config'        => array(
								'type'        => 'object',
								'description' => __( 'Initial flow configuration', 'data-machine' ),
							),
							'step_configs'       => array(
								'type'        => 'object',
								'description' => __( 'Step configurations keyed by step_type (single mode). If a flow has duplicate step types, include flow_step_id, pipeline_step_id, or execution_order in the config.', 'data-machine' ),
							),
							'flows'              => array(
								'type'        => 'array',
								'description' => __( 'Bulk mode: create multiple flows. Each item: {pipeline_id, flow_name, step_configs?, scheduling_config?}', 'data-machine' ),
							),
							'shared_step_config' => array(
								'type'        => 'object',
								'description' => __( 'Shared step config for bulk mode applied to all flows (keyed by step_type)', 'data-machine' ),
							),
							'validate_only'      => array(
								'type'        => 'boolean',
								'description' => __( 'Dry-run mode: validate without executing', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'flow_id'       => array( 'type' => array( 'integer', 'null' ) ),
							'flow_name'     => array( 'type' => array( 'string', 'null' ) ),
							'pipeline_id'   => array( 'type' => array( 'integer', 'null' ) ),
							'synced_steps'  => array( 'type' => array( 'integer', 'null' ) ),
							'flow_data'     => array( 'type' => array( 'object', 'null' ) ),
							'created_count' => array( 'type' => array( 'integer', 'null' ) ),
							'failed_count'  => array( 'type' => array( 'integer', 'null' ) ),
							'created'       => array( 'type' => array( 'array', 'null' ) ),
							'errors'        => array( 'type' => array( 'array', 'null' ) ),
							'partial'       => array( 'type' => array( 'boolean', 'null' ) ),
							'message'       => array( 'type' => array( 'string', 'null' ) ),
							'error'         => array( 'type' => array( 'string', 'null' ) ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute create flow ability.
	 *
	 * Supports two modes:
	 * - Single mode: Create one flow (pipeline_id required)
	 * - Bulk mode: Create multiple flows (flows array provided)
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flow data on success.
	 */
	public function execute( array $input ): array {
		if ( ! empty( $input['flows'] ) && is_array( $input['flows'] ) ) {
			return $this->executeBulk( $input );
		}

		return $this->executeSingle( $input );
	}

	/**
	 * Execute single flow creation.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with flow data.
	 */
	private function executeSingle( array $input ): array {
		$pipeline_id = $input['pipeline_id'] ?? null;

		if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'pipeline_id is required and must be a positive integer',
			);
		}

		$pipeline_id = (int) $pipeline_id;
		$pipeline    = $this->db_pipelines->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			do_action( 'datamachine_log', 'error', 'Pipeline not found for flow creation', array( 'pipeline_id' => $pipeline_id ) );
			return array(
				'success'    => false,
				'error'      => 'Pipeline not found',
				'error_code' => 'pipeline_not_found',
				'status'     => 404,
			);
		}

		$flow_name = sanitize_text_field( wp_unslash( $input['flow_name'] ?? 'Flow' ) );
		if ( empty( trim( $flow_name ) ) ) {
			$flow_name = 'Flow';
		}

		$agent_id          = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;
		$scheduling_config = $input['scheduling_config'] ?? array( 'interval' => 'manual' );
		$flow_config       = $input['flow_config'] ?? array();
		$step_configs      = $input['step_configs'] ?? array();
		$validate_only     = ! empty( $input['validate_only'] );

		if ( ! is_array( $scheduling_config ) ) {
			return array(
				'success' => false,
				'error'   => 'scheduling_config must be an object',
			);
		}

		if ( ! is_array( $flow_config ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_config must be an object',
			);
		}

		if ( ! is_array( $step_configs ) ) {
			return array(
				'success' => false,
				'error'   => 'step_configs must be an object',
			);
		}

		$step_config_validation = $this->validateCreateStepConfigs( $step_configs );
		if ( true !== $step_config_validation ) {
			return array(
				'success' => false,
				'error'   => $step_config_validation,
			);
		}

		// Resolve the owning agent when the caller did not supply one. Agent-first
		// scoping (#735) makes agent-scoped reads filter `WHERE agent_id = %d`, so a
		// flow persisted with agent_id = NULL is orphaned from every agent-scoped
		// query — invisible to agent-filtered listings/counts and silently dropped
		// from `agent export` / bundle round-trips. datamachine_resolve_agent_id()
		// is the context-agnostic resolver shared by logging: explicit context →
		// PermissionHelper active-agent context (set by AIStep / SystemTaskStep /
		// RunFlowAbility / AgentAuthMiddleware for REST / MCP / chat / pipeline runs)
		// → acting-user → owned-agent fallback. NULL remains a legitimate "unowned /
		// system" state when no agent context can be resolved. See #2481.
		if ( ( null === $agent_id || $agent_id <= 0 ) && function_exists( 'datamachine_resolve_agent_id' ) ) {
			$resolved_agent_id = datamachine_resolve_agent_id();
			if ( null !== $resolved_agent_id && $resolved_agent_id > 0 ) {
				$agent_id = $resolved_agent_id;
			}
		}

		// Validate and resolve interval aliases before storing.
		$interval = $scheduling_config['interval'] ?? 'manual';
		if ( 'manual' !== $interval && function_exists( 'datamachine_validate_interval' ) ) {
			$validation = datamachine_validate_interval( $interval );
			if ( ! $validation['valid'] ) {
				return array(
					'success' => false,
					'error'   => $validation['error'],
				);
			}
			// Use the resolved canonical key (alias → real key).
			$scheduling_config['interval'] = $validation['resolved'];
		}

		// Store the requested scheduling_config immediately so the DB reflects
		// the caller's intent. handle_scheduling_update() is called with
		// $force=true to bypass the unchanged guard and create the AS action.
		$flow_data = array(
			'pipeline_id'       => $pipeline_id,
			'flow_name'         => $flow_name,
			'flow_config'       => $flow_config,
			'scheduling_config' => $scheduling_config,
		);

		if ( null !== $agent_id && $agent_id > 0 ) {
			$flow_data['agent_id'] = $agent_id;
		}

		$transaction_scope = $this->beginCreationTransactionScope();
		if ( null === $transaction_scope ) {
			return array(
				'success' => false,
				'error'   => 'Unable to start flow creation transaction',
			);
		}

		$flow_id = $this->db_flows->create_flow( $flow_data );
		if ( ! $flow_id ) {
			$this->rollbackCreationTransactionScope( $transaction_scope );
			do_action(
				'datamachine_log',
				'error',
				'Failed to create flow',
				array(
					'pipeline_id' => $pipeline_id,
					'flow_name'   => $flow_name,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Failed to create flow',
			);
		}

		$pipeline_config = $pipeline['pipeline_config'] ?? array();
		$synced_steps    = 0;

		if ( ! empty( $pipeline_config ) ) {
			$pipeline_steps = is_array( $pipeline_config ) ? array_values( $pipeline_config ) : array();
			if ( ! $this->syncStepsToFlow( $flow_id, $pipeline_id, $pipeline_steps, $pipeline_config ) ) {
				return $this->rollbackCreation( $transaction_scope, $flow_id, 'Failed to sync pipeline steps to flow' );
			}
			$synced_steps = count( $pipeline_config );
		}

		if ( isset( $scheduling_config['interval'] ) && 'manual' !== $scheduling_config['interval'] ) {
			$scheduling_result = FlowScheduling::handle_scheduling_update( $flow_id, $scheduling_config, true );
			if ( is_wp_error( $scheduling_result ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to schedule flow during creation',
					array(
						'flow_id' => $flow_id,
						'error'   => $scheduling_result->get_error_message(),
					)
				);

				return $this->rollbackCreation( $transaction_scope, $flow_id, $scheduling_result->get_error_message() );
			}
		}

		$config_results = array(
			'applied' => array(),
			'errors'  => array(),
		);
		if ( ! empty( $step_configs ) ) {
			$config_results = $this->applyStepConfigsToFlow( $flow_id, $step_configs );
			if ( ! empty( $config_results['errors'] ) ) {
				$first_error = $config_results['errors'][0];
				$message     = is_array( $first_error ) ? ( $first_error['error'] ?? 'Failed to configure flow step' ) : (string) $first_error;
				return $this->rollbackCreation( $transaction_scope, $flow_id, $message, $config_results['errors'] );
			}
		}

		$configured_step_types = array_keys( $step_configs );
		$defaults_results      = $this->applySiteDefaultsToUnconfiguredSteps( $flow_id, $configured_step_types );

		if ( ! empty( $defaults_results['applied'] ) ) {
			$config_results['applied'] = array_merge( $config_results['applied'], $defaults_results['applied'] );
		}
		if ( ! empty( $defaults_results['errors'] ) ) {
			$first_error = $defaults_results['errors'][0];
			return $this->rollbackCreation(
				$transaction_scope,
				$flow_id,
				$first_error['error'] ?? 'Failed to apply site default configuration',
				$defaults_results['errors']
			);
		}

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return $this->rollbackCreation( $transaction_scope, $flow_id, 'Failed to load created flow' );
		}

		if ( $validate_only ) {
			$this->rollbackCreationTransactionScope( $transaction_scope );
			$schedule_error = $this->compensateFlowSchedule( $flow_id );
			if ( $schedule_error ) {
				return array_merge(
					array( 'success' => false ),
					RecurringScheduler::errorMetadata( $schedule_error )
				);
			}

			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_create' => array(
					array(
						'pipeline_id'        => $pipeline_id,
						'pipeline_name'      => $pipeline['pipeline_name'] ?? '',
						'flow_name'          => $flow_name,
						'scheduling'         => $scheduling_config['interval'] ?? 'manual',
						'step_configs_count' => count( $step_configs ),
					),
				),
				'message'      => 'Validation passed. Would create 1 flow.',
			);
		}

		if ( ! $this->commitCreationTransactionScope( $transaction_scope ) ) {
			return $this->rollbackCreation( $transaction_scope, $flow_id, 'Failed to commit flow creation transaction' );
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow created successfully',
			array(
				'flow_id'      => $flow_id,
				'flow_name'    => $flow_name,
				'pipeline_id'  => $pipeline_id,
				'synced_steps' => $synced_steps,
			)
		);

		$result = array(
			'success'      => true,
			'flow_id'      => $flow_id,
			'flow_name'    => $flow_name,
			'pipeline_id'  => $pipeline_id,
			'flow_data'    => $flow,
			'synced_steps' => $synced_steps,
		);

		if ( ! empty( $config_results['applied'] ) ) {
			$result['configured_steps'] = $config_results['applied'];
		}

		return $result;
	}

	/**
	 * Roll back all writes made while creating a flow.
	 *
	 * @param array  $transaction_scope Transaction or savepoint owned by this call.
	 * @param int    $flow_id Flow ID allocated in the transaction.
	 * @param string $error Error message.
	 * @param array  $configuration_errors Optional structured configuration errors.
	 * @return array Failure result.
	 */
	private function rollbackCreation( array $transaction_scope, int $flow_id, string $error, array $configuration_errors = array() ): array {
		$this->rollbackCreationTransactionScope( $transaction_scope );
		$schedule_error = $this->compensateFlowSchedule( $flow_id );

		$result = array(
			'success' => false,
			'error'   => $error,
		);

		if ( ! empty( $configuration_errors ) ) {
			$result['configuration_errors'] = $configuration_errors;
		}
		if ( $schedule_error ) {
			$result['schedule_cleanup'] = RecurringScheduler::errorMetadata( $schedule_error );
		}

		return $result;
	}

	/**
	 * Begin an owned transaction scope without committing a caller transaction.
	 *
	 * @return array{type:string,name?:string}|null Scope metadata, or null on failure.
	 */
	private function beginCreationTransactionScope(): ?array {
		global $wpdb;

		static $savepoint_sequence = 0;

		$in_transaction = false;
		if ( ! BaseRepository::is_sqlite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$in_transaction = 1 === (int) $wpdb->get_var( 'SELECT @@in_transaction' );
		}

		if ( $in_transaction || BaseRepository::is_sqlite() ) {
			++$savepoint_sequence;
			$name = 'datamachine_create_flow_' . $savepoint_sequence;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "SAVEPOINT {$name}" ) ) {
				return null;
			}

			return array(
				'type' => 'savepoint',
				'name' => $name,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return null;
		}

		return array( 'type' => 'transaction' );
	}

	/**
	 * Commit only the transaction scope opened by this ability call.
	 *
	 * @param array{type:string,name?:string} $scope Transaction scope metadata.
	 */
	private function commitCreationTransactionScope( array $scope ): bool {
		global $wpdb;

		if ( 'savepoint' === $scope['type'] ) {
			$name = $scope['name'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false !== $wpdb->query( "RELEASE SAVEPOINT {$name}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Roll back only the transaction scope opened by this ability call.
	 *
	 * @param array{type:string,name?:string} $scope Transaction scope metadata.
	 */
	private function rollbackCreationTransactionScope( array $scope ): void {
		global $wpdb;

		if ( 'savepoint' === $scope['type'] ) {
			$name = $scope['name'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ROLLBACK TO SAVEPOINT {$name}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "RELEASE SAVEPOINT {$name}" );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Remove any Action Scheduler side effect created for a rolled-back flow.
	 *
	 * @param int $flow_id Flow ID allocated in this scope.
	 */
	private function compensateFlowSchedule( int $flow_id ): ?\WP_Error {
		$result = RecurringScheduler::ensureSchedule( FlowScheduling::FLOW_HOOK, array( $flow_id ), 'manual' );
		return is_wp_error( $result ) ? $result : null;
	}

	/**
	 * Execute bulk flow creation.
	 *
	 * @param array $input Input parameters including flows array and optional shared_step_config.
	 * @return array Result with created flows data and error tracking.
	 */
	private function executeBulk( array $input ): array {
		$flows              = $input['flows'];
		$shared_step_config = $input['shared_step_config'] ?? array();
		$validate_only      = ! empty( $input['validate_only'] );

		if ( ! is_array( $shared_step_config ) ) {
			return array(
				'success' => false,
				'error'   => 'shared_step_config must be an object',
			);
		}

		$validation_errors = array();
		$pipeline_cache    = array();

		foreach ( $flows as $index => $flow_config ) {
			if ( ! is_array( $flow_config ) ) {
				$validation_errors[] = array(
					'index' => $index,
					'error' => 'Each flows entry must be an object',
				);
				continue;
			}

			$pipeline_id = $flow_config['pipeline_id'] ?? null;
			$flow_name   = $flow_config['flow_name'] ?? null;

			if ( ! is_numeric( $pipeline_id ) || (int) $pipeline_id <= 0 ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'pipeline_id is required and must be a positive integer',
					'remediation' => 'Provide a valid pipeline_id for each flow in the flows array',
				);
				continue;
			}

			if ( empty( $flow_name ) || ! is_string( $flow_name ) ) {
				$validation_errors[] = array(
					'index'       => $index,
					'error'       => 'flow_name is required and must be a non-empty string',
					'remediation' => 'Provide a "flow_name" property for each flow in the flows array',
				);
				continue;
			}

			$pipeline_id = (int) $pipeline_id;

			if ( ! isset( $pipeline_cache[ $pipeline_id ] ) ) {
				$pipeline_cache[ $pipeline_id ] = $this->db_pipelines->get_pipeline( $pipeline_id );
			}

			if ( ! $pipeline_cache[ $pipeline_id ] ) {
				$validation_errors[] = array(
					'index'       => $index,
					'flow_name'   => $flow_name,
					'error'       => "Pipeline {$pipeline_id} not found",
					'remediation' => 'Use list_pipelines tool to find valid pipeline IDs',
				);
			}
		}

		if ( ! empty( $validation_errors ) ) {
			return array(
				'success' => false,
				'error'   => 'Validation failed for ' . count( $validation_errors ) . ' flow(s)',
				'errors'  => $validation_errors,
			);
		}

		$preview = array();
		foreach ( $flows as $index => $flow_config ) {
			$pipeline_id       = (int) $flow_config['pipeline_id'];
			$flow_name         = $flow_config['flow_name'];
			$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );
			$step_configs      = $flow_config['step_configs'] ?? array();
			if ( ! is_array( $step_configs ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Validation failed for flow %d: step_configs must be an object', $index ),
				);
			}

			$single_input = array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $flow_name,
				'scheduling_config' => $scheduling_config,
				'step_configs'      => array_merge( $shared_step_config, $step_configs ),
				'validate_only'     => true,
			);
			if ( isset( $flow_config['agent_id'] ) ) {
				$single_input['agent_id'] = $flow_config['agent_id'];
			} elseif ( isset( $input['agent_id'] ) ) {
				$single_input['agent_id'] = $input['agent_id'];
			}

			$single_result = $this->executeSingle( $single_input );
			if ( ! $single_result['success'] ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Validation failed for flow %d: %s', $index, $single_result['error'] ?? 'unknown error' ),
					'errors'  => $single_result['configuration_errors'] ?? array(),
				);
			}

			$preview[] = $single_result['would_create'][0];
		}

		if ( $validate_only ) {
			return array(
				'success'      => true,
				'valid'        => true,
				'mode'         => 'validate_only',
				'would_create' => $preview,
				'message'      => sprintf( 'Validation passed. Would create %d flow(s).', count( $flows ) ),
			);
		}

		$created       = array();
		$errors        = array();
		$created_count = 0;
		$failed_count  = 0;

		foreach ( $flows as $index => $flow_config ) {
			$pipeline_id       = (int) $flow_config['pipeline_id'];
			$flow_name         = $flow_config['flow_name'];
			$scheduling_config = $flow_config['scheduling_config'] ?? array( 'interval' => 'manual' );
			$step_configs      = $flow_config['step_configs'] ?? array();

			$merged_step_configs = array_merge( $shared_step_config, $step_configs );

			$single_result = $this->executeSingle(
				array_filter(
					array(
						'pipeline_id'       => $pipeline_id,
						'flow_name'         => $flow_name,
						'scheduling_config' => $scheduling_config,
						'step_configs'      => $merged_step_configs,
						'agent_id'          => $flow_config['agent_id'] ?? ( $input['agent_id'] ?? null ),
					),
					static fn( $value ) => null !== $value
				)
			);

			if ( ! $single_result['success'] ) {
				++$failed_count;
				$errors[] = array(
					'index'       => $index,
					'pipeline_id' => $pipeline_id,
					'flow_name'   => $flow_name,
					'error'       => $single_result['error'],
					'remediation' => 'Check the error message and fix the flow configuration',
				);
				continue;
			}

			$flow_id       = $single_result['flow_id'];
			$flow_step_ids = array_keys( $single_result['flow_data']['flow_config'] ?? array() );

			++$created_count;
			$created_entry = array(
				'pipeline_id'   => $pipeline_id,
				'flow_id'       => $flow_id,
				'flow_name'     => $single_result['flow_name'],
				'synced_steps'  => $single_result['synced_steps'],
				'flow_step_ids' => $flow_step_ids,
			);

			if ( ! empty( $single_result['configured_steps'] ) ) {
				$created_entry['configured_steps'] = $single_result['configured_steps'];
			}

			$created[] = $created_entry;
		}

		$partial = $created_count > 0 && $failed_count > 0;

		do_action(
			'datamachine_log',
			'info',
			'Bulk flow creation completed',
			array(
				'created_count' => $created_count,
				'failed_count'  => $failed_count,
				'partial'       => $partial,
			)
		);

		if ( 0 === $created_count ) {
			return array(
				'success'       => false,
				'error'         => 'All flow creations failed',
				'created_count' => 0,
				'failed_count'  => $failed_count,
				'errors'        => $errors,
			);
		}

		$message = sprintf( 'Created %d flow(s).', $created_count );
		if ( $failed_count > 0 ) {
			$message .= sprintf( ' %d failed.', $failed_count );
		}

		return array(
			'success'       => true,
			'created_count' => $created_count,
			'failed_count'  => $failed_count,
			'created'       => $created,
			'errors'        => $errors,
			'partial'       => $partial,
			'message'       => $message,
			'creation_mode' => 'bulk',
		);
	}
}
