<?php
/**
 * Headless agent bundle runner.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Abilities\Job\ExecuteWorkflowAbility;
use DataMachine\Abilities\Engine\DrainJobAbility;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DataPath;
use DataMachine\Core\JobStatus;
use DataMachine\Engine\AI\Tools\HostToolPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a portable bundle flow into Data Machine's ephemeral workflow runner.
 */
final class AgentBundleRunner {

	private AgentBundler $bundler;

	public function __construct( ?AgentBundler $bundler = null ) {
		$this->bundler = $bundler ?? new AgentBundler();
	}

	/** @return array<string,mixed> */
	public function run( array $input ): array {
		$runtime_imports = $this->import_runtime_bundles( $input );
		if ( ! empty( $runtime_imports['required'] ) && empty( $runtime_imports['success'] ) ) {
			return $this->response(
				array(
					'success' => false,
					'error'   => (string) ( $runtime_imports['error'] ?? 'Runtime bundle import failed.' ),
				),
				$input,
				$runtime_imports
			);
		}

		$source = trim( (string) ( $input['source'] ?? $this->first_runtime_bundle_source( $input ) ) );
		if ( '' === $source ) {
			return $this->response(
				array(
					'success' => false,
					'error'   => 'Bundle source is required.',
				),
				$input,
				$runtime_imports
			);
		}

		$loaded = $this->load_bundle( $source, $input );
		if ( empty( $loaded['success'] ) ) {
			return $this->response( $loaded, $input, $runtime_imports );
		}

		$bundle    = $loaded['bundle'];
		$directory = AgentBundleArrayAdapter::from_array_bundle( $bundle );
		$selection = $this->select_flow( $directory, (string) ( $input['flow'] ?? $input['flow_slug'] ?? '' ) );
		if ( empty( $selection['success'] ) ) {
			return $this->response( $selection, $input, $runtime_imports );
		}

		$workflow_override = $this->workflow_override_from_input( $input );
		if ( empty( $workflow_override['success'] ) ) {
			return $this->response( $workflow_override, $input, $runtime_imports, $selection );
		}

		$workflow     = is_array( $workflow_override['workflow'] ?? null ) ? $workflow_override['workflow'] : $this->workflow_from_bundle_flow( $selection['flow'], $selection['pipeline'], $input );
		$initial_data = is_array( $workflow_override['initial_data'] ?? null ) ? $workflow_override['initial_data'] : array();
		$initial_data = array_merge( $initial_data, is_array( $input['initial_data'] ?? null ) ? $input['initial_data'] : array() );
		$manifest     = $directory->manifest()->to_array();

		$initial_data['agent_bundle'] = array(
			'bundle_slug'     => (string) ( $manifest['bundle_slug'] ?? '' ),
			'bundle_version'  => (string) ( $manifest['bundle_version'] ?? '' ),
			'source_ref'      => (string) ( $manifest['source_ref'] ?? $source ),
			'source_revision' => (string) ( $manifest['source_revision'] ?? ( $loaded['source_revision'] ?? '' ) ),
			'flow_slug'       => (string) ( $selection['flow_slug'] ?? '' ),
			'pipeline_slug'   => (string) ( $selection['pipeline_slug'] ?? '' ),
		);
		$initial_data['agent_slug']   = (string) ( $manifest['agent']['slug'] ?? '' );
		$initial_data['job_source']   = (string) ( $input['job_source'] ?? 'agent_bundle' );
		$initial_data['job_label']    = (string) ( $input['job_label'] ?? ( $selection['flow_name'] ?? 'Agent Bundle Workflow' ) );
		$this->apply_runtime_ability_tools( $initial_data, $input, $bundle );
		$this->apply_runtime_host_tool_policy( $initial_data, $input );
		$this->apply_runtime_model_config( $initial_data, $input );

		if ( ! empty( $input['dry_run'] ) ) {
			return $this->response(
				array(
					'success'      => true,
					'schema'       => 'datamachine/agent-bundle-run/v1',
					'dry_run'      => true,
					'bundle'       => $initial_data['agent_bundle'],
					'workflow'     => $workflow,
					'initial_data' => $initial_data,
				),
				$input,
				$runtime_imports,
				$selection
			);
		}

		$identity = $this->ensure_runtime_agent_identity( $bundle, $input );
		if ( empty( $identity['success'] ) ) {
			return $this->response( $identity, $input, $runtime_imports, $selection );
		}
		$this->stamp_runtime_agent_identity( $initial_data, $identity );

		$response = $this->with_runtime_controls(
			$input,
			function () use ( $workflow, $initial_data, $input ): array {
				$result = ( new ExecuteWorkflowAbility() )->execute(
					array(
						'workflow'     => $workflow,
						'initial_data' => $initial_data,
						'timestamp'    => $input['timestamp'] ?? null,
					)
				);

				$response = array_merge(
					array(
						'schema'  => 'datamachine/agent-bundle-run/v1',
						'dry_run' => false,
						'bundle'  => $initial_data['agent_bundle'],
					),
					$result
				);

				if ( ! empty( $input['wait_for_completion'] ) || ! empty( $input['wait'] ) ) {
					$response = $this->wait_for_completion( $response, $input );
				}

				return $response;
			}
		);

		return $this->response( $response, $input, $runtime_imports, $selection );
	}

