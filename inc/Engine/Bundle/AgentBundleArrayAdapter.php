<?php
/**
 * Adapter between agent bundle arrays and directory value objects.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Converts AgentBundler's runtime-state bundle arrays to the reviewable
 * schema_version 1 directory documents, and back again for the existing importer.
 */
final class AgentBundleArrayAdapter {

	/**
	 * Convert an AgentBundler bundle array into directory value objects.
	 *
	 * @param array $bundle Bundle array.
	 * @return AgentBundleDirectory
	 */
	public static function from_array_bundle( array $bundle ): AgentBundleDirectory {
		$pipeline_slugs = self::pipeline_slugs( $bundle['pipelines'] ?? array() );
		$flow_slugs     = self::flow_slugs( $bundle['flows'] ?? array() );

		$manifest = new AgentBundleManifest(
			(string) ( $bundle['exported_at'] ?? gmdate( 'c' ) ),
			self::exported_by(),
			(string) ( $bundle['bundle_slug'] ?? ( $bundle['agent']['agent_slug'] ?? 'agent-bundle' ) ),
			(string) ( $bundle['bundle_version'] ?? '1' ),
			(string) ( $bundle['source_ref'] ?? '' ),
			(string) ( $bundle['source_revision'] ?? '' ),
			self::manifest_agent_block( $bundle['agent'] ?? array() ),
			array(
				'memory'       => self::memory_paths_from_array_bundle( $bundle, $pipeline_slugs, $flow_slugs ),
				'pipelines'    => array_values( $pipeline_slugs ),
				'flows'        => array_values( $flow_slugs ),
				'handler_auth' => 'refs',
			),
			BundleSchema::normalize_run_artifact_egress_policy( $bundle['run_artifacts'] ?? array() ),
			is_array( $bundle['capabilities'] ?? null ) ? $bundle['capabilities'] : array()
		);

		return new AgentBundleDirectory(
			$manifest,
			self::memory_files_from_array_bundle( $bundle, $pipeline_slugs, $flow_slugs ),
			self::pipeline_files_from_array_bundle( $bundle['pipelines'] ?? array(), $pipeline_slugs ),
			self::flow_files_from_array_bundle( $bundle['flows'] ?? array(), $bundle['pipelines'] ?? array(), $pipeline_slugs, $flow_slugs ),
			self::artifact_files_from_array_bundle( $bundle ),
			is_array( $bundle['extension_artifacts'] ?? null ) ? $bundle['extension_artifacts'] : array(),
			is_array( $bundle['extras'] ?? null ) ? $bundle['extras'] : array()
		);
	}

