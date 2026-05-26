<?php
/**
 * Shared agent bundle lifecycle artifact projection.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\System\SystemTaskPromptRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds target and current artifact rows for bundle lifecycle planning.
 */
final class AgentBundleLifecycleProjection {

	private ?Pipelines $pipelines;
	private ?Flows $flows;

	public function __construct( ?Pipelines $pipelines = null, ?Flows $flows = null ) {
		$this->pipelines = $pipelines;
		$this->flows     = $flows;
	}

	/** @return array<int,array<string,mixed>> */
	public function target_artifacts( array $bundle, ?array $agent = null ): array {
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
				'payload'       => self::pipeline_payload( $pipeline, $slug ),
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
				'payload'       => self::flow_payload( $flow, $slug ),
			);
		}

		foreach ( self::bundle_file_artifacts( $bundle ) as $artifact ) {
			$artifacts[] = $artifact;
		}

		foreach ( AgentBundleArtifactExtensions::normalize_artifacts( is_array( $bundle['extension_artifacts'] ?? null ) ? $bundle['extension_artifacts'] : array() ) as $artifact ) {
			$artifacts[] = $artifact;
		}

		return $artifacts;
	}

	/** @param array<int,array<string,mixed>> $installed */
	public function current_artifacts( array $agent, array $installed ): array {
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
					'payload'       => self::pipeline_payload( $pipeline_by_slug[ $id ], $id ),
				);
			}
			if ( 'flow' === $type && isset( $flow_by_slug[ $id ] ) ) {
				$installed_payload = is_array( $record['installed_payload'] ?? null ) ? $record['installed_payload'] : null;
				$artifacts[]       = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $id,
					'source_path'   => (string) ( $record['source_path'] ?? '' ),
					'payload'       => self::flow_payload( $flow_by_slug[ $id ], $id, $installed_payload ),
				);
			}
			if ( in_array( $type, self::bundle_file_artifact_types(), true ) ) {
				$payload = self::current_payload_from_record( is_array( $record ) ? $record : null );
				if ( null !== $payload ) {
					$artifacts[] = array(
						'artifact_type' => $type,
						'artifact_id'   => $id,
						'source_path'   => (string) ( $record['source_path'] ?? '' ),
						'payload'       => $payload,
					);
				}
			}
		}

		return array_merge(
			$artifacts,
			SystemTaskPromptRegistry::current_artifacts(),
			AgentBundleArtifactExtensions::current_artifacts( $agent, $installed, array( 'agent_id' => $agent_id ) )
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function bundle_file_artifacts( array $bundle ): array {
		$artifacts = array();
		$files     = is_array( $bundle['artifact_files'] ?? null ) ? $bundle['artifact_files'] : array();

		foreach ( self::bundle_file_artifact_directories() as $directory => $type ) {
			foreach ( is_array( $files[ $directory ] ?? null ) ? $files[ $directory ] : array() as $relative_path => $payload ) {
				$artifact_id = is_array( $payload ) && is_string( $payload['artifact_id'] ?? null )
					? (string) $payload['artifact_id']
					: self::artifact_id_from_relative_path( (string) $relative_path );

				$artifacts[] = array(
					'artifact_type' => $type,
					'artifact_id'   => $artifact_id,
					'source_path'   => $directory . '/' . ltrim( (string) $relative_path, '/' ),
					'payload'       => $payload,
				);
			}
		}

		return $artifacts;
	}

	/** @return array<string,string> */
	private static function bundle_file_artifact_directories(): array {
		return array(
			BundleSchema::PROMPTS_DIR       => 'prompt',
			BundleSchema::RUBRICS_DIR       => 'rubric',
			BundleSchema::TOOL_POLICIES_DIR => 'tool_policy',
			BundleSchema::AUTH_REFS_DIR     => 'auth_ref',
			BundleSchema::SEED_QUEUES_DIR   => 'seed_queue',
		);
	}

	/** @return string[] */
	private static function bundle_file_artifact_types(): array {
		return array_values( self::bundle_file_artifact_directories() );
	}

	private static function artifact_id_from_relative_path( string $relative_path ): string {
		$relative_path = preg_replace( '/\.(json|md|txt)$/i', '', $relative_path );
		return null === $relative_path ? '' : $relative_path;
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

	private static function pipeline_payload( array $pipeline, string $portable_slug ): array {
		return array(
			'portable_slug'   => $portable_slug,
			'pipeline_name'   => (string) ( $pipeline['pipeline_name'] ?? '' ),
			'pipeline_config' => is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array(),
		);
	}

	private static function flow_payload( array $flow, string $portable_slug, ?array $installed_payload = null ): array {
		$scheduling_policy = self::flow_scheduling_policy( is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array() );

		return array(
			'portable_slug'     => $portable_slug,
			'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
			'flow_config'       => self::flow_config_without_runtime_queues( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array() ),
			'scheduling_policy' => $scheduling_policy,
			'queue_policy'      => 'create_seed_upgrade_preserve_existing',
			'runtime_overlays'  => self::flow_runtime_overlays( $flow, $installed_payload ),
		);
	}

	private static function flow_scheduling_policy( array $config ): string {
		$interval = (string) ( $config['interval'] ?? 'manual' );
		$enabled  = array_key_exists( 'enabled', $config ) ? false !== $config['enabled'] : 'manual' !== $interval;

		if ( 'manual' === $interval || ! $enabled ) {
			return 'create_paused_upgrade_preserve_existing';
		}

		return 'create_bundle_schedule_upgrade_preserve_existing';
	}

	private static function flow_config_without_runtime_queues( array $flow_config ): array {
		foreach ( $flow_config as &$step ) {
			if ( is_array( $step ) ) {
				unset( $step['prompt_queue'], $step['config_patch_queue'], $step['queue_mode'], $step['_queue_consume_revision'] );
				unset( $step['handler_config']['max_items'] );
				if ( empty( $step['handler_config'] ) ) {
					unset( $step['handler_config'] );
				}
				if ( is_array( $step['handler_configs'] ?? null ) ) {
					foreach ( $step['handler_configs'] as $handler_slug => &$handler_config ) {
						if ( is_array( $handler_config ) ) {
							unset( $handler_config['max_items'] );
							if ( empty( $handler_config ) ) {
								unset( $step['handler_configs'][ $handler_slug ] );
							}
						}
					}
					unset( $handler_config );
					if ( empty( $step['handler_configs'] ) ) {
						unset( $step['handler_configs'] );
					}
				}
			}
		}
		unset( $step );

		return $flow_config;
	}

	private static function flow_runtime_overlays( array $flow, ?array $installed_payload = null ): array {
		if ( is_array( $installed_payload['runtime_overlays'] ?? null ) ) {
			return $installed_payload['runtime_overlays'];
		}

		$overlays    = array();
		$flow_config = is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array();
		$steps       = array();

		foreach ( $flow_config as $flow_step_id => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_overlay = array();
			foreach ( array( 'prompt_queue', 'config_patch_queue', 'queue_mode', '_queue_consume_revision' ) as $field ) {
				if ( array_key_exists( $field, $step ) ) {
					$step_overlay[ $field ] = $step[ $field ];
				}
			}
			if ( array_key_exists( 'max_items', $step['handler_config'] ?? array() ) ) {
				$step_overlay['handler_config'] = array( 'max_items' => $step['handler_config']['max_items'] );
			}
			if ( is_array( $step['handler_configs'] ?? null ) ) {
				foreach ( $step['handler_configs'] as $handler_slug => $handler_config ) {
					if ( is_array( $handler_config ) && array_key_exists( 'max_items', $handler_config ) ) {
						$step_overlay['handler_configs'][ (string) $handler_slug ] = array( 'max_items' => $handler_config['max_items'] );
					}
				}
			}

			if ( ! empty( $step_overlay ) ) {
				ksort( $step_overlay, SORT_STRING );
				$steps[ (string) $flow_step_id ] = $step_overlay;
			}
		}

		if ( ! empty( $steps ) ) {
			ksort( $steps, SORT_STRING );
			$overlays['steps'] = $steps;
		}

		$scheduling = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
		unset( $scheduling['last_run'], $scheduling['next_run'], $scheduling['run_count'], $scheduling['run_artifacts'] );
		if ( ! empty( $scheduling ) ) {
			ksort( $scheduling, SORT_STRING );
			$overlays['scheduling_config'] = $scheduling;
		}

		ksort( $overlays, SORT_STRING );
		return $overlays;
	}

	private function pipelines(): Pipelines {
		if ( null === $this->pipelines ) {
			$this->pipelines = new Pipelines();
		}

		return $this->pipelines;
	}

	private function flows(): Flows {
		if ( null === $this->flows ) {
			$this->flows = new Flows();
		}

		return $this->flows;
	}
}
