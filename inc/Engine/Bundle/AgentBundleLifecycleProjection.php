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
		$existing_flows_by_pipeline = array();
		$incoming_config            = is_array( $bundle['agent']['agent_config'] ?? null ) ? $bundle['agent']['agent_config'] : array();

		$artifacts[] = array(
			'artifact_type' => 'agent_config',
			'artifact_id'   => 'config',
			'source_path'   => 'manifest.json#/agent/agent_config',
			'payload'       => AgentBundleAgentConfig::tracked_payload( $incoming_config ),
		);

		if ( $agent_id > 0 ) {
			// Mirror the bundle-side key: prefer stored portable_slug, fall back
			// to the normalized pipeline_name. Live-origin agents (portable_slug
			// NULL) only become matchable through the name fallback.
			$existing_pipelines_by_slug = AgentBundleSlugMatcher::index_existing(
				$this->pipelines()->get_all_pipelines( null, $agent_id ),
				'pipeline_name',
				'pipeline'
			)['matched'];

			foreach ( $this->flows()->get_all_flows( null, $agent_id ) as $existing_flow_row ) {
				if ( ! is_array( $existing_flow_row ) ) {
					continue;
				}
				$existing_flows_by_pipeline[ (int) ( $existing_flow_row['pipeline_id'] ?? 0 ) ][] = $existing_flow_row;
			}
		}

		foreach ( $bundle['pipelines'] ?? array() as $pipeline ) {
			if ( ! is_array( $pipeline ) ) {
				continue;
			}

			$slug = AgentBundleSlugMatcher::bundle_slug( $pipeline, 'pipeline_name', 'pipeline' );
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
				'payload'       => AgentBundleArtifactPayloads::pipeline_payload( $pipeline, $slug ),
			);
		}

		foreach ( $bundle['flows'] ?? array() as $flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}

			$slug            = AgentBundleSlugMatcher::bundle_slug( $flow, 'flow_name', 'flow' );
			$old_pipeline_id = (int) ( $flow['original_pipeline_id'] ?? 0 );
			$new_pipeline_id = (int) ( $pipeline_id_map[ $old_pipeline_id ] ?? 0 );
			$existing_flow   = null;
			if ( $new_pipeline_id > 0 ) {
				$flow_index    = AgentBundleSlugMatcher::index_existing( $existing_flows_by_pipeline[ $new_pipeline_id ] ?? array(), 'flow_name', 'flow' );
				$existing_flow = $flow_index['matched'][ $slug ] ?? null;
			}

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
				'payload'       => AgentBundleArtifactPayloads::flow_payload( $flow, $slug ),
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
					'payload'       => AgentBundleArtifactPayloads::pipeline_payload( $pipeline_by_slug[ $id ], $id ),
				);
			}
			if ( 'flow' === $type && isset( $flow_by_slug[ $id ] ) ) {
				$installed_payload = is_array( $record['installed_payload'] ?? null ) ? $record['installed_payload'] : null;
				$artifacts[]       = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $id,
					'source_path'   => (string) ( $record['source_path'] ?? '' ),
					'payload'       => AgentBundleArtifactPayloads::flow_payload( $flow_by_slug[ $id ], $id, $installed_payload ),
				);
			}
			if ( in_array( $type, self::bundle_file_artifact_types(), true ) ) {
				$payload = self::current_payload_from_record( $record );
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
		return AgentBundleArtifactDefinitions::file_artifact_rows_from_bundle( $bundle );
	}

	/** @return string[] */
	private static function bundle_file_artifact_types(): array {
		return AgentBundleArtifactDefinitions::file_artifact_types();
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
