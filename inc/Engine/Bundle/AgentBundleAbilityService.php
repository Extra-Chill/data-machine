<?php
/**
 * Ability-backed agent bundle lifecycle service.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical implementation for bundle lifecycle abilities.
 */
final class AgentBundleAbilityService {

	private Agents $agents;
	private Pipelines $pipelines;
	private Flows $flows;
	private AgentBundler $bundler;
	private AgentBundleLifecycleProjection $projection;

	public function __construct( ?Agents $agents = null, ?Pipelines $pipelines = null, ?Flows $flows = null, ?AgentBundler $bundler = null, ?AgentBundleLifecycleProjection $projection = null ) {
		$this->agents     = $agents ?? new Agents();
		$this->pipelines  = $pipelines ?? new Pipelines();
		$this->flows      = $flows ?? new Flows();
		$this->bundler    = $bundler ?? new AgentBundler();
		$this->projection = $projection ?? new AgentBundleLifecycleProjection( $this->pipelines, $this->flows );
	}

	/** @return array<string,mixed> */
	public function list_installed(): array {
		$items = array();
		foreach ( $this->agents->get_all() as $agent ) {
			$bundle = $agent['agent_config']['datamachine_bundle'] ?? array();
			if ( empty( $bundle['bundle_slug'] ) ) {
				continue;
			}

			$items[] = array(
				'agent_id'         => (int) $agent['agent_id'],
				'agent_slug'       => (string) $agent['agent_slug'],
				'template_slug'    => (string) ( $bundle['template_slug'] ?? $bundle['bundle_slug'] ),
				'template_version' => (string) ( $bundle['template_version'] ?? $bundle['bundle_version'] ?? '' ),
				'bundle_slug'      => (string) $bundle['bundle_slug'],
				'bundle_version'   => (string) ( $bundle['bundle_version'] ?? '' ),
				'source_ref'       => (string) ( $bundle['source_ref'] ?? '' ),
				'source_revision'  => (string) ( $bundle['source_revision'] ?? '' ),
				'artifacts'        => count( AgentBundleArtifactState::installed_for_agent( $agent ) ),
			);
		}

		return array(
			'success' => true,
			'bundles' => $items,
		);
	}

