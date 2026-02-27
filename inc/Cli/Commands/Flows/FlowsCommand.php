<?php
/**
 * WP-CLI Flows Command
 *
 * Provides CLI access to flow operations including listing, creation, and execution.
 * Wraps FlowAbilities API primitive.
 *
 * Queue and webhook subcommands are handled by dedicated command classes:
 * - QueueCommand (flows queue)
 * - WebhookCommand (flows webhook)
 *
 * @package DataMachine\Cli\Commands\Flows
 * @since 0.15.3 Added create subcommand.
 * @since 0.31.0 Extracted queue and webhook to dedicated command classes.
 */

namespace DataMachine\Cli\Commands\Flows;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class FlowsCommand extends BaseCommand {

	/**
	 * Default fields for flow list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'pipeline_id', 'handlers', 'prompt', 'status', 'next_run' );

	/**
	 * Get flows with optional filtering.
	 *
	 * ## OPTIONS
	 *
	 * [<args>...]
	 * : Subcommand and arguments. Accepts: list [pipeline_id], get <flow_id>, run <flow_id>, create, delete <flow_id>, update <flow_id>.
	 *
	 * [--handler=<slug>]
	 * : Filter flows using this handler slug (any step that uses this handler).
	 *
	 * [--per_page=<number>]
	 * : Number of flows to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--offset=<number>]
	 * : Offset for pagination.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--id=<flow_id>]
	 * : Get a specific flow by ID.
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * [--count=<number>]
	 * : Number of times to run the flow (1-10, immediate execution only).
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--timestamp=<unix>]
	 * : Unix timestamp for delayed execution (future time required).
	 *
	 * [--pipeline_id=<id>]
	 * : Pipeline ID for flow creation (create subcommand).
	 *
	 * [--name=<name>]
	 * : Flow name (create subcommand).
	 *
	 * [--step_configs=<json>]
	 * : JSON object with step configurations keyed by step_type (create subcommand).
	 *
	 * [--scheduling=<interval>]
	 * : Scheduling interval (manual, hourly, daily, etc.).
	 *
	 * [--set-prompt=<text>]
	 * : Update the prompt for a handler step (requires handler step to exist).
	 *
	 * [--handler-config=<json>]
	 * : JSON object of handler config key-value pairs to update (merged with existing config).
	 *   Requires --step to identify the target flow step.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step for prompt update or handler config update (auto-resolved if flow has exactly one handler step).
	 *
	 * [--dry-run]
	 * : Validate without creating (create subcommand).
	 *
	 * [--yes]
	 * : Skip confirmation prompt (delete subcommand).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all flows
	 *     wp datamachine flows
	 *
	 *     # List flows for pipeline 5
	 *     wp datamachine flows 5
	 *
	 *     # List flows using rss handler
	 *     wp datamachine flows --handler=rss
	 *
	 *     # Get a specific flow by ID
	 *     wp datamachine flows get 42
	 *
	 *     # Run a flow immediately
	 *     wp datamachine flows run 42
	 *
	 *     # Create a new flow
	 *     wp datamachine flows create --pipeline_id=3 --name="My Flow"
	 *
	 *     # Delete a flow
	 *     wp datamachine flows delete 141
	 *
	 *     # Update flow name
	 *     wp datamachine flows update 141 --name="New Name"
	 *
	 *     # Update flow prompt
	 *     wp datamachine flows update 42 --set-prompt="New prompt text"
	 *
	 *     # Add a handler to a flow step
	 *     wp datamachine flows add-handler 42 --handler=rss
	 *
	 *     # Remove a handler from a flow step
	 *     wp datamachine flows remove-handler 42 --handler=rss
	 *
	 *     # List handlers on a flow
	 *     wp datamachine flows list-handlers 42
	 *
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$flow_id     = null;
		$pipeline_id = null;

		// Handle 'create' subcommand: `flows create --pipeline_id=3 --name="Test"`.
		if ( ! empty( $args ) && 'create' === $args[0] ) {
			$this->createFlow( $assoc_args );
			return;
		}

		// Delegate 'queue' subcommand to QueueCommand.
		if ( ! empty( $args ) && 'queue' === $args[0] ) {
			$queue = new QueueCommand();
			$queue->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Delegate 'webhook' subcommand to WebhookCommand.
		if ( ! empty( $args ) && 'webhook' === $args[0] ) {
			$webhook = new WebhookCommand();
			$webhook->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'delete' subcommand: `flows delete 42`.
		if ( ! empty( $args ) && 'delete' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows delete <flow_id> [--yes]' );
				return;
			}
			$this->deleteFlow( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'update' subcommand: `flows update 42 --name="New Name"`.
		if ( ! empty( $args ) && 'update' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows update <flow_id> [--name=<name>] [--scheduling=<interval>] [--set-prompt=<text>] [--handler-config=<json>] [--step=<flow_step_id>]' );
				return;
			}
			$this->updateFlow( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'add-handler' subcommand.
		if ( ! empty( $args ) && 'add-handler' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows add-handler <flow_id> --handler=<slug> [--step=<flow_step_id>] [--config=<json>]' );
				return;
			}
			$this->addHandler( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'remove-handler' subcommand.
		if ( ! empty( $args ) && 'remove-handler' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows remove-handler <flow_id> --handler=<slug> [--step=<flow_step_id>]' );
				return;
			}
			$this->removeHandler( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'list-handlers' subcommand.
		if ( ! empty( $args ) && 'list-handlers' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows list-handlers <flow_id> [--step=<flow_step_id>]' );
				return;
			}
			$this->listHandlers( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'get'/'show' subcommand: `flows get 42` or `flows show 42`.
		if ( ! empty( $args ) && ( 'get' === $args[0] || 'show' === $args[0] ) ) {
			if ( isset( $args[1] ) ) {
				$flow_id = (int) $args[1];
			}
		} elseif ( ! empty( $args ) && 'run' === $args[0] ) {
			// Handle 'run' subcommand: `flows run 42`.
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows run <flow_id> [--count=N] [--timestamp=T]' );
				return;
			}
			$this->runFlow( (int) $args[1], $assoc_args );
			return;
		} elseif ( ! empty( $args ) && 'list' !== $args[0] ) {
			$pipeline_id = (int) $args[0];
		}

		// Handle --id flag (takes precedence if both provided).
		if ( isset( $assoc_args['id'] ) ) {
			$flow_id = (int) $assoc_args['id'];
		}

		$handler_slug = $assoc_args['handler'] ?? null;
		$per_page     = (int) ( $assoc_args['per_page'] ?? 20 );
		$offset       = (int) ( $assoc_args['offset'] ?? 0 );
		$format       = $assoc_args['format'] ?? 'table';

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeAbility(
			array(
				'flow_id'      => $flow_id,
				'pipeline_id'  => $pipeline_id,
				'handler_slug' => $handler_slug,
				'per_page'     => $per_page,
				'offset'       => $offset,
				'output_mode'  => 'full',
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get flows' );
			return;
		}

		$flows = $result['flows'] ?? array();
		$total = $result['total'] ?? 0;

		if ( empty( $flows ) ) {
			WP_CLI::warning( 'No flows found matching your criteria.' );
			return;
		}

		// Transform flows to flat row format.
		$items = array_map(
			function ( $flow ) {
				return array(
					'id'          => $flow['flow_id'],
					'name'        => $flow['flow_name'],
					'pipeline_id' => $flow['pipeline_id'],
					'handlers'    => $this->extractHandlers( $flow ),
					'prompt'      => $this->extractPrompt( $flow ),
					'status'      => $flow['last_run_status'] ?? 'Never',
					'next_run'    => $flow['next_run_display'] ?? 'Not scheduled',
				);
			},
			$flows
		);

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );
		$this->output_pagination( $offset, count( $flows ), $total, $format, 'flows' );
		$this->outputFilters( $result['filters_applied'] ?? array(), $format );
	}

	/**
	 * Create a new flow.
	 *
	 * @param array $assoc_args Associative arguments (pipeline_id, name, step_configs, scheduling, dry-run).
	 */
	private function createFlow( array $assoc_args ): void {
		$pipeline_id = isset( $assoc_args['pipeline_id'] ) ? (int) $assoc_args['pipeline_id'] : null;
		$flow_name   = $assoc_args['name'] ?? null;
		$scheduling  = $assoc_args['scheduling'] ?? 'manual';
		$dry_run     = isset( $assoc_args['dry-run'] );
		$format      = $assoc_args['format'] ?? 'table';

		if ( ! $pipeline_id ) {
			WP_CLI::error( 'Required: --pipeline_id=<id>' );
			return;
		}

		if ( ! $flow_name ) {
			WP_CLI::error( 'Required: --name=<name>' );
			return;
		}

		$step_configs = array();
		if ( isset( $assoc_args['step_configs'] ) ) {
			$decoded = json_decode( wp_unslash( $assoc_args['step_configs'] ), true );
			if ( null === $decoded && '' !== $assoc_args['step_configs'] ) {
				WP_CLI::error( 'Invalid JSON in --step_configs' );
				return;
			}
			if ( null !== $decoded && ! is_array( $decoded ) ) {
				WP_CLI::error( '--step_configs must be a JSON object' );
				return;
			}
			$step_configs = $decoded ?? array();
		}

		$input = array(
			'pipeline_id'       => $pipeline_id,
			'flow_name'         => $flow_name,
			'scheduling_config' => array( 'interval' => $scheduling ),
			'step_configs'      => $step_configs,
		);

		if ( $dry_run ) {
			$input['validate_only'] = true;
			$input['flows']         = array(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => array( 'interval' => $scheduling ),
					'step_configs'      => $step_configs,
				),
			);
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeCreateFlow( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create flow' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Validation passed.' );
			if ( isset( $result['would_create'] ) && 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $result['would_create'], JSON_PRETTY_PRINT ) );
			} elseif ( isset( $result['would_create'] ) ) {
				foreach ( $result['would_create'] as $preview ) {
					WP_CLI::log( sprintf(
						'Would create: "%s" on pipeline %d (scheduling: %s)',
						$preview['flow_name'],
						$preview['pipeline_id'],
						$preview['scheduling']
					) );
				}
			}
			return;
		}

		WP_CLI::success( sprintf( 'Flow created: ID %d', $result['flow_id'] ) );
		WP_CLI::log( sprintf( 'Name: %s', $result['flow_name'] ) );
		WP_CLI::log( sprintf( 'Pipeline ID: %d', $result['pipeline_id'] ) );
		WP_CLI::log( sprintf( 'Synced steps: %d', $result['synced_steps'] ?? 0 ) );

		if ( ! empty( $result['configured_steps'] ) ) {
			WP_CLI::log( sprintf( 'Configured steps: %s', implode( ', ', $result['configured_steps'] ) ) );
		}

		if ( ! empty( $result['configuration_errors'] ) ) {
			WP_CLI::warning( 'Some step configurations failed:' );
			foreach ( $result['configuration_errors'] as $error ) {
				WP_CLI::log( sprintf( '  - %s: %s', $error['step_type'] ?? 'unknown', $error['error'] ?? 'unknown error' ) );
			}
		}

		if ( 'json' === $format && isset( $result['flow_data'] ) ) {
			WP_CLI::line( wp_json_encode( $result['flow_data'], JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Run a flow immediately or with scheduling.
	 *
	 * @param int   $flow_id    Flow ID to execute.
	 * @param array $assoc_args Associative arguments (count, timestamp).
	 */
	private function runFlow( int $flow_id, array $assoc_args ): void {
		$count     = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 1;
		$timestamp = isset( $assoc_args['timestamp'] ) ? (int) $assoc_args['timestamp'] : null;

		// Validate count range (1-10).
		if ( $count < 1 || $count > 10 ) {
			WP_CLI::error( 'Count must be between 1 and 10.' );
			return;
		}

		$ability = new \DataMachine\Abilities\JobAbilities();
		$result  = $ability->executeWorkflow(
			array(
				'flow_id'   => $flow_id,
				'count'     => $count,
				'timestamp' => $timestamp,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to run flow' );
			return;
		}

		// Output success message.
		WP_CLI::success( $result['message'] ?? 'Flow execution scheduled.' );

		// Show job ID(s) for follow-up.
		if ( isset( $result['job_id'] ) ) {
			WP_CLI::log( sprintf( 'Job ID: %d', $result['job_id'] ) );
		} elseif ( isset( $result['job_ids'] ) ) {
			WP_CLI::log( sprintf( 'Job IDs: %s', implode( ', ', $result['job_ids'] ) ) );
		}
	}

	/**
	 * Delete a flow.
	 *
	 * @param int   $flow_id    Flow ID to delete.
	 * @param array $assoc_args Associative arguments (--yes).
	 */
	private function deleteFlow( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$skip_confirm = isset( $assoc_args['yes'] );

		if ( ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( 'Are you sure you want to delete flow %d?', $flow_id ) );
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeDeleteFlow( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete flow' );
			return;
		}

		WP_CLI::success( sprintf( 'Flow %d deleted.', $flow_id ) );

		if ( isset( $result['pipeline_id'] ) ) {
			WP_CLI::log( sprintf( 'Pipeline ID: %d', $result['pipeline_id'] ) );
		}
	}

	/**
	 * Update a flow's name or scheduling.
	 *
	 * @param int   $flow_id    Flow ID to update.
	 * @param array $assoc_args Associative arguments (--name, --scheduling).
	 */
	private function updateFlow( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$name           = $assoc_args['name'] ?? null;
		$scheduling     = $assoc_args['scheduling'] ?? null;
		$prompt         = isset( $assoc_args['set-prompt'] )
			? wp_kses_post( wp_unslash( $assoc_args['set-prompt'] ) )
			: null;
		$handler_config = isset( $assoc_args['handler-config'] )
			? json_decode( wp_unslash( $assoc_args['handler-config'] ), true )
			: null;
		$step           = $assoc_args['step'] ?? null;

		if ( null !== $handler_config && ! is_array( $handler_config ) ) {
			WP_CLI::error( 'Invalid JSON in --handler-config. Must be a JSON object.' );
			return;
		}

		if ( null === $name && null === $scheduling && null === $prompt && null === $handler_config ) {
			WP_CLI::error( 'Must provide --name, --scheduling, --set-prompt, or --handler-config to update' );
			return;
		}

		// Validate step resolution BEFORE any writes (atomic: fail fast, change nothing).
		$needs_step = null !== $prompt || null !== $handler_config;

		if ( $needs_step && null === $step ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( $resolved['error'] ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step = $resolved['step_id'];
		}

		// Phase 1: Flow-level updates (name, scheduling).
		$input = array( 'flow_id' => $flow_id );

		if ( null !== $name ) {
			$input['flow_name'] = $name;
		}

		if ( null !== $scheduling ) {
			$input['scheduling_config'] = array( 'interval' => $scheduling );
		}

		if ( null !== $name || null !== $scheduling ) {
			$ability = new \DataMachine\Abilities\FlowAbilities();
			$result  = $ability->executeUpdateFlow( $input );

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['error'] ?? 'Failed to update flow' );
				return;
			}

			WP_CLI::success( sprintf( 'Flow %d updated.', $flow_id ) );
			WP_CLI::log( sprintf( 'Name: %s', $result['flow_name'] ?? '' ) );

			if ( isset( $result['flow_data']['scheduling_config']['interval'] ) ) {
				WP_CLI::log( sprintf( 'Scheduling: %s', $result['flow_data']['scheduling_config']['interval'] ) );
			}
		}

		// Phase 2: Step-level updates (prompt, handler config).
		if ( null !== $prompt ) {
			$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
			$step_result  = $step_ability->execute(
				array(
					'flow_step_id'   => $step,
					'handler_config' => array( 'prompt' => $prompt ),
				)
			);

			if ( ! $step_result['success'] ) {
				WP_CLI::error( $step_result['error'] ?? 'Failed to update prompt' );
				return;
			}

			WP_CLI::success( 'Prompt updated for step: ' . $step );
		}

		if ( null !== $handler_config ) {
			$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
			$step_result  = $step_ability->execute(
				array(
					'flow_step_id'   => $step,
					'handler_config' => $handler_config,
				)
			);

			if ( ! $step_result['success'] ) {
				WP_CLI::error( $step_result['error'] ?? 'Failed to update handler config' );
				return;
			}

			$updated_keys = implode( ', ', array_keys( $handler_config ) );
			WP_CLI::success( sprintf( 'Handler config updated for step %s: %s', $step, $updated_keys ) );
		}
	}

	/**
	 * Output filter info (table format only).
	 *
	 * @param array  $filters_applied Applied filters.
	 * @param string $format          Current output format.
	 */
	private function outputFilters( array $filters_applied, string $format ): void {
		if ( 'table' !== $format ) {
			return;
		}

		if ( $filters_applied['flow_id'] ?? null ) {
			WP_CLI::log( "Filtered by flow ID: {$filters_applied['flow_id']}" );
		}
		if ( $filters_applied['pipeline_id'] ?? null ) {
			WP_CLI::log( "Filtered by pipeline ID: {$filters_applied['pipeline_id']}" );
		}
		if ( $filters_applied['handler_slug'] ?? null ) {
			WP_CLI::log( "Filtered by handler slug: {$filters_applied['handler_slug']}" );
		}
	}

	/**
	 * Extract handler slugs from flow config.
	 *
	 * @param array $flow Flow data.
	 * @return string Comma-separated handler slugs.
	 */
	private function extractHandlers( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$handlers    = array();

		foreach ( $flow_config as $step_data ) {
			// Data is normalized at the DB layer — handler_slugs is canonical.
			$handlers = array_merge( $handlers, $step_data['handler_slugs'] ?? array() );
		}

		return implode( ', ', array_unique( $handlers ) );
	}

	/**
	 * Extract the first prompt from flow config for display.
	 *
	 * @param array $flow Flow data.
	 * @return string Prompt preview.
	 */
	private function extractPrompt( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();

		foreach ( $flow_config as $step_data ) {
			$primary_slug   = $step_data['handler_slugs'][0] ?? '';
			$primary_config = ! empty( $primary_slug ) ? ( $step_data['handler_configs'][ $primary_slug ] ?? array() ) : array();
			if ( ! empty( $primary_config['prompt'] ) ) {
				$prompt = $primary_config['prompt'];
				return mb_strlen( $prompt ) > 50
					? mb_substr( $prompt, 0, 47 ) . '...'
					: $prompt;
			}
			if ( ! empty( $step_data['pipeline_config']['prompt'] ) ) {
				$prompt = $step_data['pipeline_config']['prompt'];
				return mb_strlen( $prompt ) > 50
					? mb_substr( $prompt, 0, 47 ) . '...'
					: $prompt;
			}
		}

		return '';
	}

	/**
	 * Add a handler to a flow step.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (handler, step, config).
	 */
	private function addHandler( int $flow_id, array $assoc_args ): void {
		$handler_slug = $assoc_args['handler'] ?? null;
		$step_id      = $assoc_args['step'] ?? null;

		if ( ! $handler_slug ) {
			WP_CLI::error( 'Required: --handler=<slug>' );
			return;
		}

		// Auto-resolve handler step if not specified.
		if ( ! $step_id ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step_id = $resolved['step_id'];
		}

		$input = array(
			'flow_step_id' => $step_id,
			'add_handler'  => $handler_slug,
		);

		// Parse --config if provided.
		if ( isset( $assoc_args['config'] ) ) {
			$handler_config = json_decode( wp_unslash( $assoc_args['config'] ), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				WP_CLI::error( 'Invalid JSON in --config: ' . json_last_error_msg() );
				return;
			}
			$input['add_handler_config'] = $handler_config;
		}

		$ability = new \DataMachine\Abilities\FlowStepAbilities();
		$result  = $ability->executeUpdateFlowStep( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add handler' );
			return;
		}

		WP_CLI::success( "Added handler '{$handler_slug}' to flow step {$step_id}" );
	}

	/**
	 * Remove a handler from a flow step.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (handler, step).
	 */
	private function removeHandler( int $flow_id, array $assoc_args ): void {
		$handler_slug = $assoc_args['handler'] ?? null;
		$step_id      = $assoc_args['step'] ?? null;

		if ( ! $handler_slug ) {
			WP_CLI::error( 'Required: --handler=<slug>' );
			return;
		}

		if ( ! $step_id ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$step_id = $resolved['step_id'];
		}

		$ability = new \DataMachine\Abilities\FlowStepAbilities();
		$result  = $ability->executeUpdateFlowStep( array(
			'flow_step_id'   => $step_id,
			'remove_handler' => $handler_slug,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to remove handler' );
			return;
		}

		WP_CLI::success( "Removed handler '{$handler_slug}' from flow step {$step_id}" );
	}

	/**
	 * List handlers on flow steps.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (step, format).
	 */
	private function listHandlers( int $flow_id, array $assoc_args ): void {
		$step_id = $assoc_args['step'] ?? null;

		$db   = new \DataMachine\Core\Database\Flows\Flows();
		$flow = $db->get_flow( $flow_id );

		if ( ! $flow ) {
			WP_CLI::error( "Flow {$flow_id} not found" );
			return;
		}

		$config = $flow['flow_config'] ?? array();
		$rows   = array();

		foreach ( $config as $sid => $step ) {
			if ( $step_id && $sid !== $step_id ) {
				continue;
			}

			$step_type = $step['step_type'] ?? '';

			$slugs   = $step['handler_slugs'] ?? array();
			$configs = $step['handler_configs'] ?? array();

			// Check for legacy single handler_slug.
			if ( empty( $slugs ) ) {
				$legacy = $step['handler_slug'] ?? '';
				if ( $legacy ) {
					$slugs = array( $legacy );
				}
			}

			if ( empty( $slugs ) && ! $step_id ) {
				continue; // Skip steps with no handlers unless specifically requested.
			}

			foreach ( $slugs as $slug ) {
				$handler_config = $configs[ $slug ] ?? array();
				$config_summary = array();
				foreach ( $handler_config as $k => $v ) {
					if ( is_string( $v ) && strlen( $v ) > 30 ) {
						$v = substr( $v, 0, 27 ) . '...';
					}
					$config_summary[] = "{$k}=" . ( is_array( $v ) ? wp_json_encode( $v ) : $v );
				}

				$rows[] = array(
					'flow_step_id' => $sid,
					'step_type'    => $step_type,
					'handler'      => $slug,
					'config'       => implode( ', ', $config_summary ) ?: '(default)',
				);
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No handlers found.' );
			return;
		}

		$this->format_items( $rows, array( 'flow_step_id', 'step_type', 'handler', 'config' ), $assoc_args );
	}

	/**
	 * Resolve the handler step for a flow when --step is not provided.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{step_id: string|null, error: string|null}
	 */
	private function resolveHandlerStep( int $flow_id ): array {
		global $wpdb;

		$flow = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT flow_config FROM {$wpdb->prefix}datamachine_flows WHERE flow_id = %d",
				$flow_id
			),
			ARRAY_A
		);

		if ( ! $flow ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow not found',
			);
		}

		$flow_config = json_decode( $flow['flow_config'], true );
		if ( empty( $flow_config ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow has no steps',
			);
		}

		$handler_steps = array();
		foreach ( $flow_config as $step_id => $step_data ) {
			if ( $this->stepHasHandlerConfig( $step_data ) ) {
				$handler_steps[] = $step_id;
			}
		}

		if ( empty( $handler_steps ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow has no configurable steps (no handler_slugs, handler_slug, handler_config, or step_type with config found)',
			);
		}

		if ( count( $handler_steps ) > 1 ) {
			return array(
				'step_id' => null,
				'error'   => sprintf(
					'Flow has multiple configurable steps. Use --step=<id> to specify. Available: %s',
					implode( ', ', $handler_steps )
				),
			);
		}

		return array(
			'step_id' => $handler_steps[0],
			'error'   => null,
		);
	}

	/**
	 * Check if a step has handler configuration (modern, legacy, or non-handler step with config).
	 *
	 * Detects:
	 * - handler_slugs (modern plural format)
	 * - handler_slug (legacy singular format)
	 * - handler_config / handler_configs (step with config but no explicit handler slug, e.g. agent_ping)
	 * - step_type that matches a registered handler settings provider (e.g. agent_ping)
	 *
	 * @param array $step_data Step configuration data.
	 * @return bool True if the step has configurable handler settings.
	 */
	private function stepHasHandlerConfig( array $step_data ): bool {
		// Modern plural format.
		if ( ! empty( $step_data['handler_slugs'] ) ) {
			return true;
		}

		// Legacy singular format.
		if ( ! empty( $step_data['handler_slug'] ) ) {
			return true;
		}

		// Step has handler config directly (e.g. agent_ping stores config without handler_slug).
		if ( ! empty( $step_data['handler_config'] ) || ! empty( $step_data['handler_configs'] ) ) {
			return true;
		}

		// Non-handler step types that register their own settings (e.g. agent_ping).
		$step_type = $step_data['step_type'] ?? '';
		if ( ! empty( $step_type ) ) {
			$all_settings = apply_filters( 'datamachine_handler_settings', array(), $step_type );
			if ( isset( $all_settings[ $step_type ] ) ) {
				return true;
			}
		}

		return false;
	}
}
