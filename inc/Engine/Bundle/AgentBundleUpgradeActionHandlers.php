<?php
/**
 * PendingAction handler registration for bundle upgrades.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;

defined( 'ABSPATH' ) || exit;

add_filter(
	'datamachine_pending_action_handlers',
	static function ( $handlers ) {
		if ( ! is_array( $handlers ) ) {
			$handlers = array();
		}

		$handlers[ AgentBundleUpgradePendingAction::KIND ] = array(
			'apply'       => static function ( array $apply_input ) {
				return AgentBundleUpgradePendingAction::apply( $apply_input );
			},
			'can_resolve' => static function () {
				return current_user_can( 'manage_options' );
			},
		);

		return $handlers;
	}
);

add_filter(
	'datamachine_agent_bundle_apply_artifact',
	static function ( $result, array $artifact, array $agent, array $context ) {
		if ( null !== $result ) {
			return $result;
		}

		return AgentBundleCoreArtifactApply::apply( $artifact, $agent, $context );
	},
	10,
	4
);

/**
 * Applies Data Machine-owned bundle artifacts from approved PendingActions.
 */
final class AgentBundleCoreArtifactApply {

	/**
	 * Apply an approved core artifact.
	 *
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $agent Agent row or agent context.
	 * @param array<string,mixed> $context Apply context.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public static function apply( array $artifact, array $agent, array $context ): mixed {
		$type     = (string) ( $artifact['artifact_type'] ?? '' );
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		$payload  = $artifact['payload'] ?? null;

		if ( $agent_id <= 0 || null === $payload || ! in_array( $type, array( 'agent_config', 'pipeline', 'flow', 'prompt', 'rubric' ), true ) ) {
			return null;
		}

		if ( in_array( $type, array( 'prompt', 'rubric' ), true ) ) {
			$applied = array(
				'artifact_type' => $type,
				'artifact_id'   => (string) ( $artifact['artifact_id'] ?? '' ),
			);
		} elseif ( 'agent_config' === $type ) {
			if ( ! is_array( $payload ) ) {
				return null;
			}
			$applied = self::apply_agent_config( $artifact, $agent_id, $payload );
		} else {
			if ( ! is_array( $payload ) ) {
				return null;
			}
			$applied = 'pipeline' === $type
				? self::apply_pipeline( $artifact, $agent_id, $payload )
				: self::apply_flow( $artifact, $agent_id, $payload );
		}
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$registry = self::record_applied_artifact( $artifact, $agent_id, $context );
		if ( is_wp_error( $registry ) ) {
			return $registry;
		}

		return array_merge( $applied, array( 'registry' => 'updated' ) );
	}

	/**
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $payload Artifact payload.
	 */
	private static function apply_agent_config( array $artifact, int $agent_id, array $payload ): array|\WP_Error {
		$agents = new Agents();
		$agent  = $agents->get_agent( $agent_id );
		if ( ! $agent ) {
			return new \WP_Error( 'datamachine_bundle_agent_missing', sprintf( 'Agent ID %d was not found.', $agent_id ) );
		}
		$current_config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		$payload        = AgentConfigArtifactProjector::preserve_local_paths( $payload, $current_config );
		if ( ! empty( $current_config['datamachine_bundle'] ) && ! isset( $payload['datamachine_bundle'] ) ) {
			$payload['datamachine_bundle'] = $current_config['datamachine_bundle'];
		}

		if ( ! $agents->update_agent( $agent_id, array( 'agent_config' => $payload ) ) ) {
			return new \WP_Error( 'datamachine_bundle_agent_config_update_failed', 'Failed to update agent_config bundle artifact.' );
		}

		return array(
			'artifact_type' => 'agent_config',
			'artifact_id'   => (string) ( $artifact['artifact_id'] ?? 'config' ),
			'agent_id'      => $agent_id,
		);
	}

	/**
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $payload Artifact payload.
	 */
	private static function apply_pipeline( array $artifact, int $agent_id, array $payload ): array|\WP_Error {
		$slug = self::artifact_slug( $artifact, $payload, 'pipeline' );
		$repo = new Pipelines();
		$row  = $repo->get_by_portable_slug( $agent_id, $slug );
		if ( ! $row ) {
			return new \WP_Error( 'datamachine_bundle_pipeline_missing', sprintf( 'Pipeline artifact "%s" is not installed for this agent.', $slug ) );
		}

		$updated = $repo->update_pipeline(
			(int) $row['pipeline_id'],
			array(
				'pipeline_name'   => (string) ( $payload['pipeline_name'] ?? $row['pipeline_name'] ?? $slug ),
				'pipeline_config' => is_array( $payload['pipeline_config'] ?? null ) ? $payload['pipeline_config'] : array(),
				'portable_slug'   => $slug,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'datamachine_bundle_pipeline_update_failed', sprintf( 'Failed to update pipeline artifact "%s".', $slug ) );
		}

		return array(
			'artifact_type' => 'pipeline',
			'artifact_id'   => $slug,
			'pipeline_id'   => (int) $row['pipeline_id'],
		);
	}

