<?php
/**
 * Agents API identity-store adapter for Data Machine agents.
 *
 * @package DataMachine\Core\Identity
 */

namespace DataMachine\Core\Identity;

use AgentsAPI\Core\Identity\WP_Agent_Identity_Scope;
use AgentsAPI\Core\Identity\WP_Agent_Identity_Store;
use AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity;
use DataMachine\Abilities\File\ScaffoldAbilities;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes Data Machine's existing agent table through Agents API.
 */
class AgentIdentityStoreAdapter implements WP_Agent_Identity_Store {

	private Agents $agents_repository;

	public function __construct( ?Agents $agents_repository = null ) {
		$this->agents_repository = $agents_repository ?? new Agents();
	}

	/**
	 * Register this adapter as the Agents API identity store when no host supplied one.
	 */
	public static function register(): void {
		add_filter( 'wp_agent_identity_store', array( self::class, 'filter_identity_store' ) );
	}

	/**
	 * Provide Data Machine's store unless another host already provided one.
	 *
	 * @param mixed $store Existing filtered store.
	 * @return mixed
	 */
	public static function filter_identity_store( $store ) {
		if ( $store instanceof WP_Agent_Identity_Store ) {
			return $store;
		}

		static $adapter = null;
		if ( null === $adapter ) {
			$adapter = new self();
		}

		return $adapter;
	}

	/** @inheritDoc */
	public function resolve( WP_Agent_Identity_Scope $scope ): ?WP_Agent_Materialized_Identity {
		$scope = $scope->normalize();
		$row   = $this->agents_repository->get_by_identity_scope( $scope->agent_slug, $scope->owner_user_id, $scope->instance_key );
		return is_array( $row ) && $this->is_provisioned( $row ) ? $this->identity_from_row( $row, $scope ) : null;
	}

	/** @inheritDoc */
	public function get( int $identity_id ): ?WP_Agent_Materialized_Identity {
		$row = $this->agents_repository->get_agent( $identity_id );
		return is_array( $row ) && $this->is_provisioned( $row ) ? $this->identity_from_row( $row ) : null;
	}

	/** @inheritDoc */
	public function materialize( WP_Agent_Identity_Scope $scope, array $default_config = array(), array $meta = array() ): WP_Agent_Materialized_Identity {
		$scope    = $scope->normalize();
		$existing = $this->agents_repository->get_by_identity_scope( $scope->agent_slug, $scope->owner_user_id, $scope->instance_key );
		if ( is_array( $existing ) ) {
			return $this->provision( $existing, $scope, $meta );
		}

		// Only materialize identities that correspond to a registered agent
		// definition. A stale scope (e.g., from pruned user-meta or a cached
		// pointer) must not resurrect a phantom agent row.
		if ( function_exists( 'wp_has_agent' ) && ! wp_has_agent( $scope->agent_slug ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Cannot materialize unregistered agent "%s".', esc_html( $scope->agent_slug ) )
			);
		}

		$creation = $this->agents_repository->create_identity_if_missing(
			$scope->agent_slug,
			$this->label_from_meta( $meta, $scope->agent_slug ),
			$scope->owner_user_id,
			$scope->instance_key,
			$default_config
		);
		$agent_id = $creation['agent_id'];

		if ( $agent_id <= 0 ) {
			throw new \RuntimeException(
				sprintf( 'Failed to create agent row for "%s".', esc_html( $scope->agent_slug ) )
			);
		}

		$row = $this->agents_repository->get_agent( $agent_id );
		if ( ! is_array( $row ) ) {
			$row = array(
				'agent_id'     => $agent_id,
				'agent_slug'   => $scope->agent_slug,
				'agent_name'   => $this->label_from_meta( $meta, $scope->agent_slug ),
				'owner_id'     => $scope->owner_user_id,
				'instance_key' => $scope->instance_key,
				'agent_config' => $default_config,
			);
		}

		return $this->provision( $row, $scope, $meta );
	}

	/** @inheritDoc */
	public function update( WP_Agent_Materialized_Identity $identity ): WP_Agent_Materialized_Identity {
		$row = $this->agents_repository->get_agent( $identity->id );
		if ( ! is_array( $row ) ) {
			throw new \InvalidArgumentException( sprintf( 'Cannot update unknown materialized agent identity %d.', esc_html( (string) $identity->id ) ) );
		}

		$this->identity_from_row( $row, $identity->scope );
		if ( ! $this->agents_repository->update_agent( $identity->id, array( 'agent_config' => $identity->config ) ) ) {
			throw new \RuntimeException( sprintf( 'Failed to update materialized agent identity %d.', esc_html( (string) $identity->id ) ) );
		}

		$updated = $this->get( $identity->id );
		if ( null === $updated ) {
			throw new \RuntimeException( sprintf( 'Materialized agent identity %d disappeared after update.', esc_html( (string) $identity->id ) ) );
		}

		return $updated;
	}

	/** @inheritDoc */
	public function delete( WP_Agent_Identity_Scope $scope ): bool {
		unset( $scope );
		return false;
	}

