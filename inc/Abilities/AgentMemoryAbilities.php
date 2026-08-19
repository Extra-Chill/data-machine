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
use DataMachine\Engine\AI\DataMachineAgentConsentPolicy;
use DataMachine\Engine\AI\Memory\MemorySectionPendingAction;
use DataMachine\Engine\AI\Memory\SelfMemoryWritePolicy;

defined( 'ABSPATH' ) || exit;

class AgentMemoryAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		MemorySectionPendingAction::register();
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
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ),
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
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ),
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
				'datamachine/write-self-memory',
				array(
					'label'               => 'Write Self Memory',
					'description'         => 'Policy-constrained write to the current agent\'s operational memory. Cross-agent writes and durable facts are denied by default.',
					'category'            => 'datamachine-memory',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id'          => array(
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ),
								'description' => 'Optional target agent. Defaults to the current acting agent; other agents require explicit delegation.',
							),
							'file'              => array(
								'type'        => 'string',
								'description' => 'Target memory file. Defaults to MEMORY.md.',
								'default'     => 'MEMORY.md',
							),
							'section'           => array(
								'type'        => 'string',
								'description' => 'Section name to create or update.',
							),
							'section_type'      => array(
								'type'        => 'string',
								'description' => 'Operational section type, such as operating_note, source_quirk, run_lesson, or task_note.',
								'default'     => 'operating_note',
							),
							'content'           => array(
								'type'        => 'string',
								'description' => 'Operational memory content to write.',
							),
							'mode'              => array(
								'type'        => 'string',
								'enum'        => array( 'set', 'append' ),
								'description' => 'Write mode. Defaults to append.',
								'default'     => 'append',
							),
							'reason'            => array(
								'type'        => 'string',
								'description' => 'Why this operational note should be recorded.',
							),
							'requires_approval' => array(
								'type'        => 'boolean',
								'description' => 'Force PendingAction preview instead of direct write.',
								'default'     => false,
							),
						),
						'required'   => array( 'section', 'content' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
							'error_code' => array( 'type' => 'string' ),
							'staged'     => array( 'type' => 'boolean' ),
							'action_id'  => array( 'type' => 'string' ),
							'preview'    => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'writeSelfMemory' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
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
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ),
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
								'anyOf'       => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Read agent file — full file or a specific section.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function getMemory( array $input ): array|\WP_Error {
		$memory  = self::resolveMemory( $input );
		$section = $input['section'] ?? null;

		if ( null === $section || '' === $section ) {
			return self::memoryResult( $memory->get_all(), 'agent_memory_read_failed' );
		}

		return self::memoryResult( $memory->get_section( $section ), 'agent_memory_read_failed' );
	}

	/**
	 * Update agent file — set or append to a section.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function updateMemory( array $input ): array|\WP_Error {
		$consent_decision = DataMachineAgentConsentPolicy::get()->can_store_memory(
			array(
				'mode'               => 'ability',
				'interactive'        => true,
				'permission_granted' => PermissionHelper::can_manage(),
				'agent_id'           => (int) ( $input['agent_id'] ?? 0 ),
				'user_id'            => (int) ( $input['user_id'] ?? 0 ),
			)
		);
		if ( ! $consent_decision->is_allowed() ) {
			return new \WP_Error(
				'memory_consent_denied',
				'Memory write consent denied.',
				array(
					'status'           => 403,
					'consent_decision' => $consent_decision->to_array(),
					'agent_id'         => (int) ( $input['agent_id'] ?? 0 ),
					'user_id'          => (int) ( $input['user_id'] ?? 0 ),
				)
			);
		}

		$memory  = self::resolveMemory( $input );
		$section = $input['section'];
		$content = $input['content'];
		$mode    = $input['mode'];

		// Check editability for non-MEMORY.md files.
		$filename = $input['file'] ?? 'MEMORY.md';
		if ( 'MEMORY.md' !== $filename ) {
			$editable = \DataMachine\Engine\AI\MemoryFileRegistry::is_editable( $filename );
			if ( ! $editable ) {
				$edit_cap = \DataMachine\Engine\AI\MemoryFileRegistry::get_edit_capability( $filename );
				if ( is_string( $edit_cap ) ) {
					return new \WP_Error(
						'memory_file_not_editable',
						sprintf(
							'File %s requires capability \'%s\' to edit. Pass --user=<admin-id> or run as an authenticated admin.',
							$filename,
							$edit_cap
						),
						array(
							'status'              => 403,
							'file'                => $filename,
							'required_capability' => $edit_cap,
						)
					);
				}
				return new \WP_Error(
					'memory_file_read_only',
					sprintf( 'File %s is read-only and cannot be edited via section write.', $filename ),
					array(
						'status' => 403,
						'file'   => $filename,
					)
				);
			}
		}

		if ( 'append' === $mode ) {
			return self::memoryResult( $memory->append_to_section( $section, $content ), 'agent_memory_write_failed' );
		}

		return self::memoryResult( $memory->set_section( $section, $content ), 'agent_memory_write_failed' );
	}

	/**
	 * Delete an agent file section.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function deleteMemorySection( array $input ): array|\WP_Error {
		$consent_decision = DataMachineAgentConsentPolicy::get()->can_store_memory(
			array(
				'mode'               => 'ability',
				'interactive'        => true,
				'permission_granted' => PermissionHelper::can_manage(),
				'agent_id'           => (int) ( $input['agent_id'] ?? 0 ),
				'user_id'            => (int) ( $input['user_id'] ?? 0 ),
			)
		);
		if ( ! $consent_decision->is_allowed() ) {
			return new \WP_Error(
				'memory_consent_denied',
				'Memory delete consent denied.',
				array(
					'status'           => 403,
					'consent_decision' => $consent_decision->to_array(),
					'agent_id'         => (int) ( $input['agent_id'] ?? 0 ),
					'user_id'          => (int) ( $input['user_id'] ?? 0 ),
				)
			);
		}

		$filename = $input['file'] ?? 'MEMORY.md';
		if ( 'MEMORY.md' !== $filename && ! \DataMachine\Engine\AI\MemoryFileRegistry::is_editable( $filename ) ) {
			return new \WP_Error(
				'memory_file_read_only',
				sprintf( 'File %s is read-only and cannot be edited via section delete.', $filename ),
				array(
					'status' => 403,
					'file'   => $filename,
				)
			);
		}

		$memory = self::resolveMemory( $input );
		return self::memoryResult( $memory->delete_section( (string) $input['section'] ), 'agent_memory_delete_failed' );
	}

	/**
	 * Policy-constrained write to the current agent's own operational memory.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function writeSelfMemory( array $input ): array|\WP_Error {
		$result = SelfMemoryWritePolicy::execute( $input );
		if ( ! empty( $result['success'] ) ) {
			return $result;
		}

		$code   = sanitize_key( (string) ( $result['error_code'] ?? 'self_memory_write_failed' ) );
		$status = 'missing_required_input' === $code ? 400 : 403;
		if ( ! str_ends_with( $code, '_denied' ) && ! in_array( $code, array( 'missing_agent_context', 'cross_agent_denied', 'missing_required_input' ), true ) ) {
			$status = 500;
		}

		return new \WP_Error(
			$code,
			(string) ( $result['error'] ?? $result['message'] ?? 'Self-memory write failed.' ),
			array_merge( $result, array( 'status' => $status ) )
		);
	}

	/**
	 * Search agent file content.
	 *
	 * @param array $input Input parameters with 'query' and optional 'section'.
	 * @return array Search results.
	 */
	public static function searchMemory( array $input ): array|\WP_Error {
		$memory  = self::resolveMemory( $input );
		$query   = $input['query'];
		$section = $input['section'] ?? null;

		return self::memoryResult( $memory->search( $query, $section ), 'agent_memory_search_failed' );
	}

	/**
	 * List all section headers in an agent file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function listSections( array $input ): array|\WP_Error {
		$memory = self::resolveMemory( $input );
		return self::memoryResult( $memory->get_sections(), 'agent_memory_list_sections_failed' );
	}

	/** Convert the legacy memory service result at the registered callback boundary. */
	private static function memoryResult( array $result, string $code ): array|\WP_Error {
		if ( ! empty( $result['success'] ) ) {
			return $result;
		}

		$message = (string) ( $result['message'] ?? 'Agent memory operation failed.' );
		$status  = str_contains( strtolower( $message ), 'not found' ) || str_contains( strtolower( $message ), 'does not exist' ) ? 404 : 500;

		return new \WP_Error( $code, $message, array_merge( $result, array( 'status' => $status ) ) );
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