	/** @return array<string,mixed> */
	private function response( array $response, array $input, array $runtime_imports = array(), array $selection = array() ): array {
		$response['schema'] ??= 'datamachine/agent-bundle-run/v1';
		$response           = $this->apply_wait_result_status( $response );

		if ( ! empty( $runtime_imports ) ) {
			$response['runtime_imports'] = $runtime_imports;
		}

		$response['diagnostics'] = self::compact_response_array(
			array(
				'contract'              => 'datamachine/run-agent-bundle',
				'runtime_bundle_count'  => count( $this->runtime_bundle_specs( $input ) ),
				'runtime_import_status' => (string) ( $runtime_imports['status'] ?? '' ),
				'flow_slug'             => (string) ( $selection['flow_slug'] ?? '' ),
				'pipeline_slug'         => (string) ( $selection['pipeline_slug'] ?? '' ),
				'wait_for_completion'   => ! empty( $response['wait_for_completion'] ),
			)
		);

		$response['completion_outcome'] = $this->completion_outcome( $response );
		$response['transcript_refs']    = $this->transcript_refs( $response );
		$response['export_refs']        = $this->export_refs( $response );
		$output_projection              = $this->output_projection( $response, $input );
		$response['outputs']            = $output_projection['outputs'];
		$response['output_diagnostics'] = $output_projection['diagnostics'];
		$response                       = $this->enforce_required_outputs( $response, $input );
		$response['status']             = $this->status_from_response( $response );
		$response['success']            = ! empty( $response['success'] ) && self::is_success_status( $response['status'] );
		$response['completion_outcome'] = $this->completion_outcome( $response );

		return $response;
	}

	/** @return array<string,mixed> */
	private function apply_wait_result_status( array $response ): array {
		if ( empty( $response['wait_for_completion'] ) || ! is_array( $response['wait_result'] ?? null ) ) {
			return $response;
		}

		$wait_result = $response['wait_result'];
		if ( ! empty( $wait_result['success'] ) ) {
			return $response;
		}

		$response['success'] = false;
		$response['error'] ??= (string) ( $wait_result['error'] ?? 'Agent bundle run did not reach a terminal job state before the wait budget was exhausted.' );
		if ( empty( $response['error_type'] ) ) {
			$response['error_type'] = (string) ( $wait_result['error_type'] ?? 'wait_incomplete' );
		}

		return $response;
	}

	/**
	 * Project explicit headless model config into the job snapshot.
	 *
	 * @param array<string,mixed> $initial_data Initial workflow engine data.
	 * @param array<string,mixed> $input Bundle run input.
	 */
	private function apply_runtime_model_config( array &$initial_data, array $input ): void {
		$provider = sanitize_text_field( (string) ( $input['provider'] ?? '' ) );
		$model    = sanitize_text_field( (string) ( $input['model'] ?? '' ) );
		if ( '' === $provider || '' === $model ) {
			return;
		}

		$job_snapshot = is_array( $initial_data['job'] ?? null ) ? $initial_data['job'] : array();
		$mode_models  = is_array( $job_snapshot['mode_models'] ?? null ) ? $job_snapshot['mode_models'] : array();

		$job_snapshot['default_provider']         = $provider;
		$job_snapshot['default_model']            = $model;
		$mode_models['pipeline']                  = array(
			'provider' => $provider,
			'model'    => $model,
		);
		$job_snapshot['mode_models']              = $mode_models;
		$initial_data['job']                      = $job_snapshot;
		$initial_data['agent_bundle']['provider'] = $provider;
		$initial_data['agent_bundle']['model']    = $model;
	}

	/**
	 * Project run-scoped ability tool declarations into the job snapshot.
	 *
	 * @param array<string,mixed> $initial_data Initial workflow engine data.
	 * @param array<string,mixed> $input Bundle run input.
	 * @param array<string,mixed> $bundle Loaded bundle document.
	 */
	private function apply_runtime_ability_tools( array &$initial_data, array $input, array $bundle = array() ): void {
		$ability_tools = is_array( $input['ability_tools'] ?? null ) ? $input['ability_tools'] : array();
		if ( empty( $ability_tools ) ) {
			$metadata_tools = DataPath::value( $bundle, 'metadata.agent_runtime.ability_tools' );
			$ability_tools  = is_array( $metadata_tools ) ? $metadata_tools : array();
		}
		if ( empty( $ability_tools ) ) {
			return;
		}

		$job_snapshot                  = is_array( $initial_data['job'] ?? null ) ? $initial_data['job'] : array();
		$job_snapshot['ability_tools'] = $ability_tools;
		$initial_data['job']           = $job_snapshot;
	}