	/**
	 * Convert directory value objects back into the bundle array shape.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory value object.
	 * @return array Bundle array.
	 */
	public static function to_array_bundle( AgentBundleDirectory $directory ): array {
		$manifest      = $directory->manifest()->to_array();
		$memory_files  = $directory->memory_files();
		$pipeline_ids  = array();
		$pipeline_keys = array();
		$pipelines     = array();

		foreach ( $directory->pipelines() as $index => $pipeline_file ) {
			$pipeline_id = $index + 1;
			$pipeline    = $pipeline_file->to_array();
			$slug        = $pipeline['slug'];

			$pipeline_ids[ $slug ] = $pipeline_id;
			$pipeline_config       = array();
			foreach ( $pipeline['steps'] as $step ) {
				$position                        = (int) $step['step_position'];
				$pipeline_step_id                = $pipeline_id . '_bundle_step_' . $position;
				$step_config                     = $step['step_config'];
				$step_config['pipeline_step_id'] = $pipeline_step_id;
				$step_config['step_type']        = $step['step_type'];
				$step_config['execution_order']  = $position;

				$pipeline_config[ $pipeline_step_id ] = $step_config;
				$pipeline_keys[ $slug ][ $position ]  = $pipeline_step_id;
			}

			$pipelines[] = array(
				'original_id'          => $pipeline_id,
				'portable_slug'        => $slug,
				'pipeline_name'        => $pipeline['name'],
				'pipeline_config'      => $pipeline_config,
				'memory_file_contents' => self::strip_memory_prefix( $memory_files, 'pipelines/' . $slug . '/' ),
			);
		}

		$flows = array();
		foreach ( $directory->flows() as $index => $flow_file ) {
			$flow_id       = $index + 1;
			$flow          = $flow_file->to_array();
			$slug          = $flow['slug'];
			$pipeline_slug = $flow['pipeline_slug'];
			$pipeline_id   = $pipeline_ids[ $pipeline_slug ] ?? 0;

			$flow_config = array();
			foreach ( $flow['steps'] as $step ) {
				$position                     = (int) $step['step_position'];
				$pipeline_step_id             = $pipeline_keys[ $pipeline_slug ][ $position ] ?? ( $pipeline_id . '_bundle_step_' . $position );
				$flow_step_id                 = $pipeline_step_id . '_' . $flow_id;
				$flow_config[ $flow_step_id ] = self::flow_step_config_from_document_step( $step, $pipeline_id, $flow_id, $pipeline_step_id, $flow_step_id );
			}

			$flow_entry         = array(
				'original_id'          => $flow_id,
				'original_pipeline_id' => $pipeline_id,
				'portable_slug'        => $slug,
				'flow_name'            => $flow['name'],
				'flow_config'          => $flow_config,
				'scheduling_config'    => array(
					'enabled'   => 'manual' !== $flow['schedule'],
					'interval'  => $flow['schedule'],
					'max_items' => $flow['max_items'],
				),
				'memory_file_contents' => self::strip_memory_prefix( $memory_files, 'flows/' . $slug . '/' ),
			);
			$flow_run_artifacts = BundleSchema::normalize_run_artifact_egress_policy( $flow['run_artifacts'] ?? array() );
			if ( ! empty( $flow_run_artifacts ) ) {
				$flow_entry['run_artifacts'] = $flow_run_artifacts;
			}
			$flows[] = $flow_entry;
		}

		$bundle        = array(
			'bundle_version'        => $manifest['bundle_version'],
			'bundle_slug'           => $manifest['bundle_slug'],
			'source_ref'            => $manifest['source_ref'] ?? '',
			'source_revision'       => $manifest['source_revision'] ?? '',
			'bundle_schema_version' => BundleSchema::VERSION,
			'exported_at'           => $manifest['exported_at'],
			'agent'                 => self::bundle_agent_block( $manifest['agent'] ?? array() ),
			'files'                 => self::strip_memory_prefix( $memory_files, 'agent/' ),
			'user_template'         => $memory_files['USER.md'] ?? '',
			'pipelines'             => $pipelines,
			'flows'                 => $flows,
			'artifact_files'        => self::artifact_files_from_directory( $directory ),
			'extension_artifacts'   => $directory->extension_artifacts(),
			'extras'                => $directory->extras(),
			'abilities_manifest'    => array(),
		);
		$run_artifacts = BundleSchema::normalize_run_artifact_egress_policy( $manifest['run_artifacts'] ?? array() );
		if ( ! empty( $run_artifacts ) ) {
			$bundle['run_artifacts'] = $run_artifacts;
		}
		if ( ! empty( $manifest['capabilities'] ) && is_array( $manifest['capabilities'] ) ) {
			$bundle['capabilities'] = $manifest['capabilities'];
		}

		return $bundle;
	}

	/** @return array<string,array<string,array|string>> */
	private static function artifact_files_from_array_bundle( array $bundle ): array {
		$artifact_files = is_array( $bundle['artifact_files'] ?? null ) ? $bundle['artifact_files'] : array();
		$normalized     = array();

		foreach ( AgentBundleArtifactDefinitions::file_artifact_directories() as $directory ) {
			if ( is_array( $artifact_files[ $directory ] ?? null ) ) {
				$normalized[ $directory ] = $artifact_files[ $directory ];
			}
		}

		return $normalized;
	}

	/** @return array<string,array<string,array|string>> */
	private static function artifact_files_from_directory( AgentBundleDirectory $directory ): array {
		$artifact_files = array();
		foreach ( AgentBundleArtifactDefinitions::file_artifact_directories() as $artifact_directory ) {
			$artifact_files[ $artifact_directory ] = AgentBundleArtifactDefinitions::files_from_directory( $directory, $artifact_directory );
		}

		return $artifact_files;
	}

	/** @param array<int,array<string,mixed>> $pipelines */
	private static function pipeline_slugs( array $pipelines ): array {
		$used  = array();
		$slugs = array();
		foreach ( $pipelines as $index => $pipeline ) {
			$slug            = PortableSlug::dedupe(
				PortableSlug::normalize(
					(string) ( $pipeline['portable_slug'] ?? ( $pipeline['pipeline_name'] ?? 'pipeline' ) ),
					'pipeline'
				),
				$used
			);
			$used[]          = $slug;
			$slugs[ $index ] = $slug;
		}
		return $slugs;
	}

	/** @param array<int,array<string,mixed>> $flows */
	private static function flow_slugs( array $flows ): array {
		$used  = array();
		$slugs = array();
		foreach ( $flows as $index => $flow ) {
			$slug            = PortableSlug::dedupe(
				PortableSlug::normalize(
					(string) ( $flow['portable_slug'] ?? ( $flow['flow_name'] ?? 'flow' ) ),
					'flow'
				),
				$used
			);
			$used[]          = $slug;
			$slugs[ $index ] = $slug;
		}
		return $slugs;
	}

