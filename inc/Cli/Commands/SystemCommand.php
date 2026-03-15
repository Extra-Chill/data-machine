<?php
/**
 * WP-CLI System Command
 *
 * Wraps SystemAbilities for health checks and session title generation.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\SystemAbilities;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

/**
 * System health checks and diagnostics.
 *
 * @since 0.41.0
 */
class SystemCommand extends BaseCommand {

	/**
	 * Run system health checks.
	 *
	 * ## OPTIONS
	 *
	 * [--types=<types>]
	 * : Comma-separated check types to run. Use "all" for all default checks.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system health
	 *     wp datamachine system health --types=system
	 *     wp datamachine system health --format=json
	 *
	 * @subcommand health
	 */
	public function health( array $args, array $assoc_args ): void {
		$types_raw = $assoc_args['types'] ?? 'all';
		$format    = $assoc_args['format'] ?? 'table';
		$types     = array_map( 'trim', explode( ',', $types_raw ) );

		$ability = new SystemAbilities();
		$result  = $ability->executeHealthCheck( array( 'types' => $types ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Health check failed.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table output.
		WP_CLI::log( $result['summary'] );
		WP_CLI::log( '' );

		foreach ( $result['results'] as $type_id => $data ) {
			WP_CLI::log( sprintf( '--- %s ---', $data['label'] ) );

			$check_result = $data['result'] ?? array();

			if ( isset( $check_result['version'] ) ) {
				WP_CLI::log( sprintf( '  Plugin version: %s', $check_result['version'] ) );
			}
			if ( isset( $check_result['php_version'] ) ) {
				WP_CLI::log( sprintf( '  PHP version:    %s', $check_result['php_version'] ) );
			}
			if ( isset( $check_result['wp_version'] ) ) {
				WP_CLI::log( sprintf( '  WP version:     %s', $check_result['wp_version'] ) );
			}
			if ( isset( $check_result['abilities'] ) ) {
				WP_CLI::log( sprintf( '  Abilities:      %d registered', count( $check_result['abilities'] ) ) );
			}
			if ( isset( $check_result['rest_status'] ) ) {
				$rest_ok = $check_result['rest_status']['namespace_registered'] ?? false;
				WP_CLI::log( sprintf( '  REST API:       %s', $rest_ok ? 'registered' : 'NOT registered' ) );
			}

			WP_CLI::log( '' );
		}

		WP_CLI::log( sprintf( 'Available check types: %s', implode( ', ', $result['available'] ) ) );
	}

	/**
	 * Generate a title for a chat session.
	 *
	 * ## OPTIONS
	 *
	 * <session_id>
	 * : UUID of the chat session.
	 *
	 * [--force]
	 * : Force regeneration even if title already exists.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system title abc123-def456
	 *     wp datamachine system title abc123-def456 --force
	 *
	 * @subcommand title
	 */
	public function title( array $args, array $assoc_args ): void {
		$session_id = $args[0] ?? '';
		$force      = isset( $assoc_args['force'] );
		$format     = $assoc_args['format'] ?? 'table';

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'Session ID is required.' );
			return;
		}

		$result = SystemAbilities::generateSessionTitle(
			array(
				'session_id' => $session_id,
				'force'      => $force,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? $result['message'] ?? 'Title generation failed.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Title:  %s', $result['title'] ) );
		WP_CLI::log( sprintf( 'Method: %s', $result['method'] ) );
	}

