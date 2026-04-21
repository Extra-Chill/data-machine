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
	 * Register a tool with the unified tool registry.
	 *
	 * All tools register via the single `datamachine_tools` filter. Each tool
	 * must declare its modes — the agent modes where it's available (e.g.
	 * 'chat', 'pipeline').
	 *
	 * Tools should also declare which ability they wrap via the `ability` key
	 * (or `abilities` for composed tools). The ToolPolicyResolver uses this to
	 * check the ability's permission_callback before offering the tool to AI agents.
	 * Tools without an ability declaration default to admin-only access.
	 *
	 * When the definition is a callable (for lazy evaluation), the modes
	 * are stored alongside so they're available before the callable is resolved.
	 * The ToolManager merges modes into the resolved definition.
	 *
	 * IMPORTANT: Pass a callable (e.g., [$this, 'getToolDefinition']) instead of
	 * calling the method directly. This enables lazy evaluation after translations
	 * are loaded, preventing WordPress 6.7+ translation timing errors.
	 *
	 * @since 0.14.10
	 * @since 0.54.0 Added ability, abilities, and access_level metadata for permission-aware resolution.
	 *
	 * @param string         $toolName       Tool identifier.
	 * @param array|callable $toolDefinition Tool definition array OR callable that returns it.
	 * @param array          $modes          Agent modes where this tool is available (e.g. ['chat', 'pipeline']).
	 * @param array          $meta           Optional metadata for permission resolution. {
	 *     @type string   $ability      Single ability slug this tool wraps (e.g. 'datamachine/local-search').
	 *     @type string[] $abilities    Multiple ability slugs for composed tools. ALL must pass permission check.
	 *     @type string   $access_level Fallback for tools without a linked ability.
	 *                                  One of: 'public', 'authenticated', 'author', 'editor', 'admin'. Default: 'admin'.
	 * }
	 */
	protected function registerTool( string $toolName, array|callable $toolDefinition, array $modes = array(), array $meta = array() ): void {
		add_filter(
			'datamachine_tools',
			function ( $tools ) use ( $toolName, $toolDefinition, $modes, $meta ) {
				if ( is_callable( $toolDefinition ) ) {
					// Wrap callable with modes for pre-resolution filtering.
					$tools[ $toolName ] = array(
						'_callable' => $toolDefinition,
						'modes'     => $modes,
					);
				} else {
					// Array definition — merge modes directly.
					$toolDefinition['modes'] = $modes;
					$tools[ $toolName ]      = $toolDefinition;
				}

				// Merge permission metadata into the tool entry.
				if ( ! empty( $meta['ability'] ) ) {
					$tools[ $toolName ]['ability'] = $meta['ability'];
				}
				if ( ! empty( $meta['abilities'] ) ) {
					$tools[ $toolName ]['abilities'] = $meta['abilities'];
				}
				if ( ! empty( $meta['access_level'] ) ) {
					$tools[ $toolName ]['access_level'] = $meta['access_level'];
				}

				return $tools;
			}
		);
	}

	/**
	 * Tool identifier for configuration management.
	 *
	 * Set by registerConfigurationHandlers(). Used by save_configuration()
	 * to check if this tool should handle the save request.
	 *
	 * @var string
	 */
	protected string $config_tool_id = '';

	/**
	 * Register configuration management handlers for tools that need them.
	 *
	 * @param string $tool_id Tool identifier for configuration
	 */
	protected function registerConfigurationHandlers( string $tool_id ): void {
		$this->config_tool_id = $tool_id;
		add_filter( 'datamachine_tool_configured', array( $this, 'check_configuration' ), 10, 2 );
		add_filter( 'datamachine_get_tool_config', array( $this, 'get_configuration' ), 10, 2 );
		add_filter( 'datamachine_get_tool_config_fields', array( $this, 'get_config_fields' ), 10, 2 );
		add_filter( 'datamachine_save_tool_config', array( $this, 'save_configuration' ), 10, 3 );
	}

	/**
	 * Save tool configuration via the datamachine_save_tool_config filter.
	 *
	 * Handles the common pattern: check tool_id ownership, validate input,
	 * build config, save to option, run post-save hooks. Subclasses override
	 * validate_and_build_config() to define their specific validation and
	 * config shape.
	 *
	 * Tools that need fully custom save logic can override this method directly.
	 *
	 * @param array|null $result      Result from a previous handler, or null.
	 * @param string     $tool_id     Tool identifier.
	 * @param array      $config_data Sanitized configuration data.
	 * @return array|null Result array with success/error, or passthrough null.
	 */
	public function save_configuration( $result, $tool_id, $config_data ) {
		if ( $this->config_tool_id !== $tool_id ) {
			return $result;
		}

		$config_option = $this->get_config_option_name();
		if ( empty( $config_option ) ) {
			return $result;
		}

		$validated = $this->validate_and_build_config( $config_data );

		if ( isset( $validated['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $validated['error'],
			);
		}

		$this->before_config_save( $config_data );

		if ( update_site_option( $config_option, $validated['config'] ) ) {
			return array(
				'success' => true,
				'message' => $validated['message'] ?? __( 'Configuration saved successfully', 'data-machine' ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to save configuration', 'data-machine' ),
		);
	}

	/**
	 * Get the option name used to store this tool's configuration.
	 *
	 * Override in subclasses. Return empty string if the tool does not use
	 * the standard save_configuration() flow.
	 *
	 * @return string Option name, or empty string.
	 */
	protected function get_config_option_name(): string {
		return '';
	}

	/**
	 * Validate input and build the config array to save.
	 *
	 * Override in subclasses. Return an array with either:
	 * - 'config' key: the validated config array to pass to update_site_option()
	 * - 'message' key (optional): custom success message
	 * OR:
	 * - 'error' key: validation error message (save will be aborted)
	 *
	 * @param array $config_data Sanitized input from the ability.
	 * @return array{config: array, message?: string}|array{error: string}
	 */
	protected function validate_and_build_config( array $config_data ): array {
		return array( 'config' => $config_data );
	}

	/**
	 * Hook called before saving config to the option.
	 *
	 * Override in subclasses to clear transients, invalidate caches, etc.
	 *
	 * @param array $config_data The raw config data being saved.
	 */
	protected function before_config_save( array $config_data ): void {
		// No-op by default. Subclasses override as needed.
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
	 * Schedules a task with the TaskScheduler and returns a pending response
	 * that indicates the task is being processed in the background.
	 *
	 * @param string $taskType   Registered task type identifier.
	 * @param array  $taskParams Task parameters to pass to the handler.
	 * @param array  $context    Context for routing results back.
	 * @param string $toolName   Tool name for the response.
	 * @return array Pending response array.
	 */
	protected function buildPendingResponse( string $taskType, array $taskParams, array $context = array(), string $toolName = '' ): array {
		$jobId = \DataMachine\Engine\Tasks\TaskScheduler::schedule( $taskType, $taskParams, $context );

		if ( ! $jobId ) {
			return $this->buildErrorResponse( 'Failed to schedule async task.', $toolName );
		}

		return array(
			'success'   => true,
			'pending'   => true,
			'job_id'    => $jobId,
			'task_type' => $taskType,
			'message'   => "Task scheduled for background processing (Job #{$jobId}).",
			'tool_name' => $toolName,
		);
	}
}
