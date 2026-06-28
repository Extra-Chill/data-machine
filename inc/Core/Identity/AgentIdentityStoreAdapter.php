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
		$row = $this->agents_repository->get_by_slug( $scope->normalize()->agent_slug );
		return is_array( $row ) ? $this->identity_from_row( $row, $scope ) : null;
	}

	/** @inheritDoc */
	public function get( int $identity_id ): ?WP_Agent_Materialized_Identity {
		$row = $this->agents_repository->get_agent( $identity_id );
		return is_array( $row ) ? $this->identity_from_row( $row ) : null;
	}

	/** @inheritDoc */
	public function materialize( WP_Agent_Identity_Scope $scope, array $default_config = array(), array $meta = array() ): WP_Agent_Materialized_Identity {
		$scope    = $scope->normalize();
		$existing = $this->agents_repository->get_by_slug( $scope->agent_slug );
		if ( is_array( $existing ) ) {
			return $this->identity_from_row( $existing, $scope );
		}

		$agent_id = $this->agents_repository->create_if_missing(
			$scope->agent_slug,
			$this->label_from_meta( $meta, $scope->agent_slug ),
			$scope->owner_user_id,
			$default_config
		);

		$row = $this->agents_repository->get_agent( $agent_id );
		if ( ! is_array( $row ) ) {
			$row = array(
				'agent_id'     => $agent_id,
				'agent_slug'   => $scope->agent_slug,
				'agent_name'   => $this->label_from_meta( $meta, $scope->agent_slug ),
				'owner_id'     => $scope->owner_user_id,
				'agent_config' => $default_config,
			);
		}

		$this->after_created( $agent_id, $scope, $meta );

		return $this->identity_from_row( $row, $scope, $meta );
	}

	/** @inheritDoc */
	public function update( WP_Agent_Materialized_Identity $identity ): WP_Agent_Materialized_Identity {
		$this->agents_repository->update_agent( $identity->id, array( 'agent_config' => $identity->config ) );
		return $this->get( $identity->id ) ?? $identity;
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
	private function after_created( int $agent_id, WP_Agent_Identity_Scope $scope, array $meta ): void {
		if ( class_exists( AgentAccess::class ) ) {
			( new AgentAccess() )->bootstrap_owner_access( $agent_id, $scope->owner_user_id );
		}

		if ( class_exists( DirectoryManager::class ) ) {
			$dir_mgr   = new DirectoryManager();
			$agent_dir = $dir_mgr->get_agent_identity_directory( $scope->agent_slug );
			$dir_mgr->ensure_directory_exists( $agent_dir );
		}

		$scaffold = ScaffoldAbilities::get_ability();
		if ( $scaffold ) {
			$scaffold->execute(
				array(
					'layer'      => 'agent',
					'agent_slug' => $scope->agent_slug,
					'agent_id'   => $agent_id,
				)
			);
		}

		do_action( 'datamachine_registered_agent_reconciled', $agent_id, $scope->agent_slug, $meta['datamachine_definition'] ?? $meta );
	}

	/**
	 * Convert a Data Machine agent row to the Agents API identity value object.
	 *
	 * @param array<string,mixed>          $row   Agent row.
	 * @param WP_Agent_Identity_Scope|null $scope Optional requested scope.
	 * @param array<string,mixed>          $meta  Additional metadata.
	 */
	private function identity_from_row( array $row, ?WP_Agent_Identity_Scope $scope = null, array $meta = array() ): WP_Agent_Materialized_Identity {
		$scope ??= new WP_Agent_Identity_Scope(
			(string) ( $row['agent_slug'] ?? '' ),
			(int) ( $row['owner_id'] ?? 0 )
		);

		return new WP_Agent_Materialized_Identity(
			(int) ( $row['agent_id'] ?? 0 ),
			$scope->normalize(),
			is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array(),
			array_merge(
				$meta,
				array(
					'datamachine_agent_id' => (int) ( $row['agent_id'] ?? 0 ),
					'datamachine_owner_id' => (int) ( $row['owner_id'] ?? 0 ),
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