	/**
	 * Project the active host tool policy into the durable job snapshot.
	 *
	 * Agent bundle runs can outlive the launching PHP process, so environment-only
	 * host policy must be captured before queued AI steps resolve tools.
	 *
	 * @param array<string,mixed> $initial_data Initial workflow engine data.
	 * @param array<string,mixed> $input Bundle run input.
	 */
	private function apply_runtime_host_tool_policy( array &$initial_data, array $input ): void {
		$policy = null;
		foreach ( array( 'host_tool_policy', 'external_tool_ownership_policy' ) as $key ) {
			if ( is_array( $input[ $key ] ?? null ) ) {
				$policy = $input[ $key ];
				break;
			}
		}

		if ( null === $policy ) {
			$policy = HostToolPolicy::environmentSnapshot();
		}

		if ( empty( $policy ) || ! is_array( $policy ) ) {
			return;
		}

		$job_snapshot                     = is_array( $initial_data['job'] ?? null ) ? $initial_data['job'] : array();
		$job_snapshot['host_tool_policy'] = $policy;
		$initial_data['job']              = $job_snapshot;
	}

	private function status_from_response( array $response ): string {
		if ( empty( $response['success'] ) ) {
			return 'failed';
		}

		$job_status = (string) ( $response['job_status'] ?? '' );
		if ( '' !== $job_status ) {
			return $job_status;
		}

		return ! empty( $response['dry_run'] ) ? 'dry_run' : 'scheduled';
	}

	/** @return array<string,mixed> */
	private function completion_outcome( array $response ): array {
		$engine_data = is_array( $response['engine_data'] ?? null ) ? $response['engine_data'] : array();
		$status      = (string) ( $response['status'] ?? '' );

		return self::compact_response_array(
			array(
				'status'      => $status,
				'completed'   => 'completed' === $status,
				'success'     => ! empty( $response['success'] ) && self::is_success_status( $status ),
				'error'       => (string) ( $response['error'] ?? $engine_data['error_message'] ?? '' ),
				'token_usage' => is_array( $engine_data['token_usage'] ?? null ) ? $engine_data['token_usage'] : null,
			)
		);
	}

	private static function is_success_status( string $status ): bool {
		$status = trim( $status );
		if ( JobStatus::isStatusSuccess( $status ) ) {
			return true;
		}

		if ( JobStatus::isStatusFailure( $status ) ) {
			return false;
		}

		foreach ( array( 'cancelled', 'timed_out', 'timeout' ) as $failure_status ) {
			if ( $failure_status === $status || str_starts_with( $status, $failure_status . ' - ' ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string,mixed> */
	private static function compact_response_array( array $values ): array {
		return DataPath::filterPresent( $values );
	}

	/** @return array<string,mixed> */
	private function transcript_refs( array $response ): array {
		$engine_data = is_array( $response['engine_data'] ?? null ) ? $response['engine_data'] : array();
		$session_id  = (string) ( $engine_data['transcript_session_id'] ?? '' );

		return array_filter(
			array(
				'session_id' => $session_id,
			)
		);
	}

	/** @return array<string,mixed> */
	private function export_refs( array $response ): array {
		$engine_data = is_array( $response['engine_data'] ?? null ) ? $response['engine_data'] : array();

		return array_filter(
			array(
				'job_artifacts' => is_array( $engine_data['job_artifacts'] ?? null ) ? $engine_data['job_artifacts'] : null,
				'exports'       => is_array( $engine_data['exports'] ?? null ) ? $engine_data['exports'] : null,
			)
		);
	}

	/** @return array{outputs:array<string,mixed>,diagnostics:array<string,mixed>} */
	private function output_projection( array $response, array $input = array() ): array {
		$engine_data = is_array( $response['engine_data'] ?? null ) ? $response['engine_data'] : array();
		$mappings    = $this->declared_output_mappings( $input );
		$required    = $this->required_output_keys( $engine_data, $input );
		$artifacts   = $this->required_artifact_keys( $input, $engine_data );
		$declared    = array_values( array_unique( array_merge( $required, $artifacts, array_keys( $mappings ) ) ) );
		$outputs     = array();

		foreach ( $mappings as $key => $path ) {
			$value = $this->mapped_output_value( $response, $path );
			if ( DataPath::hasValue( $value ) ) {
				$outputs[ $key ] = $value;
			}
		}

		foreach ( $declared as $key ) {
			if ( ! isset( $outputs[ $key ] ) && DataPath::hasValue( $engine_data[ $key ] ?? null ) ) {
				$outputs[ $key ] = $engine_data[ $key ];
			}
		}

		if ( is_array( $engine_data['outputs'] ?? null ) ) {
			foreach ( $engine_data['outputs'] as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key && DataPath::hasValue( $value ) ) {
					$outputs[ $key ] = $value;
				}
			}

			$typed_artifacts = is_array( $engine_data['outputs']['typed_artifacts'] ?? null ) ? $engine_data['outputs']['typed_artifacts'] : array();
			foreach ( $typed_artifacts as $key => $artifact_output ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key && is_array( $artifact_output ) && DataPath::hasValue( $artifact_output['payload'] ?? null ) ) {
					$outputs[ $key ] = $artifact_output['payload'];
				}
			}
		}

		foreach ( $engine_data as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $outputs[ $key ] ) && $this->is_common_output_key( $key ) && DataPath::hasValue( $value ) ) {
				$outputs[ $key ] = $value;
			}
		}

		$present = array_keys( $outputs );
		$missing = array_values( array_diff( $declared, $present ) );

		return array(
			'outputs'     => $outputs,
			'diagnostics' => self::compact_response_array(
				array(
					'declared_outputs'   => $declared,
					'required_outputs'   => $required,
					'required_artifacts' => $artifacts,
					'present_outputs'    => $present,
					'missing_outputs'    => $missing,
				)
			),
		);
	}