	/**
	 * Build the manifest agent block from a raw bundle agent array.
	 *
	 * Preserves the agent's actual `site_scope` through the round-trip: `null`
	 * for network-wide, a positive integer for a specific blog. Legacy/unknown
	 * values (`'site'`, empty string, absent) are omitted so the importer never
	 * re-pins a network agent to the installing blog.
	 *
	 * @param array<string,mixed> $agent Raw bundle agent array.
	 * @return array<string,mixed>
	 */
	private static function manifest_agent_block( array $agent ): array {
		$block = array(
			'slug'         => $agent['agent_slug'] ?? 'agent',
			'label'        => $agent['agent_name'] ?? ( $agent['agent_slug'] ?? 'Agent' ),
			'description'  => (string) ( $agent['description'] ?? '' ),
			'agent_config' => is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array(),
		);

		if ( array_key_exists( 'site_scope', $agent ) ) {
			$scope = BundleSchema::normalize_agent_site_scope( $agent['site_scope'] );
			if ( BundleSchema::SITE_SCOPE_UNSPECIFIED !== $scope ) {
				$block['site_scope'] = $scope;
			}
		}

		return $block;
	}

	/**
	 * Build the bundle agent block from a validated manifest agent array.
	 *
	 * Emits the real `site_scope` carried by the manifest (`null` network-wide
	 * or a positive integer). When the manifest carries no scope, the key is
	 * omitted entirely — the importer treats an absent scope as "do not
	 * re-pin", never as "scope to the current blog".
	 *
	 * @param array<string,mixed> $agent Validated manifest agent array.
	 * @return array<string,mixed>
	 */
	private static function bundle_agent_block( array $agent ): array {
		$block = array(
			'agent_slug'   => $agent['slug'] ?? 'agent',
			'agent_name'   => $agent['label'] ?? ( $agent['slug'] ?? 'Agent' ),
			'agent_config' => is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array(),
		);

		if ( array_key_exists( 'site_scope', $agent ) ) {
			$scope = BundleSchema::normalize_agent_site_scope( $agent['site_scope'] );
			if ( BundleSchema::SITE_SCOPE_UNSPECIFIED !== $scope ) {
				$block['site_scope'] = $scope;
			}
		}

		return $block;
	}

	private static function exported_by(): string {
		if ( defined( 'DATAMACHINE_VERSION' ) ) {
			return 'data-machine/' . DATAMACHINE_VERSION;
		}
		return 'data-machine/unknown';
	}

	private static function memory_paths_from_array_bundle( array $bundle, array $pipeline_slugs, array $flow_slugs ): array {
		return array_keys( self::memory_files_from_array_bundle( $bundle, $pipeline_slugs, $flow_slugs ) );
	}

	private static function memory_files_from_array_bundle( array $bundle, array $pipeline_slugs, array $flow_slugs ): array {
		$memory = array();
		foreach ( $bundle['files'] ?? array() as $path => $contents ) {
			$memory[ 'agent/' . ltrim( (string) $path, '/' ) ] = (string) $contents;
		}
		if ( '' !== (string) ( $bundle['user_template'] ?? '' ) ) {
			$memory['USER.md'] = (string) $bundle['user_template'];
		}
		foreach ( $bundle['pipelines'] ?? array() as $index => $pipeline ) {
			$slug = $pipeline_slugs[ $index ] ?? PortableSlug::normalize( (string) ( $pipeline['pipeline_name'] ?? 'pipeline' ), 'pipeline' );
			foreach ( $pipeline['memory_file_contents'] ?? array() as $path => $contents ) {
				$memory[ 'pipelines/' . $slug . '/' . ltrim( (string) $path, '/' ) ] = (string) $contents;
			}
		}
		foreach ( $bundle['flows'] ?? array() as $index => $flow ) {
			$slug = $flow_slugs[ $index ] ?? PortableSlug::normalize( (string) ( $flow['flow_name'] ?? 'flow' ), 'flow' );
			foreach ( $flow['memory_file_contents'] ?? array() as $path => $contents ) {
				$memory[ 'flows/' . $slug . '/' . ltrim( (string) $path, '/' ) ] = (string) $contents;
			}
		}
		ksort( $memory, SORT_STRING );
		return $memory;
	}

	private static function pipeline_files_from_array_bundle( array $pipelines, array $pipeline_slugs ): array {
		$files = array();
		foreach ( $pipelines as $index => $pipeline ) {
			$files[] = new AgentBundlePipelineFile(
				$pipeline_slugs[ $index ] ?? (string) ( $pipeline['pipeline_name'] ?? 'pipeline' ),
				(string) ( $pipeline['pipeline_name'] ?? 'Pipeline' ),
				self::pipeline_steps_from_config( $pipeline['pipeline_config'] ?? array() )
			);
		}
		return $files;
	}

