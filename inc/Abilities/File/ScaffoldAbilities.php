<?php
/**
 * Scaffold Abilities
 *
 * WordPress 6.9 Abilities API primitive for memory file scaffolding.
 * Universal entry point for creating missing memory files — used by
 * CLI, REST, chat tools, directives, and self-healing code paths.
 *
 * @package DataMachine\Abilities\File
 * @since   0.50.0
 */

namespace DataMachine\Abilities\File;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\FileScaffolder;

defined( 'ABSPATH' ) || exit;

class ScaffoldAbilities {

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
			$this->registerScaffoldMemoryFile();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerScaffoldMemoryFile(): void {
		wp_register_ability(
			'datamachine/scaffold-memory-file',
			array(
				'label'               => __( 'Scaffold Memory File', 'data-machine' ),
				'description'         => __( 'Create a missing memory file with default content generated from context. Never overwrites existing files.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'filename' ),
					'properties' => array(
						'filename'   => array(
							'type'        => 'string',
							'description' => __( 'Filename to scaffold (e.g. USER.md, SOUL.md, MEMORY.md, daily/2026/03/20.md).', 'data-machine' ),
						),
						'user_id'    => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID. Required for user-layer files (USER.md).', 'data-machine' ),
							'default'     => 0,
						),
						'agent_slug' => array(
							'type'        => 'string',
							'description' => __( 'Agent slug. Used for agent-layer files (SOUL.md, MEMORY.md). Falls back to user_id resolution.', 'data-machine' ),
						),
						'agent_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID. Alternative to agent_slug for agent-layer files.', 'data-machine' ),
							'default'     => 0,
						),
						'filepath'   => array(
							'type'        => 'string',
							'description' => __( 'Explicit filesystem path. For dynamic files not in the registry (e.g. daily memory). When provided, filename is treated as logical name for content generation.', 'data-machine' ),
						),
						'date'       => array(
							'type'        => 'string',
							'description' => __( 'Date in YYYY-MM-DD format. Used by daily memory content generator.', 'data-machine' ),
						),
					),
				),
				'permission_callback' => function () {
					return PermissionHelper::can( 'chat' );
				},
				'callback'            => array( __CLASS__, 'executeScaffold' ),
			)
		);
	}

	/**
	 * Execute the scaffold ability.
	 *
	 * @param array $input Ability input.
	 * @return array Result.
	 */
	public static function executeScaffold( array $input ): array {
		$filename = $input['filename'] ?? '';
		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'Filename is required.',
			);
		}

		$context = array_filter(
			array(
				'user_id'    => (int) ( $input['user_id'] ?? 0 ),
				'agent_slug' => $input['agent_slug'] ?? null,
				'agent_id'   => (int) ( $input['agent_id'] ?? 0 ),
				'date'       => $input['date'] ?? null,
			),
			function ( $v ) {
				return null !== $v && '' !== $v && 0 !== $v;
			}
		);

		// Explicit filepath = dynamic file (not in registry).
		$explicit_path = $input['filepath'] ?? '';
		if ( ! empty( $explicit_path ) ) {
			$created = FileScaffolder::ensure_at( $explicit_path, $filename, $context );
		} else {
			$created = FileScaffolder::ensure( $filename, $context );
		}

		if ( $created ) {
			return array(
				'success'  => true,
				'message'  => sprintf( 'Scaffolded %s.', $filename ),
				'filename' => $filename,
				'created'  => true,
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf( '%s already exists or no content generator is registered.', $filename ),
			'filename' => $filename,
			'created'  => false,
		);
	}
}