	/** @return array<string,mixed> */
	private function enforce_required_outputs( array $response, array $input ): array {
		$diagnostics = is_array( $response['output_diagnostics'] ?? null ) ? $response['output_diagnostics'] : array();
		$required    = array_values(
			array_unique(
				array_merge(
					is_array( $diagnostics['required_outputs'] ?? null ) ? $diagnostics['required_outputs'] : array(),
					is_array( $diagnostics['required_artifacts'] ?? null ) ? $diagnostics['required_artifacts'] : array()
				)
			)
		);
		if ( empty( $required ) || empty( $response['success'] ) || ! $this->can_enforce_required_outputs( $response, $input ) ) {
			return $response;
		}

		$outputs = is_array( $response['outputs'] ?? null ) ? $response['outputs'] : array();
		$missing = array_values(
			array_filter(
				$required,
				function ( string $key ) use ( $outputs ): bool {
					return ! array_key_exists( $key, $outputs ) || ! DataPath::hasValue( $outputs[ $key ] );
				}
			)
		);
		if ( empty( $missing ) ) {
			return $response;
		}

		$response['success']            = false;
		$response['error']              = sprintf( 'Agent bundle run completed without required semantic outputs: %s.', implode( ', ', $missing ) );
		$response['output_diagnostics'] = array_merge(
			$diagnostics,
			array(
				'enforcement'      => 'failed_missing_required_outputs',
				'missing_required' => $missing,
			)
		);

		return $response;
	}

	private function can_enforce_required_outputs( array $response, array $input ): bool {
		if ( ! empty( $response['dry_run'] ) ) {
			return false;
		}

		if ( ! empty( $response['wait_for_completion'] ) || ! empty( $input['wait_for_completion'] ) || ! empty( $input['wait'] ) ) {
			return true;
		}

		$status = (string) ( $response['status'] ?? $response['job_status'] ?? '' );
		return '' !== $status && 'scheduled' !== $status && 'pending' !== $status;
	}

	/** @return array<string,string> */
	private function declared_output_mappings( array $input ): array {
		$mappings = is_array( $input['engine_data_outputs'] ?? null ) ? $input['engine_data_outputs'] : array();
		$outputs  = array();

		foreach ( $mappings as $key => $path ) {
			$key  = sanitize_key( (string) $key );
			$path = is_scalar( $path ) ? trim( (string) $path ) : '';
			if ( '' !== $key && '' !== $path ) {
				$outputs[ $key ] = $path;
			}
		}

		return $outputs;
	}

	private function mapped_output_value( array $response, string $path ): mixed {
		$engine_data = is_array( $response['engine_data'] ?? null ) ? $response['engine_data'] : array();
		$source      = array(
			'metadata'    => array( 'engine_data' => $engine_data ),
			'engine_data' => $engine_data,
			'response'    => $response,
		);

		return DataPath::value( $source, $path );
	}

	/** @return array<int,string> */
	private function required_output_keys( array $engine_data, array $input = array() ): array {
		$keys = array();
		foreach ( array( 'completion_assertions_required' ) as $group ) {
			$assertions = is_array( $engine_data[ $group ] ?? null ) ? $engine_data[ $group ] : array();
			if ( is_array( $assertions['engine_data_keys'] ?? null ) ) {
				$keys = array_merge( $keys, $assertions['engine_data_keys'] );
			}
		}
		$keys = array_merge( $keys, $this->normalize_required_key_list( $input['required_outputs'] ?? array() ) );

		$missing = is_array( $engine_data['completion_assertions_missing'] ?? null ) ? $engine_data['completion_assertions_missing'] : array();
		if ( is_array( $missing['engine_data_keys'] ?? null ) ) {
			$keys = array_merge( $keys, $missing['engine_data_keys'] );
		}

		$keys = array_map( static fn( $key ): string => sanitize_key( (string) $key ), $keys );
		$keys = array_filter( $keys, static fn( string $key ): bool => '' !== $key );

		return array_values( array_unique( $keys ) );
	}

