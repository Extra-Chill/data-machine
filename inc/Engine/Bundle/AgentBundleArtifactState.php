<?php
/**
 * Installed bundle artifact state storage adapter.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes installed bundle artifact reads/writes.
 */
final class AgentBundleArtifactState {

	/**
	 * Return installed artifact rows for an agent.
	 *
	 * @param array<string,mixed> $agent Agent row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function installed_for_agent( array $agent ): array {
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		if ( $agent_id <= 0 ) {
			return array();
		}

		$store       = new InstalledBundleArtifacts();
		$bundle_slug = (string) ( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ?? '' );
		$installed   = '' !== $bundle_slug ? $store->list_for_bundle( $bundle_slug, $agent_id ) : $store->list_for_agent( $agent_id );

		return array_map( static fn( AgentBundleInstalledArtifact $artifact ): array => $artifact->to_array(), $installed );
	}

	/**
	 * Persist installed artifact rows for an agent.
	 *
	 * @param int                                             $agent_id Agent ID.
	 * @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts Artifact rows.
	 * @return bool
	 */
	public static function persist_for_agent( int $agent_id, array $artifacts ): bool {
		return ! ( self::persist_for_agent_result( $agent_id, $artifacts ) instanceof \WP_Error );
	}

	/**
	 * Persist installed artifact rows for an agent with structured failure details.
	 *
	 * @param int                                             $agent_id Agent ID.
	 * @param array<int,array<string,mixed>|AgentBundleInstalledArtifact> $artifacts Artifact rows.
	 * @return true|\WP_Error
	 */
	public static function persist_for_agent_result( int $agent_id, array $artifacts ): bool|\WP_Error {
		if ( $agent_id <= 0 ) {
			return new \WP_Error(
				'datamachine_bundle_artifact_invalid_agent_id',
				'Cannot persist installed bundle artifact state without a valid agent ID.',
				array( 'agent_id' => $agent_id )
			);
		}

		$store  = new InstalledBundleArtifacts();
		$errors = array();
		foreach ( $artifacts as $index => $artifact ) {
			$installed = null;
			try {
				$installed = $artifact instanceof AgentBundleInstalledArtifact ? $artifact : AgentBundleInstalledArtifact::from_array( (array) $artifact );
			} catch ( \Throwable $error ) {
				$context  = self::failure_context( $agent_id, (int) $index, $artifact, $error->getMessage(), get_class( $error ) );
				$errors[] = $context;
				do_action(
					'datamachine_log',
					'warning',
					'Failed to persist installed bundle artifact state.',
					$context
				);
				continue;
			}

			if ( ! $store->upsert( $installed, $agent_id ) ) {
				$db_context = $store->last_error_context();
				$message    = (string) ( $db_context['message'] ?? 'Database write returned false.' );
				$context    = self::failure_context( $agent_id, (int) $index, $installed, $message, 'database_write_failed' );
				if ( ! empty( $db_context ) ) {
					$context['database'] = $db_context;
				}
				$errors[] = $context;
				do_action(
					'datamachine_log',
					'warning',
					'Failed to persist installed bundle artifact state.',
					$context
				);
			}
		}

		if ( empty( $errors ) ) {
			return true;
		}

		return new \WP_Error(
			'datamachine_bundle_artifact_persist_failed',
			self::failure_message( $agent_id, $errors ),
			array(
				'agent_id' => $agent_id,
				'errors'   => $errors,
			)
		);
	}
	/**
	 * Build safe per-artifact failure context.
	 *
	 * @param int                                             $agent_id Agent ID.
	 * @param int                                             $index Artifact index.
	 * @param array<string,mixed>|AgentBundleInstalledArtifact $artifact Artifact row.
	 * @param string                                          $message Failure message.
	 * @param string                                          $error_class Failure class or category.
	 * @return array<string,mixed>
	 */
	private static function failure_context( int $agent_id, int $index, array|AgentBundleInstalledArtifact $artifact, string $message, string $error_class ): array {
		$row = $artifact instanceof AgentBundleInstalledArtifact ? $artifact->to_array() : $artifact;

		return array(
			'agent_id'       => $agent_id,
			'artifact_index' => $index,
			'artifact_type'  => (string) ( $row['artifact_type'] ?? '' ),
			'artifact_id'    => (string) ( $row['artifact_id'] ?? '' ),
			'source_path'    => (string) ( $row['source_path'] ?? '' ),
			'error'          => $message,
			'error_class'    => $error_class,
		);
	}

	/**
	 * Format a concise operator-facing failure message.
	 *
	 * @param int                        $agent_id Agent ID.
	 * @param array<int,array<string,mixed>> $errors Failure contexts.
	 */
	private static function failure_message( int $agent_id, array $errors ): string {
		$parts = array();
		foreach ( array_slice( $errors, 0, 3 ) as $error ) {
			$label   = trim( (string) ( $error['artifact_type'] ?? '' ) . ':' . (string) ( $error['artifact_id'] ?? '' ), ':' );
			$label   = '' !== $label ? $label : 'artifact #' . (string) ( $error['artifact_index'] ?? '?' );
			$parts[] = sprintf( '%s (%s)', $label, (string) ( $error['error'] ?? 'unknown error' ) );
		}

		if ( count( $errors ) > count( $parts ) ) {
			$parts[] = sprintf( '%d more failure(s)', count( $errors ) - count( $parts ) );
		}

		return sprintf( 'Failed to persist installed bundle artifact state for agent %d: %s', $agent_id, implode( '; ', $parts ) );
	}
}
