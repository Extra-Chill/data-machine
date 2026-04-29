<?php
/**
 * Adapter between legacy agent bundle arrays and directory value objects.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Converts AgentBundler's legacy runtime-state bundle arrays to the reviewable
 * schema_version 1 directory documents, and back again for the existing importer.
 */
final class AgentBundleLegacyAdapter {

	/**
	 * Convert a legacy AgentBundler bundle array into directory value objects.
	 *
	 * @param array $bundle Legacy bundle array.
	 * @return AgentBundleDirectory
	 */
	public static function from_legacy_bundle( array $bundle ): AgentBundleDirectory {
		$pipeline_slugs = self::pipeline_slugs( $bundle['pipelines'] ?? array() );
		$flow_slugs     = self::flow_slugs( $bundle['flows'] ?? array() );

		$manifest = new AgentBundleManifest(
			(string) ( $bundle['exported_at'] ?? gmdate( 'c' ) ),
			self::exported_by(),
			(string) ( $bundle['bundle_slug'] ?? ( $bundle['agent']['agent_slug'] ?? 'agent-bundle' ) ),
			(string) ( $bundle['bundle_version'] ?? '1' ),
			(string) ( $bundle['source_ref'] ?? '' ),
			(string) ( $bundle['source_revision'] ?? '' ),
			array(
				'slug'         => $bundle['agent']['agent_slug'] ?? 'agent',
				'label'        => $bundle['agent']['agent_name'] ?? ( $bundle['agent']['agent_slug'] ?? 'Agent' ),
				'description'  => (string) ( $bundle['agent']['description'] ?? '' ),
				'agent_config' => is_array( $bundle['agent']['agent_config'] ?? null ) ? $bundle['agent']['agent_config'] : array(),
			),
			array(
				'memory'       => self::memory_paths_from_legacy( $bundle, $pipeline_slugs, $flow_slugs ),
				'pipelines'    => array_values( $pipeline_slugs ),
				'flows'        => array_values( $flow_slugs ),
				'handler_auth' => 'refs',
			)
		);

		return new AgentBundleDirectory(
			$manifest,
			self::memory_files_from_legacy( $bundle, $pipeline_slugs, $flow_slugs ),
			self::pipeline_files_from_legacy( $bundle['pipelines'] ?? array(), $pipeline_slugs ),
			self::flow_files_from_legacy( $bundle['flows'] ?? array(), $bundle['pipelines'] ?? array(), $pipeline_slugs, $flow_slugs ),
			array(),
			is_array( $bundle['extension_artifacts'] ?? null ) ? $bundle['extension_artifacts'] : array()
		);
	}

	/**
	 * Convert directory value objects back into the legacy bundle array shape.
	 *
	 * @param AgentBundleDirectory $directory Bundle directory value object.
	 * @return array Legacy bundle array.
	 */
	public static function to_legacy_bundle( AgentBundleDirectory $directory ): array {
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

			$flows[] = array(
				'original_id'          => $flow_id,
				'original_pipeline_id' => $pipeline_id,
				'portable_slug'        => $slug,
				'flow_name'            => $flow['name'],
				'flow_config'          => $flow_config,
				'scheduling_config'    => array(
					'enabled'   => false,
					'interval'  => $flow['schedule'],
					'max_items' => $flow['max_items'],
				),
				'memory_file_contents' => self::strip_memory_prefix( $memory_files, 'flows/' . $slug . '/' ),
			);
		}

		return array(
			'bundle_version'        => $manifest['bundle_version'],
			'bundle_slug'           => $manifest['bundle_slug'],
			'source_ref'            => $manifest['source_ref'] ?? '',
			'source_revision'       => $manifest['source_revision'] ?? '',
			'bundle_schema_version' => BundleSchema::VERSION,
			'exported_at'           => $manifest['exported_at'],
			'agent'                 => array(
				'agent_slug'   => $manifest['agent']['slug'],
				'agent_name'   => $manifest['agent']['label'],
				'agent_config' => $manifest['agent']['agent_config'],
				'site_scope'   => 'site',
			),
			'files'                 => self::strip_memory_prefix( $memory_files, 'agent/' ),
			'user_template'         => $memory_files['USER.md'] ?? '',
			'pipelines'             => $pipelines,
			'flows'                 => $flows,
			'extension_artifacts'   => $directory->extension_artifacts(),
			'abilities_manifest'    => array(),
		);
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

	private static function exported_by(): string {
		if ( defined( 'DATAMACHINE_VERSION' ) ) {
			return 'data-machine/' . DATAMACHINE_VERSION;
		}
		return 'data-machine/unknown';
	}

	private static function memory_paths_from_legacy( array $bundle, array $pipeline_slugs, array $flow_slugs ): array {
		return array_keys( self::memory_files_from_legacy( $bundle, $pipeline_slugs, $flow_slugs ) );
	}

	private static function memory_files_from_legacy( array $bundle, array $pipeline_slugs, array $flow_slugs ): array {
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

	private static function pipeline_files_from_legacy( array $pipelines, array $pipeline_slugs ): array {
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

	private static function flow_files_from_legacy( array $flows, array $pipelines, array $pipeline_slugs, array $flow_slugs ): array {
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
				self::flow_steps_from_config( $flow['flow_config'] ?? array(), $pipeline_step_types_by_id )
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
			if ( ! isset( $step['step_type'] ) && isset( $pipeline_step_types_by_id[ $pipeline_step_id ] ) ) {
				$document_step['step_type'] = $pipeline_step_types_by_id[ $pipeline_step_id ];
			}

			foreach ( array( 'step_type', 'handler_slug', 'handler_slugs', 'handler_config', 'enabled_tools', 'disabled_tools', 'prompt_queue', 'config_patch_queue', 'queue_mode', 'enabled' ) as $field ) {
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

		foreach ( array( 'step_type', 'handler_slug', 'handler_slugs', 'handler_config', 'handler_configs', 'enabled_tools', 'disabled_tools', 'prompt_queue', 'config_patch_queue', 'queue_mode', 'enabled' ) as $field ) {
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

		if ( is_string( $step['handler_slug'] ?? null ) && is_array( $step['handler_config'] ?? null ) ) {
			return array( $step['handler_slug'] => $step['handler_config'] );
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