	/**
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $payload Artifact payload.
	 */
	private static function apply_flow( array $artifact, int $agent_id, array $payload ): array|\WP_Error {
		$slug = self::artifact_slug( $artifact, $payload, 'flow' );
		$repo = new Flows();
		$row  = null;
		foreach ( $repo->get_all_flows( null, $agent_id ) as $flow ) {
			if ( (string) ( $flow['portable_slug'] ?? '' ) === $slug ) {
				$row = $flow;
				break;
			}
		}

		if ( ! $row ) {
			return new \WP_Error( 'datamachine_bundle_flow_missing', sprintf( 'Flow artifact "%s" is not installed for this agent.', $slug ) );
		}

		$updated = $repo->update_flow(
			(int) $row['flow_id'],
			array(
				'flow_name'     => (string) ( $payload['flow_name'] ?? $row['flow_name'] ?? $slug ),
				'flow_config'   => is_array( $payload['flow_config'] ?? null ) ? $payload['flow_config'] : array(),
				'portable_slug' => $slug,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'datamachine_bundle_flow_update_failed', sprintf( 'Failed to update flow artifact "%s".', $slug ) );
		}

		return array(
			'artifact_type' => 'flow',
			'artifact_id'   => $slug,
			'flow_id'       => (int) $row['flow_id'],
		);
	}

	/**
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $payload Artifact payload.
	 */
	private static function artifact_slug( array $artifact, array $payload, string $fallback ): string {
		return PortableSlug::normalize(
			(string) ( $payload['portable_slug'] ?? $artifact['artifact_id'] ?? $fallback ),
			$fallback
		);
	}

	/**
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $context Apply context.
	 */
	private static function record_applied_artifact( array $artifact, int $agent_id, array $context ): bool|\WP_Error {
		$agents = new Agents();
		$agent  = $agents->get_agent( $agent_id );
		if ( ! $agent ) {
			return new \WP_Error( 'datamachine_bundle_agent_missing', sprintf( 'Agent ID %d was not found.', $agent_id ) );
		}

		$config           = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		$bundle           = is_array( $context['bundle'] ?? null ) ? $context['bundle'] : array();
		$bundle_slug      = (string) ( $bundle['bundle_slug'] ?? $config['datamachine_bundle']['bundle_slug'] ?? '' );
		$version          = (string) ( $bundle['bundle_version'] ?? $config['datamachine_bundle']['bundle_version'] ?? '' );
		$template_slug    = (string) ( $bundle['template_slug'] ?? $config['datamachine_bundle']['template_slug'] ?? $bundle_slug );
		$template_version = (string) ( $bundle['template_version'] ?? $config['datamachine_bundle']['template_version'] ?? $version );
		$source_ref       = (string) ( $bundle['source_ref'] ?? $config['datamachine_bundle']['source_ref'] ?? '' );
		$source_revision  = (string) ( $bundle['source_revision'] ?? $config['datamachine_bundle']['source_revision'] ?? '' );
		$type             = (string) ( $artifact['artifact_type'] ?? '' );
		$id               = (string) ( $artifact['artifact_id'] ?? '' );
		$payload          = $artifact['payload'] ?? null;
		$hash             = AgentBundleArtifactHasher::hash( $payload );
		$now              = gmdate( 'c' );

		if ( '' === $bundle_slug || '' === $type || '' === $id ) {
			return new \WP_Error( 'datamachine_bundle_registry_incomplete', 'Bundle artifact registry metadata is incomplete.' );
		}

		$config['datamachine_bundle']['bundle_slug']      = $bundle_slug;
		$config['datamachine_bundle']['bundle_version']   = $version;
		$config['datamachine_bundle']['template_slug']    = $template_slug;
		$config['datamachine_bundle']['template_version'] = $template_version;
		$config['datamachine_bundle']['source_ref']       = $source_ref;
		$config['datamachine_bundle']['source_revision']  = $source_revision;
		$artifact_record                                  = array(
			'bundle_slug'       => $bundle_slug,
			'bundle_version'    => $version,
			'artifact_type'     => $type,
			'artifact_id'       => $id,
			'source_path'       => (string) ( $artifact['source_path'] ?? '' ),
			'installed_hash'    => $hash,
			'current_hash'      => $hash,
			'installed_payload' => $payload,
			'status'            => AgentBundleArtifactStatus::CLEAN,
			'installed_at'      => $now,
			'updated_at'        => $now,
		);
		if ( ! AgentBundleArtifactState::persist_for_agent( $agent_id, array( $artifact_record ) ) ) {
			return new \WP_Error( 'datamachine_bundle_registry_update_failed', 'Failed to update bundle artifact registry.' );
		}

		if ( ! $agents->update_agent( $agent_id, array( 'agent_config' => $config ) ) ) {
			return new \WP_Error( 'datamachine_bundle_registry_update_failed', 'Failed to update bundle artifact registry.' );
		}

		return true;
	}
}
