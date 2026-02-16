<?php
/**
 * Base Tool
 *
 * Abstract base class for all AI tools (global and chat). Provides standardized
 * error handling and tool registration through inheritance.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.14.10
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

abstract class BaseTool {

	/**
	 * Whether this tool supports async execution.
	 *
	 * @var bool
	 */
	protected bool $async = false;

	/**
	 * Register a tool for any agent type.
	 *
	 * Agent-agnostic tool registration that dynamically creates the appropriate filter
	 * based on the agent type. Enables unlimited agent specialization while maintaining
	 * consistent registration patterns.
	 *
	 * IMPORTANT: Pass a callable (e.g., [$this, 'getToolDefinition']) instead of
	 * calling the method directly. This enables lazy evaluation after translations
	 * are loaded, preventing WordPress 6.7+ translation timing errors.
	 *
	 * @param string         $agentType Agent type (global, chat, frontend, supportbot, etc.)
	 * @param string         $toolName Tool identifier
	 * @param array|callable $toolDefinition Tool definition array OR callable that returns it
	 */
	protected function registerTool( string $agentType, string $toolName, array|callable $toolDefinition ): void {
		$filterName = "datamachine_{$agentType}_tools";
		add_filter(
			$filterName,
			function ( $tools ) use ( $toolName, $toolDefinition ) {
				$tools[ $toolName ] = $toolDefinition;
				return $tools;
			}
		);
	}

	/**
	 * Register a global tool available to all AI agents.
	 *
	 * @param string         $tool_name Tool identifier
	 * @param array|callable $tool_definition Tool definition array OR callable
	 */
	protected function registerGlobalTool( string $tool_name, array|callable $tool_definition ): void {
		$this->registerTool( 'global', $tool_name, $tool_definition );
	}

	/**
	 * Register a chat-specific tool.
	 *
	 * @param string         $tool_name Tool identifier
	 * @param array|callable $tool_definition Tool definition array OR callable
	 */
	protected function registerChatTool( string $tool_name, array|callable $tool_definition ): void {
		$this->registerTool( 'chat', $tool_name, $tool_definition );
	}

	/**
	 * Register configuration management handlers for tools that need them.
	 *
	 * @param string $tool_id Tool identifier for configuration
	 */
	protected function registerConfigurationHandlers( string $tool_id ): void {
		add_filter( 'datamachine_tool_configured', array( $this, 'check_configuration' ), 10, 2 );
		add_filter( 'datamachine_get_tool_config', array( $this, 'get_configuration' ), 10, 2 );
		add_filter( 'datamachine_get_tool_config_fields', array( $this, 'get_config_fields' ), 10, 2 );
		add_action( 'datamachine_save_tool_config', array( $this, 'save_configuration' ), 10, 2 );
	}

	/**
	 * Check if ability result indicates success.
	 *
	 * Handles WP_Error, non-array results, and missing success key.
	 *
	 * @param mixed $result Ability execution result.
	 * @return bool
	 */
	protected function isAbilitySuccess( $result ): bool {
		if ( is_wp_error( $result ) ) {
			return false;
		}
		if ( ! is_array( $result ) ) {
			return false;
		}
		return $result['success'] ?? false;
	}

	/**
	 * Extract error message from ability result.
	 *
	 * @param mixed  $result   Ability execution result.
	 * @param string $fallback Fallback error message.
	 * @return string
	 */
	protected function getAbilityError( $result, string $fallback ): string {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		if ( is_array( $result ) && isset( $result['error'] ) ) {
			return $result['error'];
		}
		return $fallback;
	}

	/**
	 * Classify error type for AI agent guidance.
	 *
	 * Error types tell the AI whether to retry:
	 * - not_found: Resource doesn't exist, do not retry
	 * - validation: Fix parameters and retry
	 * - permission: Access denied, do not retry
	 * - system: May retry once if error suggests fixable cause
	 *
	 * @param string $error Error message.
	 * @return string Error type classification.
	 */
	protected function classifyErrorType( string $error ): string {
		$lower = strtolower( $error );

		if ( strpos( $lower, 'not found' ) !== false ) {
			return 'not_found';
		}
		if ( strpos( $lower, 'does not exist' ) !== false ) {
			return 'not_found';
		}
		if ( strpos( $lower, 'required' ) !== false ) {
			return 'validation';
		}
		if ( strpos( $lower, 'invalid' ) !== false ) {
			return 'validation';
		}
		if ( strpos( $lower, 'permission' ) !== false ) {
			return 'permission';
		}
		if ( strpos( $lower, 'denied' ) !== false ) {
			return 'permission';
		}
		if ( strpos( $lower, 'unauthorized' ) !== false ) {
			return 'permission';
		}

		return 'system';
	}

	/**
	 * Build standardized error response with classification.
	 *
	 * @param string $error     Error message.
	 * @param string $tool_name Tool name for response.
	 * @return array
	 */
	protected function buildErrorResponse( string $error, string $tool_name ): array {
		return array(
			'success'    => false,
			'error'      => $error,
			'error_type' => $this->classifyErrorType( $error ),
			'tool_name'  => $tool_name,
		);
	}

	/**
	 * Build error response with diagnostic context and remediation hints.
	 *
	 * Provides AI agents with actionable information to self-correct:
	 * - diagnostic: Current state (what exists, IDs involved)
	 * - remediation: Suggested next action with tool hints
	 *
	 * @param string $error       Error message.
	 * @param string $error_type  Error type (prerequisite_missing, not_found, validation, etc.).
	 * @param string $tool_name   Tool name for response.
	 * @param array  $diagnostic  Current state information.
	 * @param array  $remediation Suggested fix with action, message, and tool_hint.
	 * @return array
	 */
	protected function buildDiagnosticErrorResponse(
		string $error,
		string $error_type,
		string $tool_name,
		array $diagnostic = array(),
		array $remediation = array()
	): array {
		$response = array(
			'success'    => false,
			'error'      => $error,
			'error_type' => $error_type,
			'tool_name'  => $tool_name,
		);

		if ( ! empty( $diagnostic ) ) {
			$response['diagnostic'] = $diagnostic;
		}

		if ( ! empty( $remediation ) ) {
			$response['remediation'] = $remediation;
		}

		return $response;
	}

	/**
	 * Build pending response for async tasks.
	 *
	 * Schedules a task with the System Agent and returns a pending response
	 * that indicates the task is being processed in the background.
	 *
	 * @param string $taskType   Task type identifier for System Agent.
	 * @param array  $taskParams Task parameters to pass to the handler.
	 * @param array  $context    Context for routing results back.
	 * @param string $toolName   Tool name for the response.
	 * @return array Pending response array.
	 */
	protected function buildPendingResponse( string $taskType, array $taskParams, array $context = [], string $toolName = '' ): array {
		$systemAgent = \DataMachine\Engine\AI\System\SystemAgent::getInstance();
		$jobId = $systemAgent->scheduleTask( $taskType, $taskParams, $context );

		if ( ! $jobId ) {
			return $this->buildErrorResponse( 'Failed to schedule async task.', $toolName );
		}

		return [
			'success'   => true,
			'pending'   => true,
			'job_id'    => $jobId,
			'task_type' => $taskType,
			'message'   => "Task scheduled for background processing (Job #{$jobId}).",
			'tool_name' => $toolName,
		];
	}
}