	private static function flow_files_from_array_bundle( array $flows, array $pipelines, array $pipeline_slugs, array $flow_slugs ): array {
		$pipeline_slugs_by_id      = array();
		$pipeline_step_types_by_id = array();
		foreach ( $pipelines as $index => $pipeline ) {
			$original_id = (int) ( $pipeline['original_id'] ?? ( $index + 1 ) );

			$pipeline_slugs_by_id[ $original_id ] = $pipeline_slugs[ $index ] ?? PortableSlug::normalize( (string) ( $pipeline['pipeline_name'] ?? 'pipeline' ), 'pipeline' );
			foreach ( $pipeline['pipeline_config'] ?? array() as $pipeline_step_id => $pipeline_step ) {
				if ( is_array( $pipeline_step ) ) {
					$pipeline_step_types_by_id[ (string) $pipeline_step_id ] = (string) ( $pipeline_step['step_type'] ?? '' );
				}
			}
		}

		$files = array();
		foreach ( $flows as $index => $flow ) {
			$old_pipeline_id       = (int) ( $flow['original_pipeline_id'] ?? 0 );
			$default_pipeline_slug = reset( $pipeline_slugs );
			$pipeline_slug         = $pipeline_slugs_by_id[ $old_pipeline_id ] ?? ( $default_pipeline_slug ? $default_pipeline_slug : 'pipeline' );
			$scheduling            = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();

			$files[] = new AgentBundleFlowFile(
				$flow_slugs[ $index ] ?? (string) ( $flow['flow_name'] ?? 'flow' ),
				(string) ( $flow['flow_name'] ?? 'Flow' ),
				$pipeline_slug,
				(string) ( $scheduling['interval'] ?? 'manual' ),
				is_array( $scheduling['max_items'] ?? null ) ? $scheduling['max_items'] : array(),
				self::flow_steps_from_config( $flow['flow_config'] ?? array(), $pipeline_step_types_by_id ),
				BundleSchema::normalize_run_artifact_egress_policy( $flow['run_artifacts'] ?? $scheduling['run_artifacts'] ?? array() )
			);
		}
		return $files;
	}

	private static function pipeline_steps_from_config( array $pipeline_config ): array {
		$steps = array();
		foreach ( $pipeline_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$position = (int) ( $step['execution_order'] ?? count( $steps ) );
			$steps[]  = array(
				'step_position' => $position,
				'step_type'     => (string) ( $step['step_type'] ?? '' ),
				'step_config'   => self::without_keys( $step, array( 'pipeline_step_id' ) ),
			);
		}
		return $steps;
	}

	private static function flow_steps_from_config( array $flow_config, array $pipeline_step_types_by_id ): array {
		$steps = array();
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$pipeline_step_id = (string) ( $step['pipeline_step_id'] ?? '' );
			$document_step    = array(
				'step_position'   => (int) ( $step['execution_order'] ?? count( $steps ) ),
				'handler_configs' => self::handler_configs_from_step( $step ),
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

	private static function flow_step_config_from_document_step( array $step, int $pipeline_id, int $flow_id, string $pipeline_step_id, string $flow_step_id ): array {
		$config = array(
			'flow_step_id'     => $flow_step_id,
			'pipeline_step_id' => $pipeline_step_id,
			'pipeline_id'      => $pipeline_id,
			'flow_id'          => $flow_id,
			'execution_order'  => (int) $step['step_position'],
		);

		foreach ( array( 'step_type', 'handler_slugs', 'handler_configs', 'flow_step_settings', 'enabled_tools', 'disabled_tools', 'prompt_queue', 'config_patch_queue', 'queue_mode', 'completion_assertions', 'tool_runtime_rules', 'enabled' ) as $field ) {
			if ( array_key_exists( $field, $step ) ) {
				$config[ $field ] = $step[ $field ];
			}
		}

		return $config;
	}

	private static function handler_configs_from_step( array $step ): array {
		if ( is_array( $step['handler_configs'] ?? null ) ) {
			return $step['handler_configs'];
		}

		return array();
	}

	private static function strip_memory_prefix( array $memory_files, string $prefix ): array {
		$files = array();
		foreach ( $memory_files as $path => $contents ) {
			if ( str_starts_with( (string) $path, $prefix ) ) {
				$files[ substr( (string) $path, strlen( $prefix ) ) ] = $contents;
			}
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	private static function without_keys( array $data, array $keys ): array {
		foreach ( $keys as $key ) {
			unset( $data[ $key ] );
		}
		return $data;
	}
}
