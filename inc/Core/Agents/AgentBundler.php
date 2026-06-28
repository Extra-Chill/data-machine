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
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactPayloads;
use DataMachine\Engine\Bundle\AgentBundleArtifactDefinitions;
use DataMachine\Engine\Bundle\AgentBundleArtifactExtensions;
use DataMachine\Engine\Bundle\AgentBundleArtifactState;
use DataMachine\Engine\Bundle\AgentBundleAgentConfig;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleMemoryArtifact;
use DataMachine\Engine\Bundle\BundleStepIdRemapper;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleFlowFile;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\AgentBundleRuntimeDrift;
use DataMachine\Engine\Bundle\AgentBundlePipelineFile;
use DataMachine\Engine\Bundle\AgentConfigArtifactProjector;
use DataMachine\Engine\Bundle\AgentTemplateMetadata;
use DataMachine\Engine\Bundle\AgentPackageProjection;
use DataMachine\Engine\Bundle\BundleSchema;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PortableSlug;
use DataMachine\Engine\Bundle\PromptArtifact;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\AI\System\SystemTaskPromptRegistry;

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
		$result = $this->export_directory_object( $slug );
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$directory = $result['directory'] ?? null;
		if ( ! $directory instanceof AgentBundleDirectory ) {
			return array(
				'success' => false,
				'error'   => 'Failed to build agent bundle directory.',
			);
		}

		$bundle                       = AgentBundleArrayAdapter::to_array_bundle( $directory );
		$bundle['abilities_manifest'] = $this->collect_abilities_manifest();

		return array(
			'success' => true,
			'bundle'  => $bundle,
		);
	}

	/**
	 * Export an agent into review-friendly bundle directory value objects.
	 *
	 * @param string $slug Agent slug.
	 * @return array{success: bool, directory?: AgentBundleDirectory, agent?: array, error?: string}
	 */
	public function export_directory_object( string $slug, array $context = array() ): array {
		$agent = $this->agents_repo->get_by_slug( sanitize_title( $slug ) );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent "%s" not found.', $slug ),
			);
		}

		$agent_id        = (int) $agent['agent_id'];
		$export_manifest = self::resolve_export_manifest( $agent_id, $context );
		$handler_auth    = (string) $export_manifest['handler_auth'];

		$pipelines                 = $this->pipelines_repo->get_all_pipelines( null, $agent_id );
		$flows                     = $this->flows_repo->get_all_flows( null, $agent_id );
		$pipeline_documents        = array();
		$flow_documents            = array();
		$extension_artifacts       = AgentBundleArtifactExtensions::export_artifacts( $agent, array( 'agent_id' => $agent_id ) );
		$memory_files              = array();
		$pipeline_slugs_by_id      = array();
		$pipeline_step_types_by_id = array();
		$used_pipeline_slugs       = array();
		$used_flow_slugs           = array();

		foreach ( $this->collect_agent_files( $agent['agent_slug'] ) as $path => $contents ) {
			if ( 'SOUL.md' === $path && empty( $export_manifest['soul'] ) ) {
				continue;
			}
			if ( 'MEMORY.md' === $path && empty( $export_manifest['memory'] ) ) {
				continue;
			}
			$memory_files[ 'agent/' . ltrim( (string) $path, '/' ) ] = $contents;
		}

		$owner_id      = (int) $agent['owner_id'];
		$user_template = $this->collect_user_template( $owner_id );
		if ( ! empty( $export_manifest['user'] ) && '' !== $user_template ) {
			$memory_files['USER.md'] = $user_template;
		}

		if ( ! empty( $export_manifest['pipelines'] ) ) {
			foreach ( $pipelines as $pipeline ) {
				$pipeline_id                          = (int) $pipeline['pipeline_id'];
				$portable_slug                        = ! empty( $pipeline['portable_slug'] )
					? PortableSlug::normalize( (string) $pipeline['portable_slug'], 'pipeline' )
					: PortableSlug::normalize( (string) $pipeline['pipeline_name'], 'pipeline' );
				$portable_slug                        = PortableSlug::dedupe( $portable_slug, $used_pipeline_slugs );
				$used_pipeline_slugs[]                = $portable_slug;
				$pipeline_config                      = is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array();
				$pipeline_slugs_by_id[ $pipeline_id ] = $portable_slug;

				foreach ( $pipeline_config as $pipeline_step_id => $pipeline_step ) {
					if ( is_array( $pipeline_step ) ) {
						$pipeline_step_types_by_id[ (string) $pipeline_step_id ] = (string) ( $pipeline_step['step_type'] ?? '' );
					}
				}

				$pipeline_documents[] = new AgentBundlePipelineFile(
					$portable_slug,
					(string) $pipeline['pipeline_name'],
					self::pipeline_document_steps_from_config( $pipeline_config )
				);

				if ( ! empty( $export_manifest['memory'] ) ) {
					foreach ( $this->collect_pipeline_memory_files( $pipeline_id ) as $path => $contents ) {
						$memory_files[ 'pipelines/' . $portable_slug . '/' . ltrim( (string) $path, '/' ) ] = $contents;
					}
				}
			}
		}

		if ( ! empty( $export_manifest['flows'] ) ) {
			foreach ( $flows as $flow ) {
				$flow_id           = (int) $flow['flow_id'];
				$pipeline_id       = (int) $flow['pipeline_id'];
				$portable_slug     = ! empty( $flow['portable_slug'] )
					? PortableSlug::normalize( (string) $flow['portable_slug'], 'flow' )
					: PortableSlug::normalize( (string) $flow['flow_name'], 'flow' );
				$portable_slug     = PortableSlug::dedupe( $portable_slug, $used_flow_slugs );
				$used_flow_slugs[] = $portable_slug;
				$scheduling        = $this->sanitize_scheduling_config( is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array() );
				$flow_documents[]  = new AgentBundleFlowFile(
					$portable_slug,
					(string) $flow['flow_name'],
					$pipeline_slugs_by_id[ $pipeline_id ] ?? 'pipeline',
					(string) ( $scheduling['interval'] ?? 'manual' ),
					is_array( $scheduling['max_items'] ?? null ) ? $scheduling['max_items'] : array(),
					self::flow_document_steps_from_config(
						is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(),
						$pipeline_step_types_by_id,
						$handler_auth,
						array_merge(
							$context,
							array(
								'agent_id' => $agent_id,
								'flow_id'  => $flow_id,
							)
						)
					),
					\DataMachine\Engine\Bundle\BundleSchema::normalize_run_artifact_egress_policy( $scheduling['run_artifacts'] ?? array() )
				);

				if ( ! empty( $export_manifest['memory'] ) ) {
					foreach ( $this->collect_flow_memory_files( $pipeline_id, $flow_id ) as $path => $contents ) {
						$memory_files[ 'flows/' . $portable_slug . '/' . ltrim( (string) $path, '/' ) ] = $contents;
					}
				}
			}
		}

		$pipeline_slugs  = array_map( fn( AgentBundlePipelineFile $pipeline ) => $pipeline->slug(), $pipeline_documents );
		$flow_slugs      = array_map( fn( AgentBundleFlowFile $flow ) => $flow->slug(), $flow_documents );
		$artifact_files  = array(
			\DataMachine\Engine\Bundle\BundleSchema::PROMPTS_DIR => SystemTaskPromptRegistry::bundle_prompt_files(),
		);
		$extension_paths = array_map( static fn( array $artifact ) => (string) ( $artifact['source_path'] ?? '' ), $extension_artifacts );
		$manifest        = new AgentBundleManifest(
			gmdate( 'c' ),
			defined( 'DATAMACHINE_VERSION' ) ? 'data-machine/' . DATAMACHINE_VERSION : 'data-machine/unknown',
			sanitize_title( (string) $agent['agent_slug'] ),
			(string) self::BUNDLE_VERSION,
			'',
			'',
			array(
				'slug'         => $agent['agent_slug'],
				'label'        => $agent['agent_name'],
				'description'  => '',
				'agent_config' => is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array(),
				// Preserve the agent's actual scope through the bundle round-trip:
				// null = network-wide, positive int = a specific blog. The manifest
				// drops legacy/unknown values so import never re-pins to a blog.
				'site_scope'   => self::normalize_export_site_scope( $agent['site_scope'] ?? null ),
			),
			array(
				'memory'       => array_keys( $memory_files ),
				'pipelines'    => $pipeline_slugs,
				'flows'        => $flow_slugs,
				'prompts'      => array_keys( $artifact_files[ \DataMachine\Engine\Bundle\BundleSchema::PROMPTS_DIR ] ),
				'extensions'   => array_values( array_filter( $extension_paths ) ),
				'handler_auth' => $handler_auth,
			)
		);

		$extras    = self::collect_export_extras( $agent_id, $agent );
		$directory = new AgentBundleDirectory( $manifest, $memory_files, $pipeline_documents, $flow_documents, $artifact_files, $extension_artifacts, $extras );

		return array(
			'success'   => true,
			'agent'     => $agent,
			'directory' => $directory,
			'package'   => AgentPackageProjection::from_directory( $directory ),
		);
	}

	/**
	 * Collect plugin-owned extras to fold into the exported bundle.
	 *
	 * Data Machine has no opinion about extras content, so it never reads them
	 * from disk. Consumer plugins return their own `extras` map keyed by a
	 * top-level directory name (e.g. `wiki`, `datasets`). Conflicts on the
	 * same key are last-write-wins; collisions are logged.
	 *
	 * @param int   $agent_id Agent ID being exported.
	 * @param array $agent    Agent row.
	 * @return array<string,array<string,string>> Validated extras map.
	 */
	private static function collect_export_extras( int $agent_id, array $agent ): array {
		/**
		 * Filters the bundle extras map produced for export.
		 *
		 * Each consumer adds entries keyed by their top-level directory name.
		 * Values are maps of `<key>/path` => string contents. Data Machine
		 * validates the shape (path prefix, key naming, no `..` segments) and
		 * drops empty maps. Reserved bundle directory names cannot be used.
		 *
		 * @param array<string,array<string,string>> $extras   Accumulated extras.
		 * @param int                                $agent_id Agent ID being exported.
		 * @param array                              $agent    Agent row.
		 */
		/** @var mixed $extras */
		$extras = apply_filters( 'datamachine_bundle_export_extras', array(), $agent_id, $agent );
		if ( ! is_array( $extras ) ) {
			return array();
		}

		try {
			return \DataMachine\Engine\Bundle\BundleSchema::validate_extras( $extras );
		} catch ( \DataMachine\Engine\Bundle\BundleValidationException $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'AgentBundler::collect_export_extras dropped invalid extras payload.',
				array(
					'agent_id' => $agent_id,
					'error'    => $e->getMessage(),
				)
			);
			return array();
		}
	}

	/**
	 * Project a legacy bundle array to the Core-shaped package contract.
	 *
	 * @param array<string,mixed> $bundle Legacy bundle array.
	 * @return object
	 */
	public static function package_from_bundle( array $bundle ): object {
		return AgentPackageProjection::from_array_bundle( $bundle );
	}

	/**
	 * Project a bundle directory to the Core-shaped package contract.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory.
	 * @return object
	 */
	public static function package_from_directory( AgentBundleDirectory $directory ): object {
		return AgentPackageProjection::from_directory( $directory );
	}

	/**
	 * Convert runtime pipeline config rows to bundle pipeline document steps.
	 *
	 * @param array $pipeline_config Runtime pipeline config keyed by pipeline step ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function pipeline_document_steps_from_config( array $pipeline_config ): array {
		$steps = array();
		foreach ( $pipeline_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_config = $step;
			unset( $step_config['pipeline_step_id'] );
			$steps[] = array(
				'step_position' => (int) ( $step['execution_order'] ?? count( $steps ) ),
				'step_type'     => (string) ( $step['step_type'] ?? '' ),
				'step_config'   => $step_config,
			);
		}
		return $steps;
	}

	/**
	 * Convert runtime flow config rows to bundle flow document steps.
	 *
	 * @param array $flow_config Runtime flow config keyed by flow step ID.
	 * @param array $pipeline_step_types_by_id Pipeline step ID to step type map.
	 * @return array<int,array<string,mixed>>
	 */
	private static function flow_document_steps_from_config( array $flow_config, array $pipeline_step_types_by_id, string $handler_auth = 'refs', array $context = array() ): array {
		$steps = array();
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$pipeline_step_id = (string) ( $step['pipeline_step_id'] ?? '' );
			$document_step    = array(
				'step_position'   => (int) ( $step['execution_order'] ?? count( $steps ) ),
				'handler_configs' => self::handler_configs_from_flow_step( $step, $handler_auth, $context ),
			);
			if ( ! FlowStepConfig::usesHandler( $step ) && ! empty( FlowStepConfig::getPrimaryHandlerConfig( $step ) ) ) {
				$document_step['flow_step_settings'] = FlowStepConfig::getPrimaryHandlerConfig( $step );
			}

			if ( ! isset( $step['step_type'] ) && isset( $pipeline_step_types_by_id[ $pipeline_step_id ] ) ) {
				$document_step['step_type'] = $pipeline_step_types_by_id[ $pipeline_step_id ];
			}

			foreach ( array( 'step_type', 'handler_slugs', 'flow_step_settings', 'enabled_tools', 'disabled_tools', 'prompt_queue', 'config_patch_queue', 'queue_mode', 'completion_assertions', 'tool_runtime_rules', 'enabled' ) as $field ) {
				if ( array_key_exists( $field, $step ) ) {
					$document_step[ $field ] = $step[ $field ];
				}
			}

			$steps[] = $document_step;
		}
		return $steps;
	}

	/**
	 * Extract handler config map from a runtime flow step row.
	 *
	 * @param array $step Runtime flow step row.
	 * @return array<string,array<string,mixed>>
	 */
	private static function handler_configs_from_flow_step( array $step, string $handler_auth = 'refs', array $context = array() ): array {
		if ( 'omit' === $handler_auth ) {
			return array();
		}

		if ( ! is_array( $step['handler_configs'] ?? null ) ) {
			return array();
		}

		$configs = $step['handler_configs'];

		if ( 'refs' !== $handler_auth ) {
			return $configs;
		}

		$rewritten = array();
		foreach ( $configs as $handler_slug => $handler_config ) {
			if ( ! is_array( $handler_config ) ) {
				continue;
			}
			$config = apply_filters( 'datamachine_handler_config_to_auth_ref', $handler_config, (string) $handler_slug, $context );
			if ( is_wp_error( $config ) || ! is_array( $config ) ) {
				$config = array();
			}
			$rewritten[ (string) $handler_slug ] = self::strip_secret_like_values( $config );
		}

		ksort( $rewritten, SORT_STRING );
		return $rewritten;
	}

	private static function resolve_export_manifest( int $agent_id, array $context ): array {
		$profile = (string) ( $context['profile'] ?? 'share' );
		$default = array(
			'soul'         => true,
			'memory'       => false,
			'user'         => false,
			'daily_memory' => false,
			'agent_config' => true,
			'pipelines'    => true,
			'flows'        => true,
			'handler_auth' => 'refs',
		);

		$profiles = array(
			'share'  => array(
				'memory'       => false,
				'user'         => false,
				'daily_memory' => false,
				'handler_auth' => 'refs',
			),
			'backup' => array(
				'memory'       => true,
				'user'         => true,
				'daily_memory' => true,
				'handler_auth' => 'full',
			),
			'fork'   => array(
				'memory'       => false,
				'user'         => false,
				'daily_memory' => false,
				'handler_auth' => 'omit',
			),
		);

		if ( isset( $profiles[ $profile ] ) ) {
			$default = array_merge( $default, $profiles[ $profile ] );
		}

		$context['profile'] = '' !== $profile ? $profile : null;
		$manifest           = apply_filters( 'datamachine_agent_export_manifest', $default, $agent_id, $context );
		if ( ! is_array( $manifest ) ) {
			$manifest = $default;
		}

		$manifest = array_merge( $default, $manifest );
		if ( ! in_array( $manifest['handler_auth'], array( 'refs', 'full', 'omit' ), true ) ) {
			$manifest['handler_auth'] = 'refs';
		}

		foreach ( array( 'soul', 'memory', 'user', 'daily_memory', 'agent_config', 'pipelines', 'flows' ) as $flag ) {
			$manifest[ $flag ] = ! empty( $manifest[ $flag ] );
		}

		return $manifest;
	}

	/**
	 * Normalize a stored agent `site_scope` for export into a manifest.
	 *
	 * The DB column is `NULL` for network-wide or a blog ID otherwise. We map
	 * `NULL` to a first-class `null` (network-wide) and a numeric value to its
	 * integer blog ID. Anything else collapses to the unspecified sentinel,
	 * which the manifest validator drops so it can't re-pin an agent on import.
	 *
	 * @param mixed $stored_scope Raw site_scope value from the agent row.
	 * @return int|null|string
	 */
	private static function normalize_export_site_scope( mixed $stored_scope ): int|null|string {
		if ( null === $stored_scope ) {
			return null;
		}

		return \DataMachine\Engine\Bundle\BundleSchema::normalize_agent_site_scope( $stored_scope );
	}

	private static function strip_secret_like_values( array $value ): array {
		$secret_keys = array( 'access_token', 'refresh_token', 'token', 'secret', 'client_secret', 'password', 'api_key', 'apikey', 'key' );
		foreach ( $value as $key => $child ) {
			$key_string = strtolower( (string) $key );
			if ( in_array( $key_string, $secret_keys, true ) || str_contains( $key_string, 'secret' ) || str_contains( $key_string, 'token' ) || str_contains( $key_string, 'password' ) ) {
				unset( $value[ $key ] );
				continue;
			}
			if ( is_array( $child ) ) {
				$value[ $key ] = self::strip_secret_like_values( $child );
			}
		}
		ksort( $value, SORT_STRING );
		return $value;
	}

	/**
	 * Import an agent from a bundle array.
	 *
	 * @param array       $bundle   The bundle data.
	 * @param string|null $new_slug Optional override slug.
	 * @param int         $owner_id WordPress user ID to own the imported agent.
	 * @param bool        $dry_run  If true, validate without writing.
	 * @param array       $options  Import options. Supported keys:
	 *                              - reconcile_runtime (bool) Replace preserved runtime queue/scheduling fields.
	 *                              - is_upgrade (bool) Treat an existing agent with the same slug as the upgrade
	 *                                target instead of returning a slug-collision error. Required when the live
	 *                                pipelines/flows have been edited (`local_modified`) so the importer can stage
	 *                                conflicts and the CLI can hand them to the planner / PendingActions.
	 * @return array{success: bool, message?: string, error?: string, error_code?: string, summary?: array}
	 */
	public function import( array $bundle, ?string $new_slug = null, int $owner_id = 0, bool $dry_run = false, array $options = array() ): array {
		// Validate bundle.
		if ( empty( $bundle['bundle_version'] ) || empty( $bundle['agent'] ) ) {
			return array(
				'success'    => false,
				'error_code' => 'install_invalid_bundle',
				'error'      => 'Invalid bundle: missing bundle_version or agent data.',
			);
		}

		try {
			$bundle = $this->canonical_import_bundle( $bundle );
		} catch ( BundleValidationException $e ) {
			return array(
				'success'    => false,
				'error_code' => 'install_invalid_bundle',
				'error'      => $e->getMessage(),
			);
		}

		$agent_data             = $bundle['agent'];
		$slug                   = $new_slug
			? sanitize_title( $new_slug )
			: sanitize_title( $agent_data['agent_slug'] );
		$bundle_slug            = PortableSlug::normalize( (string) ( $bundle['bundle_slug'] ?? $slug ), 'bundle' );
		$bundle_version         = trim( (string) $bundle['bundle_version'] );
		$bundle_source_ref      = trim( (string) ( $bundle['source_ref'] ?? '' ) );
		$bundle_source_revision = trim( (string) ( $bundle['source_revision'] ?? '' ) );
		$bundle_run_artifacts   = \DataMachine\Engine\Bundle\BundleSchema::normalize_run_artifact_egress_policy( $bundle['run_artifacts'] ?? array() );
		$bundle_metadata        = array(
			'bundle_slug'     => $bundle_slug,
			'bundle_version'  => $bundle_version,
			'source_ref'      => $bundle_source_ref,
			'source_revision' => $bundle_source_revision,
		);
		$template_metadata      = AgentTemplateMetadata::from_bundle_array( $bundle )->to_array();
		unset( $template_metadata['installed_hashes'] );
		$bundle_metadata    = array_merge( $template_metadata, $bundle_metadata );
		$is_portable_bundle = ! empty( $bundle['bundle_slug'] ) || $this->bundle_has_portable_artifacts( $bundle );
		$reconcile_runtime  = ! empty( $options['reconcile_runtime'] );
		$is_upgrade         = ! empty( $options['is_upgrade'] );

		// Check for slug collision.
		// On install: existing slug + (renamed-to-collision OR non-portable bundle) is a hard error.
		// On upgrade: an existing slug is the upgrade target. Bundle-slug mismatch is still an error so we
		// don't silently overwrite an unrelated agent that happens to share the slug.
		$existing = $this->agents_repo->get_by_slug( $slug );
		if ( $existing && ! $is_upgrade && ( $new_slug || ! $is_portable_bundle ) ) {
			return array(
				'success'    => false,
				'error_code' => 'install_slug_collision',
				'error'      => sprintf( 'Agent slug "%s" already exists. Use --slug=<new-slug> to rename on import, or run `agent upgrade` to update the existing install.', $slug ),
			);
		}
		if ( $existing ) {
			$installed_bundle = $existing['agent_config']['datamachine_bundle'] ?? array();
			if ( ! empty( $installed_bundle['bundle_slug'] ) && $installed_bundle['bundle_slug'] !== $bundle_slug ) {
				return array(
					'success'    => false,
					'error_code' => 'install_bundle_slug_mismatch',
					'error'      => sprintf( 'Agent slug "%s" is installed from bundle "%s", not "%s".', $slug, $installed_bundle['bundle_slug'], $bundle_slug ),
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
			'agent_slug'          => $slug,
			'agent_name'          => $agent_data['agent_name'],
			'owner_id'            => $owner_id,
			'bundle_slug'         => $bundle_slug,
			'bundle_version'      => $bundle_version,
			'files'               => count( $bundle['files'] ?? array() ),
			'pipelines'           => count( $bundle['pipelines'] ?? array() ),
			'flows'               => count( $bundle['flows'] ?? array() ),
			'prompt_artifacts'    => count( $bundle['prompt_artifacts'] ?? array() ),
			'rubric_artifacts'    => count( $bundle['rubric_artifacts'] ?? array() ),
			'extension_artifacts' => count( $bundle['extension_artifacts'] ?? array() ),
			'has_user_template'   => ! empty( $bundle['user_template'] ),
			'upgrade'             => (bool) $existing,
			'runtime_policy'      => $reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing',
		);
		if ( ! empty( $bundle_run_artifacts ) ) {
			$summary['run_artifacts'] = $bundle_run_artifacts;
		}

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
		//
		// Everything below this point is the post-claim mutation block. Any failure must be reported as
		// `success: false` with a typed error_code and the agent row (if newly created) must be removed so
		// `agent list` doesn't show a half-installed entry. Pre-claim guards above guarantee no DB writes
		// have happened yet.
		$created_agent_id     = 0; // Tracks an agent row this call inserted, for manual rollback.
		$created_pipeline_ids = array();
		$created_flow_ids     = array();
		$transaction_started  = $this->begin_transaction();

		try {
			// Test fault-injection seam. Production code path is a no-op. Tests load the
			// `DataMachine\Tests\Support\AgentBundlerImportFaultInjector` and trigger a typed failure to
			// exercise rollback semantics without waiting for a live SQLite race.
			do_action( 'datamachine_bundle_import_post_claim_started', $bundle_metadata, $slug );

			// 1. Create or update the agent record.
			$incoming_config = $agent_data['agent_config'] ?? array();

			$incoming_config                = is_array( $incoming_config ) ? $incoming_config : array();
			$existing_bundle_state          = is_array( $existing['agent_config']['datamachine_bundle'] ?? null )
				? $existing['agent_config']['datamachine_bundle']
				: array();
			$artifact_records               = $existing ? AgentBundleArtifactState::installed_for_agent( $existing ) : array();
			$artifact_records               = self::index_artifacts( $artifact_records );
			$config_conflicts               = array();
			$agent_config_key               = self::artifact_key( 'agent_config', 'config' );
			$incoming_config_payload        = AgentBundleAgentConfig::tracked_payload( $incoming_config );
			$current_config_payload         = AgentBundleAgentConfig::tracked_payload( is_array( $existing['agent_config'] ?? null ) ? $existing['agent_config'] : array() );
			$agent_config_record            = $artifact_records[ $agent_config_key ] ?? null;
			$agent_config_has_local_changes = $existing && (
				$this->artifact_has_local_modifications( is_array( $agent_config_record ) ? $agent_config_record : null, $current_config_payload )
				|| ( ! is_array( $agent_config_record ) && ! empty( $current_config_payload ) )
			);
			$agent_config_target_differs    = ! hash_equals(
				AgentBundleArtifactHasher::hash( $incoming_config_payload ),
				AgentBundleArtifactHasher::hash( $current_config_payload )
			);

			if ( $agent_config_has_local_changes && $agent_config_target_differs ) {
				$config_conflicts[] = array(
					'artifact_type' => 'agent_config',
					'artifact_id'   => 'config',
					'reason'        => 'local_modified',
				);
				$incoming_config    = array();
			}

			$config = is_array( $existing['agent_config'] ?? null )
				? array_merge( $existing['agent_config'], $incoming_config )
				: $incoming_config;
			if ( $existing ) {
				$config = AgentConfigArtifactProjector::preserve_local_paths( $config, is_array( $existing['agent_config'] ?? null ) ? $existing['agent_config'] : array() );
			}

			$config['datamachine_bundle'] = array_merge(
				$existing_bundle_state,
				$bundle_metadata
			);
			if ( ! empty( $bundle_run_artifacts ) ) {
				$config['datamachine_bundle']['run_artifacts'] = $bundle_run_artifacts;
			}

			if ( $existing ) {
				$agent_id = (int) $existing['agent_id'];
				if ( ! $this->agents_repo->update_agent(
					$agent_id,
					array(
						'agent_name'   => $agent_data['agent_name'] ?? $slug,
						'agent_config' => $config,
					)
				) ) {
					throw new \RuntimeException( 'Failed to update existing agent record.' );
				}
			} else {
				// Honor the bundle's portable scope on CREATE only. `null` is
				// first-class network-wide; a positive int scopes to that blog.
				// An unspecified/legacy scope falls to the column default (NULL).
				// Read with array_key_exists so an explicit null is not swallowed
				// by `??` and collapsed into the unspecified sentinel.
				$raw_site_scope    = array_key_exists( 'site_scope', $agent_data ) ? $agent_data['site_scope'] : BundleSchema::SITE_SCOPE_UNSPECIFIED;
				$bundle_site_scope = BundleSchema::normalize_agent_site_scope( $raw_site_scope );
				$create_site_scope = ( BundleSchema::SITE_SCOPE_UNSPECIFIED === $bundle_site_scope ) ? false : $bundle_site_scope;

				$agent_id = $this->agents_repo->create_if_missing(
					$slug,
					$agent_data['agent_name'] ?? $slug,
					$owner_id,
					$config,
					$create_site_scope
				);
				if ( ! $agent_id ) {
					throw new \RuntimeException( 'Failed to create agent record.' );
				}
				$created_agent_id = $agent_id;
			}

			// 2. Write agent identity files. A fresh install seeds every
			// bundle-carried file. On upgrade over an existing agent, identity
			// files are skipped here and materialized through the ledger-aware
			// memory block below, so authored identity (SOUL.md) is updated with
			// local-modification protection while learned runtime memory
			// (MEMORY.md, WAKE.md, daily/*) is never clobbered by a deploy.
			if ( ! $existing ) {
				$this->write_agent_files( $slug, $agent_id, $owner_id, $bundle['files'] ?? array(), false );
			}

			// 3. Write USER.md template if provided.
			if ( ! empty( $bundle['user_template'] ) ) {
				$this->write_user_template( $owner_id, $bundle['user_template'] );
			}

			$conflicts     = $config_conflicts;
			$runtime_drift = array();
			if ( empty( $config_conflicts ) ) {
				$artifact_records[ $agent_config_key ] = $this->bundle_artifact_record(
					$bundle_metadata,
					'agent_config',
					'config',
					'manifest.json#/agent/agent_config',
					$incoming_config_payload
				);
			}

			// 4. Import pipelines — build old→new ID map.
			$pipeline_id_map = array(); // old_id => new_id.
			foreach ( $bundle['pipelines'] ?? array() as $pipeline_data ) {
				$old_id            = (int) ( $pipeline_data['original_id'] ?? 0 );
				$pipeline_config   = is_array( $pipeline_data['pipeline_config'] ?? null ) ? $pipeline_data['pipeline_config'] : array();
				$portable_slug     = PortableSlug::normalize(
				(string) ( $pipeline_data['portable_slug'] ?? ( $pipeline_data['pipeline_name'] ?? 'pipeline' ) ),
				'pipeline'
				);
				$artifact_key      = 'pipeline:' . $portable_slug;
				$existing_pipeline = $this->pipelines_repo->get_by_portable_slug( $agent_id, $portable_slug );
				$target_pipeline   = $pipeline_data;
				if ( $existing_pipeline ) {
					$target_pipeline['pipeline_config'] = BundleStepIdRemapper::remap_pipeline_step_ids( $pipeline_config, $old_id, (int) $existing_pipeline['pipeline_id'] );
				}
				$payload = $this->pipeline_artifact_payload( $target_pipeline, $portable_slug );

				if (
				$existing_pipeline
				&& $this->artifact_has_local_modifications(
					$artifact_records[ $artifact_key ] ?? null,
					$this->pipeline_artifact_payload( $existing_pipeline, $portable_slug )
				)
				&& ! hash_equals(
					AgentBundleArtifactHasher::hash( $payload ),
					AgentBundleArtifactHasher::hash( $this->pipeline_artifact_payload( $existing_pipeline, $portable_slug ) )
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
				} else {
					$new_pipeline_id = $this->pipelines_repo->create_pipeline( array(
						'pipeline_name'   => $pipeline_data['pipeline_name'],
						'pipeline_config' => $pipeline_config,
						'portable_slug'   => $portable_slug,
						'agent_id'        => $agent_id,
						'user_id'         => $owner_id,
					) );
					if ( ! $new_pipeline_id ) {
						throw new \RuntimeException( sprintf( 'Failed to create pipeline "%s".', $portable_slug ) );
					}
					$created_pipeline_ids[] = (int) $new_pipeline_id;
				}

				$pipeline_config = BundleStepIdRemapper::remap_pipeline_step_ids( $pipeline_config, $old_id, (int) $new_pipeline_id );
				if ( ! $this->pipelines_repo->update_pipeline(
				(int) $new_pipeline_id,
				array(
					'pipeline_name'   => $pipeline_data['pipeline_name'],
					'pipeline_config' => $pipeline_config,
					'portable_slug'   => $portable_slug,
				)
				) ) {
					throw new \RuntimeException( sprintf( 'Failed to update pipeline "%s".', $portable_slug ) );
				}

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

			// 5. Import flows: honor bundle schedules on create, preserve local schedules/queues on update.
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

				$flow_run_artifacts = \DataMachine\Engine\Bundle\BundleSchema::normalize_run_artifact_egress_policy( $flow_data['run_artifacts'] ?? $bundle_run_artifacts );
				$scheduling         = $this->bundle_create_scheduling_config( is_array( $flow_data['scheduling_config'] ?? null ) ? $flow_data['scheduling_config'] : array() );
				if ( ! empty( $flow_run_artifacts ) ) {
					$scheduling['run_artifacts'] = $flow_run_artifacts;
				}

				$flow_config          = is_array( $flow_data['flow_config'] ?? null ) ? $flow_data['flow_config'] : array();
				$existing_flow        = $this->flows_repo->get_by_portable_slug( (int) $new_pipeline_id, $portable_slug );
				$target_flow_config   = $existing_flow
				? BundleStepIdRemapper::remap_flow_step_ids( $flow_config, $old_pipeline_id, (int) $new_pipeline_id, (int) $existing_flow['flow_id'] )
				: $flow_config;
				$flow_artifact_record = is_array( $artifact_records[ $artifact_key ] ?? null ) ? $artifact_records[ $artifact_key ] : null;
				$installed_payload    = is_array( $flow_artifact_record['installed_payload'] ?? null ) ? $flow_artifact_record['installed_payload'] : null;
				$flow_payload_source  = array_merge(
				$flow_data,
				array(
					'flow_config' => $target_flow_config,
				)
				);
				$payload              = $this->flow_artifact_payload( $flow_payload_source, $portable_slug, $installed_payload );

				if ( $existing_flow ) {
					$preview = AgentBundleRuntimeDrift::preview(
						$portable_slug,
						$existing_flow,
						array_merge( $flow_data, array( 'flow_config' => $target_flow_config ) ),
						$reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing'
					);
					if ( null !== $preview ) {
							$runtime_drift[] = $preview;
					}
				}

				if (
				$existing_flow
				&& ! $reconcile_runtime
				&& $this->artifact_has_local_modifications(
					$flow_artifact_record,
					$this->normalized_existing_flow_payload( $existing_flow, $portable_slug, (int) $new_pipeline_id, is_array( $artifact_records[ $artifact_key ] ?? null ) ? $artifact_records[ $artifact_key ] : null )
				)
				&& ! hash_equals(
					AgentBundleArtifactHasher::hash( $payload ),
					AgentBundleArtifactHasher::hash( $this->normalized_existing_flow_payload( $existing_flow, $portable_slug, (int) $new_pipeline_id, is_array( $artifact_records[ $artifact_key ] ?? null ) ? $artifact_records[ $artifact_key ] : null ) )
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
					$new_flow_id          = (int) $existing_flow['flow_id'];
					$scheduling_to_ensure = is_array( $existing_flow['scheduling_config'] ?? null ) ? $existing_flow['scheduling_config'] : array();
					$flow_config          = BundleStepIdRemapper::remap_flow_step_ids( $flow_config, $old_pipeline_id, (int) $new_pipeline_id, $new_flow_id );
					$flow_config          = $reconcile_runtime
					? AgentBundleRuntimeDrift::replace_runtime_queue_fields( $flow_config, $target_flow_config )
					: $this->preserve_runtime_queue_fields( $flow_config, $existing_flow['flow_config'] ?? array() );
					$update_data          = array(
						'flow_name'     => $flow_data['flow_name'],
						'flow_config'   => $flow_config,
						'portable_slug' => $portable_slug,
					);
					if ( ! empty( $flow_run_artifacts ) ) {
						$update_scheduling                  = is_array( $existing_flow['scheduling_config'] ?? null ) ? $existing_flow['scheduling_config'] : array();
						$update_scheduling['run_artifacts'] = $flow_run_artifacts;
						$update_data['scheduling_config']   = $update_scheduling;
						$scheduling_to_ensure               = $update_scheduling;
					}
					if ( $reconcile_runtime ) {
						$update_data['scheduling_config'] = $scheduling;
						$scheduling_to_ensure             = $scheduling;
					}
					if ( ! $this->flows_repo->update_flow( $new_flow_id, $update_data ) ) {
						throw new \RuntimeException( sprintf( 'Failed to update flow "%s".', $portable_slug ) );
					}
				} else {
					$new_flow_id = $this->flows_repo->create_flow( array(
						'pipeline_id'       => $new_pipeline_id,
						'flow_name'         => $flow_data['flow_name'],
						'flow_config'       => array(),
						'scheduling_config' => $scheduling,
						'portable_slug'     => $portable_slug,
						'agent_id'          => $agent_id,
						'user_id'           => $owner_id,
					) );

					if ( ! $new_flow_id ) {
						throw new \RuntimeException( sprintf( 'Failed to create flow "%s".', $portable_slug ) );
					}
					$created_flow_ids[] = (int) $new_flow_id;

					$flow_config = BundleStepIdRemapper::remap_flow_step_ids( $flow_config, $old_pipeline_id, (int) $new_pipeline_id, (int) $new_flow_id );
					if ( ! $this->flows_repo->update_flow(
					(int) $new_flow_id,
					array(
						'flow_name'     => $flow_data['flow_name'],
						'flow_config'   => $flow_config,
						'portable_slug' => $portable_slug,
					)
					) ) {
						throw new \RuntimeException( sprintf( 'Failed to update freshly-created flow "%s".', $portable_slug ) );
					}
					$scheduling_to_ensure = $scheduling;
				}

				$this->ensure_imported_flow_schedule( (int) $new_flow_id, $scheduling_to_ensure );

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

			// 6. Apply bundle-owned prompt artifacts and track rubric artifacts.
			foreach ( self::bundle_file_artifacts( $bundle ) as $artifact ) {
				$type = (string) $artifact['artifact_type'];
				if ( ! in_array( $type, array( PromptArtifact::TYPE_PROMPT, PromptArtifact::TYPE_RUBRIC ), true ) ) {
					continue;
				}

				$artifact_key = self::artifact_key( $type, (string) $artifact['artifact_id'] );
				$record       = is_array( $artifact_records[ $artifact_key ] ?? null ) ? $artifact_records[ $artifact_key ] : null;
				if ( PromptArtifact::TYPE_RUBRIC === $type ) {
					$local_payload = self::current_payload_from_record( $record );
					if (
						$record
						&& $this->artifact_has_local_modifications( $record, $local_payload )
						&& ! hash_equals(
							AgentBundleArtifactHasher::hash( $artifact['payload'] ?? null ),
							AgentBundleArtifactHasher::hash( $local_payload )
						)
					) {
						$conflicts[] = array(
							'artifact_type' => $artifact['artifact_type'],
							'artifact_id'   => $artifact['artifact_id'],
							'reason'        => 'local_modified',
						);
						continue;
					}
				}
				if ( PromptArtifact::TYPE_PROMPT === $type && SystemTaskPromptRegistry::has_local_override_for_artifact( $artifact ) ) {
					$conflicts[] = array(
						'artifact_type' => $artifact['artifact_type'],
						'artifact_id'   => $artifact['artifact_id'],
						'reason'        => 'local_modified',
					);
					continue;
				}

				if ( PromptArtifact::TYPE_PROMPT === $type && ! SystemTaskPromptRegistry::apply_bundle_artifact( $artifact ) ) {
					$conflicts[] = array(
						'artifact_type' => $artifact['artifact_type'],
						'artifact_id'   => $artifact['artifact_id'],
						'reason'        => 'missing_apply_handler',
					);
					continue;
				}

				$artifact_records[ $artifact_key ] = $this->bundle_artifact_record(
					$bundle_metadata,
					(string) $artifact['artifact_type'],
					(string) $artifact['artifact_id'],
					(string) $artifact['source_path'],
					$artifact['payload'] ?? null
				);
			}

			// 6b. Materialize authored agent identity (SOUL.md) into the live
			// store and track it in the ledger. Learned runtime memory is never
			// in scope here — AgentBundleMemoryArtifact gates on authority tier.
			// On fresh install the file is already on disk (write_agent_files);
			// on upgrade we apply it here with local-modification protection so a
			// deploy can ship updated identity without blowing away local edits.
			foreach ( AgentBundleMemoryArtifact::target_artifacts( $bundle ) as $artifact ) {
				$artifact_key = self::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
				$record       = is_array( $artifact_records[ $artifact_key ] ?? null ) ? $artifact_records[ $artifact_key ] : null;

				if ( $existing ) {
					$local_payload = AgentBundleMemoryArtifact::current_payload( $agent_id, (string) $artifact['artifact_id'] );
					if (
						$record
						&& $this->artifact_has_local_modifications( $record, $local_payload )
						&& ! hash_equals(
							AgentBundleArtifactHasher::hash( $artifact['payload'] ?? null ),
							AgentBundleArtifactHasher::hash( $local_payload )
						)
					) {
						$conflicts[] = array(
							'artifact_type' => $artifact['artifact_type'],
							'artifact_id'   => $artifact['artifact_id'],
							'reason'        => 'local_modified',
						);
						continue;
					}

					$applied = AgentBundleMemoryArtifact::apply( $artifact, $agent_id );
					if ( is_wp_error( $applied ) ) {
						$conflicts[] = array(
							'artifact_type' => $artifact['artifact_type'],
							'artifact_id'   => $artifact['artifact_id'],
							'reason'        => $applied->get_error_message(),
						);
						continue;
					}
				}

				$artifact_records[ $artifact_key ] = $this->bundle_artifact_record(
					$bundle_metadata,
					(string) $artifact['artifact_type'],
					(string) $artifact['artifact_id'],
					(string) $artifact['source_path'],
					$artifact['payload'] ?? null
				);
			}

			// 7. Apply plugin-owned artifacts through their owning plugin.
			$agent_context               = array_merge(
			$agent_data,
			array(
				'agent_id'   => $agent_id,
				'agent_slug' => $slug,
				'agent_name' => $agent_data['agent_name'] ?? $slug,
				'owner_id'   => $owner_id,
			)
			);
			$current_extension_artifacts = self::index_artifacts(
			AgentBundleArtifactExtensions::current_artifacts(
				$agent_context,
				array_values( $artifact_records ),
				array( 'bundle' => $bundle_metadata )
			)
			);

			foreach ( AgentBundleArtifactExtensions::normalize_artifacts( is_array( $bundle['extension_artifacts'] ?? null ) ? $bundle['extension_artifacts'] : array() ) as $artifact ) {
				$artifact_key = self::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
				$current      = $current_extension_artifacts[ $artifact_key ] ?? null;

				if (
				$current
				&& $this->artifact_has_local_modifications(
					$artifact_records[ $artifact_key ] ?? null,
					$current['payload'] ?? null
				)
				) {
					$conflicts[] = array(
						'artifact_type' => $artifact['artifact_type'],
						'artifact_id'   => $artifact['artifact_id'],
						'reason'        => 'local_modified',
					);
					continue;
				}

				$result = AgentBundleArtifactExtensions::apply_artifact(
				$artifact,
				$agent_context,
				array( 'bundle' => $bundle_metadata )
				);
				if ( null === $result || is_wp_error( $result ) ) {
					$conflicts[] = array(
						'artifact_type' => $artifact['artifact_type'],
						'artifact_id'   => $artifact['artifact_id'],
						'reason'        => is_wp_error( $result ) ? $result->get_error_message() : 'missing_apply_handler',
					);
					continue;
				}

				$artifact_records[ $artifact_key ] = $this->bundle_artifact_record(
				$bundle_metadata,
				(string) $artifact['artifact_type'],
				(string) $artifact['artifact_id'],
				(string) $artifact['source_path'],
				$artifact['payload'] ?? null
				);
			}

			$summary['agent_id']           = $agent_id;
			$summary['pipelines_imported'] = count( $pipeline_id_map );
			$summary['flows_imported']     = $flow_count;
			$summary['conflicts']          = $conflicts;
			$summary['runtime_drift']      = $runtime_drift;

			$artifact_persist_result = AgentBundleArtifactState::persist_for_agent_result( $agent_id, array_values( $artifact_records ) );
			if ( is_wp_error( $artifact_persist_result ) ) {
				throw new \RuntimeException( $artifact_persist_result->get_error_message() );
			}
			if ( ! $this->agents_repo->update_agent( $agent_id, array( 'agent_config' => $config ) ) ) {
				throw new \RuntimeException( 'Failed to persist final agent_config with bundle metadata.' );
			}

			// Test fault-injection seam — fires after every mutation but before commit so a test handler
			// can throw and exercise the rollback path without waiting for a SQLite race.
			do_action( 'datamachine_bundle_import_pre_commit', $bundle_metadata, $slug, $agent_id );

			// Verify persistence end-to-end. SQLite under Studio has been observed silently rolling back the
			// outer mutations under contention (#1801): the in-memory bundler thinks everything wrote, but a
			// fresh SELECT shows no agent row and no artifacts. Re-fetching closes the door on that path —
			// if the row isn't there, we surface the failure instead of returning a ghost agent_id.
			$persisted = $this->agents_repo->get_agent( $agent_id );
			if ( ! $persisted ) {
				throw new \RuntimeException( sprintf( 'Agent row for ID %d disappeared after install — possible silent rollback.', $agent_id ) );
			}
			$persisted_artifacts = AgentBundleArtifactState::installed_for_agent( $persisted );
			if ( count( $persisted_artifacts ) < count( $artifact_records ) ) {
				throw new \RuntimeException( sprintf(
				'Agent ID %d persisted %d artifact records but %d were written — possible silent rollback.',
				$agent_id,
				count( $persisted_artifacts ),
				count( $artifact_records )
				) );
			}

			$this->commit_transaction( $transaction_started );

			$extras = is_array( $bundle['extras'] ?? null ) ? $bundle['extras'] : array();
			try {
				$extras = \DataMachine\Engine\Bundle\BundleSchema::validate_extras( $extras );
			} catch ( \DataMachine\Engine\Bundle\BundleValidationException $e ) {
				do_action(
				'datamachine_log',
				'warning',
				'AgentBundler::import dropped invalid extras payload before success hook.',
				array(
					'agent_slug' => $slug,
					'error'      => $e->getMessage(),
				)
				);
				$extras = array();
			}

			/**
			 * Fires after a bundle install/upgrade succeeds and the transaction commits.
			 *
			 * Consumers can react to bundle installation — e.g. import bundle-carried
			 * content into other plugins. The full extras payload is included so
			 * consumers do not need to re-read the bundle from disk.
			 *
			 * Listeners are fire-and-forget; their failures do not roll back the
			 * install. PHP exceptions thrown by listeners are caught, logged, and
			 * suppressed.
			 *
			 * @param int    $agent_id        Newly installed/upgraded agent ID.
			 * @param string $slug            Agent slug.
			 * @param array  $bundle_metadata bundle_slug, bundle_version, source_ref, source_revision.
			 * @param array  $extras          Map of extras-tree name => file map (path => contents).
			 * @param array  $context         is_upgrade flag, summary, etc.
			 */
			try {
				do_action(
				'datamachine_bundle_install_succeeded',
				$agent_id,
				$slug,
				$bundle_metadata,
				$extras,
				array(
					'is_upgrade' => $is_upgrade,
					'summary'    => $summary,
				)
				);
			} catch ( \Throwable $hook_error ) {
				do_action(
				'datamachine_log',
				'error',
				'datamachine_bundle_install_succeeded listener threw — install already committed.',
				array(
					'agent_slug'  => $slug,
					'agent_id'    => $agent_id,
					'is_upgrade'  => $is_upgrade,
					'error'       => $hook_error->getMessage(),
					'error_class' => get_class( $hook_error ),
				)
				);
			}

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
		} catch ( \Throwable $e ) {
			// Roll back any DB writes from this call. Run native ROLLBACK first; if the underlying engine
			// (e.g. the SQLite drop-in) silently no-ops on rollback, fall back to manual cleanup of rows
			// we created so the next install attempt sees a clean slate.
			$this->rollback_transaction( $transaction_started );
			$this->manual_rollback( $created_agent_id, $created_pipeline_ids, $created_flow_ids );

			do_action(
				'datamachine_log',
				'error',
				'AgentBundler::import post-claim failure — rolled back.',
				array(
					'agent_slug'  => $slug,
					'bundle_slug' => $bundle_slug,
					'is_upgrade'  => $is_upgrade,
					'error'       => $e->getMessage(),
					'error_class' => get_class( $e ),
				)
			);

			return array(
				'success'    => false,
				'error_code' => 'install_post_claim_failure',
				'error'      => sprintf( 'Agent install rolled back: %s', $e->getMessage() ),
			);
		}
	}

	/**
	 * Import an agent from a directory bundle value object.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory value object.
	 * @param string|null          $new_slug Optional override slug.
	 * @param int                  $owner_id WordPress user ID to own the imported agent.
	 * @param bool                 $dry_run If true, validate without writing.
	 * @param array                $options Import options.
	 * @return array{success: bool, message?: string, error?: string, error_code?: string, summary?: array}
	 */
	public function import_directory_object( AgentBundleDirectory $directory, ?string $new_slug = null, int $owner_id = 0, bool $dry_run = false, array $options = array() ): array {
		return $this->import( AgentBundleArrayAdapter::to_array_bundle( $directory ), $new_slug, $owner_id, $dry_run, $options );
	}

	/**
	 * Normalize schema-versioned bundle arrays through directory value objects before import.
	 *
	 * Legacy backup arrays intentionally keep the raw runtime-config path for compatibility.
	 *
	 * @param array $bundle Bundle array.
	 * @return array Importable bundle array.
	 */
	private function canonical_import_bundle( array $bundle ): array {
		if ( (string) ( $bundle['bundle_schema_version'] ?? '' ) !== (string) BundleSchema::VERSION ) {
			return $bundle;
		}

		$canonical = AgentBundleArrayAdapter::to_array_bundle( AgentBundleArrayAdapter::from_array_bundle( $bundle ) );
		if ( is_array( $bundle['abilities_manifest'] ?? null ) ) {
			$canonical['abilities_manifest'] = $bundle['abilities_manifest'];
		}

		return $canonical;
	}

	/**
	 * Open a DB transaction for the import critical section.
	 *
	 * Returns true when the engine accepted `START TRANSACTION`. SQLite via the Studio drop-in maps this
	 * to `BEGIN`. If the engine refuses (returns false), we still proceed — the manual_rollback() path
	 * is the safety net that cleans up any rows we know we created.
	 */
	private function begin_transaction(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( 'START TRANSACTION' );

		return false !== $result;
	}

	private function commit_transaction( bool $started ): void {
		if ( ! $started ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );
	}

	private function rollback_transaction( bool $started ): void {
		if ( ! $started ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Manually undo writes from a failed import.
	 *
	 * Belt-and-braces: if the engine ignored ROLLBACK we still delete the agent row and any
	 * pipelines/flows this call created so a retry sees the same shape as a fresh install. We never
	 * delete rows that pre-existed.
	 *
	 * @param int   $created_agent_id     Agent row this call inserted (0 if upgrade).
	 * @param int[] $created_pipeline_ids Pipeline rows this call inserted.
	 * @param int[] $created_flow_ids     Flow rows this call inserted.
	 */
	private function manual_rollback( int $created_agent_id, array $created_pipeline_ids, array $created_flow_ids ): void {
		foreach ( $created_flow_ids as $flow_id ) {
			$this->flows_repo->delete_flow( (int) $flow_id );
		}
		foreach ( $created_pipeline_ids as $pipeline_id ) {
			$this->pipelines_repo->delete_pipeline( (int) $pipeline_id );
		}
		if ( $created_agent_id > 0 ) {
			global $wpdb;
			$agents_table = $wpdb->base_prefix . 'datamachine_agents';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $agents_table, array( 'agent_id' => (int) $created_agent_id ), array( '%d' ) );
		}
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
		if ( ! empty( $bundle['extension_artifacts'] ) ) {
			return true;
		}
		if ( ! empty( $bundle['prompt_artifacts'] ) || ! empty( $bundle['rubric_artifacts'] ) ) {
			return true;
		}
		return false;
	}

	private function pipeline_artifact_payload( array $pipeline, string $portable_slug ): array {
		return AgentBundleArtifactPayloads::pipeline_payload( $pipeline, $portable_slug );
	}

	private function flow_artifact_payload( array $flow, string $portable_slug, ?array $installed_payload = null ): array {
		return AgentBundleArtifactPayloads::flow_payload( $flow, $portable_slug, $installed_payload );
	}

	private function bundle_create_scheduling_config( array $config ): array {
		$config   = $this->sanitize_scheduling_config( $config );
		$interval = (string) ( $config['interval'] ?? 'manual' );

		$config['interval'] = $interval;
		if ( ! array_key_exists( 'enabled', $config ) ) {
			$config['enabled'] = 'manual' !== $interval || 'every_cycle' === (string) ( $config['cycle_policy'] ?? '' );
		}

		return $config;
	}

	private function ensure_imported_flow_schedule( int $flow_id, array $config ): void {
		$config   = $this->bundle_create_scheduling_config( $config );
		$interval = (string) ( $config['interval'] ?? 'manual' );

		if ( 'manual' === $interval || false === ( $config['enabled'] ?? true ) ) {
			return;
		}

		$result = FlowScheduling::handle_scheduling_update( $flow_id, $config );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException(
				sprintf(
					'Failed to schedule imported flow %d: %s',
					(int) $flow_id,
					esc_html( $result->get_error_message() )
				)
			);
		}

		$flow             = $this->flows_repo->get_flow( $flow_id );
		$scheduled_config = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
		foreach ( array( 'enabled', 'max_items' ) as $metadata_key ) {
			if ( array_key_exists( $metadata_key, $config ) ) {
				$scheduled_config[ $metadata_key ] = $config[ $metadata_key ];
			}
		}

		if ( ! $this->flows_repo->update_flow( $flow_id, array( 'scheduling_config' => $scheduled_config ) ) ) {
			throw new \RuntimeException( sprintf( 'Failed to preserve imported flow schedule metadata for flow %d.', (int) $flow_id ) );
		}
	}

	private function artifact_has_local_modifications( ?array $record, mixed $current_payload ): bool {
		if ( empty( $record['installed_hash'] ) ) {
			return false;
		}

		return AgentBundleArtifactStatus::MODIFIED === AgentBundleArtifactStatus::classify(
			(string) $record['installed_hash'],
			AgentBundleArtifactHasher::hash( $current_payload )
		);
	}

	private function bundle_artifact_record( array $bundle_metadata, string $type, string $id, string $source_path, mixed $payload ): array {
		$hash = AgentBundleArtifactHasher::hash( $payload );
		$now  = gmdate( 'Y-m-d H:i:s' );

		// installed_payload is the install-time snapshot. AgentBundleArtifactRebase
		// uses it as the `base` side of the 3-way merge so burn-in-safe can tell
		// "local moved this field away from base" from "local just inherited base".
		// Without it, the rebase primitive degrades to flagging more hunks
		// ambiguous than necessary (#1832 follow-up).
		return array(
			'bundle_slug'       => $bundle_metadata['bundle_slug'],
			'bundle_version'    => $bundle_metadata['bundle_version'],
			'artifact_type'     => $type,
			'artifact_id'       => $id,
			'source_path'       => $source_path,
			'installed_hash'    => $hash,
			'current_hash'      => $hash,
			'installed_payload' => $payload,
			'current_payload'   => $payload,
			'status'            => AgentBundleArtifactStatus::CLEAN,
			'installed_at'      => $now,
			'updated_at'        => $now,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function bundle_file_artifacts( array $bundle ): array {
		return AgentBundleArtifactDefinitions::file_artifact_rows_from_bundle( $bundle );
	}

	private static function current_payload_from_record( ?array $record ): mixed {
		if ( ! is_array( $record ) ) {
			return null;
		}
		if ( array_key_exists( 'current_payload', $record ) ) {
			return $record['current_payload'];
		}
		if ( array_key_exists( 'installed_payload', $record ) ) {
			return $record['installed_payload'];
		}
		if ( array_key_exists( 'payload', $record ) ) {
			return $record['payload'];
		}

		return null;
	}

	/** @param array<int,array<string,mixed>> $artifacts */
	private static function index_artifacts( array $artifacts ): array {
		$indexed = array();
		foreach ( $artifacts as $artifact ) {
			$indexed[ self::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) ) ] = $artifact;
		}

		return $indexed;
	}

	private static function artifact_key( string $type, string $id ): string {
		return AgentBundleArtifactExtensions::artifact_key( $type, $id );
	}

	private function preserve_runtime_queue_fields( array $incoming_flow_config, array $existing_flow_config ): array {
		$runtime_fields = array( 'prompt_queue', 'config_patch_queue', 'queue_mode', '_queue_consume_revision' );

		foreach ( $incoming_flow_config as $flow_step_id => &$step ) {
			if ( ! is_array( $step ) || ! is_array( $existing_flow_config[ $flow_step_id ] ?? null ) ) {
				continue;
			}
			$existing_step = $existing_flow_config[ $flow_step_id ];
			foreach ( $runtime_fields as $field ) {
				if ( array_key_exists( $field, $existing_step ) ) {
					$step[ $field ] = $existing_step[ $field ];
				}
			}
			$this->preserve_handler_max_items( $step, $existing_step );
		}
		unset( $step );

		return $incoming_flow_config;
	}

	private function preserve_handler_max_items( array &$step, array $existing_step ): void {
		if ( array_key_exists( 'max_items', $existing_step['handler_config'] ?? array() ) ) {
			if ( ! is_array( $step['handler_config'] ?? null ) ) {
				$step['handler_config'] = array();
			}
			$step['handler_config']['max_items'] = $existing_step['handler_config']['max_items'];
		}

		if ( ! is_array( $existing_step['handler_configs'] ?? null ) ) {
			return;
		}

		foreach ( $existing_step['handler_configs'] as $handler_slug => $handler_config ) {
			if ( ! is_array( $handler_config ) || ! array_key_exists( 'max_items', $handler_config ) ) {
				continue;
			}
			if ( ! array_key_exists( $handler_slug, is_array( $step['handler_configs'] ?? null ) ? $step['handler_configs'] : array() ) ) {
				continue;
			}
			if ( ! is_array( $step['handler_configs'] ?? null ) ) {
				$step['handler_configs'] = array();
			}
			if ( ! is_array( $step['handler_configs'][ $handler_slug ] ?? null ) ) {
				$step['handler_configs'][ $handler_slug ] = array();
			}
			$step['handler_configs'][ $handler_slug ]['max_items'] = $handler_config['max_items'];
		}
	}

	private function normalized_existing_flow_payload( array $flow, string $portable_slug, int $new_pipeline_id, ?array $artifact_record = null ): array {
		$flow_id     = (int) ( $flow['flow_id'] ?? 0 );
		$flow_config = is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array();

		foreach ( $flow_config as $step_config ) {
			if ( ! is_array( $step_config ) || ! isset( $step_config['pipeline_id'] ) ) {
				continue;
			}

			$old_pipeline_id = (int) $step_config['pipeline_id'];
			if ( $old_pipeline_id > 0 && $flow_id > 0 ) {
				$flow['flow_config'] = BundleStepIdRemapper::remap_flow_step_ids( $flow_config, $old_pipeline_id, $new_pipeline_id, $flow_id );
			}

			break;
		}

		$installed_payload = is_array( $artifact_record['installed_payload'] ?? null ) ? $artifact_record['installed_payload'] : null;

		return $this->flow_artifact_payload( $flow, $portable_slug, $installed_payload );
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
				$files[ $filename ] = $this->read_text_file( $path );
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
			return $this->read_text_file( $path );
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
			$files[ $relative_path ] = $this->read_text_file( $file->getPathname() );
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
	 * On a fresh install every bundle-carried file is written so the new agent
	 * is fully seeded. On an upgrade ($is_upgrade = true) only authored identity
	 * (SOUL.md, authority tier `agent_identity`) is overwritten; learned runtime
	 * memory (MEMORY.md, WAKE.md, daily/*) is preserved so a deploy never
	 * clobbers what the live agent has accumulated.
	 *
	 * @param string $slug       Agent slug.
	 * @param int    $agent_id   Imported agent ID.
	 * @param int    $owner_id   Imported agent owner ID.
	 * @param array  $files      filename => content map.
	 * @param bool   $is_upgrade Whether this write targets an existing agent.
	 */
	private function write_agent_files( string $slug, int $agent_id, int $owner_id, array $files, bool $is_upgrade = false ): void {
		$agent_dir = $this->directory_manager->get_agent_identity_directory( $slug );
		$this->directory_manager->ensure_directory_exists( $agent_dir );

		foreach ( $files as $relative_path => $content ) {
			$relative_path = str_replace( '\\', '/', (string) $relative_path );
			if ( preg_match( '#^daily/(\d{4})/(\d{2})/(\d{2})\.md$#', $relative_path, $matches ) ) {
				// daily/* is learned runtime memory — never overwrite on upgrade.
				if ( $is_upgrade ) {
					continue;
				}
				( new DailyMemory( $owner_id, $agent_id ) )->write( $matches[1], $matches[2], $matches[3], (string) $content );
				continue;
			}

			// On upgrade, only authored identity is materialized from the bundle.
			if ( $is_upgrade && ! AgentBundleMemoryArtifact::is_upgradeable_agent_file( $relative_path ) ) {
				continue;
			}

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
	 * Serialize a bundle to JSON string.
	 *
	 * @param array $bundle Bundle data.
	 * @return string JSON string.
	 */
	public function to_json( array $bundle ): string {
		return (string) wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
			AgentBundleArrayAdapter::from_array_bundle( $bundle )->write( $directory );
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
			return AgentBundleArrayAdapter::to_array_bundle( AgentBundleDirectory::read( $directory ) );
		} catch ( BundleValidationException $e ) {
			if ( $this->is_directory_bundle_schema( $directory ) ) {
				throw $e;
			}
			// Fall through to the legacy monolithic manifest reader for old exports.
		}

		$manifest_path = $directory . '/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return null;
		}

		$manifest = json_decode( $this->read_text_file( $manifest_path ), true );
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
			? $this->read_text_file( $user_md_path )
			: '';

		// Read pipeline memory files.
		$pipelines_dir = $directory . '/pipelines';
		if ( is_dir( $pipelines_dir ) ) {
			$pipeline_dirs = $this->glob_directories( $pipelines_dir );
			foreach ( $pipeline_dirs as $i => $pipeline_dir ) {
				if ( isset( $bundle['pipelines'][ $i ] ) ) {
					$bundle['pipelines'][ $i ]['memory_file_contents'] = $this->read_directory_recursive( $pipeline_dir );
				}
			}
		}

		// Read flow memory files.
		$flows_dir = $directory . '/flows';
		if ( is_dir( $flows_dir ) ) {
			$flow_dirs = $this->glob_directories( $flows_dir );
			foreach ( $flow_dirs as $i => $flow_dir ) {
				if ( isset( $bundle['flows'][ $i ] ) ) {
					$bundle['flows'][ $i ]['memory_file_contents'] = $this->read_directory_recursive( $flow_dir );
				}
			}
		}

		return $bundle;
	}

	private function is_directory_bundle_schema( string $directory ): bool {
		$manifest_path = rtrim( $directory, '/\\' ) . '/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return false;
		}

		$manifest = json_decode( $this->read_text_file( $manifest_path ), true );
		return is_array( $manifest ) && array_key_exists( 'schema_version', $manifest );
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
					$files[ $relative ] = $this->read_text_file( $item->getPathname() );
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

		try {
			if ( file_exists( $temp_dir . '/manifest.json' ) ) {
				$bundle = $this->from_directory( $temp_dir );
			} else {
				// Look one level deep.
				$subdirs = $this->glob_directories( $temp_dir );
				foreach ( $subdirs as $subdir ) {
					if ( file_exists( $subdir . '/manifest.json' ) ) {
						$bundle = $this->from_directory( $subdir );
						break;
					}
				}
			}
		} catch ( BundleValidationException $e ) {
			$this->rm_rf( $temp_dir );
			throw $e;
		}

		$this->rm_rf( $temp_dir );
		return $bundle;
	}

	/**
	 * Read a text file as a string.
	 *
	 * @param string $path File path.
	 * @return string File contents, or empty string when unreadable.
	 */
	private function read_text_file( string $path ): string {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Return one-level child directories for a path.
	 *
	 * @param string $directory Directory path.
	 * @return string[] Child directory paths.
	 */
	private function glob_directories( string $directory ): array {
		$directories = glob( $directory . '/*', GLOB_ONLYDIR );
		return is_array( $directories ) ? $directories : array();
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
