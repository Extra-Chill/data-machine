<?php
/**
 * Agent Memory AI Tool - Persistent memory management for AI agents
 *
 * Delegates to AgentMemoryAbilities for section-level read/write operations
 * on the agent's MEMORY.md file. Provides persistent knowledge storage
 * across sessions for all agent types.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since   0.30.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class AgentMemory extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'agent_memory', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Handle tool call by routing to the appropriate ability.
	 *
	 * @param array $parameters Tool parameters from AI.
	 * @param array $tool_def   Tool definition context.
	 * @return array Response array.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		return match ( $action ) {
			'get'           => $this->handleGet( $parameters ),
			'update'        => $this->handleUpdate( $parameters ),
			'list_sections' => $this->handleListSections(),
			default         => $this->buildErrorResponse(
				'Invalid action "' . $action . '". Use "get", "update", or "list_sections".',
				'agent_memory'
			),
		};
	}

	/**
	 * Read full memory or a specific section.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array Response.
	 */
	private function handleGet( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/get-agent-memory' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Agent Memory ability not registered. Ensure WordPress 6.9+ and AgentMemoryAbilities is loaded.',
				'agent_memory'
			);
		}

		$result = $ability->execute(
			array(
				'section' => $parameters['section'] ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to read agent memory.' ),
				'agent_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_memory',
		);
	}

	/**
	 * Write to a section — set (replace) or append.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array Response.
	 */
	private function handleUpdate( array $parameters ): array {
		$section = $parameters['section'] ?? '';
		$content = $parameters['content'] ?? '';
		$mode    = $parameters['mode'] ?? 'set';

		if ( '' === $section ) {
			return $this->buildErrorResponse(
				'Parameter "section" is required for update action.',
				'agent_memory'
			);
		}

		if ( '' === $content ) {
			return $this->buildErrorResponse(
				'Parameter "content" is required for update action.',
				'agent_memory'
			);
		}

		$ability = wp_get_ability( 'datamachine/update-agent-memory' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Agent Memory ability not registered. Ensure WordPress 6.9+ and AgentMemoryAbilities is loaded.',
				'agent_memory'
			);
		}

		$result = $ability->execute(
			array(
				'section' => $section,
				'content' => $content,
				'mode'    => $mode,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to update agent memory.' ),
				'agent_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_memory',
		);
	}

	/**
	 * List all section headers.
	 *
	 * @return array Response.
	 */
	private function handleListSections(): array {
		$ability = wp_get_ability( 'datamachine/list-agent-memory-sections' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Agent Memory ability not registered. Ensure WordPress 6.9+ and AgentMemoryAbilities is loaded.',
				'agent_memory'
			);
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to list memory sections.' ),
				'agent_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_memory',
		);
	}

	/**
	 * Tool definition for AI agent discovery.
	 *
	 * @return array Tool schema.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Manage persistent agent memory (MEMORY.md) — long-lived knowledge that survives across sessions. Stored as markdown sections (## headers). Use "list_sections" to see what exists, "get" to read content, and "update" to write. Use "append" mode to add new information without losing existing content. Use "set" mode to replace a section entirely. For session activity and temporal events, use agent_daily_memory instead.',
			'requires_config' => false,
			'parameters'      => array(
				'action'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "get" (read memory), "update" (write to section), or "list_sections" (show all section headers).',
				),
				'section' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Section name without "##" prefix. Required for "update". Optional for "get" (omit to read full memory).',
				),
				'content' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Content to write. Required for "update" action.',
				),
				'mode'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Write mode for "update": "set" replaces section content (default), "append" adds to end of section.',
				),
			),
		);
	}

	/**
	 * Always configured — no external dependencies.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return true;
	}

	/**
	 * Configuration check filter handler.
	 *
	 * @param bool   $configured Current configuration state.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'agent_memory' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
