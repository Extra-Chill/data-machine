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
use DataMachine\Core\Database\Jobs\Jobs;

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

		$workflow     = $this->workflow_from_bundle_flow( $selection['flow'], $selection['pipeline'] );
		$initial_data = is_array( $input['initial_data'] ?? null ) ? $input['initial_data'] : array();
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
		$response['status']   = $this->status_from_response( $response );

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
				'success'     => ! empty( $response['success'] ) && 'failed' !== $status,
				'error'       => (string) ( $response['error'] ?? $engine_data['error_message'] ?? '' ),
				'token_usage' => is_array( $engine_data['token_usage'] ?? null ) ? $engine_data['token_usage'] : null,
			)
		);
	}

	/** @return array<string,mixed> */
	private static function compact_response_array( array $values ): array {
		return array_filter(
			$values,
			static fn( $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
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

		if ( function_exists( 'wp_agent_import_runtime_bundles' ) ) {
			$imports = wp_agent_import_runtime_bundles( $bundle_specs, $import_input );
		} else {
			$imports = $this->import_runtime_bundles_via_filter( $bundle_specs, $import_input );
		}

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

	private function first_runtime_bundle_source( array $input ): string {
		foreach ( $this->runtime_bundle_specs( $input ) as $spec ) {
			$source = trim( (string) ( $spec['source'] ?? '' ) );
			if ( '' !== $source ) {
				return $source;
			}
		}

		return '';
	}

	/** @return array<int,array<string,mixed>> */
	private function import_runtime_bundles_via_filter( array $bundle_specs, array $defaults ): array {
		$imports = array();
		foreach ( $bundle_specs as $index => $spec ) {
			$input = array_merge(
				array(
					'on_conflict' => (string) ( $spec['on_conflict'] ?? 'upgrade' ),
				),
				$defaults
			);

			foreach ( array( 'source', 'slug', 'token_env' ) as $field ) {
				if ( isset( $spec[ $field ] ) && '' !== trim( (string) $spec[ $field ] ) ) {
					$input[ $field ] = trim( (string) $spec[ $field ] );
				}
			}

			if ( isset( $spec['import_principal'] ) && is_array( $spec['import_principal'] ) ) {
				$input['import_principal'] = $spec['import_principal'];
			}

			$result = apply_filters( 'wp_agent_runtime_import_bundle', null, $spec, $input, $index );
			if ( is_wp_error( $result ) ) {
				$imports[] = array(
					'success' => false,
					'index'   => $index,
					'error'   => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
						'data'    => $result->get_error_data(),
					),
				);
				continue;
			}

			$imports[] = array_merge(
				array(
					'success' => is_array( $result ) && ! empty( $result['success'] ),
					'index'   => $index,
				),
				is_array( $result ) ? $result : array( 'result' => $result )
			);
		}

		return $imports;
	}

	/** @param callable():array<string,mixed> $callback */
	private function with_runtime_controls( array $input, callable $callback ): array {
		$filters = array();

		if ( ! empty( $input['disable_datamachine_directives'] ) || ! empty( $input['disable_directives'] ) ) {
			$filters[] = array( 'datamachine_directives_enabled', '__return_false', 100 );
			add_filter( 'datamachine_directives_enabled', '__return_false', 100, 3 );
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
	private function workflow_from_bundle_flow( array $flow, array $pipeline ): array {
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

		return array( 'steps' => $workflow_steps );
	}
}
