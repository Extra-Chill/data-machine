<?php
/**
 * Agent package lifecycle helpers for WP-CLI.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use DataMachine\Engine\Bundle\AgentBundleArtifactExtensions;
use DataMachine\Engine\Bundle\AgentBundleAgentConfig;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactRebase;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleUpgradePendingAction;
use DataMachine\Engine\Bundle\AgentBundleUpgradePlanner;
use DataMachine\Engine\Bundle\AgentBundleRuntimeDrift;
use DataMachine\Engine\Bundle\BundleStepIdRemapper;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\BundleSource;
use DataMachine\Engine\Bundle\BundleSourceAuth;
use DataMachine\Engine\Bundle\PortableSlug;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Install, inspect, and upgrade portable agent packages.
 *
 * This class is intentionally not registered as a top-level command. Its
 * public lifecycle methods are inherited by AgentsCommand so operators use
 * `wp datamachine agent ...` as the canonical surface.
 */
class AgentBundleCommand extends BaseCommand {

	/**
	 * Install an agent package from a local file or directory.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Package path (.zip, .json, or directory).
	 *
	 * [--slug=<slug>]
	 * : Override target agent slug.
	 *
	 * [--owner=<user>]
	 * : Owner WordPress user ID, login, or email.
	 *
	 * [--dry-run]
	 * : Preview the install without writing.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--reconcile-runtime]
	 * : Replace preserved flow runtime queues and scheduling with the bundle seed on existing bundle-owned flows.
	 *
	 * [--token=<token>]
	 * : Auth token for private archive downloads. Used for this single resolve(); never persisted, never logged.
	 *
	 * [--token-env=<varname>]
	 * : Environment variable (or PHP constant) name to read the auth token from. Preferred over --token for shell-history hygiene.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function install( array $args, array $assoc_args ): void {
		$this->run_install( $args, $assoc_args );
	}

	/**
	 * List installed package-backed agents.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 * @subcommand installed
	 */
	public function installed( array $args, array $assoc_args ): void {
		unset( $args );

		$items = array();
		foreach ( $this->agents()->get_all() as $agent ) {
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
				'artifacts'        => count( is_array( $bundle['artifacts'] ?? null ) ? $bundle['artifacts'] : array() ),
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) && empty( $items ) ) {
			WP_CLI::line( '[]' );
			return;
		}