	/** @return array<string,mixed> */
	public function status( string $slug ): array {
		$agent = $this->resolve_installed_agent( $slug );
		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Installed bundle not found.',
			);
		}

		return array_merge( array( 'success' => true ), $this->installed_status( $agent ) );
	}

	/** @return array<string,mixed> */
	public function plan( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle            = $loaded['bundle'];
		$slug              = (string) ( $input['slug'] ?? '' );
		$reconcile_runtime = ! empty( $input['reconcile_runtime'] );
		$plan              = $this->add_runtime_drift_to_plan(
			$this->plan_for_bundle( $bundle, $slug )->to_array(),
			$bundle,
			$slug,
			$reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing'
		);

		return array(
			'success' => true,
			'plan'    => $plan,
			'bundle'  => $this->bundle_summary( $bundle, $slug ),
		);
	}

	/** @return array<string,mixed> */
	public function rebase( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle      = $loaded['bundle'];
		$slug        = (string) ( $input['slug'] ?? '' );
		$policy_name = (string) ( $input['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );
		$only        = isset( $input['artifact'] ) ? (string) $input['artifact'] : '';
		$plan        = $this->plan_for_bundle( $bundle, $slug );
		$rebased     = $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name );

		if ( '' !== $only ) {
			$rebased = array_values(
				array_filter(
					$rebased,
					static fn( array $entry ) => ( $entry['artifact_key'] ?? '' ) === $only
				)
			);
		}

		return array(
			'success' => true,
			'policy'  => $policy_name,
			'count'   => count( $rebased ),
			'rebased' => $rebased,
		);
	}

	/** @return array<string,mixed> */
	public function upgrade( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle            = $loaded['bundle'];
		$slug              = (string) ( $input['slug'] ?? '' );
		$dry_run           = ! empty( $input['dry_run'] );
		$reconcile_runtime = ! empty( $input['reconcile_runtime'] );
		$rebase_local      = ! empty( $input['rebase_local'] );
		$policy_name       = (string) ( $input['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );
		$plan              = $this->plan_for_bundle( $bundle, $slug );

		if ( $dry_run ) {
			$plan_array = $this->add_runtime_drift_to_plan(
				$plan->to_array(),
				$bundle,
				$slug,
				$reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing'
			);
			if ( $rebase_local ) {
				$plan_array['rebase'] = $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name );
			}

			return array(
				'success' => true,
				'plan'    => $plan_array,
				'bundle'  => $this->bundle_summary( $bundle, $slug ),
			);
		}

		$result = $this->bundler->import(
			$bundle,
			'' !== $slug ? $slug : null,
			isset( $input['owner_id'] ) ? (int) $input['owner_id'] : 0,
			false,
			array(
				'reconcile_runtime' => $reconcile_runtime,
				'is_upgrade'        => true,
			)
		);
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$response = array(
			'success' => true,
			'import'  => $result,
			'plan'    => $plan->to_array(),
		);

		if ( $plan->has_pending_approval() ) {
			$agent                      = $this->resolve_bundle_agent( $bundle, $slug );
			$rebased_artifacts          = $rebase_local
				? $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name )
				: array();
			$pending                    = AgentBundleUpgradePendingAction::stage(
				$plan,
				array(
					'bundle'             => $this->bundle_summary( $bundle, $slug ),
					'agent'              => $agent ? $agent : array(),
					'target_artifacts'   => $this->projection->target_artifacts( $bundle, $agent ? $agent : null ),
					'approved_artifacts' => $this->approved_rebased_artifact_keys( $rebased_artifacts ),
					'rebased_artifacts'  => $rebased_artifacts,
					'summary'            => 'Review locally modified bundle artifacts before applying.',
					'agent_id'           => $agent ? (int) $agent['agent_id'] : 0,
					'user_id'            => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				)
			);
			$response['pending_action'] = $pending;
			if ( ! empty( $rebased_artifacts ) ) {
				$response['rebase'] = $rebased_artifacts;
			}
		}

		return $response;
	}

	/** @return array<string,mixed> */
	public function apply_pending_action( string $action_id ): array {
		$action_id = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $action_id ) : trim( $action_id );
		if ( '' === $action_id ) {
			return array(
				'success' => false,
				'error'   => 'Pending action ID is required.',
			);
		}

		return ResolvePendingActionAbility::execute(
			array(
				'action_id' => $action_id,
				'decision'  => 'accepted',
			)
		);
	}

	/** @return array<string,mixed> */
	private function load_bundle_from_input( array $input ): array {
		$source = trim( (string) ( $input['source'] ?? '' ) );
		if ( '' === $source ) {
			return array(
				'success' => false,
				'error'   => 'Bundle source is required.',
			);
		}

		$context  = BundleSourceAuth::build_resolve_context(
			isset( $input['token'] ) ? (string) $input['token'] : null,
			isset( $input['token_env'] ) ? (string) $input['token_env'] : null
		);
		$resolved = BundleSource::resolve( $source, $context );
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
				'error'   => 'Failed to parse bundle. Use .zip, .json, or a bundle directory.',
			);
		}

		if ( BundleSource::is_remote( $source ) && empty( $bundle['source_ref'] ) ) {
			$bundle['source_ref'] = $source;
		}
		if ( null !== $revision && empty( $bundle['source_revision'] ) ) {
			$bundle['source_revision'] = $revision;
		}

		return array(
			'success' => true,
			'bundle'  => $bundle,
		);
	}

	private function plan_for_bundle( array $bundle, string $slug = '' ): AgentBundleUpgradePlan {
		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return AgentBundleUpgradePlanner::plan( array(), array(), $this->projection->target_artifacts( $bundle ), $this->bundle_summary( $bundle, $slug ) );
		}

		$installed = AgentBundleArtifactState::installed_for_agent( $agent );

		return AgentBundleUpgradePlanner::plan(
			$installed,
			$this->projection->current_artifacts( $agent, $installed ),
			$this->projection->target_artifacts( $bundle, $agent ),
			$this->bundle_summary( $bundle, $slug )
		);
	}

	private function add_runtime_drift_to_plan( array $plan, array $bundle, string $slug = '', string $decision = 'preserve_existing' ): array {
		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return $plan;
		}

		$drifts = $this->runtime_drifts_for_bundle( $bundle, $agent, $decision );
		if ( empty( $drifts ) ) {
			return $plan;
		}

		$plan['runtime_drift'] = $drifts;
		foreach ( $drifts as $drift ) {
			$plan['warnings'][] = $drift;
		}
		$plan['counts']['warnings'] = count( $plan['warnings'] ?? array() );

		return $plan;
	}

	/** @return array<int,array<string,mixed>> */
	private function runtime_drifts_for_bundle( array $bundle, array $agent, string $decision ): array {
		$agent_id                   = (int) ( $agent['agent_id'] ?? 0 );
		$pipeline_id_map            = array();
		$existing_pipelines_by_slug = array();
		$drifts                     = array();

		foreach ( $this->pipelines->get_all_pipelines( null, $agent_id ) as $pipeline ) {
			$existing_pipelines_by_slug[ (string) ( $pipeline['portable_slug'] ?? '' ) ] = $pipeline;
		}

		foreach ( $bundle['pipelines'] ?? array() as $pipeline ) {
			if ( ! is_array( $pipeline ) ) {
				continue;
			}
			$slug = PortableSlug::normalize( (string) ( $pipeline['portable_slug'] ?? ( $pipeline['pipeline_name'] ?? 'pipeline' ) ), 'pipeline' );
			if ( isset( $existing_pipelines_by_slug[ $slug ] ) ) {
				$pipeline_id_map[ (int) ( $pipeline['original_id'] ?? 0 ) ] = (int) ( $existing_pipelines_by_slug[ $slug ]['pipeline_id'] ?? 0 );
			}
		}

		foreach ( $bundle['flows'] ?? array() as $flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$old_pipeline_id = (int) ( $flow['original_pipeline_id'] ?? 0 );
			$new_pipeline_id = (int) ( $pipeline_id_map[ $old_pipeline_id ] ?? 0 );
			if ( $new_pipeline_id <= 0 ) {
				continue;
			}
			$flow_slug     = PortableSlug::normalize( (string) ( $flow['portable_slug'] ?? ( $flow['flow_name'] ?? 'flow' ) ), 'flow' );
			$existing_flow = $this->flows->get_by_portable_slug( $new_pipeline_id, $flow_slug );
			if ( ! $existing_flow ) {
				continue;
			}
			$target_flow = array_merge(
				$flow,
				array(
					'flow_config' => BundleStepIdRemapper::remap_flow_step_ids( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(), $old_pipeline_id, $new_pipeline_id, (int) $existing_flow['flow_id'] ),
				)
			);
			$preview     = AgentBundleRuntimeDrift::preview( $flow_slug, $existing_flow, $target_flow, $decision );
			if ( null !== $preview ) {
				$drifts[] = $preview;
			}
		}

		return $drifts;
	}

	private function rebase_locally_modified( AgentBundleUpgradePlan $plan, array $bundle, string $slug, string $policy_name ): array {
		$needs_approval = $plan->bucket( 'needs_approval' );
		if ( empty( $needs_approval ) ) {
			return array();
		}

		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return array();
		}

		$installed       = AgentBundleArtifactState::installed_for_agent( $agent );
		$installed_index = array();
		foreach ( $installed as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                     = AgentBundleArtifactExtensions::artifact_key( (string) ( $row['artifact_type'] ?? '' ), (string) ( $row['artifact_id'] ?? '' ) );
			$installed_index[ $key ] = $row;
		}

		$current_index = array();
		foreach ( $this->projection->current_artifacts( $agent, $installed ) as $artifact ) {
			$key                   = AgentBundleArtifactExtensions::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
			$current_index[ $key ] = $artifact;
		}

		$target_index = array();
		foreach ( $this->projection->target_artifacts( $bundle, $agent ) as $artifact ) {
			$key                  = AgentBundleArtifactExtensions::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
			$target_index[ $key ] = $artifact;
		}

		$results = array();
		foreach ( $needs_approval as $entry ) {
			$key    = (string) ( $entry['artifact_key'] ?? '' );
			$target = $target_index[ $key ] ?? null;
			$local  = $current_index[ $key ] ?? null;
			if ( ! is_array( $target ) || ! is_array( $local ) ) {
				continue;
			}

			$base_payload  = null;
			$installed_row = $installed_index[ $key ] ?? null;
			if ( is_array( $installed_row ) ) {
				if ( array_key_exists( 'installed_payload', $installed_row ) ) {
					$base_payload = $installed_row['installed_payload'];
				} elseif ( array_key_exists( 'payload', $installed_row ) ) {
					$base_payload = $installed_row['payload'];
				}
			}

			$results[] = AgentBundleArtifactRebase::rebase(
				array(
					'artifact_type' => (string) $target['artifact_type'],
					'artifact_id'   => (string) $target['artifact_id'],
					'source_path'   => (string) ( $target['source_path'] ?? '' ),
					'base'          => $base_payload,
					'local'         => $local['payload'] ?? null,
					'remote'        => $target['payload'] ?? null,
				),
				$policy_name
			);
		}

		return $results;
	}

	private function approved_rebased_artifact_keys( array $rebased_artifacts ): array {
		$approved = array();
		foreach ( $rebased_artifacts as $artifact ) {
			if ( ! is_array( $artifact ) || ! empty( $artifact['requires_approval'] ) ) {
				continue;
			}
			$key = (string) ( $artifact['artifact_key'] ?? '' );
			if ( '' === $key ) {
				$key = AgentBundleArtifactExtensions::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) );
			}
			if ( '' !== $key ) {
				$approved[] = $key;
			}
		}

		return array_values( array_unique( $approved ) );
	}

	private function resolve_bundle_agent( array $bundle, string $slug = '' ): ?array {
		$target = '' !== $slug ? sanitize_title( $slug ) : sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		return '' === $target ? null : $this->agents->get_by_slug( $target );
	}

	private function resolve_installed_agent( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$agent = $this->agents->get_by_slug( $slug );
		if ( $agent && ! empty( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ) ) {
			return $agent;
		}

		foreach ( $this->agents->get_all() as $candidate ) {
			$bundle = $candidate['agent_config']['datamachine_bundle'] ?? array();
			if ( sanitize_title( (string) ( $bundle['bundle_slug'] ?? '' ) ) === $slug ) {
				return $candidate;
			}
		}

		return null;
	}

	private function installed_status( array $agent ): array {
		$bundle    = $agent['agent_config']['datamachine_bundle'] ?? array();
		$artifacts = AgentBundleArtifactState::installed_for_agent( $agent );

		return array(
			'agent_id'         => (int) $agent['agent_id'],
			'agent_slug'       => (string) $agent['agent_slug'],
			'template_slug'    => (string) ( $bundle['template_slug'] ?? $bundle['bundle_slug'] ?? '' ),
			'template_version' => (string) ( $bundle['template_version'] ?? $bundle['bundle_version'] ?? '' ),
			'bundle_slug'      => (string) ( $bundle['bundle_slug'] ?? '' ),
			'bundle_version'   => (string) ( $bundle['bundle_version'] ?? '' ),
			'source_ref'       => (string) ( $bundle['source_ref'] ?? '' ),
			'source_revision'  => (string) ( $bundle['source_revision'] ?? '' ),
			'artifact_count'   => count( $artifacts ),
			'artifacts'        => $artifacts,
		);
	}

	private function bundle_summary( array $bundle, string $slug = '' ): array {
		$package = AgentBundler::package_from_bundle( $bundle );

		return array(
			'bundle_slug'    => $package->get_slug(),
			'bundle_version' => $package->get_version(),
			'target_slug'    => '' !== $slug ? sanitize_title( $slug ) : $package->get_agent()->get_slug(),
			'pipelines'      => count( $bundle['pipelines'] ?? array() ),
			'flows'          => count( $bundle['flows'] ?? array() ),
			'artifacts'      => count( $package->get_artifacts() ),
		);
	}
}