	/**
	 * Run Data Machine side effects for newly-created identities.
	 *
	 * @param int                     $agent_id Created Data Machine agent ID.
	 * @param WP_Agent_Identity_Scope $scope    Normalized identity scope.
	 * @param array<string,mixed>     $meta     Materialization metadata.
	 */
	protected function provision_identity( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		if ( class_exists( AgentAccess::class ) && $scope->owner_user_id > 0 && ! ( new AgentAccess() )->bootstrap_owner_access( $agent_id, $scope->owner_user_id ) ) {
			throw new \RuntimeException( 'Failed to provision materialized agent owner access.' );
		}

		if ( class_exists( DirectoryManager::class ) ) {
			$dir_mgr   = new DirectoryManager();
			$agent_dir = $dir_mgr->resolve_agent_directory( array( 'agent_id' => $agent_id ) );
			if ( ! $dir_mgr->ensure_directory_exists( $agent_dir ) ) {
				throw new \RuntimeException( 'Failed to provision materialized agent directory.' );
			}
		}

		$scaffold = ScaffoldAbilities::get_ability();
		if ( $scaffold ) {
			$result = $scaffold->execute(
				array(
					'layer'         => 'agent',
					'agent_slug'    => $scope->agent_slug,
					'agent_id'      => $agent_id,
					'owner_user_id' => $scope->owner_user_id,
					'instance_key'  => $scope->instance_key,
				)
			);
			if ( is_array( $result ) && empty( $result['success'] ) ) {
				throw new \RuntimeException( 'Failed to provision materialized agent scaffold.' );
			}
		}

		do_action( 'datamachine_registered_agent_reconciled', $agent_id, $scope->agent_slug, $meta['datamachine_definition'] ?? $meta, $scope );
	}

	/** @param array<string,mixed> $row */
	private function provision( array $row, WP_Agent_Identity_Scope $scope, array $meta ): WP_Agent_Materialized_Identity {
		$this->identity_from_row( $row, $scope );
		if ( $this->is_provisioned( $row ) ) {
			return $this->identity_from_row( $row, $scope, $meta );
		}

		$agent_id = (int) ( $row['agent_id'] ?? 0 );
		$token    = hash( 'sha256', wp_generate_uuid4() . ':' . $agent_id );
		if ( ! $this->agents_repository->claim_identity_provisioning( $agent_id, $token ) ) {
			$current = $this->agents_repository->get_agent( $agent_id );
			if ( is_array( $current ) && $this->is_provisioned( $current ) ) {
				return $this->identity_from_row( $current, $scope, $meta );
			}
			throw new \RuntimeException( sprintf( 'Materialized agent identity %d is currently provisioning; retry.', esc_html( (string) $agent_id ) ) );
		}

		try {
			$this->provision_identity( $agent_id, $scope, $meta );
			if ( ! $this->agents_repository->complete_identity_provisioning( $agent_id, $token ) ) {
				throw new \RuntimeException( sprintf( 'Failed to mark materialized agent identity %d provisioned.', esc_html( (string) $agent_id ) ) );
			}
		} catch ( \Throwable $throwable ) {
			$this->agents_repository->release_identity_provisioning( $agent_id, $token );
			throw $throwable;
		}

		$current = $this->agents_repository->get_agent( $agent_id );
		if ( ! is_array( $current ) || ! $this->is_provisioned( $current ) ) {
			throw new \RuntimeException( sprintf( 'Materialized agent identity %d provisioning state was not persisted.', esc_html( (string) $agent_id ) ) );
		}
		return $this->identity_from_row( $current, $scope, $meta );
	}

	/** @param array<string,mixed> $row */
	private function is_provisioned( array $row ): bool {
		return is_scalar( $row['provisioned_at'] ?? null ) && '' !== (string) $row['provisioned_at'];
	}

	/**
	 * Convert a Data Machine agent row to the Agents API identity value object.
	 *
	 * @param array<string,mixed>          $row   Agent row.
	 * @param WP_Agent_Identity_Scope|null $scope Optional requested scope.
	 * @param array<string,mixed>          $meta  Additional metadata.
	 */
	private function identity_from_row( array $row, ?WP_Agent_Identity_Scope $scope = null, array $meta = array() ): WP_Agent_Materialized_Identity {
		$persisted_scope = new WP_Agent_Identity_Scope(
			(string) ( $row['agent_slug'] ?? '' ),
			(int) ( $row['owner_id'] ?? 0 ),
			(string) ( $row['instance_key'] ?? 'default' )
		);
		$persisted_scope = $persisted_scope->normalize();
		if ( null !== $scope && $persisted_scope->key() !== $scope->normalize()->key() ) {
			throw new \UnexpectedValueException( sprintf( 'Persisted identity %d does not match requested scope.', (int) ( $row['agent_id'] ?? 0 ) ) );
		}

		return new WP_Agent_Materialized_Identity(
			(int) ( $row['agent_id'] ?? 0 ),
			$persisted_scope,
			is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array(),
			array_merge(
				$meta,
				array(
					'datamachine_agent_id'     => (int) ( $row['agent_id'] ?? 0 ),
					'datamachine_owner_id'     => (int) ( $row['owner_id'] ?? 0 ),
					'datamachine_instance_key' => (string) ( $row['instance_key'] ?? 'default' ),
				)
			),
			$this->timestamp_from_row( $row, 'created_at' ),
			$this->timestamp_from_row( $row, 'updated_at' )
		);
	}

	/**
	 * @param array<string,mixed> $meta Agent metadata.
	 */
	private function label_from_meta( array $meta, string $fallback_slug ): string {
		$label = is_scalar( $meta['label'] ?? null ) ? trim( (string) $meta['label'] ) : '';
		return '' !== $label ? $label : $fallback_slug;
	}

	/**
	 * @param array<string,mixed> $row Agent row.
	 */
	private function timestamp_from_row( array $row, string $field ): ?int {
		$value = $row[ $field ] ?? null;
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : $timestamp;
	}
}
