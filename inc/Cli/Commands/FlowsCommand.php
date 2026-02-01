<?php
/**
 * WP-CLI Flows Command
 *
 * Provides CLI access to flow operations including listing, creation, and execution.
 * Wraps FlowAbilities API primitive.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.15.3 Added create subcommand.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class FlowsCommand extends BaseCommand {

	/**
	 * Default fields for flow list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'pipeline_id', 'handlers', 'status', 'next_run' );

	/**
	 * Get flows with optional filtering.
	 *
	 * ## OPTIONS
	 *
	 * [<args>...]
	 * : Subcommand and arguments. Accepts: list [pipeline_id], get <flow_id>, run <flow_id>, create, queue.
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
	 * : Scheduling interval (manual, hourly, daily, etc.) (create subcommand).
	 * ---
	 * default: manual
	 * ---
	 *
	 * [--dry-run]
	 * : Validate without creating (create subcommand).
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
	 *     # List flows for pipeline 3 using wordpress_publish handler
	 *     wp datamachine flows 3 --handler=wordpress_publish
	 *
	 *     # List with pagination
	 *     wp datamachine flows --per_page=10 --offset=20
	 *
	 *     # Output as CSV
	 *     wp datamachine flows --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine flows --format=ids
	 *
	 *     # Count total flows
	 *     wp datamachine flows --format=count
	 *
	 *     # Select specific fields
	 *     wp datamachine flows --fields=id,name
	 *
	 *     # JSON output
	 *     wp datamachine flows --format=json
	 *
	 *     # Get a specific flow by ID
	 *     wp datamachine flows --id=42
	 *
	 *     # Alias: flows get <id>
	 *     wp datamachine flows get 42
	 *
	 *     # Run a flow immediately
	 *     wp datamachine flows run 42
	 *
	 *     # Run a flow 3 times (creates 3 independent jobs)
	 *     wp datamachine flows run 42 --count=3
	 *
	 *     # Schedule a flow for later execution
	 *     wp datamachine flows run 42 --timestamp=1735689600
	 *
	 *     # Create a new flow (minimal)
	 *     wp datamachine flows create --pipeline_id=3 --name="My Flow"
	 *
	 *     # Create a flow with step configuration
	 *     wp datamachine flows create --pipeline_id=3 --name="Dice.fm Charleston" \
	 *       --step_configs='{"event_import":{"handler_slug":"dice_fm","handler_config":{"city":"Charleston"}}}'
	 *
	 *     # Create a flow with scheduling
	 *     wp datamachine flows create --pipeline_id=3 --name="Daily RSS" --scheduling=daily
	 *
	 *     # Dry-run validation
	 *     wp datamachine flows create --pipeline_id=3 --name="Test" --dry-run
	 *
	 *     # Add a prompt to the queue
	 *     wp datamachine flows queue add 42 "Generate a blog post about AI"
	 *
	 *     # List queued prompts
	 *     wp datamachine flows queue list 42
	 *
	 *     # List queued prompts as JSON
	 *     wp datamachine flows queue list 42 --format=json
	 *
	 *     # Remove a prompt from queue by index
	 *     wp datamachine flows queue remove 42 0
	 *
	 *     # Clear all prompts from queue
	 *     wp datamachine flows queue clear 42
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$flow_id     = null;
		$pipeline_id = null;

		// Handle 'create' subcommand: `flows create --pipeline_id=3 --name="Test"`.
		if ( ! empty( $args ) && 'create' === $args[0] ) {
			$this->createFlow( $assoc_args );
			return;
		}

		// Handle 'queue' subcommand: `flows queue add|list|clear|remove <flow_id> [prompt|index]`.
		if ( ! empty( $args ) && 'queue' === $args[0] ) {
			$this->handleQueue( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'get' subcommand: `flows get 42`.
		if ( ! empty( $args ) && 'get' === $args[0] ) {
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
			$decoded = json_decode( $assoc_args['step_configs'], true );
			if ( null === $decoded && '' !== $assoc_args['step_configs'] ) {
				WP_CLI::error( 'Invalid JSON in --step_configs' );
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
			if ( ! empty( $step_data['handler_slug'] ) ) {
				$handlers[] = $step_data['handler_slug'];
			}
		}

		return implode( ', ', array_unique( $handlers ) );
	}

	/**
	 * Handle queue subcommands.
	 *
	 * @param array $args       Positional arguments (action, flow_id, [prompt|index]).
	 * @param array $assoc_args Associative arguments.
	 */
	private function handleQueue( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue <add|list|clear|remove> <flow_id> [prompt|index]' );
			return;
		}

		$action = $args[0] ?? '';

		switch ( $action ) {
			case 'add':
				$this->queueAdd( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'list':
				$this->queueList( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'clear':
				$this->queueClear( array_slice( $args, 1 ), $assoc_args );
				break;
			case 'remove':
				$this->queueRemove( array_slice( $args, 1 ), $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown queue action: {$action}. Use: add, list, clear, remove" );
		}
	}

	/**
	 * Add a prompt to the flow queue.
	 *
	 * @param array $args       Positional arguments (flow_id, prompt).
	 * @param array $assoc_args Associative arguments.
	 */
	private function queueAdd( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue add <flow_id> "prompt text"' );
			return;
		}

		$flow_id = (int) $args[0];
		$prompt  = $args[1];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( empty( trim( $prompt ) ) ) {
			WP_CLI::error( 'prompt cannot be empty' );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueAdd(
			array(
				'flow_id' => $flow_id,
				'prompt'  => $prompt,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add prompt to queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Prompt added to queue.' );
	}

	/**
	 * List all prompts in the flow queue.
	 *
	 * @param array $args       Positional arguments (flow_id).
	 * @param array $assoc_args Associative arguments (format).
	 */
	private function queueList( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue list <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];
		$format  = $assoc_args['format'] ?? 'table';

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueList( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list queue' );
			return;
		}

		$queue = $result['queue'] ?? array();

		if ( empty( $queue ) ) {
			WP_CLI::log( 'Queue is empty.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $queue, JSON_PRETTY_PRINT ) );
			return;
		}

		// Transform for table display.
		$items = array();
		foreach ( $queue as $index => $item ) {
			$prompt_preview = mb_strlen( $item['prompt'] ) > 60
				? mb_substr( $item['prompt'], 0, 57 ) . '...'
				: $item['prompt'];

			$items[] = array(
				'index'    => $index,
				'prompt'   => $prompt_preview,
				'added_at' => $item['added_at'] ?? '',
			);
		}

		$this->format_items( $items, array( 'index', 'prompt', 'added_at' ), $assoc_args, 'index' );
		WP_CLI::log( sprintf( 'Total: %d prompt(s) in queue.', count( $queue ) ) );
	}

	/**
	 * Clear all prompts from the flow queue.
	 *
	 * @param array $args       Positional arguments (flow_id).
	 * @param array $assoc_args Associative arguments.
	 */
	private function queueClear( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue clear <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueClear( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Queue cleared.' );
	}

	/**
	 * Remove a specific prompt from the queue by index.
	 *
	 * @param array $args       Positional arguments (flow_id, index).
	 * @param array $assoc_args Associative arguments.
	 */
	private function queueRemove( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows queue remove <flow_id> <index>' );
			return;
		}

		$flow_id = (int) $args[0];
		$index   = (int) $args[1];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		if ( $index < 0 ) {
			WP_CLI::error( 'index must be a non-negative integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\FlowAbilities();
		$result  = $ability->executeQueueRemove(
			array(
				'flow_id' => $flow_id,
				'index'   => $index,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to remove prompt from queue' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Prompt removed from queue.' );
		if ( ! empty( $result['removed_prompt'] ) ) {
			$preview = mb_strlen( $result['removed_prompt'] ) > 80
				? mb_substr( $result['removed_prompt'], 0, 77 ) . '...'
				: $result['removed_prompt'];
			WP_CLI::log( sprintf( 'Removed: %s', $preview ) );
		}
	}
}
