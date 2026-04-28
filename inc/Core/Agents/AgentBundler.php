<?php
/**
 * Agent Bundler
 *
 * Serializes and deserializes agent bundles for export/import.
 * A bundle contains everything needed to recreate an agent on
 * another Data Machine installation: identity files, database
 * config, pipelines, flows, and associated memory files.
 *
 * @package DataMachine\Core\Agents
 * @since 0.58.0
 */

namespace DataMachine\Core\Agents;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleLegacyAdapter;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PortableSlug;

defined( 'ABSPATH' ) || exit;

class AgentBundler {

	/**
	 * Bundle format version for forward compatibility.
	 */
	const BUNDLE_VERSION = 1;

	/**
	 * @var Agents
	 */
	private Agents $agents_repo;

	/**
	 * @var Pipelines
	 */
	private Pipelines $pipelines_repo;

	/**
	 * @var Flows
	 */
	private Flows $flows_repo;

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	public function __construct() {
		$this->agents_repo       = new Agents();
		$this->pipelines_repo    = new Pipelines();
		$this->flows_repo        = new Flows();
		$this->directory_manager = new DirectoryManager();
	}

	/**
	 * Export an agent into a portable bundle array.
	 *
	 * @param string $slug Agent slug.
	 * @return array{success: bool, bundle?: array, error?: string}
	 */
	public function export( string $slug ): array {
		$agent = $this->agents_repo->get_by_slug( sanitize_title( $slug ) );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent "%s" not found.', $slug ),
			);
		}

		$agent_id = (int) $agent['agent_id'];

		// 1. Agent identity.
		$bundle = array(
			'bundle_version'        => self::BUNDLE_VERSION,
			'bundle_schema_version' => self::BUNDLE_VERSION,
			'bundle_slug'           => sanitize_title( $agent['agent_slug'] ),
			'exported_at'           => gmdate( 'c' ),
			'agent'                 => array(
				'agent_slug'   => $agent['agent_slug'],
				'agent_name'   => $agent['agent_name'],
				'agent_config' => $agent['agent_config'] ?? array(),
				'site_scope'   => $agent['site_scope'],
			),
		);

		// 2. Agent identity files (SOUL.md, MEMORY.md).
		$bundle['files'] = $this->collect_agent_files( $agent['agent_slug'] );

		// 3. Owner's USER.md template (without sensitive data).
		$owner_id                = (int) $agent['owner_id'];
		$bundle['user_template'] = $this->collect_user_template( $owner_id );

		// 4. Pipelines scoped to this agent.
		$pipelines           = $this->pipelines_repo->get_all_pipelines( null, $agent_id );
		$bundle['pipelines'] = array();

		foreach ( $pipelines as $pipeline ) {
			$pipeline_id   = (int) $pipeline['pipeline_id'];
			$portable_slug = ! empty( $pipeline['portable_slug'] )
				? $pipeline['portable_slug']
				: PortableSlug::normalize( (string) $pipeline['pipeline_name'], 'pipeline' );
			$pipeline_data = array(
				'original_id'     => $pipeline_id,
				'portable_slug'   => $portable_slug,
				'pipeline_name'   => $pipeline['pipeline_name'],
				'pipeline_config' => $pipeline['pipeline_config'] ?? array(),
			);

			// Collect pipeline memory files from disk.
			$pipeline_data['memory_file_contents'] = $this->collect_pipeline_memory_files( $pipeline_id );

			$bundle['pipelines'][] = $pipeline_data;
		}

		// 5. Flows scoped to this agent.
		$flows           = $this->flows_repo->get_all_flows( null, $agent_id );
		$bundle['flows'] = array();

		foreach ( $flows as $flow ) {
			$flow_id       = (int) $flow['flow_id'];
			$portable_slug = ! empty( $flow['portable_slug'] )
				? $flow['portable_slug']
				: PortableSlug::normalize( (string) $flow['flow_name'], 'flow' );
			$flow_data     = array(
				'original_id'          => $flow_id,
				'original_pipeline_id' => (int) $flow['pipeline_id'],
				'portable_slug'        => $portable_slug,
				'flow_name'            => $flow['flow_name'],
				'flow_config'          => $flow['flow_config'] ?? array(),
				'scheduling_config'    => $this->sanitize_scheduling_config( $flow['scheduling_config'] ?? array() ),
			);

			// Collect flow memory files from disk.
			$flow_data['memory_file_contents'] = $this->collect_flow_memory_files(
				(int) $flow['pipeline_id'],
				$flow_id
			);

			$bundle['flows'][] = $flow_data;
		}

		// 6. Abilities manifest — list of ability slugs registered system-wide.
		// Importers can use this to verify the target has matching abilities.
		$bundle['abilities_manifest'] = $this->collect_abilities_manifest();

		return array(
			'success' => true,
			'bundle'  => $bundle,
		);
	}

	/**
	 * Import an agent from a bundle array.
	 *
	 * @param array       $bundle   The bundle data.
	 * @param string|null $new_slug Optional override slug.
	 * @param int         $owner_id WordPress user ID to own the imported agent.
	 * @param bool        $dry_run  If true, validate without writing.
	 * @return array{success: bool, message?: string, error?: string, summary?: array}
	 */
	public function import( array $bundle, ?string $new_slug = null, int $owner_id = 0, bool $dry_run = false ): array {
		// Validate bundle.
		if ( empty( $bundle['bundle_version'] ) || empty( $bundle['agent'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid bundle: missing bundle_version or agent data.',
			);
		}

		$agent_data             = $bundle['agent'];
		$slug                   = $new_slug
			? sanitize_title( $new_slug )
			: sanitize_title( $agent_data['agent_slug'] );
		$bundle_slug            = PortableSlug::normalize( (string) ( $bundle['bundle_slug'] ?? $slug ), 'bundle' );
		$bundle_version         = trim( (string) ( $bundle['bundle_version'] ?? self::BUNDLE_VERSION ) );
		$bundle_source_ref      = trim( (string) ( $bundle['source_ref'] ?? '' ) );
		$bundle_source_revision = trim( (string) ( $bundle['source_revision'] ?? '' ) );
		$bundle_metadata        = array(
			'bundle_slug'     => $bundle_slug,
			'bundle_version'  => $bundle_version,
			'source_ref'      => $bundle_source_ref,
			'source_revision' => $bundle_source_revision,
		);
		$is_portable_bundle     = ! empty( $bundle['bundle_slug'] ) || $this->bundle_has_portable_artifacts( $bundle );

		// Check for slug collision.
		$existing = $this->agents_repo->get_by_slug( $slug );
		if ( $existing && ( $new_slug || ! $is_portable_bundle ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent slug "%s" already exists. Use --slug=<new-slug> to rename on import.', $slug ),
			);
		}
		if ( $existing ) {
			$installed_bundle = $existing['agent_config']['datamachine_bundle'] ?? array();
			if ( ! empty( $installed_bundle['bundle_slug'] ) && $installed_bundle['bundle_slug'] !== $bundle_slug ) {
				return array(
					'success' => false,
					'error'   => sprintf( 'Agent slug "%s" is installed from bundle "%s", not "%s".', $slug, $installed_bundle['bundle_slug'], $bundle_slug ),
				);
			}
		}

		// Resolve owner.
		if ( $owner_id <= 0 ) {
			$owner_id = get_current_user_id();
			if ( $owner_id <= 0 ) {
				// WP-CLI context: fall back to first admin.
				$admins   = get_users( array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				) );
				$owner_id = ! empty( $admins ) ? (int) $admins[0] : 1;
			}
		}

		// Build summary for dry-run reporting.
		$summary = array(
			'agent_slug'        => $slug,
			'agent_name'        => $agent_data['agent_name'],
			'owner_id'          => $owner_id,
			'bundle_slug'       => $bundle_slug,
			'bundle_version'    => $bundle_version,
			'files'             => count( $bundle['files'] ?? array() ),
			'pipelines'         => count( $bundle['pipelines'] ?? array() ),
			'flows'             => count( $bundle['flows'] ?? array() ),
			'has_user_template' => ! empty( $bundle['user_template'] ),
			'upgrade'           => (bool) $existing,
		);

		if ( $dry_run ) {
			// Check ability mismatches.
			$missing_abilities            = $this->check_abilities_manifest( $bundle['abilities_manifest'] ?? array() );
			$summary['missing_abilities'] = $missing_abilities;

			return array(
				'success' => true,
				'message' => 'Dry run — no changes made.',
				'summary' => $summary,
			);
		}

		// --- Actual import ---

		// 1. Create or update the agent record.
		$incoming_config = $agent_data['agent_config'] ?? array();

		$incoming_config = is_array( $incoming_config ) ? $incoming_config : array();

		$config = is_array( $existing['agent_config'] ?? null )
			? array_merge( $existing['agent_config'], $incoming_config )
			: $incoming_config;

		$existing_bundle_state = is_array( $existing['agent_config']['datamachine_bundle'] ?? null )
			? $existing['agent_config']['datamachine_bundle']
			: array();

		$config['datamachine_bundle'] = array_merge(
			$existing_bundle_state,
			$bundle_metadata,
			array( 'artifacts' => $existing_bundle_state['artifacts'] ?? array() )
		);

		if ( $existing ) {
			$agent_id = (int) $existing['agent_id'];
			$this->agents_repo->update_agent(
				$agent_id,
				array(
					'agent_name'   => $agent_data['agent_name'] ?? $slug,
					'agent_config' => $config,
				)
			);
		} else {
			$agent_id = $this->agents_repo->create_if_missing(
				$slug,
				$agent_data['agent_name'] ?? $slug,
				$owner_id,
				$config
			);
		}

		if ( ! $agent_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent record.',
			);
		}

		// 2. Write agent identity files.
		$this->write_agent_files( $slug, $bundle['files'] ?? array() );

		// 3. Write USER.md template if provided.
		if ( ! empty( $bundle['user_template'] ) ) {
			$this->write_user_template( $owner_id, $bundle['user_template'] );
		}

		$artifact_records = $config['datamachine_bundle']['artifacts'] ?? array();
		$conflicts        = array();

		// 4. Import pipelines — build old→new ID map.
		$pipeline_id_map = array(); // old_id => new_id.
		foreach ( $bundle['pipelines'] ?? array() as $pipeline_data ) {
			$old_id            = (int) ( $pipeline_data['original_id'] ?? 0 );
			$portable_slug     = PortableSlug::normalize(
				(string) ( $pipeline_data['portable_slug'] ?? ( $pipeline_data['pipeline_name'] ?? 'pipeline' ) ),
				'pipeline'
			);
			$artifact_key      = 'pipeline:' . $portable_slug;
			$payload           = $this->pipeline_artifact_payload( $pipeline_data, $portable_slug );
			$existing_pipeline = $this->pipelines_repo->get_by_portable_slug( $agent_id, $portable_slug );

			if (
				$existing_pipeline
				&& $this->artifact_has_local_modifications(
					$artifact_records[ $artifact_key ] ?? null,
					$this->pipeline_artifact_payload( $existing_pipeline, $portable_slug )
				)
			) {
				$conflicts[]                = array(
					'artifact_type' => 'pipeline',
					'artifact_id'   => $portable_slug,
					'reason'        => 'local_modified',
				);
				$pipeline_id_map[ $old_id ] = (int) $existing_pipeline['pipeline_id'];
				continue;
			}

			if ( $existing_pipeline ) {
				$new_pipeline_id = (int) $existing_pipeline['pipeline_id'];
				$this->pipelines_repo->update_pipeline(
					$new_pipeline_id,
					array(
						'pipeline_name'   => $pipeline_data['pipeline_name'],
						'pipeline_config' => $pipeline_data['pipeline_config'] ?? array(),
						'portable_slug'   => $portable_slug,
					)
				);
			} else {
				$new_pipeline_id = $this->pipelines_repo->create_pipeline( array(
					'pipeline_name'   => $pipeline_data['pipeline_name'],
					'pipeline_config' => $pipeline_data['pipeline_config'] ?? array(),
					'portable_slug'   => $portable_slug,
					'agent_id'        => $agent_id,
					'user_id'         => $owner_id,
				) );
			}

			if ( $new_pipeline_id ) {
				$pipeline_id_map[ $old_id ]        = (int) $new_pipeline_id;
				$artifact_records[ $artifact_key ] = $this->bundle_artifact_record(
					$bundle_metadata,
					'pipeline',
					$portable_slug,
					'pipelines/' . $portable_slug . '.json',
					$payload
				);

				// Write pipeline memory files to disk.
				$this->write_pipeline_memory_files(
					(int) $new_pipeline_id,
					$pipeline_data['memory_file_contents'] ?? array()
				);
			}
		}

		// 5. Import flows: create paused, preserve local schedules/queues on update.
		$flow_count = 0;
		foreach ( $bundle['flows'] ?? array() as $flow_data ) {
			$old_pipeline_id = (int) ( $flow_data['original_pipeline_id'] ?? 0 );
			$new_pipeline_id = $pipeline_id_map[ $old_pipeline_id ] ?? null;

			if ( ! $new_pipeline_id ) {
				continue; // Skip orphan flows.
			}

			$portable_slug = PortableSlug::normalize(
				(string) ( $flow_data['portable_slug'] ?? ( $flow_data['flow_name'] ?? 'flow' ) ),
				'flow'
			);
			$artifact_key  = 'flow:' . $portable_slug;

			// Force paused/manual scheduling on create.
			$scheduling            = $flow_data['scheduling_config'] ?? array();
			$scheduling['enabled'] = false;
			if ( ! isset( $scheduling['interval'] ) || 'manual' !== $scheduling['interval'] ) {
				$scheduling['_original_interval'] = $scheduling['interval'] ?? 'manual';
				$scheduling['interval']           = 'manual';
			}

			$flow_config = $flow_data['flow_config'] ?? array();

			// Remap pipeline step IDs inside flow_config.
			$flow_config         = $this->remap_flow_step_ids( $flow_config, $old_pipeline_id, $new_pipeline_id );
			$existing_flow       = $this->flows_repo->get_by_portable_slug( (int) $new_pipeline_id, $portable_slug );
			$flow_payload_source = array_merge( $flow_data, array( 'flow_config' => $flow_config ) );
			$payload             = $this->flow_artifact_payload( $flow_payload_source, $portable_slug );

			if (
				$existing_flow
				&& $this->artifact_has_local_modifications(
					$artifact_records[ $artifact_key ] ?? null,
					$this->flow_artifact_payload( $existing_flow, $portable_slug )
				)
			) {
				$conflicts[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $portable_slug,
					'reason'        => 'local_modified',
				);
				continue;
			}

			if ( $existing_flow ) {
				$new_flow_id = (int) $existing_flow['flow_id'];
				$flow_config = $this->preserve_runtime_queue_fields( $flow_config, $existing_flow['flow_config'] ?? array() );
				$this->flows_repo->update_flow(
					$new_flow_id,
					array(
						'flow_name'     => $flow_data['flow_name'],
						'flow_config'   => $flow_config,
						'portable_slug' => $portable_slug,
					)
				);
			} else {
				$new_flow_id = $this->flows_repo->create_flow( array(
					'pipeline_id'       => $new_pipeline_id,
					'flow_name'         => $flow_data['flow_name'],
					'flow_config'       => $flow_config,
					'scheduling_config' => $scheduling,
					'portable_slug'     => $portable_slug,
					'agent_id'          => $agent_id,
					'user_id'           => $owner_id,
				) );
			}

			if ( $new_flow_id ) {
				++$flow_count;
				$artifact_records[ $artifact_key ] = $this->bundle_artifact_record(
					$bundle_metadata,
					'flow',
					$portable_slug,
					'flows/' . $portable_slug . '.json',
					$payload
				);

				// Write flow memory files to disk.
				$this->write_flow_memory_files(
					$new_pipeline_id,
					(int) $new_flow_id,
					$flow_data['memory_file_contents'] ?? array()
				);
			}
		}

		$summary['agent_id']           = $agent_id;
		$summary['pipelines_imported'] = count( $pipeline_id_map );
		$summary['flows_imported']     = $flow_count;
		$summary['conflicts']          = $conflicts;

		$config['datamachine_bundle']['artifacts'] = $artifact_records;
		$this->agents_repo->update_agent( $agent_id, array( 'agent_config' => $config ) );

		return array(
			'success' => true,
			'message' => sprintf(
				'Agent "%s" imported successfully (ID: %d, %d pipeline(s), %d flow(s)).',
				$slug,
				$agent_id,
				count( $pipeline_id_map ),
				$flow_count
			),
			'summary' => $summary,
		);
	}

	private function bundle_has_portable_artifacts( array $bundle ): bool {
		foreach ( $bundle['pipelines'] ?? array() as $pipeline ) {
			if ( ! empty( $pipeline['portable_slug'] ) ) {
				return true;
			}
		}
		foreach ( $bundle['flows'] ?? array() as $flow ) {
			if ( ! empty( $flow['portable_slug'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function pipeline_artifact_payload( array $pipeline, string $portable_slug ): array {
		return array(
			'portable_slug'   => $portable_slug,
			'pipeline_name'   => (string) ( $pipeline['pipeline_name'] ?? '' ),
			'pipeline_config' => is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array(),
		);
	}

	private function flow_artifact_payload( array $flow, string $portable_slug ): array {
		return array(
			'portable_slug'     => $portable_slug,
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => $this->flow_config_without_runtime_queues( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array() ),
			'scheduling_policy' => 'create_paused_upgrade_preserve_existing',
			'queue_policy'      => 'create_seed_upgrade_preserve_existing',
		);
	}

	private function artifact_has_local_modifications( ?array $record, array $current_payload ): bool {
		if ( empty( $record['installed_hash'] ) ) {
			return false;
		}

		return AgentBundleArtifactStatus::MODIFIED === AgentBundleArtifactStatus::classify(
			(string) $record['installed_hash'],
			AgentBundleArtifactHasher::hash( $current_payload )
		);
	}

	private function bundle_artifact_record( array $bundle_metadata, string $type, string $id, string $source_path, array $payload ): array {
		$hash = AgentBundleArtifactHasher::hash( $payload );
		$now  = gmdate( 'c' );

		return array(
			'bundle_slug'    => $bundle_metadata['bundle_slug'],
			'bundle_version' => $bundle_metadata['bundle_version'],
			'artifact_type'  => $type,
			'artifact_id'    => $id,
			'source_path'    => $source_path,
			'installed_hash' => $hash,
			'current_hash'   => $hash,
			'status'         => AgentBundleArtifactStatus::CLEAN,
			'installed_at'   => $now,
			'updated_at'     => $now,
		);
	}

	private function preserve_runtime_queue_fields( array $incoming_flow_config, array $existing_flow_config ): array {
		foreach ( $incoming_flow_config as $flow_step_id => &$step ) {
			if ( ! is_array( $step ) || ! is_array( $existing_flow_config[ $flow_step_id ] ?? null ) ) {
				continue;
			}
			foreach ( array( 'prompt_queue', 'config_patch_queue', 'queue_mode' ) as $field ) {
				if ( array_key_exists( $field, $existing_flow_config[ $flow_step_id ] ) ) {
					$step[ $field ] = $existing_flow_config[ $flow_step_id ][ $field ];
				}
			}
		}
		unset( $step );

		return $incoming_flow_config;
	}

	private function flow_config_without_runtime_queues( array $flow_config ): array {
		foreach ( $flow_config as &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			unset( $step['prompt_queue'], $step['config_patch_queue'], $step['queue_mode'] );
		}
		unset( $step );

		return $flow_config;
	}

	/**
	 * Collect agent identity files from disk.
	 *
	 * @param string $slug Agent slug.
	 * @return array<string, string> filename => content.
	 */
	private function collect_agent_files( string $slug ): array {
		$agent_dir = $this->directory_manager->get_agent_identity_directory( $slug );
		$files     = array();

		if ( ! is_dir( $agent_dir ) ) {
			return $files;
		}

		$identity_files = array( 'SOUL.md', 'MEMORY.md' );

		foreach ( $identity_files as $filename ) {
			$path = $agent_dir . '/' . $filename;
			if ( file_exists( $path ) ) {
				$files[ $filename ] = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		return $files;
	}

	/**
	 * Collect USER.md template for the agent owner.
	 *
	 * @param int $user_id Owner user ID.
	 * @return string USER.md content or empty string.
	 */
	private function collect_user_template( int $user_id ): string {
		$user_dir = $this->directory_manager->get_user_directory( $user_id );
		$path     = $user_dir . '/USER.md';

		if ( file_exists( $path ) ) {
			return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return '';
	}

	/**
	 * Collect memory files stored on disk for a pipeline.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @return array<string, string> filename => content.
	 */
	private function collect_pipeline_memory_files( int $pipeline_id ): array {
		$pipeline_dir = $this->directory_manager->get_pipeline_directory( $pipeline_id );
		return $this->collect_directory_files( $pipeline_dir );
	}

	/**
	 * Collect memory files stored on disk for a flow.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @param int $flow_id     Flow ID.
	 * @return array<string, string> filename => content.
	 */
	private function collect_flow_memory_files( int $pipeline_id, int $flow_id ): array {
		$flow_dir = $this->directory_manager->get_flow_directory( $pipeline_id, $flow_id );
		$files    = $this->collect_directory_files( $flow_dir );

		// Also collect flow-specific files directory.
		$flow_files_dir = $this->directory_manager->get_flow_files_directory( $pipeline_id, $flow_id );
		$flow_files     = $this->collect_directory_files( $flow_files_dir, 'files/' );

		return array_merge( $files, $flow_files );
	}

	/**
	 * Collect all files from a directory (non-recursive, .md and .txt only).
	 *
	 * @param string $directory Directory path.
	 * @param string $prefix    Path prefix for keys.
	 * @return array<string, string> relative_path => content.
	 */
	private function collect_directory_files( string $directory, string $prefix = '' ): array {
		$files = array();

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$iterator = new \DirectoryIterator( $directory );
		foreach ( $iterator as $file ) {
			if ( $file->isDot() || ! $file->isFile() ) {
				continue;
			}

			// Only include text-based files.
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'md', 'txt', 'json', 'yaml', 'yml', 'csv' ), true ) ) {
				continue;
			}

			// Skip job directories.
			if ( str_starts_with( $file->getFilename(), 'job-' ) ) {
				continue;
			}

			$relative_path           = $prefix . $file->getFilename();
			$files[ $relative_path ] = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $files;
	}

	/**
	 * Collect abilities manifest — all registered ability slugs.
	 *
	 * @return array List of ability slugs.
	 */
	private function collect_abilities_manifest(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities();
		return array_keys( $abilities );
	}

	/**
	 * Check which abilities from the manifest are missing.
	 *
	 * @param array $manifest List of ability slugs.
	 * @return array Missing ability slugs.
	 */
	private function check_abilities_manifest( array $manifest ): array {
		if ( empty( $manifest ) || ! function_exists( 'wp_get_ability' ) ) {
			return array();
		}

		$missing = array();
		foreach ( $manifest as $slug ) {
			if ( ! wp_get_ability( $slug ) ) {
				$missing[] = $slug;
			}
		}

		return $missing;
	}

	/**
	 * Sanitize scheduling config for export.
	 *
	 * Removes runtime state that shouldn't be exported.
	 *
	 * @param array $config Scheduling config.
	 * @return array Cleaned config.
	 */
	private function sanitize_scheduling_config( array $config ): array {
		// Remove runtime-only fields.
		unset( $config['last_run'] );
		unset( $config['next_run'] );
		unset( $config['run_count'] );

		return $config;
	}

	/**
	 * Write agent identity files to disk.
	 *
	 * @param string $slug  Agent slug.
	 * @param array  $files filename => content map.
	 */
	private function write_agent_files( string $slug, array $files ): void {
		$agent_dir = $this->directory_manager->get_agent_identity_directory( $slug );
		$this->directory_manager->ensure_directory_exists( $agent_dir );

		foreach ( $files as $relative_path => $content ) {
			$full_path = $agent_dir . '/' . $relative_path;

			// Ensure subdirectories exist (e.g., contexts/).
			$dir = dirname( $full_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			file_put_contents( $full_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Write USER.md template for the new owner.
	 *
	 * Only writes if USER.md doesn't already exist (don't overwrite).
	 *
	 * @param int    $owner_id Owner user ID.
	 * @param string $content  USER.md content.
	 */
	private function write_user_template( int $owner_id, string $content ): void {
		$user_dir = $this->directory_manager->get_user_directory( $owner_id );
		$path     = $user_dir . '/USER.md';

		// Don't overwrite existing USER.md.
		if ( file_exists( $path ) ) {
			return;
		}

		if ( ! is_dir( $user_dir ) ) {
			wp_mkdir_p( $user_dir );
		}

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Write pipeline memory files to disk at the new pipeline's directory.
	 *
	 * @param int   $pipeline_id New pipeline ID.
	 * @param array $files       filename => content map.
	 */
	private function write_pipeline_memory_files( int $pipeline_id, array $files ): void {
		if ( empty( $files ) ) {
			return;
		}

		$pipeline_dir = $this->directory_manager->get_pipeline_directory( $pipeline_id );
		$this->directory_manager->ensure_directory_exists( $pipeline_dir );

		foreach ( $files as $filename => $content ) {
			$full_path = $pipeline_dir . '/' . $filename;
			$dir       = dirname( $full_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			file_put_contents( $full_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Write flow memory files to disk at the new flow's directory.
	 *
	 * @param int   $pipeline_id New pipeline ID.
	 * @param int   $flow_id     New flow ID.
	 * @param array $files       filename => content map.
	 */
	private function write_flow_memory_files( int $pipeline_id, int $flow_id, array $files ): void {
		if ( empty( $files ) ) {
			return;
		}

		$flow_dir = $this->directory_manager->get_flow_directory( $pipeline_id, $flow_id );
		$this->directory_manager->ensure_directory_exists( $flow_dir );

		foreach ( $files as $relative_path => $content ) {
			// Handle files/ prefix for flow files directory.
			if ( str_starts_with( $relative_path, 'files/' ) ) {
				$flow_files_dir = $this->directory_manager->get_flow_files_directory( $pipeline_id, $flow_id );
				if ( ! is_dir( $flow_files_dir ) ) {
					wp_mkdir_p( $flow_files_dir );
				}
				$filename  = substr( $relative_path, 6 ); // Strip 'files/' prefix.
				$full_path = $flow_files_dir . '/' . $filename;
			} else {
				$full_path = $flow_dir . '/' . $relative_path;
			}

			$dir = dirname( $full_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			file_put_contents( $full_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Remap pipeline step IDs inside a flow config.
	 *
	 * Pipeline step IDs have the format {pipeline_id}_{uuid}. When importing,
	 * the pipeline ID changes, so we need to rewrite these keys.
	 *
	 * @param array $flow_config      Flow config.
	 * @param int   $old_pipeline_id  Original pipeline ID.
	 * @param int   $new_pipeline_id  New pipeline ID.
	 * @return array Updated flow config.
	 */
	private function remap_flow_step_ids( array $flow_config, int $old_pipeline_id, int $new_pipeline_id ): array {
		if ( $old_pipeline_id === $new_pipeline_id ) {
			return $flow_config;
		}

		$remapped = array();
		$prefix   = $old_pipeline_id . '_';

		foreach ( $flow_config as $key => $value ) {
			// Remap step ID keys that start with old pipeline ID.
			if ( is_string( $key ) && str_starts_with( $key, $prefix ) ) {
				$new_key              = $new_pipeline_id . '_' . substr( $key, strlen( $prefix ) );
				$remapped[ $new_key ] = $value;
			} else {
				$remapped[ $key ] = $value;
			}
		}

		return $remapped;
	}

	/**
	 * Serialize a bundle to JSON string.
	 *
	 * @param array $bundle Bundle data.
	 * @return string JSON string.
	 */
	public function to_json( array $bundle ): string {
		return wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Parse a bundle from JSON string.
	 *
	 * @param string $json JSON string.
	 * @return array|null Bundle data or null on parse failure.
	 */
	public function from_json( string $json ): ?array {
		$bundle = json_decode( $json, true );

		if ( ! is_array( $bundle ) ) {
			return null;
		}

		return $bundle;
	}

	/**
	 * Write bundle as a directory of files.
	 *
	 * @param array  $bundle    Bundle data.
	 * @param string $directory Target directory.
	 * @return bool True on success.
	 */
	public function to_directory( array $bundle, string $directory ): bool {
		try {
			AgentBundleLegacyAdapter::from_legacy_bundle( $bundle )->write( $directory );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Read bundle from a directory export.
	 *
	 * @param string $directory Source directory.
	 * @return array|null Bundle data or null on failure.
	 */
	public function from_directory( string $directory ): ?array {
		try {
			return AgentBundleLegacyAdapter::to_legacy_bundle( AgentBundleDirectory::read( $directory ) );
		} catch ( BundleValidationException $e ) {
			unset( $e );
			// Fall through to the legacy monolithic manifest reader for old exports.
		}

		$manifest_path = $directory . '/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return null;
		}

		$manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $manifest ) ) {
			return null;
		}

		$bundle = $manifest;

		// Read agent identity files.
		$agent_dir       = $directory . '/agent';
		$bundle['files'] = array();
		if ( is_dir( $agent_dir ) ) {
			$bundle['files'] = $this->read_directory_recursive( $agent_dir );
		}

		// Read USER.md template.
		$user_md_path            = $directory . '/USER.md';
		$bundle['user_template'] = file_exists( $user_md_path )
			? file_get_contents( $user_md_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: '';

		// Read pipeline memory files.
		$pipelines_dir = $directory . '/pipelines';
		if ( is_dir( $pipelines_dir ) ) {
			$pipeline_dirs = glob( $pipelines_dir . '/*', GLOB_ONLYDIR );
			foreach ( $pipeline_dirs as $i => $pipeline_dir ) {
				if ( isset( $bundle['pipelines'][ $i ] ) ) {
					$bundle['pipelines'][ $i ]['memory_file_contents'] = $this->read_directory_recursive( $pipeline_dir );
				}
			}
		}

		// Read flow memory files.
		$flows_dir = $directory . '/flows';
		if ( is_dir( $flows_dir ) ) {
			$flow_dirs = glob( $flows_dir . '/*', GLOB_ONLYDIR );
			foreach ( $flow_dirs as $i => $flow_dir ) {
				if ( isset( $bundle['flows'][ $i ] ) ) {
					$bundle['flows'][ $i ]['memory_file_contents'] = $this->read_directory_recursive( $flow_dir );
				}
			}
		}

		return $bundle;
	}

	/**
	 * Read all text files from a directory recursively.
	 *
	 * @param string $directory Directory to read.
	 * @param string $prefix    Path prefix.
	 * @return array<string, string> relative_path => content.
	 */
	private function read_directory_recursive( string $directory, string $prefix = '' ): array {
		$files = array();

		if ( ! is_dir( $directory ) ) {
			return $files;
		}

		$iterator = new \DirectoryIterator( $directory );
		foreach ( $iterator as $item ) {
			if ( $item->isDot() ) {
				continue;
			}

			$relative = $prefix . $item->getFilename();

			if ( $item->isDir() ) {
				$sub_files = $this->read_directory_recursive( $item->getPathname(), $relative . '/' );
				$files     = array_merge( $files, $sub_files );
			} elseif ( $item->isFile() ) {
				$ext = strtolower( $item->getExtension() );
				if ( in_array( $ext, array( 'md', 'txt', 'json', 'yaml', 'yml', 'csv' ), true ) ) {
					$files[ $relative ] = file_get_contents( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
			}
		}

		return $files;
	}

	/**
	 * Create a ZIP archive from a bundle.
	 *
	 * @param array  $bundle    Bundle data.
	 * @param string $zip_path  Path for the ZIP file.
	 * @return bool True on success.
	 */
	public function to_zip( array $bundle, string $zip_path ): bool {
		// Write to temp directory first, then zip.
		$temp_dir = sys_get_temp_dir() . '/dm-agent-export-' . uniqid();
		wp_mkdir_p( $temp_dir );

		$slug    = sanitize_title( $bundle['agent']['agent_slug'] ?? 'agent' );
		$sub_dir = $temp_dir . '/' . $slug;

		$wrote = $this->to_directory( $bundle, $sub_dir );
		if ( ! $wrote ) {
			$this->rm_rf( $temp_dir );
			return false;
		}

		// Create ZIP.
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			$this->rm_rf( $temp_dir );
			return false;
		}

		$this->add_directory_to_zip( $zip, $sub_dir, $slug );
		$zip->close();

		$this->rm_rf( $temp_dir );
		return true;
	}

	/**
	 * Read a bundle from a ZIP archive.
	 *
	 * @param string $zip_path Path to ZIP file.
	 * @return array|null Bundle data or null on failure.
	 */
	public function from_zip( string $zip_path ): ?array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}

		$temp_dir = sys_get_temp_dir() . '/dm-agent-import-' . uniqid();
		wp_mkdir_p( $temp_dir );

		$zip->extractTo( $temp_dir );
		$zip->close();

		// Find the manifest.json — it might be in a subdirectory.
		$bundle = null;

		if ( file_exists( $temp_dir . '/manifest.json' ) ) {
			$bundle = $this->from_directory( $temp_dir );
		} else {
			// Look one level deep.
			$subdirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );
			foreach ( $subdirs as $subdir ) {
				if ( file_exists( $subdir . '/manifest.json' ) ) {
					$bundle = $this->from_directory( $subdir );
					break;
				}
			}
		}

		$this->rm_rf( $temp_dir );
		return $bundle;
	}

	/**
	 * Add a directory to a ZIP archive recursively.
	 *
	 * @param \ZipArchive $zip       ZIP archive.
	 * @param string      $directory Directory to add.
	 * @param string      $prefix    Path prefix in ZIP.
	 */
	private function add_directory_to_zip( \ZipArchive $zip, string $directory, string $prefix ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative_path = $prefix . '/' . substr( $item->getPathname(), strlen( $directory ) + 1 );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative_path );
			} else {
				$zip->addFile( $item->getPathname(), $relative_path );
			}
		}
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function rm_rf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
