<?php
/**
 * Agent Daily Memory AI Tool - Session-specific daily journal entries
 *
 * Delegates to DailyMemoryAbilities for read/write/search operations
 * on daily memory files (YYYY/MM/DD.md). Gives agents the ability to
 * record session activity, search past daily entries, and manage the
 * boundary between persistent memory (MEMORY.md) and temporal daily logs.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since   0.33.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Core\FilesRepository\DirectoryManager;

class AgentDailyMemory extends BaseTool {

	public function __construct() {
		$this->registerTool( 'agent_daily_memory', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline', 'standalone' ) );
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
			'read'   => $this->handleRead( $parameters ),
			'write'  => $this->handleWrite( $parameters ),
			'list'   => $this->handleList( $parameters ),
			'search' => $this->handleSearch( $parameters ),
			default  => $this->buildErrorResponse(
				'Invalid action "' . $action . '". Use "read", "write", "list", or "search".',
				'agent_daily_memory'
			),
		};
	}

	/**
	 * Read a daily memory file.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array Response.
	 */
	private function handleRead( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/daily-memory-read' );
		$user_id = $this->resolve_user_id( $parameters );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Daily Memory ability not registered. Ensure WordPress 6.9+ and DailyMemoryAbilities is loaded.',
				'agent_daily_memory'
			);
		}

		$result = $ability->execute(
			array(
				'user_id' => $user_id,
				'date'    => $parameters['date'] ?? gmdate( 'Y-m-d' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_daily_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to read daily memory.' ),
				'agent_daily_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_daily_memory',
		);
	}

	/**
	 * Write or append to a daily memory file.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array Response.
	 */
	private function handleWrite( array $parameters ): array {
		$content = $parameters['content'] ?? '';
		$user_id = $this->resolve_user_id( $parameters );

		if ( '' === $content ) {
			return $this->buildErrorResponse(
				'Parameter "content" is required for write action.',
				'agent_daily_memory'
			);
		}

		$ability = wp_get_ability( 'datamachine/daily-memory-write' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Daily Memory ability not registered. Ensure WordPress 6.9+ and DailyMemoryAbilities is loaded.',
				'agent_daily_memory'
			);
		}

		$result = $ability->execute(
			array(
				'user_id' => $user_id,
				'content' => $content,
				'date'    => $parameters['date'] ?? gmdate( 'Y-m-d' ),
				'mode'    => $parameters['mode'] ?? 'append',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_daily_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to write daily memory.' ),
				'agent_daily_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_daily_memory',
		);
	}

	/**
	 * List all daily memory files grouped by month.
	 *
	 * @return array Response.
	 */
	private function handleList( array $parameters = array() ): array {
		$ability = wp_get_ability( 'datamachine/daily-memory-list' );
		$user_id = $this->resolve_user_id( $parameters );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Daily Memory ability not registered. Ensure WordPress 6.9+ and DailyMemoryAbilities is loaded.',
				'agent_daily_memory'
			);
		}

		$result = $ability->execute( array( 'user_id' => $user_id ) );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_daily_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to list daily memory files.' ),
				'agent_daily_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_daily_memory',
		);
	}

	/**
	 * Search across daily memory files.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array Response.
	 */
	private function handleSearch( array $parameters ): array {
		$query   = $parameters['query'] ?? '';
		$user_id = $this->resolve_user_id( $parameters );

		if ( '' === $query ) {
			return $this->buildErrorResponse(
				'Parameter "query" is required for search action.',
				'agent_daily_memory'
			);
		}

		$ability = wp_get_ability( 'datamachine/search-daily-memory' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Daily Memory search ability not registered. Ensure WordPress 6.9+ and DailyMemoryAbilities is loaded.',
				'agent_daily_memory'
			);
		}

		$result = $ability->execute(
			array(
				'user_id' => $user_id,
				'query'   => $query,
				'from'    => $parameters['from'] ?? null,
				'to'      => $parameters['to'] ?? null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'agent_daily_memory' );
		}

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse(
				$this->getAbilityError( $result, 'Failed to search daily memory.' ),
				'agent_daily_memory'
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'agent_daily_memory',
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
			'description'     => 'Manage daily memory journal entries (daily/YYYY/MM/DD.md). Use for session activity, temporal events, and work logs. Use "write" to record today\'s session notes (defaults to append mode). Use "read" to review a specific day. Use "search" to find past entries by keyword. Use "list" to see which days have entries. Daily memory captures WHAT HAPPENED — persistent knowledge belongs in agent_memory (MEMORY.md) instead.',
			'requires_config' => false,
			'parameters'      => array(
				'user_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Optional WordPress user ID for layered memory context. Defaults to current user context.',
				),
				'action'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "read" (get daily file), "write" (add to daily file), "list" (show all daily files), or "search" (find entries by keyword).',
				),
				'date'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Date in YYYY-MM-DD format. Defaults to today. Used by "read" and "write".',
				),
				'content' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Content to write. Required for "write" action. Use markdown format with ### headings for session sections.',
				),
				'mode'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Write mode: "append" adds to end of file (default), "write" replaces the entire file. Prefer "append" to preserve earlier entries from the same day.',
				),
				'query'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Search term for "search" action. Case-insensitive substring match across all daily files.',
				),
				'from'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Start date (YYYY-MM-DD) for "search" action. Omit for no lower bound.',
				),
				'to'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'End date (YYYY-MM-DD) for "search" action. Omit for no upper bound.',
				),
			),
		);
	}

	/**
	 * Resolve scoped user ID from tool parameters.
	 *
	 * @param array $parameters Tool parameters.
	 * @return int
	 */
	private function resolve_user_id( array $parameters ): int {
		$directory_manager = new DirectoryManager();
		$raw_user_id       = (int) ( $parameters['user_id'] ?? 0 );

		if ( $raw_user_id > 0 ) {
			return $directory_manager->get_effective_user_id( $raw_user_id );
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			return $directory_manager->get_effective_user_id( $current_user_id );
		}

		return $directory_manager->get_effective_user_id( 0 );
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
		if ( 'agent_daily_memory' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
