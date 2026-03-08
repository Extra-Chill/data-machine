<?php
/**
 * Agent Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent identity operations.
 * Provides rename functionality for first-class agent identities.
 *
 * @package DataMachine\Abilities
 * @since 0.38.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class AgentAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/rename-agent',
				array(
					'label'               => 'Rename Agent',
					'description'         => 'Rename an agent slug — updates database and moves filesystem directory',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'old_slug', 'new_slug' ),
						'properties' => array(
							'old_slug' => array(
								'type'        => 'string',
								'description' => 'Current agent slug.',
							),
							'new_slug' => array(
								'type'        => 'string',
								'description' => 'New agent slug (sanitized automatically).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'message'  => array( 'type' => 'string' ),
							'old_slug' => array( 'type' => 'string' ),
							'new_slug' => array( 'type' => 'string' ),
							'old_path' => array( 'type' => 'string' ),
							'new_path' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'renameAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/list-agents',
				array(
					'label'               => 'List Agents',
					'description'         => 'List all registered agent identities',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agents'  => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'agent_id'   => array( 'type' => 'integer' ),
										'agent_slug' => array( 'type' => 'string' ),
										'agent_name' => array( 'type' => 'string' ),
										'owner_id'   => array( 'type' => 'integer' ),
										'status'     => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listAgents' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Rename an agent — update DB slug and move filesystem directory.
	 *
	 * @param array $input Input parameters with old_slug and new_slug.
	 * @return array Result.
	 */
	public static function renameAgent( array $input ): array {
		$old_slug = sanitize_title( $input['old_slug'] );
		$new_slug = sanitize_title( $input['new_slug'] );

		if ( $old_slug === $new_slug ) {
			return array(
				'success' => false,
				'message' => 'Old and new slugs are identical.',
			);
		}

		if ( empty( $new_slug ) ) {
			return array(
				'success' => false,
				'message' => 'New slug cannot be empty.',
			);
		}

		$agents_repo = new Agents();

		// Validate source exists.
		$existing = $agents_repo->get_by_slug( $old_slug );

		if ( ! $existing ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Agent with slug "%s" not found.', $old_slug ),
			);
		}

		// Validate target is free.
		$conflict = $agents_repo->get_by_slug( $new_slug );

		if ( $conflict ) {
			return array(
				'success' => false,
				'message' => sprintf( 'An agent with slug "%s" already exists.', $new_slug ),
			);
		}

		$agent_id          = (int) $existing['agent_id'];
		$directory_manager = new DirectoryManager();
		$old_path          = $directory_manager->get_agent_identity_directory( $old_slug );
		$new_path          = $directory_manager->get_agent_identity_directory( $new_slug );

		// Move directory first — easier to roll back than a DB change.
		$dir_moved = false;

		if ( is_dir( $old_path ) ) {
			if ( is_dir( $new_path ) ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Target directory "%s" already exists.', $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$dir_moved = rename( $old_path, $new_path );

			if ( ! $dir_moved ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Failed to move directory from "%s" to "%s".', $old_path, $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}
		}

		// Update database.
		$db_ok = $agents_repo->update_slug( $agent_id, $new_slug );

		if ( ! $db_ok ) {
			// Roll back directory move.
			if ( $dir_moved ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $new_path, $old_path );
			}

			return array(
				'success'  => false,
				'message'  => 'Database update failed. Directory change reverted.',
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
				'old_path' => $old_path,
				'new_path' => $new_path,
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				'Agent renamed from "%s" to "%s".%s',
				$old_slug,
				$new_slug,
				$dir_moved ? ' Directory moved.' : ' No directory to move.'
			),
			'old_slug' => $old_slug,
			'new_slug' => $new_slug,
			'old_path' => $old_path,
			'new_path' => $new_path,
		);
	}

	/**
	 * List all registered agents.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function listAgents( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_Ability interface.
		$agents_repo = new Agents();
		$rows        = $agents_repo->get_all();

		$agents = array();

		foreach ( $rows as $row ) {
			$agents[] = array(
				'agent_id'   => (int) $row['agent_id'],
				'agent_slug' => (string) $row['agent_slug'],
				'agent_name' => (string) $row['agent_name'],
				'owner_id'   => (int) $row['owner_id'],
				'status'     => (string) $row['status'],
			);
		}

		return array(
			'success' => true,
			'agents'  => $agents,
		);
	}
}
