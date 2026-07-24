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
use DataMachine\Abilities\Engine\DrainJobAbility;
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
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
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
			if ( isset( $check_result['status'] ) ) {
				WP_CLI::log( sprintf( '  Status:         %s', $check_result['status'] ) );
			}
			if ( isset( $check_result['message'] ) ) {
				WP_CLI::log( sprintf( '  Message:        %s', $check_result['message'] ) );
			}
			if ( isset( $check_result['action_scheduler'] ) && is_array( $check_result['action_scheduler'] ) ) {
				$action_scheduler = $check_result['action_scheduler'];
				WP_CLI::log( sprintf( '  Pending:        %d', (int) ( $action_scheduler['pending_count'] ?? 0 ) ) );
				WP_CLI::log( sprintf( '  Due now:        %d', (int) ( $action_scheduler['due_count'] ?? 0 ) ) );
				if ( ! empty( $action_scheduler['oldest_due_gmt'] ) ) {
					WP_CLI::log( sprintf( '  Oldest due:     %s GMT', $action_scheduler['oldest_due_gmt'] ) );
				}
				if ( ! empty( $action_scheduler['last_attempt_gmt'] ) ) {
					WP_CLI::log( sprintf( '  Last attempt:   %s GMT', $action_scheduler['last_attempt_gmt'] ) );
				}
			}
			if ( isset( $check_result['wp_cron'] ) && is_array( $check_result['wp_cron'] ) ) {
				$wp_cron = $check_result['wp_cron'];
				if ( ! empty( $wp_cron['action_scheduler_run_queue_next_gmt'] ) ) {
					WP_CLI::log( sprintf( '  WP-Cron AS run: %s GMT', $wp_cron['action_scheduler_run_queue_next_gmt'] ) );
				}
				if ( null !== ( $wp_cron['action_scheduler_run_queue_overdue_seconds'] ?? null ) ) {
					WP_CLI::log( sprintf( '  WP-Cron lag:    %d seconds', (int) $wp_cron['action_scheduler_run_queue_overdue_seconds'] ) );
				}
			}
			if ( isset( $check_result['daily_memory'] ) && is_array( $check_result['daily_memory'] ) ) {
				$daily_memory = $check_result['daily_memory'];
				if ( ! empty( $daily_memory['next_pending_gmt'] ) ) {
					WP_CLI::log( sprintf( '  Daily memory:   next %s GMT', $daily_memory['next_pending_gmt'] ) );
				}
				if ( ! empty( $daily_memory['last_attempt_gmt'] ) ) {
					WP_CLI::log( sprintf( '  Daily memory:   last attempted %s GMT', $daily_memory['last_attempt_gmt'] ) );
				}
			}
			if ( isset( $check_result['flow_schedule_coverage'] ) && is_array( $check_result['flow_schedule_coverage'] ) ) {
				$coverage = $check_result['flow_schedule_coverage'];
				WP_CLI::log(
					sprintf(
						'  Flow schedules: %d covered, %d missing, %d blocked, %d invalid',
						(int) ( $coverage['covered'] ?? 0 ),
						(int) ( $coverage['remaining_missing'] ?? 0 ),
						(int) ( $coverage['blocked'] ?? 0 ),
						(int) ( $coverage['invalid'] ?? 0 )
					)
				);
			}
			if ( ! empty( $check_result['rejected_schedules'] ) && is_array( $check_result['rejected_schedules'] ) ) {
				WP_CLI::log( sprintf( '  Rejected schedules: %d (rejected every tick, never running)', count( $check_result['rejected_schedules'] ) ) );
				foreach ( $check_result['rejected_schedules'] as $rejected ) {
					WP_CLI::log(
						sprintf(
							'    - %s (task %s): %d consecutive rejections since %s GMT',
							$rejected['schedule_id'] ?? '?',
							$rejected['task_type'] ?? '?',
							(int) ( $rejected['consecutive_count'] ?? 0 ),
							$rejected['first_rejected_gmt'] ?? '?'
						)
					);
				}
			}
			if ( isset( $check_result['unowned_pipelines'] ) || isset( $check_result['unowned_flows'] ) ) {
				WP_CLI::log( sprintf( '  Unowned pipelines: %d', (int) ( $check_result['unowned_pipelines'] ?? 0 ) ) );
				WP_CLI::log( sprintf( '  Unowned flows:     %d', (int) ( $check_result['unowned_flows'] ?? 0 ) ) );
			}
			if ( ! empty( $check_result['recommendation'] ) ) {
				WP_CLI::log( sprintf( '  Recommendation: %s', $check_result['recommendation'] ) );
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
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Title:  %s', $result['title'] ) );
		WP_CLI::log( sprintf( 'Method: %s', $result['method'] ) );
	}

	/**
	 * Schedule a system task to run now.
	 *
	 * Enqueues a registered system task via the datamachine/run-task ability.
	 * Only tasks with supports_run: true can be scheduled manually; process the
	 * resulting job with `wp datamachine worker run` or `wp datamachine drain`.
	 *
	 * ## OPTIONS
	 *
	 * <task_type>
	 * : The task type to run (e.g. alt_text_generation, daily_memory_generation).
	 *
	 * [--param=<param>]
	 * : Structured task param as key=value. Repeatable.
	 *
	 * [--params=<json>]
	 * : JSON object of structured task params.
	 *
	 * [--agent=<agent>]
	 * : Agent ID or slug for agent-scoped tasks.
	 *
	 * [--dry-run]
	 * : Request preview mode for tasks that support it.
	 *
	 * [--apply]
	 * : Request apply mode for mutating tasks.
	 *
	 * [--wait]
	 * : After scheduling, synchronously drain only the created job. Does not drain
	 * unrelated overdue Data Machine work.
	 *
	 * [--step-budget=<number>]
	 * : Maximum due job actions to execute with --wait.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--time-budget-ms=<milliseconds>]
	 * : Maximum wall-clock milliseconds to drain with --wait.
	 * ---
	 * default: 300000
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
	 *     wp datamachine system run daily_memory_generation --agent=intelligence-chubes4
	 *     wp datamachine system run daily_memory_generation
	 *     wp datamachine system run daily_memory_generation --param=agent_slug=my-agent --wait
	 *     wp datamachine system run alt_text_generation --format=json
	 *     wp datamachine system run retention_logs --dry-run
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

		$params = self::parseRunTaskParams( $assoc_args );
		if ( isset( $params['error'] ) ) {
			WP_CLI::error( $params['error'] );
			return;
		}

		$result = SystemAbilities::runTask(
			array(
				'task_type'   => $task_type,
				'task_params' => $params,
			)
		);

		if ( ! $result['success'] ) {
			if ( 'json' === $format ) {
				WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			WP_CLI::error( $result['error'] ?? $result['message'] ?? 'Failed to run task.' );
			return;
		}

		if ( ! empty( $assoc_args['wait'] ) ) {
			$step_budget    = isset( $assoc_args['step-budget'] ) ? max( 1, (int) $assoc_args['step-budget'] ) : 50;
			$time_budget_ms = isset( $assoc_args['time-budget-ms'] ) ? max( 1, (int) $assoc_args['time-budget-ms'] ) : 300000;

			$result['drain'] = ( new DrainJobAbility() )->execute(
				array(
					'job_id'         => (int) ( $result['job_id'] ?? 0 ),
					'step_budget'    => $step_budget,
					'time_budget_ms' => $time_budget_ms,
				)
			);
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( $result['message'] );
		if ( isset( $result['drain'] ) ) {
			$drain = $result['drain'];
			if ( ! empty( $drain['success'] ) ) {
				WP_CLI::success(
					sprintf(
						'Job #%d drained to %s.',
						(int) ( $drain['job_id'] ?? 0 ),
						(string) ( $drain['terminal_state'] ?? 'terminal' )
					)
				);
				return;
			}

			WP_CLI::error(
				$drain['error'] ?? sprintf(
					'Job #%d did not reach a terminal state before the wait budget was exhausted.',
					(int) ( $drain['job_id'] ?? 0 )
				)
			);
		}
	}

	/**
	 * Parse `system run` structured task params.
	 *
	 * @param array $assoc_args WP-CLI associative args.
	 * @return array Parsed params, or array{error: string} on parse failure.
	 */
	private static function parseRunTaskParams( array $assoc_args ): array {
		$params = array();

		if ( isset( $assoc_args['params'] ) ) {
			$decoded = json_decode( (string) $assoc_args['params'], true );
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
				return array( 'error' => '--params must be a JSON object.' );
			}
			$params = $decoded;
		}

		$param_args = self::collectRunTaskParamArgs( $assoc_args );
		if ( ! is_array( $param_args ) ) {
			return array( 'error' => '--param must be key=value.' );
		}

		foreach ( $param_args as $param_arg ) {
			if ( ! is_string( $param_arg ) || ! str_contains( $param_arg, '=' ) ) {
				return array( 'error' => '--param must be key=value.' );
			}
			list( $key, $value ) = explode( '=', $param_arg, 2 );
			if ( '' === trim( $key ) ) {
				return array( 'error' => '--param key cannot be empty.' );
			}
			$params[ trim( $key ) ] = self::coerceRunTaskParamValue( $value );
		}

		if ( array_key_exists( 'agent', $assoc_args ) ) {
			if ( '' === trim( (string) $assoc_args['agent'] ) ) {
				return array( 'error' => '--agent cannot be empty.' );
			}
			$params['agent'] = self::coerceRunTaskParamValue( (string) $assoc_args['agent'] );
		}

		if ( ! empty( $assoc_args['dry-run'] ) && ! empty( $assoc_args['apply'] ) ) {
			return array( 'error' => 'Use either --dry-run or --apply, not both.' );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$params['dry_run'] = true;
		}
		if ( ! empty( $assoc_args['apply'] ) ) {
			$params['dry_run'] = false;
			$params['apply']   = true;
		}

		return $params;
	}

	/**
	 * Collect repeatable `--param` values from WP-CLI args.
	 *
	 * WP-CLI keeps only the last value for repeated named args unless the
	 * command uses a generic `--<field>=<value>` synopsis. Preserve the public
	 * `--param=key=value` contract by reading the raw argv when repeats exist.
	 *
	 * @param array      $assoc_args WP-CLI associative args.
	 * @param array|null $argv Raw argv override for tests.
	 * @return array|string
	 */
	private static function collectRunTaskParamArgs( array $assoc_args, ?array $argv = null ): array|string {
		$argv = $argv ?? array_map(
			static function ( $arg ): string {
				// Preserve raw CLI parameter payloads; values are parsed/coerced after
				// the `--param` boundary and may intentionally contain JSON/URLs.
				return (string) wp_unslash( $arg );
			},
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw argv values are unslashed here and parsed as CLI task parameters below; full-field sanitization would corrupt structured values.
			$_SERVER['argv'] ?? array()
		);
		$raw_params = array();

		$argv_values = array_values( $argv );
		foreach ( $argv_values as $index => $arg ) {
			if ( ! is_string( $arg ) ) {
				continue;
			}
			if ( str_starts_with( $arg, '--param=' ) ) {
				$raw_params[] = substr( $arg, strlen( '--param=' ) );
				continue;
			}
			if ( '--param' === $arg && isset( $argv_values[ $index + 1 ] ) ) {
				$raw_params[] = (string) $argv_values[ $index + 1 ];
			}
		}

		if ( count( $raw_params ) > 1 || ( ! isset( $assoc_args['param'] ) && ! empty( $raw_params ) ) ) {
			return $raw_params;
		}

		$param_args = $assoc_args['param'] ?? array();
		if ( is_string( $param_args ) ) {
			$param_args = array( $param_args );
		}
		return $param_args;
	}

	/**
	 * Coerce scalar CLI param values to simple JSON-like types.
	 *
	 * @param string $value Raw CLI value.
	 * @return mixed
	 */
	private static function coerceRunTaskParamValue( string $value ): mixed {
		$trimmed = trim( $value );
		if ( 'true' === strtolower( $trimmed ) ) {
			return true;
		}
		if ( 'false' === strtolower( $trimmed ) ) {
			return false;
		}
		if ( 'null' === strtolower( $trimmed ) ) {
			return null;
		}
		if ( is_numeric( $trimmed ) ) {
			return str_contains( $trimmed, '.' ) ? (float) $trimmed : (int) $trimmed;
		}
		return $value;
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
		$format    = $assoc_args['format'] ?? 'table';
		$handlers  = TaskRegistry::getHandlers();
		$overrides = SystemTask::getAllPromptOverrides();

		$rows = array();

		foreach ( $handlers as $task_type => $handler_class ) {
			if ( ! class_exists( $handler_class ) ) {
				continue;
			}

			$task = new $handler_class();
			if ( ! $task instanceof SystemTask ) {
				continue;
			}
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
			WP_CLI::line( (string) wp_json_encode( array(
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
				return '{{' . $k . '}} — ' . $v;
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

		$task = new $handler_class();
		if ( ! $task instanceof SystemTask ) {
			WP_CLI::error( sprintf( 'Task handler class is not a SystemTask: %s', $handler_class ) );
			return null;
		}
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