	/**
	 * Run a system task immediately.
	 *
	 * Triggers a registered system task for immediate execution via the
	 * datamachine/run-task ability. Only tasks with supports_run: true
	 * can be triggered (currently alt_text_generation and daily_memory_generation).
	 *
	 * ## OPTIONS
	 *
	 * <task_type>
	 * : The task type to run (e.g. alt_text_generation, daily_memory_generation).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system run daily_memory_generation
	 *     wp datamachine system run alt_text_generation --format=json
	 *
	 * @subcommand run
	 */
	public function run( array $args, array $assoc_args ): void {
		$task_type = $args[0] ?? '';
		$format    = $assoc_args['format'] ?? 'table';

		if ( empty( $task_type ) ) {
			WP_CLI::error( 'Task type is required. Use: wp datamachine system run <task_type>' );
			return;
		}

		$result = SystemAbilities::runTask( array( 'task_type' => $task_type ) );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? $result['message'] ?? 'Failed to run task.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * List all editable system task prompts.
	 *
	 * Shows each task's editable prompts with their labels and override status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system prompts
	 *     wp datamachine system prompts --format=json
	 *
	 * @subcommand prompts
	 */
	public function prompts( array $args, array $assoc_args ): void {
		$format       = $assoc_args['format'] ?? 'table';
		$handlers  = TaskRegistry::getHandlers();
		$overrides = SystemTask::getAllPromptOverrides();

		$rows = array();

		foreach ( $handlers as $task_type => $handler_class ) {
			if ( ! class_exists( $handler_class ) ) {
				continue;
			}

			$task        = new $handler_class();
			$definitions = $task->getPromptDefinitions();

			if ( empty( $definitions ) ) {
				continue;
			}

			foreach ( $definitions as $prompt_key => $definition ) {
				$has_override = isset( $overrides[ $task_type ][ $prompt_key ] )
					&& '' !== $overrides[ $task_type ][ $prompt_key ];

				$rows[] = array(
					'task_type'    => $task_type,
					'prompt_key'   => $prompt_key,
					'label'        => $definition['label'],
					'has_override' => $has_override ? 'yes' : 'no',
					'variables'    => implode( ', ', array_keys( $definition['variables'] ) ),
				);
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No editable prompts found.' );
			return;
		}

		$this->format_items( $rows, array( 'task_type', 'prompt_key', 'label', 'has_override', 'variables' ), $assoc_args );
	}

	/**
	 * Get the current (effective) prompt for a task.
	 *
	 * Shows the override if set, otherwise the default template.
	 *
	 * ## OPTIONS
	 *
	 * <task_type>
	 * : Task type identifier (e.g. alt_text_generation).
	 *
	 * <prompt_key>
	 * : Prompt key within the task (e.g. generate).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system prompt-get alt_text_generation generate
	 *     wp datamachine system prompt-get daily_memory_generation memory_cleanup --format=json
	 *
	 * @subcommand prompt-get
	 */
	public function prompt_get( array $args, array $assoc_args ): void {
		$task_type  = $args[0] ?? '';
		$prompt_key = $args[1] ?? '';
		$format     = $assoc_args['format'] ?? 'table';

		if ( empty( $task_type ) || empty( $prompt_key ) ) {
			WP_CLI::error( 'Both task_type and prompt_key are required.' );
			return;
		}

		$resolved = $this->resolve_task_prompt( $task_type, $prompt_key );

		if ( null === $resolved ) {
			return; // Error already logged.
		}

		list( $definition, $task ) = $resolved;

		$overrides    = SystemTask::getAllPromptOverrides();
		$has_override = isset( $overrides[ $task_type ][ $prompt_key ] )
			&& '' !== $overrides[ $task_type ][ $prompt_key ];
		$effective    = $has_override ? $overrides[ $task_type ][ $prompt_key ] : $definition['default'];

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array(
				'task_type'    => $task_type,
				'prompt_key'   => $prompt_key,
				'label'        => $definition['label'],
				'description'  => $definition['description'],
				'has_override' => $has_override,
				'variables'    => $definition['variables'],
				'effective'    => $effective,
				'default'      => $definition['default'],
				'override'     => $has_override ? $overrides[ $task_type ][ $prompt_key ] : null,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::log( sprintf( 'Task:        %s', $task_type ) );
		WP_CLI::log( sprintf( 'Prompt:      %s', $prompt_key ) );
		WP_CLI::log( sprintf( 'Label:       %s', $definition['label'] ) );
		WP_CLI::log( sprintf( 'Description: %s', $definition['description'] ) );
		WP_CLI::log( sprintf( 'Override:    %s', $has_override ? 'yes' : 'no (using default)' ) );
		WP_CLI::log( sprintf( 'Variables:   %s', implode( ', ', array_map(
			function ( $k, $v ) {
				return "{{" . $k . "}} — " . $v;
			},
			array_keys( $definition['variables'] ),
			array_values( $definition['variables'] )
		) ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( '--- Effective Prompt ---' );
		WP_CLI::log( $effective );
	}

	/**
	 * Set a prompt override for a task.
	 *
	 * ## OPTIONS
	 *
	 * <task_type>
	 * : Task type identifier.
	 *
	 * <prompt_key>
	 * : Prompt key within the task.
	 *
	 * <prompt>
	 * : The override prompt text. Use {{variable}} placeholders.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system prompt-set alt_text_generation generate "Write a brief alt text. Context: {{context}}"
	 *
	 * @subcommand prompt-set
	 */
	public function prompt_set( array $args, array $assoc_args ): void {
		$task_type  = $args[0] ?? '';
		$prompt_key = $args[1] ?? '';
		$prompt     = $args[2] ?? '';

		if ( empty( $task_type ) || empty( $prompt_key ) || empty( $prompt ) ) {
			WP_CLI::error( 'task_type, prompt_key, and prompt text are all required.' );
			return;
		}

		$resolved = $this->resolve_task_prompt( $task_type, $prompt_key );

		if ( null === $resolved ) {
			return;
		}

		$saved = SystemTask::setPromptOverride( $task_type, $prompt_key, $prompt );

		if ( $saved ) {
			WP_CLI::success( sprintf( 'Prompt override set for %s/%s.', $task_type, $prompt_key ) );
		} else {
			WP_CLI::error( 'Failed to save prompt override.' );
		}
	}

	/**
	 * Reset a prompt override to default.
	 *
	 * ## OPTIONS
	 *
	 * <task_type>
	 * : Task type identifier.
	 *
	 * <prompt_key>
	 * : Prompt key within the task.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine system prompt-reset alt_text_generation generate
	 *
	 * @subcommand prompt-reset
	 */
	public function prompt_reset( array $args, array $assoc_args ): void {
		$task_type  = $args[0] ?? '';
		$prompt_key = $args[1] ?? '';

		if ( empty( $task_type ) || empty( $prompt_key ) ) {
			WP_CLI::error( 'Both task_type and prompt_key are required.' );
			return;
		}

		$resolved = $this->resolve_task_prompt( $task_type, $prompt_key );

		if ( null === $resolved ) {
			return;
		}

		$reset = SystemTask::setPromptOverride( $task_type, $prompt_key, '' );

		if ( $reset ) {
			WP_CLI::success( sprintf( 'Prompt override removed for %s/%s (using default).', $task_type, $prompt_key ) );
		} else {
			WP_CLI::error( 'Failed to reset prompt override.' );
		}
	}

	/**
	 * Resolve and validate a task prompt definition.
	 *
	 * @param string $task_type  Task type identifier.
	 * @param string $prompt_key Prompt key.
	 * @return array|null [definition, task] or null on error.
	 */
	private function resolve_task_prompt( string $task_type, string $prompt_key ): ?array {
		$handlers = TaskRegistry::getHandlers();

		if ( ! isset( $handlers[ $task_type ] ) ) {
			WP_CLI::error( sprintf( 'Unknown task type: %s', $task_type ) );
			return null;
		}

		$handler_class = $handlers[ $task_type ];

		if ( ! class_exists( $handler_class ) ) {
			WP_CLI::error( sprintf( 'Task handler class not found: %s', $handler_class ) );
			return null;
		}

		$task        = new $handler_class();
		$definitions = $task->getPromptDefinitions();

		if ( ! isset( $definitions[ $prompt_key ] ) ) {
			$available = ! empty( $definitions ) ? implode( ', ', array_keys( $definitions ) ) : 'none';
			WP_CLI::error( sprintf(
				'Unknown prompt key "%s" for task "%s". Available: %s',
				$prompt_key,
				$task_type,
				$available
			) );
			return null;
		}

		return array( $definitions[ $prompt_key ], $task );
	}
}
