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
use DataMachine\Engine\Bundle\AgentBundleUpgradePendingAction;
use DataMachine\Engine\Bundle\AgentBundleUpgradePlanner;
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
				'agent_id'       => (int) $agent['agent_id'],
				'agent_slug'     => (string) $agent['agent_slug'],
				'bundle_slug'    => (string) $bundle['bundle_slug'],
				'bundle_version' => (string) ( $bundle['bundle_version'] ?? '' ),
				'artifacts'      => count( is_array( $bundle['artifacts'] ?? null ) ? $bundle['artifacts'] : array() ),
			);
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) && empty( $items ) ) {
			WP_CLI::line( '[]' );
			return;
		}

		$this->format_items( $items, array( 'agent_id', 'agent_slug', 'bundle_slug', 'bundle_version', 'artifacts' ), $assoc_args, 'agent_id' );
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
		$this->output( $status, $assoc_args, array( 'agent_id', 'agent_slug', 'bundle_slug', 'bundle_version', 'artifact_count' ) );
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
		$bundle = $this->load_bundle_arg( $args );
		$plan   = $this->plan_for_bundle( $bundle, (string) ( $assoc_args['slug'] ?? '' ) )->to_array();
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
		$bundle  = $this->load_bundle_arg( $args );
		$slug    = (string) ( $assoc_args['slug'] ?? '' );
		$plan    = $this->plan_for_bundle( $bundle, $slug );
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( $dry_run ) {
			$this->output_plan( $plan->to_array(), $assoc_args );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'Apply clean bundle artifact updates now?' );
		}

		$owner_id = isset( $assoc_args['owner'] ) ? $this->resolve_user_id( $assoc_args['owner'] ) : 0;
		$result   = $this->bundler()->import( $bundle, '' !== $slug ? $slug : null, $owner_id, false );
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
			$pending                    = AgentBundleUpgradePendingAction::stage(
				$plan,
				array(
					'bundle'           => $this->bundle_summary( $bundle, $slug ),
					'agent'            => $agent ? $agent : array(),
					'target_artifacts' => $this->bundle_artifacts( $bundle ),
					'summary'          => 'Review locally modified bundle artifacts before applying.',
					'agent_id'         => $agent ? (int) $agent['agent_id'] : 0,
					'user_id'          => get_current_user_id(),
				)
			);
			$response['pending_action'] = $pending;
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

	private function run_install( array $args, array $assoc_args ): void {
		$bundle  = $this->load_bundle_arg( $args );
		$slug    = isset( $assoc_args['slug'] ) ? sanitize_title( (string) $assoc_args['slug'] ) : null;
		$owner   = isset( $assoc_args['owner'] ) ? $this->resolve_user_id( $assoc_args['owner'] ) : 0;
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Install agent bundle "%s"?', $this->bundle_summary( $bundle, (string) $slug )['target_slug'] ) );
		}

		$result = $this->bundler()->import( $bundle, $slug, $owner, $dry_run );
		$this->output( $result, $assoc_args, array( 'success', 'message' ) );
	}

	private function load_bundle_arg( array $args ): array {
		$path = (string) ( $args[0] ?? '' );
		if ( '' === $path || ! file_exists( $path ) ) {
			WP_CLI::error( 'Bundle path not found.' );
		}

		$bundle = null;
		if ( is_dir( $path ) ) {
			$bundle = $this->bundler()->from_directory( $path );
		} elseif ( preg_match( '/\.zip$/i', $path ) ) {
			$bundle = $this->bundler()->from_zip( $path );
		} elseif ( preg_match( '/\.json$/i', $path ) ) {
			$bundle = $this->bundler()->from_json( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		if ( ! is_array( $bundle ) ) {
			WP_CLI::error( 'Failed to parse bundle. Use .zip, .json, or a bundle directory.' );
		}

		return $bundle;
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
		$artifacts = array();
		$agent_id  = is_array( $agent ) ? (int) ( $agent['agent_id'] ?? 0 ) : 0;
		$pipeline_id_map            = array();
		$existing_pipelines_by_slug = array();

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
				$old_id                        = (int) ( $pipeline['original_id'] ?? 0 );
				$new_id                        = (int) ( $existing_pipelines_by_slug[ $slug ]['pipeline_id'] ?? 0 );
				$pipeline_id_map[ $old_id ]     = $new_id;
				$pipeline['pipeline_config']    = $this->remap_pipeline_step_ids(
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
				$flow['flow_config'] = $this->remap_flow_step_ids(
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
	private function current_artifacts( array $agent, array $installed ): array {
		$agent_id  = (int) $agent['agent_id'];
		$artifacts = array();
		$pipelines = $this->pipelines()->get_all_pipelines( null, $agent_id );
		$flows     = $this->flows()->get_all_flows( null, $agent_id );

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
				$flow = $this->normalize_current_flow_ids( $flow_by_slug[ $id ] );
				$artifacts[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $id,
					'source_path'   => (string) ( $record['source_path'] ?? '' ),
					'payload'       => $this->flow_payload( $flow, $id ),
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
		return array(
			'portable_slug'     => $portable_slug,
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => $this->flow_config_without_runtime_queues( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array() ),
			'scheduling_policy' => 'create_paused_upgrade_preserve_existing',
			'queue_policy'      => 'create_seed_upgrade_preserve_existing',
		);
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

	private function normalize_current_flow_ids( array $flow ): array {
		$new_pipeline_id = (int) ( $flow['pipeline_id'] ?? 0 );
		$flow_id         = (int) ( $flow['flow_id'] ?? 0 );
		$flow_config     = is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array();

		foreach ( $flow_config as $step_config ) {
			if ( ! is_array( $step_config ) || ! isset( $step_config['pipeline_id'] ) ) {
				continue;
			}

			$old_pipeline_id = (int) $step_config['pipeline_id'];
			if ( $old_pipeline_id > 0 && $new_pipeline_id > 0 && $flow_id > 0 ) {
				$flow['flow_config'] = $this->remap_flow_step_ids( $flow_config, $old_pipeline_id, $new_pipeline_id, $flow_id );
			}

			break;
		}

		return $flow;
	}

	private function remap_pipeline_step_ids( array $pipeline_config, int $old_pipeline_id, int $new_pipeline_id ): array {
		$remapped = array();

		foreach ( $pipeline_config as $pipeline_step_id => $step_config ) {
			$new_pipeline_step_id = $this->remap_step_id_prefix( (string) $pipeline_step_id, $old_pipeline_id, $new_pipeline_id );
			if ( is_array( $step_config ) ) {
				$step_config['pipeline_step_id'] = $new_pipeline_step_id;
			}

			$remapped[ $new_pipeline_step_id ] = $step_config;
		}

		return $remapped;
	}

	private function remap_flow_step_ids( array $flow_config, int $old_pipeline_id, int $new_pipeline_id, int $new_flow_id ): array {
		$remapped = array();

		foreach ( $flow_config as $flow_step_id => $step_config ) {
			$pipeline_step_id = is_array( $step_config ) && is_string( $step_config['pipeline_step_id'] ?? null )
				? $step_config['pipeline_step_id']
				: preg_replace( '/_\d+$/', '', (string) $flow_step_id );
			$pipeline_step_id = $this->remap_step_id_prefix( (string) $pipeline_step_id, $old_pipeline_id, $new_pipeline_id );
			$new_flow_step_id = $pipeline_step_id . '_' . $new_flow_id;

			if ( is_array( $step_config ) ) {
				$step_config['pipeline_step_id'] = $pipeline_step_id;
				$step_config['pipeline_id']      = $new_pipeline_id;
				$step_config['flow_id']          = $new_flow_id;
				$step_config['flow_step_id']     = $new_flow_step_id;
			}

			$remapped[ $new_flow_step_id ] = $step_config;
		}

		return $remapped;
	}

	private function remap_step_id_prefix( string $step_id, int $old_pipeline_id, int $new_pipeline_id ): string {
		$prefix = $old_pipeline_id . '_';
		if ( $old_pipeline_id === $new_pipeline_id || ! str_starts_with( $step_id, $prefix ) ) {
			return $step_id;
		}

		return $new_pipeline_id . '_' . substr( $step_id, strlen( $prefix ) );
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

	private function installed_status( array $agent ): array {
		$bundle    = $agent['agent_config']['datamachine_bundle'] ?? array();
		$artifacts = is_array( $bundle['artifacts'] ?? null ) ? $bundle['artifacts'] : array();

		return array(
			'agent_id'        => (int) $agent['agent_id'],
			'agent_slug'      => (string) $agent['agent_slug'],
			'bundle_slug'     => (string) ( $bundle['bundle_slug'] ?? '' ),
			'bundle_version'  => (string) ( $bundle['bundle_version'] ?? '' ),
			'source_ref'      => (string) ( $bundle['source_ref'] ?? '' ),
			'source_revision' => (string) ( $bundle['source_revision'] ?? '' ),
			'artifact_count'  => count( $artifacts ),
			'artifacts'       => array_values( $artifacts ),
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