		$this->format_items( $items, array( 'agent_id', 'agent_slug', 'template_slug', 'template_version', 'bundle_slug', 'bundle_version', 'artifacts' ), $assoc_args, 'agent_id' );
	}

	/**
	 * Show installed package status for an agent or package slug.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug or package slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function status( array $args, array $assoc_args ): void {
		$agent = $this->resolve_installed_agent( (string) ( $args[0] ?? '' ) );
		if ( ! $agent ) {
			WP_CLI::error( 'Installed bundle not found.' );
			return;
		}

		$status = $this->installed_status( $agent );
		$this->output( $status, $assoc_args, array( 'agent_id', 'agent_slug', 'template_slug', 'template_version', 'bundle_slug', 'bundle_version', 'artifact_count' ) );
	}

	/**
	 * Show an upgrade diff for a package path.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Package path (.zip, .json, or directory).
	 *
	 * [--slug=<slug>]
	 * : Override target agent slug.
	 *
	 * [--token=<token>]
	 * : Auth token for private archive downloads. Used for this single resolve(); never persisted, never logged.
	 *
	 * [--token-env=<varname>]
	 * : Environment variable (or PHP constant) name to read the auth token from. Preferred over --token for shell-history hygiene.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function diff( array $args, array $assoc_args ): void {
		$bundle = $this->load_bundle_arg( $args, $assoc_args );
		$plan   = $this->add_runtime_drift_to_plan(
			$this->plan_for_bundle( $bundle, (string) ( $assoc_args['slug'] ?? '' ) )->to_array(),
			$bundle,
			(string) ( $assoc_args['slug'] ?? '' )
		);
		$this->output_plan( $plan, $assoc_args );
	}

	/**
	 * Upgrade an installed agent package.
	 *
	 * Clean artifacts are applied through the importer. Approval-required changes
	 * are staged as PendingActions.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Package path (.zip, .json, or directory).
	 *
	 * [--slug=<slug>]
	 * : Override target agent slug.
	 *
	 * [--owner=<user>]
	 * : Owner WordPress user ID, login, or email.
	 *
	 * [--dry-run]
	 * : Preview the upgrade without writing.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--reconcile-runtime]
	 * : Replace preserved flow runtime queues and scheduling with the bundle seed on existing bundle-owned flows.
	 *
	 * [--rebase-local]
	 * : 3-way rebase locally modified artifacts against the target using a merge policy. Preview only unless --yes is set.
	 *
	 * [--policy=<policy>]
	 * : Rebase policy name. Defaults to "conservative" (no auto-merge).
	 * ---
	 * default: conservative
	 * options:
	 *   - conservative
	 *   - burn-in-safe
	 * ---
	 *
	 * [--token=<token>]
	 * : Auth token for private archive downloads. Used for this single resolve(); never persisted, never logged.
	 *
	 * [--token-env=<varname>]
	 * : Environment variable (or PHP constant) name to read the auth token from. Preferred over --token for shell-history hygiene.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function upgrade( array $args, array $assoc_args ): void {
		$bundle            = $this->load_bundle_arg( $args, $assoc_args );
		$slug              = (string) ( $assoc_args['slug'] ?? '' );
		$plan              = $this->plan_for_bundle( $bundle, $slug );
		$dry_run           = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$reconcile_runtime = \WP_CLI\Utils\get_flag_value( $assoc_args, 'reconcile-runtime', false );
		$rebase_local      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'rebase-local', false );
		$policy_name       = (string) ( $assoc_args['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );

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
			$this->output_plan( $plan_array, $assoc_args );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'Apply clean bundle artifact updates now?' );
		}

		$owner_id = isset( $assoc_args['owner'] ) ? $this->resolve_user_id( $assoc_args['owner'] ) : 0;
		$result   = $this->bundler()->import(
			$bundle,
			'' !== $slug ? $slug : null,
			$owner_id,
			false,
			array(
				'reconcile_runtime' => $reconcile_runtime,
				// Mark this as an upgrade so the importer treats an existing agent row as the upgrade
				// target instead of returning "Agent slug already exists" when local pipelines/flows
				// have been edited (#1801). Local-modified artifacts come back in `result.conflicts`
				// and get staged as PendingActions below.
				'is_upgrade'        => true,
			)
		);
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Bundle upgrade failed.' ) );
			return;
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
			$approved_artifacts         = $this->approved_rebased_artifact_keys( $rebased_artifacts );
			$pending                    = AgentBundleUpgradePendingAction::stage(
				$plan,
				array(
					'bundle'             => $this->bundle_summary( $bundle, $slug ),
					'agent'              => $agent ? $agent : array(),
					'target_artifacts'   => $this->bundle_artifacts( $bundle ),
					'approved_artifacts' => $approved_artifacts,
					'rebased_artifacts'  => $rebased_artifacts,
					'summary'            => 'Review locally modified bundle artifacts before applying.',
					'agent_id'           => $agent ? (int) $agent['agent_id'] : 0,
					'user_id'            => get_current_user_id(),
				)
			);
			$response['pending_action'] = $pending;
			if ( ! empty( $rebased_artifacts ) ) {
				$response['rebase'] = $rebased_artifacts;
			}
		}

		$this->output( $response, $assoc_args, array( 'success' ) );
	}

	/**
	 * Resolve a staged package PendingAction.
	 *
	 * ## OPTIONS
	 *
	 * <pending_action_id>
	 * : PendingAction ID to accept.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function apply( array $args, array $assoc_args ): void {
		$action_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );
		if ( '' === $action_id ) {
			WP_CLI::error( 'Pending action ID is required.' );
			return;
		}

		$result               = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $action_id,
				'decision'  => 'accepted',
			)
		);
		$assoc_args['format'] = $assoc_args['format'] ?? 'json';
		$this->output( $result, $assoc_args, array( 'success', 'action_id', 'kind' ) );
	}

	/**
	 * 3-way rebase locally modified bundle artifacts against a target package.
	 *
	 * Always emits the merged preview. Use `upgrade --rebase-local` to stage
	 * a PendingAction with merged payloads attached.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Package path (.zip, .json, or directory).
	 *
	 * [--slug=<slug>]
	 * : Override target agent slug.
	 *
	 * [--policy=<policy>]
	 * : Rebase policy name.
	 * ---
	 * default: conservative
	 * options:
	 *   - conservative
	 *   - burn-in-safe
	 * ---
	 *
	 * [--artifact=<key>]
	 * : Limit output to one artifact_key (e.g. "flow:wordpress-com-history").
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function rebase( array $args, array $assoc_args ): void {
		$bundle      = $this->load_bundle_arg( $args );
		$slug        = (string) ( $assoc_args['slug'] ?? '' );
		$plan        = $this->plan_for_bundle( $bundle, $slug );
		$policy_name = (string) ( $assoc_args['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );
		$only        = isset( $assoc_args['artifact'] ) ? (string) $assoc_args['artifact'] : '';

		$rebased = $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name );
		if ( '' !== $only ) {
			$rebased = array_values(
				array_filter(
					$rebased,
					static fn( array $entry ) => ( $entry['artifact_key'] ?? '' ) === $only
				)
			);
		}

		$response = array(
			'policy'  => $policy_name,
			'count'   => count( $rebased ),
			'rebased' => $rebased,
		);

		$assoc_args['format'] = $assoc_args['format'] ?? 'json';
		$this->output( $response, $assoc_args, array( 'policy', 'count' ) );
	}

	/**
	 * Build rebased artifact entries for every needs_approval plan row.
	 *
	 * @param \DataMachine\Engine\Bundle\AgentBundleUpgradePlan $plan Upgrade plan.
	 * @param array<string,mixed>                                $bundle Parsed bundle payload.
	 * @param string                                             $slug Optional agent slug override.
	 * @param string                                             $policy_name Rebase policy.
	 * @return array<int,array<string,mixed>>
	 */
	private function rebase_locally_modified( \DataMachine\Engine\Bundle\AgentBundleUpgradePlan $plan, array $bundle, string $slug, string $policy_name ): array {
		$needs_approval = $plan->bucket( 'needs_approval' );
		if ( empty( $needs_approval ) ) {
			return array();
		}

		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return array();
		}

		$bundle_state    = is_array( $agent['agent_config']['datamachine_bundle'] ?? null ) ? $agent['agent_config']['datamachine_bundle'] : array();
		$installed       = is_array( $bundle_state['artifacts'] ?? null ) ? $bundle_state['artifacts'] : array();
		$installed_index = array();
		foreach ( $installed as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                     = AgentBundleArtifactExtensions::artifact_key( (string) ( $row['artifact_type'] ?? '' ), (string) ( $row['artifact_id'] ?? '' ) );
			$installed_index[ $key ] = $row;
		}

		$current_index = array();
		foreach ( $this->current_artifacts( $agent, $installed ) as $artifact ) {
			$key                   = AgentBundleArtifactExtensions::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
			$current_index[ $key ] = $artifact;
		}

		$target_index = array();
		foreach ( $this->bundle_artifacts_for_agent( $bundle, $agent ) as $artifact ) {
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

			// Reconstruct the base payload from the install-time snapshot. New
			// installs/upgrades persist `installed_payload` on the agent_config
			// record (see AgentBundler::bundle_artifact_record()) so 3-way merge
			// has full fidelity. Pre-snapshot rows leave `installed_payload`
			// missing — burn-in-safe degrades to flagging more fields ambiguous
			// rather than silently merging without a real base.
			//
			// `payload` (no `installed_` prefix) is checked as a back-compat alias
			// for older test fixtures and any custom writers; new code should use
			// `installed_payload`.
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

	/**
	 * Collect rebased artifact keys that can be applied after approval.
	 *
	 * @param array<int,array<string,mixed>> $rebased_artifacts Rebase preview entries.
	 * @return array<int,string>
	 */
	private function approved_rebased_artifact_keys( array $rebased_artifacts ): array {
		$approved = array();

		foreach ( $rebased_artifacts as $artifact ) {
			if ( ! is_array( $artifact ) || ! empty( $artifact['requires_approval'] ) ) {
				continue;
			}

			$key = (string) ( $artifact['artifact_key'] ?? '' );
			if ( '' === $key ) {
				$key = AgentBundleArtifactExtensions::artifact_key(
					(string) ( $artifact['artifact_type'] ?? '' ),
					(string) ( $artifact['artifact_id'] ?? '' )
				);
			}

			if ( '' !== $key ) {
				$approved[] = $key;
			}
		}

		return array_values( array_unique( $approved ) );
	}

	private function run_install( array $args, array $assoc_args ): void {
		$bundle            = $this->load_bundle_arg( $args, $assoc_args );
		$slug              = isset( $assoc_args['slug'] ) ? sanitize_title( (string) $assoc_args['slug'] ) : null;
		$owner             = isset( $assoc_args['owner'] ) ? $this->resolve_user_id( $assoc_args['owner'] ) : 0;
		$dry_run           = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$reconcile_runtime = \WP_CLI\Utils\get_flag_value( $assoc_args, 'reconcile-runtime', false );

		if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Install agent bundle "%s"?', $this->bundle_summary( $bundle, (string) $slug )['target_slug'] ) );
		}

		$result = $this->bundler()->import( $bundle, $slug, $owner, $dry_run, array( 'reconcile_runtime' => $reconcile_runtime ) );
		$agent  = $this->resolve_bundle_agent( $bundle, (string) $slug );
		if ( $agent ) {
			$result['runtime_drift'] = $this->runtime_drifts_for_bundle( $bundle, $agent, $reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing' );
		}
		$this->output( $result, $assoc_args, array( 'success', 'message' ) );
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

		foreach ( $this->pipelines()->get_all_pipelines( null, $agent_id ) as $pipeline ) {
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
			$existing_flow = $this->flows()->get_by_portable_slug( $new_pipeline_id, $flow_slug );
			if ( ! $existing_flow ) {
				continue;
			}
			$target_flow = array_merge(
				$flow,
				array(
					'flow_config' => BundleStepIdRemapper::remap_flow_step_ids(
						is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(),
						$old_pipeline_id,
						$new_pipeline_id,
						(int) $existing_flow['flow_id']
					),
				)
			);
			$preview     = AgentBundleRuntimeDrift::preview( $flow_slug, $existing_flow, $target_flow, $decision );
			if ( null !== $preview ) {
				$drifts[] = $preview;
			}
		}

		return $drifts;
	}

	private function load_bundle_arg( array $args, array $assoc_args = array() ): array {
		$source = (string) ( $args[0] ?? '' );
		if ( '' === $source ) {
			WP_CLI::error( 'Bundle source is required.' );
		}

		$context  = $this->build_cli_resolve_context( $assoc_args );
		$resolved = BundleSource::resolve( $source, $context );
		if ( is_wp_error( $resolved ) ) {
			WP_CLI::error( $resolved->get_error_message() );
		}
		/** @var string $resolved */

		// Snapshot the revision before any downstream resolve() call
		// resets it (e.g. via re-entry through abilities).
		$revision = BundleSource::is_remote( $source ) ? BundleSource::last_resolved_revision() : null;

		$bundle = null;
		try {
			if ( is_dir( $resolved ) ) {
				$bundle = $this->bundler()->from_directory( $resolved );
			} elseif ( preg_match( '/\.zip$/i', $resolved ) ) {
				$bundle = $this->bundler()->from_zip( $resolved );
			} elseif ( preg_match( '/\.json$/i', $resolved ) ) {
				$bundle = $this->bundler()->from_json( (string) file_get_contents( $resolved ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} catch ( BundleValidationException $e ) {
			BundleSource::cleanup( $resolved, $source );
			WP_CLI::error( $e->getMessage() );
		}

		// Resolver downloaded a temp file for remote sources; from_zip()
		// extracts to its own tempdir and from_json() reads contents into
		// memory, so the downloaded file is safe to clean up now.
		BundleSource::cleanup( $resolved, $source );

		if ( ! is_array( $bundle ) ) {
			WP_CLI::error( 'Failed to parse bundle. Use .zip, .json, or a bundle directory.' );
		}

		// Stamp the original source URL into the bundle so installed
		// metadata records where this came from. AgentBundler::import()
		// reads $bundle['source_ref']. source_revision is best-effort:
		// captured from the response ETag for GitHub archives (#1830).
		if ( BundleSource::is_remote( $source ) && empty( $bundle['source_ref'] ) ) {
			$bundle['source_ref'] = $source;
		}
		if ( null !== $revision && empty( $bundle['source_revision'] ) ) {
			$bundle['source_revision'] = $revision;
		}

		return $bundle;
	}

	/**
	 * Build the {@see BundleSource::resolve()} context array from CLI
	 * --token / --token-env flags. The CLI token short-circuits the
	 * env/constant/option/filter chain in BundleSourceAuth::token_for().
	 *
	 * @param array $assoc_args WP_CLI assoc args.
	 * @return array
	 */
	private function build_cli_resolve_context( array $assoc_args ): array {
		$token     = isset( $assoc_args['token'] ) ? (string) $assoc_args['token'] : null;
		$token_env = isset( $assoc_args['token-env'] ) ? (string) $assoc_args['token-env'] : null;

		$context = BundleSourceAuth::build_resolve_context( $token, $token_env );

		if ( null !== $token_env && '' !== trim( (string) $token_env ) && empty( $context['cli_token'] ) && null === $token ) {
			WP_CLI::warning(
				sprintf(
					'--token-env=%s did not resolve to a non-empty token. Falling back to environment/constant/option chain.',
					trim( (string) $token_env )
				)
			);
		}

		return $context;
	}

	private function plan_for_bundle( array $bundle, string $slug = '' ): \DataMachine\Engine\Bundle\AgentBundleUpgradePlan {
		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return AgentBundleUpgradePlanner::plan(
				array(),
				array(),
				$this->bundle_artifacts( $bundle ),
				$this->bundle_summary( $bundle, $slug )
			);
		}

		$bundle_state = is_array( $agent['agent_config']['datamachine_bundle'] ?? null ) ? $agent['agent_config']['datamachine_bundle'] : array();
		$installed    = array_values( is_array( $bundle_state['artifacts'] ?? null ) ? $bundle_state['artifacts'] : array() );

		return AgentBundleUpgradePlanner::plan(
			$installed,
			$this->current_artifacts( $agent, $installed ),
			$this->bundle_artifacts_for_agent( $bundle, $agent ),
			$this->bundle_summary( $bundle, $slug )
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function bundle_artifacts( array $bundle ): array {
		return $this->bundle_artifacts_for_agent( $bundle, null );
	}

	/** @return array<int,array<string,mixed>> */
	private function bundle_artifacts_for_agent( array $bundle, ?array $agent ): array {
		$artifacts                  = array();
		$agent_id                   = is_array( $agent ) ? (int) ( $agent['agent_id'] ?? 0 ) : 0;
		$pipeline_id_map            = array();
		$existing_pipelines_by_slug = array();
		$incoming_config            = is_array( $bundle['agent']['agent_config'] ?? null ) ? $bundle['agent']['agent_config'] : array();

		$artifacts[] = array(
			'artifact_type' => 'agent_config',
			'artifact_id'   => 'config',
			'source_path'   => 'manifest.json#/agent/agent_config',
			'payload'       => AgentBundleAgentConfig::tracked_payload( $incoming_config ),
		);

		if ( $agent_id > 0 ) {
			foreach ( $this->pipelines()->get_all_pipelines( null, $agent_id ) as $pipeline ) {
				$existing_pipelines_by_slug[ (string) ( $pipeline['portable_slug'] ?? '' ) ] = $pipeline;
			}
		}

		foreach ( $bundle['pipelines'] ?? array() as $pipeline ) {
			if ( ! is_array( $pipeline ) ) {
				continue;
			}
			$slug = PortableSlug::normalize( (string) ( $pipeline['portable_slug'] ?? ( $pipeline['pipeline_name'] ?? 'pipeline' ) ), 'pipeline' );
			if ( isset( $existing_pipelines_by_slug[ $slug ] ) ) {
				$old_id = (int) ( $pipeline['original_id'] ?? 0 );
				$new_id = (int) ( $existing_pipelines_by_slug[ $slug ]['pipeline_id'] ?? 0 );

				$pipeline_id_map[ $old_id ]  = $new_id;
				$pipeline['pipeline_config'] = BundleStepIdRemapper::remap_pipeline_step_ids(
					is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array(),
					$old_id,
					$new_id
				);
			}

			$artifacts[] = array(
				'artifact_type' => 'pipeline',
				'artifact_id'   => $slug,
				'source_path'   => 'pipelines/' . $slug . '.json',
				'payload'       => $this->pipeline_payload( $pipeline, $slug ),
			);
		}

		foreach ( $bundle['flows'] ?? array() as $flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$slug            = PortableSlug::normalize( (string) ( $flow['portable_slug'] ?? ( $flow['flow_name'] ?? 'flow' ) ), 'flow' );
			$old_pipeline_id = (int) ( $flow['original_pipeline_id'] ?? 0 );
			$new_pipeline_id = (int) ( $pipeline_id_map[ $old_pipeline_id ] ?? 0 );
			$existing_flow   = $new_pipeline_id > 0 ? $this->flows()->get_by_portable_slug( $new_pipeline_id, $slug ) : null;

			if ( $existing_flow ) {
				$flow['flow_config'] = BundleStepIdRemapper::remap_flow_step_ids(
					is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(),
					$old_pipeline_id,
					$new_pipeline_id,
					(int) $existing_flow['flow_id']
				);
			}

			$artifacts[] = array(
				'artifact_type' => 'flow',
				'artifact_id'   => $slug,
				'source_path'   => 'flows/' . $slug . '.json',
				'payload'       => $this->flow_payload( $flow, $slug ),
			);
		}

		foreach ( AgentBundleArtifactExtensions::normalize_artifacts( is_array( $bundle['extension_artifacts'] ?? null ) ? $bundle['extension_artifacts'] : array() ) as $artifact ) {
			$artifacts[] = $artifact;
		}

		return $artifacts;
	}

	/** @param array<int,array<string,mixed>> $installed */
	protected function current_artifacts( array $agent, array $installed ): array {
		$agent_id  = (int) $agent['agent_id'];
		$artifacts = array();
		$pipelines = $this->pipelines()->get_all_pipelines( null, $agent_id );
		$flows     = $this->flows()->get_all_flows( null, $agent_id );

		$artifacts[] = array(
			'artifact_type' => 'agent_config',
			'artifact_id'   => 'config',
			'source_path'   => 'manifest.json#/agent/agent_config',
			'payload'       => AgentBundleAgentConfig::tracked_payload( is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array() ),
		);

		$pipeline_by_slug = array();
		foreach ( $pipelines as $pipeline ) {
			$slug = (string) ( $pipeline['portable_slug'] ?? '' );
			if ( '' !== $slug ) {
				$pipeline_by_slug[ $slug ] = $pipeline;
			}
		}

		$flow_by_slug = array();
		foreach ( $flows as $flow ) {
			$slug = (string) ( $flow['portable_slug'] ?? '' );
			if ( '' !== $slug ) {
				$flow_by_slug[ $slug ] = $flow;
			}
		}

		foreach ( $installed as $record ) {
			$type = (string) ( $record['artifact_type'] ?? '' );
			$id   = (string) ( $record['artifact_id'] ?? '' );
			if ( 'pipeline' === $type && isset( $pipeline_by_slug[ $id ] ) ) {
				$artifacts[] = array(
					'artifact_type' => 'pipeline',
					'artifact_id'   => $id,
					'source_path'   => (string) ( $record['source_path'] ?? '' ),
					'payload'       => $this->pipeline_payload( $pipeline_by_slug[ $id ], $id ),
				);
			}
			if ( 'flow' === $type && isset( $flow_by_slug[ $id ] ) ) {
				$artifacts[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $id,
					'source_path'   => (string) ( $record['source_path'] ?? '' ),
					'payload'       => $this->flow_payload( $flow_by_slug[ $id ], $id ),
				);
			}
		}

		$artifacts = array_merge(
			$artifacts,
			AgentBundleArtifactExtensions::current_artifacts(
				$agent,
				$installed,
				array( 'agent_id' => $agent_id )
			)
		);

		return $artifacts;
	}

	private function pipeline_payload( array $pipeline, string $portable_slug ): array {
		return array(
			'portable_slug'   => $portable_slug,
			'pipeline_name'   => (string) ( $pipeline['pipeline_name'] ?? '' ),
			'pipeline_config' => is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array(),
		);
	}

	private function flow_payload( array $flow, string $portable_slug ): array {
		$scheduling_policy = $this->flow_scheduling_policy( is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array() );

		return array(
			'portable_slug'     => $portable_slug,
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => $this->flow_config_without_runtime_queues( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array() ),
			'scheduling_policy' => $scheduling_policy,
			'queue_policy'      => 'create_seed_upgrade_preserve_existing',
		);
	}

	private function flow_scheduling_policy( array $config ): string {
		$interval = (string) ( $config['interval'] ?? 'manual' );
		$enabled  = array_key_exists( 'enabled', $config ) ? false !== $config['enabled'] : 'manual' !== $interval;

		if ( 'manual' === $interval || ! $enabled ) {
			return 'create_paused_upgrade_preserve_existing';
		}

		return 'create_bundle_schedule_upgrade_preserve_existing';
	}

	private function flow_config_without_runtime_queues( array $flow_config ): array {
		foreach ( $flow_config as &$step ) {
			if ( is_array( $step ) ) {
				unset( $step['prompt_queue'], $step['config_patch_queue'], $step['queue_mode'], $step['_queue_consume_revision'] );
			}
		}
		unset( $step );

		return $flow_config;
	}

	private function resolve_bundle_agent( array $bundle, string $slug = '' ): ?array {
		$target = '' !== $slug ? sanitize_title( $slug ) : sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		if ( '' === $target ) {
			return null;
		}

		return $this->agents()->get_by_slug( $target );
	}

	private function resolve_installed_agent( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$agent = $this->agents()->get_by_slug( $slug );
		if ( $agent && ! empty( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ) ) {
			return $agent;
		}

		foreach ( $this->agents()->get_all() as $candidate ) {
			$bundle = $candidate['agent_config']['datamachine_bundle'] ?? array();
			if ( sanitize_title( (string) ( $bundle['bundle_slug'] ?? '' ) ) === $slug ) {
				return $candidate;
			}
		}

		return null;
	}

	protected function installed_status( array $agent ): array {
		$bundle    = $agent['agent_config']['datamachine_bundle'] ?? array();
		$artifacts = is_array( $bundle['artifacts'] ?? null ) ? $bundle['artifacts'] : array();
		$artifacts = $this->classified_installed_artifacts( $agent, array_values( $artifacts ) );

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

	/**
	 * @param array<string,mixed>          $agent Agent row.
	 * @param array<int,array<string,mixed>> $installed Installed registry rows.
	 * @return array<int,array<string,mixed>>
	 */
	protected function classified_installed_artifacts( array $agent, array $installed ): array {
		$current = array();
		foreach ( $this->current_artifacts( $agent, $installed ) as $artifact ) {
			$key             = AgentBundleArtifactExtensions::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) );
			$current[ $key ] = $artifact;
		}

		$classified = array();
		foreach ( $installed as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$key                    = AgentBundleArtifactExtensions::artifact_key( (string) ( $record['artifact_type'] ?? '' ), (string) ( $record['artifact_id'] ?? '' ) );
			$current_hash           = isset( $current[ $key ] ) ? AgentBundleArtifactHasher::hash( $current[ $key ]['payload'] ?? null ) : null;
			$record['current_hash'] = $current_hash;
			$record['status']       = AgentBundleArtifactStatus::classify( (string) ( $record['installed_hash'] ?? '' ), $current_hash );
			$classified[]           = $record;
		}

		return $classified;
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

	private function output_plan( array $plan, array $assoc_args ): void {
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $plan, JSON_PRETTY_PRINT ) );
			return;
		}

		$counts = $plan['counts'] ?? array();
		WP_CLI::log( sprintf( 'Auto-apply:     %d', (int) ( $counts['auto_apply'] ?? 0 ) ) );
		WP_CLI::log( sprintf( 'Needs approval: %d', (int) ( $counts['needs_approval'] ?? 0 ) ) );
		WP_CLI::log( sprintf( 'Warnings:       %d', (int) ( $counts['warnings'] ?? 0 ) ) );
		WP_CLI::log( sprintf( 'No-op:          %d', (int) ( $counts['no_op'] ?? 0 ) ) );

		$rows = array();
		foreach ( array( 'auto_apply', 'needs_approval', 'warnings', 'no_op' ) as $bucket ) {
			foreach ( $plan[ $bucket ] ?? array() as $entry ) {
				$rows[] = array(
					'bucket'       => $bucket,
					'artifact_key' => (string) ( $entry['artifact_key'] ?? '' ),
					'reason'       => (string) ( $entry['reason'] ?? '' ),
					'summary'      => (string) ( $entry['summary'] ?? '' ),
				);
			}
		}

		if ( $rows ) {
			$this->format_items( $rows, array( 'bucket', 'artifact_key', 'reason', 'summary' ), array( 'format' => 'table' ) );
		}
	}

	private function output( array $value, array $assoc_args, array $table_fields ): void {
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $value, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $value ), $table_fields, array( 'format' => 'table' ) );
	}

	private function resolve_user_id( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$user = is_email( $value ) ? get_user_by( 'email', $value ) : get_user_by( 'login', $value );
		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error( sprintf( 'User "%s" not found.', (string) $value ) );
			return 0;
		}

		return (int) $user->ID;
	}

	private function bundler(): AgentBundler {
		return new AgentBundler();
	}

	private function agents(): Agents {
		return new Agents();
	}

	private function pipelines(): Pipelines {
		return new Pipelines();
	}

	private function flows(): Flows {
		return new Flows();
	}
}
