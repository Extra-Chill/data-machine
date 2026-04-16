<?php
/**
 * Agent Memory Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent memory operations.
 * Provides section-level read/write access to any agent file.
 *
 * @package DataMachine\Abilities
 * @since 0.30.0
 * @since 0.45.0 Added file parameter to all abilities for any-file support.
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\AgentMemory;

defined( 'ABSPATH' ) || exit;

class AgentMemoryAbilities {

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
				'datamachine/get-agent-memory',
				array(
					'label'               => 'Get Agent Memory',
					'description'         => 'Read agent file content — full file or a specific section. Supports any agent file (MEMORY.md, SOUL.md, USER.md, etc.).',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID for agent-scoped memory. Takes priority over user_id when provided.',
							),
							'user_id'  => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'file'     => array(
								'type'        => 'string',
								'description' => 'Target file (e.g. MEMORY.md, SOUL.md, USER.md). Defaults to MEMORY.md.',
								'default'     => 'MEMORY.md',
							),
							'section'  => array(
								'type'        => 'string',
								'description' => 'Section name to read (without ##). If omitted, returns the full file.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'content'            => array( 'type' => 'string' ),
							'section'            => array( 'type' => 'string' ),
							'message'            => array( 'type' => 'string' ),
							'available_sections' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'getMemory' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/update-agent-memory',
				array(
					'label'               => 'Update Agent Memory',
					'description'         => 'Write to a specific section of an agent file — set (replace) or append. Supports any agent file.',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID for agent-scoped memory. Takes priority over user_id when provided.',
							),
							'user_id'  => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'file'     => array(
								'type'        => 'string',
								'description' => 'Target file (e.g. MEMORY.md, SOUL.md, USER.md). Defaults to MEMORY.md.',
								'default'     => 'MEMORY.md',
							),
							'section'  => array(
								'type'        => 'string',
								'description' => 'Section name (without ##). Created if it does not exist.',
							),
							'content'  => array(
								'type'        => 'string',
								'description' => 'Content to write to the section.',
							),
							'mode'     => array(
								'type'        => 'string',
								'enum'        => array( 'set', 'append' ),
								'description' => 'Write mode: "set" replaces section content, "append" adds to end.',
							),
						),
						'required'   => array( 'section', 'content', 'mode' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'updateMemory' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/search-agent-memory',
				array(
					'label'               => 'Search Agent Memory',
					'description'         => 'Search across agent file content. Returns matching lines with context, grouped by section. Supports any agent file.',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID for agent-scoped memory. Takes priority over user_id when provided.',
							),
							'user_id'  => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'file'     => array(
								'type'        => 'string',
								'description' => 'Target file to search (e.g. MEMORY.md, SOUL.md). Defaults to MEMORY.md.',
								'default'     => 'MEMORY.md',
							),
							'query'    => array(
								'type'        => 'string',
								'description' => 'Search term (case-insensitive substring match).',
							),
							'section'  => array(
								'type'        => 'string',
								'description' => 'Optional section name to limit search to (without ##).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'query'       => array( 'type' => 'string' ),
							'match_count' => array( 'type' => 'integer' ),
							'matches'     => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'section' => array( 'type' => 'string' ),
										'line'    => array( 'type' => 'integer' ),
										'content' => array( 'type' => 'string' ),
										'context' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'searchMemory' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/list-agent-memory-sections',
				array(
					'label'               => 'List Agent Memory Sections',
					'description'         => 'List all section headers in an agent file. Supports any agent file.',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID for agent-scoped memory. Takes priority over user_id when provided.',
							),
							'user_id'  => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'file'     => array(
								'type'        => 'string',
								'description' => 'Target file (e.g. MEMORY.md, SOUL.md, USER.md). Defaults to MEMORY.md.',
								'default'     => 'MEMORY.md',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'sections' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'message'  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listSections' ),
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
	 * Read agent file — full file or a specific section.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function getMemory( array $input ): array {
		$memory  = self::resolveMemory( $input );
		$section = $input['section'] ?? null;

		if ( null === $section || '' === $section ) {
			return $memory->get_all();
		}

		return $memory->get_section( $section );
	}

	/**
	 * Update agent file — set or append to a section.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function updateMemory( array $input ): array {
		$memory  = self::resolveMemory( $input );
		$section = $input['section'];
		$content = $input['content'];
		$mode    = $input['mode'];

		// Check editability for non-MEMORY.md files.
		$filename = $input['file'] ?? 'MEMORY.md';
		if ( 'MEMORY.md' !== $filename ) {
			$editable = \DataMachine\Engine\AI\MemoryFileRegistry::is_editable( $filename );
			if ( ! $editable ) {
				return array(
					'success' => false,
					'message' => sprintf( 'File %s is read-only and cannot be edited via section write.', $filename ),
				);
			}
		}

		if ( 'append' === $mode ) {
			return $memory->append_to_section( $section, $content );
		}

		return $memory->set_section( $section, $content );
	}

	/**
	 * Search agent file content.
	 *
	 * @param array $input Input parameters with 'query' and optional 'section'.
	 * @return array Search results.
	 */
	public static function searchMemory( array $input ): array {
		$memory  = self::resolveMemory( $input );
		$query   = $input['query'];
		$section = $input['section'] ?? null;

		return $memory->search( $query, $section );
	}

	/**
	 * List all section headers in an agent file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function listSections( array $input ): array {
		$memory = self::resolveMemory( $input );
		return $memory->get_sections();
	}

	/**
	 * Resolve an AgentMemory instance from input parameters.
	 *
	 * @since 0.45.0
	 * @param array $input Input parameters with optional user_id, agent_id, file.
	 * @return AgentMemory
	 */
	private static function resolveMemory( array $input ): AgentMemory {
		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$filename = $input['file'] ?? 'MEMORY.md';

		return new AgentMemory( $user_id, $agent_id, $filename );
	}
}
