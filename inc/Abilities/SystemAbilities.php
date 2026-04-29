<?php
/**
 * System Abilities
 *
 * WordPress 6.9 Abilities API primitives for system infrastructure operations.
 * Handles session title generation and other system-level tasks.
 *
 * @package DataMachine\Abilities
 * @since   0.13.7
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Engine\AI\AgentMessageEnvelope;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachine\Engine\Tasks\TaskRegistry;

defined('ABSPATH') || exit;

class SystemAbilities {


	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerSessionTitleAbility();
			$this->registerHealthCheckAbility();
			// registerGitHubIssueAbility moved to data-machine-code extension.
			$this->registerRunTaskAbility();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerSessionTitleAbility(): void {
		wp_register_ability(
			'datamachine/generate-session-title',
			array(
				'label'               => 'Generate Session Title',
				'description'         => 'Generate an AI-powered title for a chat session based on conversation content',
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array(
							'type'        => 'string',
							'description' => 'UUID of the chat session to generate title for',
						),
						'force'      => array(
							'type'        => 'boolean',
							'description' => 'Force regeneration even if title already exists',
							'default'     => false,
						),
					),
					'required'   => array( 'session_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'title'   => array( 'type' => 'string' ),
						'method'  => array(
							'type' => 'string',
							'enum' => array( 'ai', 'fallback', 'existing' ),
						),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'generateSessionTitle' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function registerHealthCheckAbility(): void {
		wp_register_ability(
			'datamachine/system-health-check',
			array(
				'label'               => __( 'System Health Check', 'data-machine' ),
				'description'         => __( 'Unified health diagnostics for Data Machine and extensions', 'data-machine' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'types'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Check types to run. Use "all" for all default checks, or specific type IDs.',
						),
						'options' => array(
							'type'        => 'object',
							'description' => 'Type-specific options (scope, limit, url, etc.)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'results'   => array( 'type' => 'object' ),
						'summary'   => array( 'type' => 'string' ),
						'available' => array(
							'type'        => 'array',
							'description' => 'List of available check types',
						),
					),
				),
				'execute_callback'    => array( $this, 'executeHealthCheck' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Get registered health check providers.
	 *
	 * Extensions register via filter:
	 * add_filter( 'datamachine_system_health_checks', function( $checks ) {
	 *     $checks['events'] = array(
	 *         'label'    => 'Event Health',
	 *         'callback' => array( EventHealthAbilities::class, 'executeHealthCheck' ),
	 *         'default'  => true, // included in 'all'
	 *     );
	 *     return $checks;
	 * } );
	 *
	 * @return array Registered health checks
	 */
	private function getRegisteredChecks(): array {
		$checks = array(
			'system' => array(
				'label'    => __( 'System Diagnostics', 'data-machine' ),
				'callback' => array( $this, 'runSystemDiagnostics' ),
				'default'  => true,
			),
		);

		return apply_filters( 'datamachine_system_health_checks', $checks );
	}

	/**
	 * Execute unified health check.
	 *
	 * @param array $input Input with optional 'types' and 'options'
	 * @return array Health check results
	 */
	public function executeHealthCheck( array $input ): array {
		$requested_types = $input['types'] ?? array( 'all' );
		$options         = $input['options'] ?? array();
		$checks          = $this->getRegisteredChecks();
		$results         = array();

		if ( empty( $requested_types ) ) {
			$requested_types = array( 'all' );
		}

		$run_all = in_array( 'all', $requested_types, true );

		foreach ( $checks as $type_id => $check ) {
			$should_run = $run_all
				? ( $check['default'] ?? true )
				: in_array( $type_id, $requested_types, true );

			if ( ! $should_run ) {
				continue;
			}

			$check_options       = $options[ $type_id ] ?? $options;
			$results[ $type_id ] = array(
				'label'  => $check['label'],
				'result' => call_user_func( $check['callback'], $check_options ),
			);
		}

		return array(
			'success'   => true,
			'results'   => $results,
			'summary'   => $this->buildSummary( $results ),
			'available' => array_keys( $checks ),
		);
	}

	/**
	 * Run core system diagnostics.
	 *
	 * @param array $options Optional check options
	 * @return array System diagnostic results
	 */
	private function runSystemDiagnostics( array $options = array() ): array {
		$options;
		return array(
			'version'     => defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'unknown',
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
			'abilities'   => $this->listRegisteredAbilities(),
			'rest_status' => $this->checkRestApi(),
		);
	}

	/**
	 * List all datamachine abilities.
	 *
	 * @return array List of ability IDs
	 */
	private function listRegisteredAbilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$all = wp_get_abilities();

		return array_values(
			array_filter(
				array_keys( $all ),
				fn( $id ) => str_starts_with( $id, 'datamachine' )
			)
		);
	}

	/**
	 * Check REST API status.
	 *
	 * @return array REST API status info
	 */
	private function checkRestApi(): array {
		$server     = rest_get_server();
		$namespaces = $server ? $server->get_namespaces() : array();

		return array(
			'namespace_registered' => in_array( 'datamachine/v1', $namespaces, true ),
		);
	}

	/**
	 * Build summary message from results.
	 *
	 * @param array $results Health check results
	 * @return string Summary message
	 */
	private function buildSummary( array $results ): string {
		$parts = array();

		foreach ( $results as $type_id => $data ) {
			$result = $data['result'] ?? array();

			if ( isset( $result['error'] ) ) {
				$parts[] = $data['label'] . ': error';
				continue;
			}

			if ( isset( $result['message'] ) ) {
				$parts[] = $data['label'] . ': ' . $result['message'];
			} else {
				$parts[] = $data['label'] . ': completed';
			}
		}

		return implode( '; ', $parts );
	}

	// registerGitHubIssueAbility() moved to data-machine-code extension.

	/**
	 * Register the run-task ability.
	 *
	 * Generic ability for manually triggering any registered system task
	 * that supports manual execution (supports_run: true in task meta).
	 *
	 * @since 0.42.0
	 */
	private function registerRunTaskAbility(): void {
		wp_register_ability(
			'datamachine/run-task',
			array(
				'label'               => __( 'Run System Task', 'data-machine' ),
				'description'         => __( 'Manually trigger a registered system task for immediate execution.', 'data-machine' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'task_type'   => array(
							'type'        => 'string',
							'description' => 'Registered task type identifier (e.g. alt_text_generation, daily_memory_generation).',
						),
						'task_params' => array(
							'type'        => 'object',
							'description' => 'Structured task parameters passed through to the scheduled SystemTask.',
						),
					),
					'required'   => array( 'task_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'task_type' => array( 'type' => 'string' ),
						'job_id'    => array( 'type' => array( 'integer', 'null' ) ),
						'message'   => array( 'type' => 'string' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'runTask' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute the run-task ability.
	 *
	 * @param array $input { task_type: string, task_params?: array }
	 * @return array Result with success, job_id, message.
	 * @since 0.42.0
	 */
	public static function runTask( array $input ): array {
		$task_type   = $input['task_type'] ?? '';
		$task_params = is_array( $input['task_params'] ?? null ) ? $input['task_params'] : array();

		if ( empty( $task_type ) ) {
			return array(
				'success' => false,
				'error'   => 'task_type is required.',
				'message' => 'Specify which task to run.',
			);
		}

		if ( ! TaskRegistry::isRegistered( $task_type ) ) {
			return array(
				'success' => false,
				'error'   => "Unknown task type: {$task_type}",
				'message' => 'Task type is not registered.',
			);
		}

		$registry = TaskRegistry::getRegistry();
		$meta     = $registry[ $task_type ] ?? array();

		if ( empty( $meta['supports_run'] ) ) {
			return array(
				'success' => false,
				'error'   => "Task '{$task_type}' does not support manual execution.",
				'message' => 'This task can only be triggered by its configured event or schedule.',
			);
		}

		$validation = self::validateRunTaskParams( $task_type, $meta, $task_params );
		if ( ! $validation['success'] ) {
			return array(
				'success' => false,
				'error'   => $validation['error'],
				'message' => $validation['message'],
			);
		}

		$task_params = $validation['task_params'];

		$job_id = TaskScheduler::schedule( $task_type, array_merge( $task_params, array(
			'source'       => 'admin_run_now',
			'triggered_by' => get_current_user_id(),
		) ) );

		if ( ! $job_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to schedule task.',
				'message' => 'TaskScheduler returned false — check logs for details.',
			);
		}

		$label = $meta['label'] ?? $task_type;

		return array(
			'success'   => true,
			'task_type' => $task_type,
			'job_id'    => $job_id,
			'message'   => "{$label} scheduled (Job #{$job_id}).",
		);
	}

	/**
	 * Validate manual run params against task-declared safety metadata.
	 *
	 * The schema is intentionally small: task authors may declare accepted,
	 * required, and scope param names without dragging JSON Schema into the
	 * SystemTask contract.
	 *
	 * @param string $task_type Task type identifier.
	 * @param array  $meta      Normalized task registry metadata.
	 * @param array  $params    Proposed task params.
	 * @return array{success: bool, task_params?: array, error?: string, message?: string}
	 */
	private static function validateRunTaskParams( string $task_type, array $meta, array $params ): array {
		$schema          = is_array( $meta['params_schema'] ?? null ) ? $meta['params_schema'] : array();
		$accepted_params = self::stringList( $schema['accepted'] ?? $schema['accepted_params'] ?? array() );
		$required_params = self::stringList( $schema['required'] ?? $schema['required_params'] ?? array() );
		$scope_params    = self::stringList( $schema['scope'] ?? $schema['scope_params'] ?? array() );

		$core_params = array( 'dry_run', 'apply', 'mode' );
		if ( ! empty( $accepted_params ) ) {
			$allowed = array_unique( array_merge( $accepted_params, $required_params, $scope_params, $core_params ) );
			$unknown = array_diff( array_keys( $params ), $allowed );
			if ( ! empty( $unknown ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' does not accept param(s): %s", $task_type, implode( ', ', $unknown ) ),
					'message' => 'Remove unsupported task params or update the task params_schema.',
				);
			}
		}

		$missing = array();
		foreach ( $required_params as $required_param ) {
			if ( ! self::hasNonEmptyParam( $params, $required_param ) ) {
				$missing[] = $required_param;
			}
		}
		if ( ! empty( $missing ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( "Task '%s' is missing required param(s): %s", $task_type, implode( ', ', $missing ) ),
				'message' => 'Provide the required task params and retry.',
			);
		}

		if ( ! empty( $meta['requires_scope'] ) ) {
			$scope_candidates = ! empty( $scope_params ) ? $scope_params : $required_params;
			if ( empty( $scope_candidates ) ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' declares requires_scope but no scope params_schema entries.", $task_type ),
					'message' => 'Declare params_schema.scope for tasks that require scope.',
				);
			}

			$has_scope = false;
			foreach ( $scope_candidates as $scope_param ) {
				if ( self::hasNonEmptyParam( $params, $scope_param ) ) {
					$has_scope = true;
					break;
				}
			}
			if ( ! $has_scope ) {
				return array(
					'success' => false,
					'error'   => sprintf( "Task '%s' requires an explicit scope param: %s", $task_type, implode( ', ', $scope_candidates ) ),
					'message' => 'Provide a scope param before scheduling this task.',
				);
			}
		}

		if ( ! empty( $meta['mutates'] ) && ! empty( $meta['supports_dry_run'] ) && empty( $params['apply'] ) && ! array_key_exists( 'dry_run', $params ) ) {
			$params['dry_run'] = true;
		}

		return array(
			'success'     => true,
			'task_params' => $params,
		);
	}

	/**
	 * Normalize a list of string param names.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	private static function stringList( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $value ), static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Whether a param exists with a non-empty value.
	 *
	 * @param array  $params Params array.
	 * @param string $key    Param key.
	 * @return bool
	 */
	private static function hasNonEmptyParam( array $params, string $key ): bool {
		return array_key_exists( $key, $params ) && null !== $params[ $key ] && '' !== $params[ $key ];
	}

	public static function generateSessionTitle( array $input ): array {
		$session_id = $input['session_id'];
		$force      = $input['force'] ?? false;

		$chat_db = ConversationStoreFactory::get();
		$session = $chat_db->get_session($session_id);

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => 'Session not found',
				'message' => 'Unable to find chat session',
			);
		}

		// Check if title already exists and we're not forcing regeneration
		if ( ! empty($session['title']) && ! $force ) {
			return array(
				'success' => true,
				'title'   => $session['title'],
				'method'  => 'existing',
				'message' => 'Title already exists',
			);
		}

		$messages = $session['messages'] ?? array();
		if ( empty($messages) ) {
			return array(
				'success' => false,
				'error'   => 'No messages found',
				'message' => 'Session has no conversation messages',
			);
		}

		// Extract first user message and first assistant response
		$first_user_message       = null;
		$first_assistant_response = null;

		foreach ( $messages as $msg ) {
			$msg     = AgentMessageEnvelope::normalize( $msg );
			$role    = $msg['role'] ?? '';
			$content = $msg['content'] ?? '';
			$content = is_string( $content ) ? $content : wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( 'user' === $role && null === $first_user_message && ! empty($content) ) {
				$first_user_message = $content;
			} elseif ( 'assistant' === $role && null === $first_assistant_response && ! empty($content) ) {
				$first_assistant_response = $content;
			}

			if ( null !== $first_user_message && null !== $first_assistant_response ) {
				break;
			}
		}

		if ( null === $first_user_message ) {
			return array(
				'success' => false,
				'error'   => 'No user message found',
				'message' => 'Session has no user messages to generate title from',
			);
		}

		// Check if AI titles are enabled
		$ai_titles_enabled = PluginSettings::get('chat_ai_titles_enabled', true);

		if ( ! $ai_titles_enabled ) {
			$title   = self::generateTruncatedTitle($first_user_message);
			$success = $chat_db->update_title($session_id, $title);

			return array(
				'success' => $success,
				'title'   => $title,
				'method'  => 'fallback',
				'message' => $success ? 'Title generated using fallback method' : 'Failed to update session title',
			);
		}

		// Try AI generation
		$title = self::generateAITitle($first_user_message, $first_assistant_response);

		if ( null === $title ) {
			$title  = self::generateTruncatedTitle($first_user_message);
			$method = 'fallback';
		} else {
			$method = 'ai';
		}

		$success = $chat_db->update_title($session_id, $title);

		if ( $success ) {
			do_action(
				'datamachine_log',
				'debug',
				'Session title generated',
				array(
					'session_id' => $session_id,
					'title'      => $title,
					'method'     => $method,
					'context'    => 'system',
				)
			);
		}

		return array(
			'success' => $success,
			'title'   => $title,
			'method'  => $method,
			'message' => $success ? 'Title generated successfully' : 'Failed to update session title',
		);
	}

	private static function generateAITitle( string $first_user_message, ?string $first_assistant_response ): ?string {
		$chat_defaults = PluginSettings::resolveModelForAgentMode( null, 'chat' );
		$provider      = $chat_defaults['provider'];
		$model         = $chat_defaults['model'];

		if ( empty($provider) || empty($model) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Session title AI generation skipped - no default provider/model configured',
				array( 'context' => 'system' )
			);
			return null;
		}

		$context = 'User: ' . mb_substr($first_user_message, 0, 500);
		if ( $first_assistant_response ) {
			$context .= "\n\nAssistant: " . mb_substr($first_assistant_response, 0, 500);
		}

		$default_prompt = "Generate a concise title (3-6 words) for this conversation. Return ONLY the title text, nothing else.\n\n" . $context;

		/**
		 * Filter the prompt used for AI session title generation.
		 *
		 * Allows plugins to customize or completely replace the title generation prompt.
		 * Return a different prompt to change title style (e.g., code names like 'azure-phoenix').
		 *
		 * @since 0.21.1
		 *
		 * @param string $prompt  The default prompt including conversation context.
		 * @param array  $context {
		 *     Context data for prompt customization.
		 *
		 *     @type string $first_user_message       The first user message (truncated to 500 chars).
		 *     @type string $first_assistant_response The first assistant response (truncated to 500 chars).
		 *     @type string $conversation_context     Formatted "User: ... Assistant: ..." context string.
		 * }
		 */
		$prompt = apply_filters(
			'datamachine_session_title_prompt',
			$default_prompt,
			array(
				'first_user_message'       => $first_user_message,
				'first_assistant_response' => $first_assistant_response,
				'conversation_context'     => $context,
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$request = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => 50,
		);

		try {
			$response = RequestBuilder::build(
				$messages,
				$provider,
				$model,
				array(), // No tools for title generation
				'system', // Agent type
				array() // No payload needed
			);

			if ( ! $response['success'] ) {
				do_action(
					'datamachine_log',
					'error',
					'Session title AI generation failed',
					array(
						'error'   => $response['error'] ?? 'Unknown error',
						'context' => 'system',
					)
				);
					return null;
			}

			$content = $response['data']['content'] ?? '';
			if ( empty($content) ) {
				return null;
			}

			// Clean up the response - remove quotes, trim, limit length
			$title = trim($content);
			$title = trim($title, '"\'');
			$title = mb_substr($title, 0, 100); // Max title length

			return $title;
		} catch ( \Exception $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Session title AI generation exception',
				array(
					'exception' => $e->getMessage(),
					'context'   => 'system',
				)
			);
			return null;
		}
	}

	private static function generateTruncatedTitle( string $first_message ): string {
		$title = trim($first_message);

		// Remove newlines and excessive whitespace
		$title = preg_replace('/\s+/', ' ', $title);

		// Truncate to max length
		if ( mb_strlen($title) > 97 ) { // Leave room for "..."
			$title = mb_substr($title, 0, 97) . '...';
		}

		return $title;
	}
}
