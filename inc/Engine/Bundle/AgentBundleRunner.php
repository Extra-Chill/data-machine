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
		$source = trim( (string) ( $input['source'] ?? '' ) );
		if ( '' === $source ) {
			return array(
				'success' => false,
				'error'   => 'Bundle source is required.',
			);
		}

		$loaded = $this->load_bundle( $source, $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle    = $loaded['bundle'];
		$directory = AgentBundleArrayAdapter::from_array_bundle( $bundle );
		$selection = $this->select_flow( $directory, (string) ( $input['flow'] ?? $input['flow_slug'] ?? '' ) );
		if ( empty( $selection['success'] ) ) {
			return $selection;
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
			return array(
				'success'      => true,
				'schema'       => 'datamachine/agent-bundle-run/v1',
				'dry_run'      => true,
				'bundle'       => $initial_data['agent_bundle'],
				'workflow'     => $workflow,
				'initial_data' => $initial_data,
			);
		}

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