	/** @return array<int,string> */
	private function required_artifact_keys( array $input, array $engine_data = array() ): array {
		$keys = $this->normalize_required_key_list( $input['required_artifacts'] ?? array() );
		$keys = array_merge( $keys, $this->required_artifact_output_keys_from_engine_data( $engine_data ) );
		return array_values( array_unique( $keys ) );
	}

	/** @return array<int,string> */
	private function required_artifact_output_keys_from_engine_data( array $engine_data ): array {
		$assertions = is_array( $engine_data['completion_assertions_required'] ?? null ) ? $engine_data['completion_assertions_required'] : array();
		$outputs    = is_array( $assertions['artifact_outputs'] ?? null ) ? $assertions['artifact_outputs'] : array();
		$keys       = array();
		foreach ( $outputs as $output ) {
			if ( is_array( $output ) ) {
				$keys[] = $output['output_key'] ?? '';
			} elseif ( is_scalar( $output ) ) {
				$keys[] = (string) $output;
			}
		}

		return $this->normalize_required_key_list( $keys );
	}

	/** @return array<int,string> */
	private function normalize_required_key_list( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();
		foreach ( $raw as $key => $value ) {
			$candidate = is_string( $key ) ? $key : ( is_scalar( $value ) ? (string) $value : '' );
			$candidate = sanitize_key( $candidate );
			if ( '' !== $candidate ) {
				$keys[] = $candidate;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	private function is_common_output_key( string $key ): bool {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		$internal_keys = array(
			'action_id',
			'agent_id',
			'agent_slug',
			'flow_id',
			'flow_step_id',
			'job_id',
			'parent_job_id',
			'pipeline_id',
			'pipeline_step_id',
			'post_id',
			'user_id',
		);
		if ( in_array( $key, $internal_keys, true ) ) {
			return false;
		}

		return 1 === preg_match( '/(^|_)(id|ids|number|path|paths|slug|title|url|urls)$/', $key );
	}

	/** @return array<string,mixed> */
	private function wait_for_completion( array $response, array $input ): array {
		$job_id = (int) ( $response['job_id'] ?? 0 );
		if ( $job_id <= 0 || empty( $response['success'] ) ) {
			return $response;
		}

		if ( 'delayed' === (string) ( $response['execution_type'] ?? '' ) ) {
			$response['wait_result'] = array(
				'success' => false,
				'error'   => 'Cannot wait for delayed agent bundle runs.',
			);
			return $response;
		}

		$drain_result = ( new DrainJobAbility() )->execute(
			array(
				'job_id'         => $job_id,
				'step_budget'    => max( 1, (int) ( $input['step_budget'] ?? 50 ) ),
				'time_budget_ms' => max( 1, (int) ( $input['time_budget_ms'] ?? 300000 ) ),
			)
		);

		$job         = ( new Jobs() )->get_job( $job_id );
		$job_status  = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
		$engine_data = function_exists( 'datamachine_get_engine_data' ) ? datamachine_get_engine_data( $job_id ) : array();

		$response['wait_for_completion'] = true;
		$response['wait_result']         = $drain_result;
		$response['job_status']          = $job_status;
		$response['engine_data']         = $engine_data;

		return $response;
	}

	/** @return array<string,mixed> */
	private function import_runtime_bundles( array $input ): array {
		$bundle_specs = $this->runtime_bundle_specs( $input );
		if ( empty( $bundle_specs ) ) {
			return array( 'status' => 'not_requested' );
		}

		$import_input = is_array( $input['runtime_import'] ?? null ) ? $input['runtime_import'] : array();
		$owner_id     = get_current_user_id();
		$import_input = array_merge(
			array(
				'owner_id' => 0 !== $owner_id ? $owner_id : 1,
			),
			$import_input
		);

		if ( ! function_exists( '\wp_agent_import_runtime_bundles' ) ) {
			return array(
				'required' => true,
				'success'  => false,
				'status'   => 'unavailable',
				'imports'  => array(),
				'failed'   => array(),
				'error'    => 'The generic runtime bundle importer is unavailable.',
			);
		}

		$imports = \wp_agent_import_runtime_bundles( $bundle_specs, $import_input );

		$failed = array_values(
			array_filter(
				is_array( $imports ) ? $imports : array(),
				static fn( $import ): bool => ! is_array( $import ) || empty( $import['success'] )
			)
		);

		return array(
			'required' => true,
			'success'  => empty( $failed ),
			'status'   => empty( $failed ) ? 'imported' : 'failed',
			'imports'  => is_array( $imports ) ? $imports : array(),
			'failed'   => $failed,
			'error'    => empty( $failed ) ? '' : 'One or more runtime agent bundles failed to import.',
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function runtime_bundle_specs( array $input ): array {
		$specs = $input['runtime_bundles'] ?? $input['agent_bundles'] ?? array();
		if ( is_array( $input['runtime_bundle'] ?? null ) ) {
			$specs = array( $input['runtime_bundle'] );
		}

		return array_values( array_filter( is_array( $specs ) ? $specs : array(), 'is_array' ) );
	}

	/** @return array<string,mixed> */
	private function ensure_runtime_agent_identity( array $bundle, array $input ): array {
		$slug = sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		if ( '' === $slug ) {
			return array(
				'success' => false,
				'error'   => 'Bundle agent slug is required for runtime execution.',
			);
		}

		try {
			$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $slug );
			return array_merge( array( 'success' => true ), $identity->to_array() );
		} catch ( \InvalidArgumentException $e ) {
			// A directory-backed runtime bundle can be valid without already
			// being installed on the disposable site. Import it to give queued
			// AI execution a real agent owner while still running the loaded
			// bundle workflow from source.
			unset( $e );
		}

		$owner_id = $this->runtime_import_owner_id( $input );
		$result   = $this->bundler->import(
			$bundle,
			null,
			$owner_id,
			false,
			array(
				'is_upgrade'        => true,
				'reconcile_runtime' => true,
			)
		);
		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $result['error'] ?? 'Failed to import runtime bundle agent.' ),
				'import'  => $result,
			);
		}

		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		return array(
			'success'    => true,
			'agent_id'   => (int) ( $summary['agent_id'] ?? 0 ),
			'agent_slug' => (string) ( $summary['agent_slug'] ?? $slug ),
			'owner_id'   => (int) ( $summary['owner_id'] ?? $owner_id ),
			'import'     => $result,
		);
	}

	private function runtime_import_owner_id( array $input ): int {
		$runtime_import = is_array( $input['runtime_import'] ?? null ) ? $input['runtime_import'] : array();
		$owner_id       = (int) ( $runtime_import['owner_id'] ?? $input['owner_id'] ?? 0 );
		if ( $owner_id > 0 ) {
			return $owner_id;
		}

		$current_user_id = get_current_user_id();
		return $current_user_id > 0 ? $current_user_id : 1;
	}

	private function stamp_runtime_agent_identity( array &$initial_data, array $identity ): void {
		$agent_id   = (int) ( $identity['agent_id'] ?? 0 );
		$agent_slug = (string) ( $identity['agent_slug'] ?? '' );
		$owner_id   = (int) ( $identity['owner_id'] ?? 0 );

		if ( $agent_id > 0 ) {
			$initial_data['agent_id'] = $agent_id;
		}
		if ( '' !== $agent_slug ) {
			$initial_data['agent_slug'] = $agent_slug;
		}
		if ( $owner_id > 0 ) {
			$initial_data['user_id'] = $owner_id;
		}

		$job_snapshot = is_array( $initial_data['job'] ?? null ) ? $initial_data['job'] : array();
		if ( $agent_id > 0 ) {
			$job_snapshot['agent_id'] = $agent_id;
		}
		if ( '' !== $agent_slug ) {
			$job_snapshot['agent_slug'] = $agent_slug;
		}
		if ( $owner_id > 0 ) {
			$job_snapshot['user_id'] = $owner_id;
		}
		$initial_data['job'] = $job_snapshot;
	}

	private function first_runtime_bundle_source( array $input ): string {
		foreach ( $this->runtime_bundle_specs( $input ) as $spec ) {
			$source = trim( (string) ( $spec['source'] ?? '' ) );
			if ( '' !== $source ) {
				return $source;
			}
		}

		return '';
	}

	/** @param callable():array<string,mixed> $callback */
	private function with_runtime_controls( array $input, callable $callback ): array {
		$filters = array();

		if ( ! empty( $input['disable_datamachine_directives'] ) || ! empty( $input['disable_directives'] ) ) {
			$disable_directives = static function ( $value = null, $context = null, $input = null ): bool {
				unset( $value, $context, $input );
				return false;
			};
			$filters[]          = array( 'datamachine_directives_enabled', $disable_directives, 100 );
			add_filter( 'datamachine_directives_enabled', $disable_directives, 100, 3 );
		}

		$runtime_tools = is_array( $input['runtime_tools'] ?? null ) ? $input['runtime_tools'] : ( is_array( $input['tools'] ?? null ) ? $input['tools'] : array() );
		if ( ! empty( $runtime_tools ) ) {
			$tool_filter = static function ( array $tools ) use ( $runtime_tools ): array {
				foreach ( $runtime_tools as $name => $definition ) {
					if ( ! is_array( $definition ) ) {
						continue;
					}

					$tool_name = is_string( $name ) ? $name : (string) ( $definition['name'] ?? $definition['tool'] ?? '' );
					if ( '' !== $tool_name ) {
						$tools[ $tool_name ] = $definition;
					}
				}

				return $tools;
			};
			$filters[]   = array( 'datamachine_resolved_tools', $tool_filter, 100 );
			add_filter( 'datamachine_resolved_tools', $tool_filter, 100, 1 );
		}

		try {
			return $callback();
		} finally {
			foreach ( $filters as $filter ) {
				remove_filter( $filter[0], $filter[1], $filter[2] );
			}
		}
	}

	/** @return array<string,mixed> */
	private function load_bundle( string $source, array $input ): array {
		$resolved = BundleSource::resolve( $source, $this->resolve_context( $input ) );
		if ( is_wp_error( $resolved ) ) {
			return array(
				'success' => false,
				'error'   => $resolved->get_error_message(),
			);
		}

		$revision = BundleSource::is_remote( $source ) ? BundleSource::last_resolved_revision() : null;
		$bundle   = null;
		try {
			if ( is_dir( $resolved ) ) {
				$bundle = $this->bundler->from_directory( $resolved );
			} elseif ( preg_match( '/\.zip$/i', $resolved ) ) {
				$bundle = $this->bundler->from_zip( $resolved );
			} elseif ( preg_match( '/\.json$/i', $resolved ) ) {
				$bundle = $this->bundler->from_json( (string) file_get_contents( $resolved ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} catch ( BundleValidationException $e ) {
			BundleSource::cleanup( $resolved, $source );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		BundleSource::cleanup( $resolved, $source );
		if ( ! is_array( $bundle ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse bundle. Use a bundle directory, .json file, or .zip archive.',
			);
		}
		if ( BundleSource::is_remote( $source ) && empty( $bundle['source_ref'] ) ) {
			$bundle['source_ref'] = $source;
		}
		if ( null !== $revision && empty( $bundle['source_revision'] ) ) {
			$bundle['source_revision'] = $revision;
		}

		return array(
			'success'         => true,
			'bundle'          => $bundle,
			'source_revision' => $revision,
		);
	}

	/** @return array<string,mixed> */
	private function workflow_override_from_input( array $input ): array {
		$workflow = null;
		if ( isset( $input['execute_workflow'] ) ) {
			$workflow = $input['execute_workflow'];
		} elseif ( isset( $input['execute_workflow_path'] ) ) {
			$path = trim( (string) $input['execute_workflow_path'] );
			if ( '' === $path || ! is_file( $path ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Workflow override path not found: %s', $path ),
				);
			}

			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $raw ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Workflow override path is not readable: %s', $path ),
				);
			}

			$workflow = json_decode( $raw, true );
			if ( ! is_array( $workflow ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Workflow override path does not contain valid JSON: %s', $path ),
				);
			}
		}

		if ( null === $workflow ) {
			return array( 'success' => true );
		}

		$initial_data = is_array( $workflow['initial_data'] ?? null ) ? $workflow['initial_data'] : array();
		if ( is_array( $workflow['workflow'] ?? null ) ) {
			$workflow = $workflow['workflow'];
		}

		if ( ! is_array( $workflow ) || ! is_array( $workflow['steps'] ?? null ) ) {
			return array(
				'success' => false,
				'error'   => 'Workflow override must contain a workflow steps array.',
			);
		}

		return array(
			'success'      => true,
			'workflow'     => $workflow,
			'initial_data' => $initial_data,
		);
	}

	/** @return array<string,mixed> */
	private function resolve_context( array $input ): array {
		$token     = isset( $input['token'] ) ? (string) $input['token'] : null;
		$token_env = isset( $input['token_env'] ) ? (string) $input['token_env'] : null;

		return BundleSourceAuth::build_resolve_context( $token, $token_env );
	}

	/** @return array<string,mixed> */
	private function select_flow( AgentBundleDirectory $directory, string $requested_slug ): array {
		$flows = $directory->flows();
		if ( empty( $flows ) ) {
			return array(
				'success' => false,
				'error'   => 'Bundle does not contain any flows to run.',
			);
		}

		$requested_slug = '' !== $requested_slug ? PortableSlug::normalize( $requested_slug, 'flow' ) : '';
		$flow           = $flows[0];
		if ( '' !== $requested_slug ) {
			$flow = null;
			foreach ( $flows as $candidate ) {
				$data = $candidate->to_array();
				if ( (string) ( $data['slug'] ?? '' ) === $requested_slug ) {
					$flow = $candidate;
					break;
				}
			}
			if ( null === $flow ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Bundle flow "%s" not found.', $requested_slug ),
				);
			}
		}

		$flow_data     = $flow->to_array();
		$pipeline_slug = (string) ( $flow_data['pipeline_slug'] ?? '' );
		foreach ( $directory->pipelines() as $pipeline ) {
			$pipeline_data = $pipeline->to_array();
			if ( (string) ( $pipeline_data['slug'] ?? '' ) === $pipeline_slug ) {
				return array(
					'success'       => true,
					'flow'          => $flow_data,
					'flow_slug'     => (string) ( $flow_data['slug'] ?? '' ),
					'flow_name'     => (string) ( $flow_data['name'] ?? '' ),
					'pipeline'      => $pipeline_data,
					'pipeline_slug' => $pipeline_slug,
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'Bundle pipeline "%s" for selected flow was not found.', $pipeline_slug ),
		);
	}

	/** @return array<string,array<int,array<string,mixed>>> */
	private function workflow_from_bundle_flow( array $flow, array $pipeline, array $input = array() ): array {
		$pipeline_steps = array();
		foreach ( $pipeline['steps'] ?? array() as $step ) {
			$pipeline_steps[ (int) ( $step['step_position'] ?? 0 ) ] = is_array( $step ) ? $step : array();
		}

		$workflow_steps = array();
		foreach ( $flow['steps'] ?? array() as $flow_step ) {
			if ( ! is_array( $flow_step ) ) {
				continue;
			}
			$position         = (int) ( $flow_step['step_position'] ?? 0 );
			$pipeline_step    = $pipeline_steps[ $position ] ?? array();
			$workflow_steps[] = array_merge(
				is_array( $pipeline_step['step_config'] ?? null ) ? $pipeline_step['step_config'] : array(),
				$flow_step,
				array(
					'step_type' => (string) ( $flow_step['step_type'] ?? $pipeline_step['step_type'] ?? '' ),
					'label'     => (string) ( $pipeline_step['name'] ?? $pipeline_step['label'] ?? $flow_step['label'] ?? '' ),
				)
			);
		}

		$this->apply_flow_step_patches( $workflow_steps, is_array( $input['flow_step_patches'] ?? null ) ? $input['flow_step_patches'] : array() );
		$this->apply_run_tool_recorders( $workflow_steps, $input );

		return array( 'steps' => $workflow_steps );
	}

	/**
	 * @param array<int,array<string,mixed>> $workflow_steps Workflow steps to mutate.
	 * @param array<string,mixed>            $input          Bundle run input.
	 */
	private function apply_run_tool_recorders( array &$workflow_steps, array $input ): void {
		$recorders = array_values( array_filter( is_array( $input['tool_recorders'] ?? null ) ? $input['tool_recorders'] : array(), 'is_array' ) );
		if ( empty( $recorders ) ) {
			return;
		}

		foreach ( $workflow_steps as &$workflow_step ) {
			if ( 'ai' !== (string) ( $workflow_step['step_type'] ?? '' ) ) {
				continue;
			}

			$existing                        = is_array( $workflow_step['tool_recorders'] ?? null ) ? $workflow_step['tool_recorders'] : array();
			$workflow_step['tool_recorders'] = array_merge( $existing, $recorders );
		}
		unset( $workflow_step );
	}

	/**
	 * @param array<int,array<string,mixed>> $workflow_steps Workflow steps to mutate.
	 * @param array<int,mixed>               $patches Run-scoped step patch definitions.
	 */
	private function apply_flow_step_patches( array &$workflow_steps, array $patches ): void {
		foreach ( $patches as $patch ) {
			if ( ! is_array( $patch ) ) {
				continue;
			}

			$payload = $this->flow_step_patch_payload( $patch );
			if ( empty( $payload ) ) {
				continue;
			}

			foreach ( $workflow_steps as &$workflow_step ) {
				if ( ! $this->flow_step_patch_matches( $workflow_step, $patch ) ) {
					continue;
				}

				$workflow_step = $this->recursive_array_merge( $workflow_step, $payload );
			}
			unset( $workflow_step );
		}
	}

	/** @return array<string,mixed> */
	private function flow_step_patch_payload( array $patch ): array {
		foreach ( array( 'merge', 'config', 'patch' ) as $payload_key ) {
			if ( isset( $patch[ $payload_key ] ) && is_array( $patch[ $payload_key ] ) ) {
				return $patch[ $payload_key ];
			}
		}

		$payload = $patch;
		foreach ( array( 'flow_step_id', 'pipeline_step_id', 'step_type', 'slug' ) as $selector_key ) {
			unset( $payload[ $selector_key ] );
		}

		return $payload;
	}

	/** @param array<string,mixed> $workflow_step */
	private function flow_step_patch_matches( array $workflow_step, array $patch ): bool {
		foreach ( array( 'flow_step_id', 'pipeline_step_id', 'step_type' ) as $selector_key ) {
			if ( isset( $patch[ $selector_key ] ) && (string) ( $workflow_step[ $selector_key ] ?? '' ) !== (string) $patch[ $selector_key ] ) {
				return false;
			}
		}

		if ( isset( $patch['slug'] ) ) {
			$slug = (string) $patch['slug'];
			return in_array( $slug, array( (string) ( $workflow_step['flow_step_id'] ?? '' ), (string) ( $workflow_step['pipeline_step_id'] ?? '' ) ), true );
		}

		return isset( $patch['flow_step_id'] ) || isset( $patch['pipeline_step_id'] ) || isset( $patch['step_type'] );
	}

	/** @return array<string,mixed> */
	private function recursive_array_merge( array $base, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_associative_array( $value ) && self::is_associative_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->recursive_array_merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	private static function is_associative_array( array $value ): bool {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
