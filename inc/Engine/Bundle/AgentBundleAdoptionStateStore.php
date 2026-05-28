<?php
/**
 * Agents API package adoption state store for Data Machine bundles.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Adapts Data Machine bundle artifact state to the storage-neutral Agents API contract.
 */
final class AgentBundleAdoptionStateStore implements \WP_Agent_Package_Artifact_State_Store {

	/** @var array<string,mixed> */
	private array $agent;

	/** @var array<int,array<string,mixed>> */
	private array $installed;

	/** @var array<int,array<string,mixed>> */
	private array $current;

	/** @var array<int,array<string,mixed>> */
	private array $target;

	/** @var array<string,mixed> */
	private array $bundle;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>              $agent Agent row or context.
	 * @param array<int,array<string,mixed>>   $installed Installed bundle artifact rows.
	 * @param array<int,array<string,mixed>>   $current Current artifact rows.
	 * @param array<int,array<string,mixed>>   $target Target artifact rows.
	 * @param array<string,mixed>              $bundle Bundle metadata.
	 */
	public function __construct( array $agent, array $installed, array $current, array $target, array $bundle ) {
		$this->agent     = $agent;
		$this->installed = $installed;
		$this->current   = $current;
		$this->target    = $target;
		$this->bundle    = $bundle;
	}

	public function get_installed_artifacts( \WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return self::package_artifact_rows( $this->installed );
	}

	public function get_current_artifacts( \WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return self::package_artifact_rows( $this->current );
	}

	public function get_target_artifacts( \WP_Agent_Package $package, array $context = array() ): array {
		unset( $package, $context );
		return self::package_artifact_rows( $this->target );
	}

	public function record_installed_artifacts( \WP_Agent_Package $package, array $artifacts, array $context = array() ): bool {
		unset( $package, $context );
		global $wpdb;

		$agent_id = (int) ( $this->agent['agent_id'] ?? 0 );
		if ( $agent_id <= 0 || ! is_object( $wpdb ) ) {
			return false;
		}

		$agents = new Agents();
		$agent  = $agents->get_agent( $agent_id );
		if ( ! $agent ) {
			return false;
		}

		$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		if ( ! isset( $config['datamachine_bundle'] ) || ! is_array( $config['datamachine_bundle'] ) ) {
			$config['datamachine_bundle'] = array();
		}

		$config['datamachine_bundle']['bundle_slug']      = (string) ( $this->bundle['bundle_slug'] ?? $config['datamachine_bundle']['bundle_slug'] ?? '' );
		$config['datamachine_bundle']['bundle_version']   = (string) ( $this->bundle['bundle_version'] ?? $config['datamachine_bundle']['bundle_version'] ?? '' );
		$config['datamachine_bundle']['template_slug']    = (string) ( $this->bundle['template_slug'] ?? $config['datamachine_bundle']['template_slug'] ?? $config['datamachine_bundle']['bundle_slug'] ?? '' );
		$config['datamachine_bundle']['template_version'] = (string) ( $this->bundle['template_version'] ?? $config['datamachine_bundle']['template_version'] ?? $config['datamachine_bundle']['bundle_version'] ?? '' );
		$config['datamachine_bundle']['source_ref']       = (string) ( $this->bundle['source_ref'] ?? $config['datamachine_bundle']['source_ref'] ?? '' );
		$config['datamachine_bundle']['source_revision']  = (string) ( $this->bundle['source_revision'] ?? $config['datamachine_bundle']['source_revision'] ?? '' );

		$artifact_records = array();
		foreach ( $artifacts as $artifact ) {
			if ( ! $artifact instanceof \WP_Agent_Package_Installed_Artifact ) {
				continue;
			}

			$row  = $artifact->to_array();
			$type = AgentBundleUpgradePlanner::bundle_artifact_type( (string) $row['artifact_type'] );
			$id   = (string) $row['artifact_id'];
			$key  = AgentBundleArtifactExtensions::artifact_key( $type, $id );

			$artifact_records[ $key ] = array(
				'bundle_slug'       => (string) $config['datamachine_bundle']['bundle_slug'],
				'bundle_version'    => (string) $config['datamachine_bundle']['bundle_version'],
				'artifact_type'     => $type,
				'artifact_id'       => $id,
				'source_path'       => (string) ( $row['source'] ?? '' ),
				'installed_hash'    => $row['installed_hash'] ?? null,
				'current_hash'      => $row['current_hash'] ?? null,
				'installed_payload' => $row['installed_payload'] ?? null,
				'status'            => AgentBundleArtifactStatus::classify( $row['installed_hash'] ?? null, $row['current_hash'] ?? null ),
				'installed_at'      => (string) ( $row['installed_at'] ?? '' ),
				'updated_at'        => (string) ( $row['updated_at'] ?? '' ),
			);
		}

		if ( ! AgentBundleArtifactState::persist_for_agent( $agent_id, array_values( $artifact_records ) ) ) {
			return false;
		}

		return (bool) $agents->update_agent( $agent_id, array( 'agent_config' => $config ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $artifacts Bundle artifact rows.
	 * @return array<int,array<string,mixed>> Package artifact rows.
	 */
	public static function package_artifact_rows( array $artifacts ): array {
		$rows = array();
		foreach ( $artifacts as $artifact ) {
			if ( ! is_array( $artifact ) ) {
				continue;
			}

			$type = (string) ( $artifact['artifact_type'] ?? '' );
			$id   = (string) ( $artifact['artifact_id'] ?? '' );
			if ( '' === $type || '' === $id ) {
				continue;
			}

			$rows[] = array_merge(
				$artifact,
				array(
					'artifact_type' => AgentBundleUpgradePlanner::package_artifact_type( $type ),
					'artifact_id'   => $id,
					'source'        => (string) ( $artifact['source_path'] ?? ( $artifact['source'] ?? '' ) ),
				)
			);
		}

		return $rows;
	}
}
