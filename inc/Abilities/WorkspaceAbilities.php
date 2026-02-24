<?php
/**
 * Workspace Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent workspace operations.
 * Provides read-only discovery of the workspace path and repo listing.
 *
 * Note: Clone and remove operations are intentionally CLI-only for now.
 * See issue #338 for future exploration of coding capabilities.
 *
 * @package DataMachine\Abilities
 * @since 0.31.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceAbilities {

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
				'datamachine/workspace-path',
				array(
					'label'               => 'Get Workspace Path',
					'description'         => 'Get the agent workspace directory path',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'exists'  => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( self::class, 'getPath' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/workspace-list',
				array(
					'label'               => 'List Workspace Repos',
					'description'         => 'List repositories in the agent workspace',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'path'    => array( 'type' => 'string' ),
							'repos'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name'   => array( 'type' => 'string' ),
										'path'   => array( 'type' => 'string' ),
										'git'    => array( 'type' => 'boolean' ),
										'remote' => array( 'type' => 'string' ),
										'branch' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listRepos' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Get workspace path.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function getPath( array $input ): array {
		$workspace = new Workspace();

		return array(
			'success' => true,
			'path'    => $workspace->get_path(),
			'exists'  => is_dir( $workspace->get_path() ),
		);
	}

	/**
	 * List workspace repos.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function listRepos( array $input ): array {
		$workspace = new Workspace();
		return $workspace->list_repos();
	}
}
