<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * WP-CLI Flows Command
 *
 * Provides CLI access to flow operations including listing, creation, and execution.
 * Wraps the datamachine/get-flows / create-flow / update-flow / delete-flow / pause-flow / resume-flow / duplicate-flow abilities.
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
use DataMachine\Cli\AgentResolver;
use DataMachine\Cli\UserResolver;
use DataMachine\Cli\Commands\DrainCommand;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\Debug\SyncRunner;

defined( 'ABSPATH' ) || exit;

class FlowsCommand extends BaseCommand {

	/**
	 * Default fields for flow list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'pipeline_id', 'handlers', 'config', 'schedule', 'max_items', 'status', 'next_run' );

	/**
	 * Get flows with optional filtering.
	 *
	 * ## OPTIONS
	 *
	 * [<args>...]
	 * : Subcommand and arguments. Accepts: list [pipeline_id], get <flow_id>, run <flow_id>, create, delete <flow_id>, update <flow_id>, reconcile-schedules.
	 *
	 * [--handler=<slug>]
	 * : Filter flows using this handler slug (any step that uses this handler).
	 *
	 * [--per_page=<number>]
	 * : Number of flows to return. 0 = all (default).
	 * ---
	 * default: 0
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
	 * [--[no-]drain]
	 * : Drain due Data Machine batch chunk and step actions after an immediate run.
	 *
	 * [--max-steps=<number>]
	 * : Maximum inline steps for run-sync. Default: 20.
	 *
	 * [--max-items=<number>]
	 * : Maximum packets retained from any sync step. Default: 50.
	 *
	 * [--timeout=<seconds>]
	 * : Maximum wall time for run-sync. Default: 60.
	 *
	 * [--show-packets]
	 * : Include full packets in run-sync output instead of summaries.
	 *
	 * [--input-file=<path>]
	 * : JSON packet input for run-sync, useful when exploring downstream steps.
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
	 * : Scheduling interval (manual, hourly, daily, one_time, etc.) or cron expression (e.g. "0 9 * * 1-5").
	 *
	 * [--scheduled-at=<datetime>]
	 * : ISO-8601 datetime for one-time scheduling (e.g. "2026-03-20T15:00:00Z"). Implies --scheduling=one_time.
	 *
	 * [--set-user-message=<text>]
	 * : Update the per-flow user message for an AI step. Stored as a 1-entry
	 *   static prompt_queue (the queue head fires every tick). For the pipeline-
	 *   wide system prompt shared across all flows, use `pipeline update
	 *   --set-system-prompt`.
	 *
	 * [--handler-config=<json>]
	 * : JSON object of config key-value pairs to update (deep-merged with existing config).
	 *   For handler-backed steps (fetch/publish), key the object by handler slug, e.g.
	 *   {"reddit":{"subreddit":"music"}}. For handler-free steps (system_task/dispatch),
	 *   nest settings under `params`, e.g. {"params":{"message":"..."}}. The flat shape
	 *   shown by `flows get` is also accepted and auto-wrapped into `params`. Partial
	 *   `params` updates are deep-merged, so sibling keys (channel/recipient) are preserved.
	 *   Resolves to the flow's sole handler step when --step is omitted.
	 *
	 * [--step=<flow_step_id>]
	 * : Target a specific flow step for prompt update or handler config update (auto-resolved if flow has exactly one handler step).
	 *
	 * [--patch=<json>]
	 * : JSON-encoded config patch object for fetch step queue operations (queue add/update subcommands).
	 *   The patch is deep-merged into the handler config when the fetch step runs.
	 *
	 * [--add=<filename>]
	 * : Attach a memory file to a flow (memory-files subcommand).
	 *
	 * [--remove=<filename>]
	 * : Detach a memory file from a flow (memory-files subcommand).
	 *
	 * [--post_type=<post_type>]
	 * : Post type to check against (validate subcommand). Default: 'post'.
	 *
	 * [--threshold=<threshold>]
	 * : Jaccard similarity threshold 0.0-1.0 (validate subcommand). Default: 0.65.
	 *
	 * [--dry-run]
	 * : Validate and preview without writing. For the create subcommand this
	 *   validates the new flow without creating it. For the update subcommand
	 *   it validates every requested change (name, scheduling, agent,
	 *   set-user-message, handler-config) and prints a "[dry-run] would update
	 *   ..." preview while making ZERO database writes.
	 *
	 * [--apply]
	 * : Repair missing schedules for reconcile-schedules. Without this flag the command is a dry-run.
	 *
	 * [--spread-hours=<hours>]
	 * : Explicit first-run distribution window for reconcile-schedules (1-24).
	 *
	 * [--pipeline=<id>]
	 * : Pipeline ID for pause/resume scoping.
	 *
 * [--agent=<slug_or_id>]
 * : Agent slug or ID. For update: set the flow's agent_id.
 *   For pause/resume/list: scope by agent.
 *   For reassign: see --from-agent / --to-agent instead.
 *
 * [--from-agent=<id>]
 * : Source agent ID for bulk reassign. Accepts raw numeric ID (need not exist in agents table).
 *   Mutually exclusive with --where-null.
 *
 * [--where-null]
 * : Target rows where agent_id IS NULL (reassign subcommand).
 *   Mutually exclusive with --from-agent.
 *
 * [--to-agent=<slug_or_id>]
 * : Destination agent slug or ID for bulk reassign. Must be a valid agent.
 *
	 * [--yes]
	 * : Skip confirmation prompt (delete/reassign subcommands).
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
	 *     # Run a flow inline for bounded local exploration
	 *     wp datamachine flows run-sync 42 --max-steps=20 --show-packets --format=json
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
	 *     # Update per-flow user message for an AI step (stored as a 1-entry static prompt_queue)
	 *     wp datamachine flows update 42 --set-user-message="New user message"
	 *
	 *     # Update a system_task / dispatch step's message (handler-free step).
	 *     # Settings live under a `params` wrapper; partial params are deep-merged
	 *     # so sibling keys (channel, recipient) survive. The flat shape shown by
	 *     # `flows get` is also accepted and auto-wrapped into params.
	 *     wp datamachine flows update 42 --step=<flow_step_id> \
	 *       --handler-config='{"params":{"message":"New message body"}}'
	 *
	 *     # Update a handler-backed (fetch/publish) step: key the JSON by handler slug
	 *     wp datamachine flows update 42 --step=<flow_step_id> \
	 *       --handler-config='{"reddit":{"subreddit":"music"}}'
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
	 *     # List memory files for a flow
	 *     wp datamachine flows memory-files 42
	 *
	 *     # Attach a memory file to a flow
	 *     wp datamachine flows memory-files 42 --add=content-briefing.md
	 *
	 *     # Detach a memory file from a flow
	 *     wp datamachine flows memory-files 42 --remove=content-briefing.md
	 *
	 *     # Pause a single flow
	 *     wp datamachine flows pause 42
	 *
	 *     # Pause all flows in a pipeline
	 *     wp datamachine flows pause --pipeline=12
	 *
	 *     # Pause all flows for an agent
	 *     wp datamachine flows pause --agent=my-agent
	 *
	 *     # Resume a single flow
	 *     wp datamachine flows resume 42
	 *
 *     # Resume all flows for an agent
 *     wp datamachine flows resume --agent=my-agent
 *
 *     # Update flow agent
 *     wp datamachine flows update 42 --agent=events-bot
 *
 *     # Bulk reassign: move orphan flows → events-bot
 *     wp datamachine flows reassign --where-null --to-agent=events-bot
 *
 *     # Bulk reassign: move flows from agent_id=1 → events-bot
 *     wp datamachine flows reassign --from-agent=1 --to-agent=events-bot
 *
 *     # Dry-run reassign
	 *     wp datamachine flows reassign --where-null --to-agent=events-bot --dry-run
	 *
	 *     # Audit or repair recurring schedule coverage
	 *     wp datamachine flows reconcile-schedules --format=json
	 *     wp datamachine flows reconcile-schedules --apply --spread-hours=24
 *
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$flow_id     = null;
		$pipeline_id = null;

		if ( ! empty( $args ) && 'reconcile-schedules' === $args[0] ) {
			$this->reconcileSchedules( $assoc_args );
			return;
		}

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

		// Delegate 'bulk-config' subcommand to BulkConfigCommand.
		if ( ! empty( $args ) && 'bulk-config' === $args[0] ) {
			$bulk_config = new BulkConfigCommand();
			$bulk_config->dispatch( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'memory-files' subcommand.
		if ( ! empty( $args ) && 'memory-files' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows memory-files <flow_id> [--add=<filename>] [--remove=<filename>]' );
				return;
			}
			$this->memoryFiles( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'pause' subcommand: `flows pause 42` or `flows pause --pipeline=12`.
		if ( ! empty( $args ) && 'pause' === $args[0] ) {
			$this->pauseFlows( array_slice( $args, 1 ), $assoc_args );
			return;
		}

		// Handle 'resume' subcommand: `flows resume 42` or `flows resume --pipeline=12`.
		if ( ! empty( $args ) && 'resume' === $args[0] ) {
			$this->resumeFlows( array_slice( $args, 1 ), $assoc_args );
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

		// Handle 'reassign' subcommand.
		if ( ! empty( $args ) && 'reassign' === $args[0] ) {
			$this->reassignFlows( $assoc_args );
			return;
		}

		// Handle 'orphans' subcommand.
		if ( ! empty( $args ) && 'orphans' === $args[0] ) {
			$this->listOrphanedFlows( $assoc_args );
			return;
		}

		// Handle 'update' subcommand: `flows update 42 --name="New Name"`.
		if ( ! empty( $args ) && 'update' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows update <flow_id> [--name=<name>] [--scheduling=<interval>] [--set-user-message=<text>] [--handler-config=<json>] [--step=<flow_step_id>]' );
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
		} elseif ( ! empty( $args ) && 'run-sync' === $args[0] ) {
			// Handle 'run-sync' subcommand: `flows run-sync 42`.
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows run-sync <flow_id> [--max-steps=N] [--max-items=N] [--timeout=N] [--show-packets] [--input-file=<path>] [--format=json]' );
				return;
			}
			$this->runFlowSync( (int) $args[1], $assoc_args );
			return;
		} elseif ( ! empty( $args ) && 'run' === $args[0] ) {
			// Handle 'run' subcommand: `flows run 42`.
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine flows run <flow_id> [--count=N] [--timestamp=T] [--[no-]drain]' );
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
		$per_page     = (int) ( $assoc_args['per_page'] ?? 0 );
		$offset       = (int) ( $assoc_args['offset'] ?? 0 );
		$format       = $assoc_args['format'] ?? 'table';

		if ( $per_page < 0 ) {
			$per_page = 0;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$scoping = AgentResolver::buildScopingInput( $assoc_args );
		$ability = wp_get_ability( 'datamachine/get-flows' );

		// Use 'list' mode for multi-flow views (skips expensive handler enrichment).
		// Use 'full' mode for single-flow detail views.
		$output_mode = $flow_id ? 'full' : 'list';

		$result = $ability->execute(
			array_merge(
				$scoping,
				array(
					'flow_id'      => $flow_id,
					'pipeline_id'  => $pipeline_id,
					'handler_slug' => $handler_slug,
					'per_page'     => $per_page,
					'offset'       => $offset,
					'output_mode'  => $output_mode,
				)
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

		// Single flow detail view: show full data including step configs.
		if ( $flow_id && 1 === count( $flows ) ) {
			$this->showFlowDetail( $flows[0], $format );
			return;
		}

		// Apply the human empty-cell placeholder ("—") ONLY for the table
		// display. Structured formats (json/csv/yaml/ids/count) must carry the
		// real value (an empty string when there's no config summary) — a
		// display dash in structured output is a silent data-integrity bug: it
		// reads as "this flow has config '—'" when the flow simply has none
		// (#2754).
		$is_table = 'table' === $format;

		// Transform flows to flat row format.
		$items = array_map(
			function ( $flow ) use ( $is_table ) {
				$config_summary = $this->extractConfigSummary( $flow );

				return array(
					'id'          => $flow['flow_id'],
					'name'        => $flow['flow_name'],
					'pipeline_id' => $flow['pipeline_id'],
					'handlers'    => $this->extractHandlers( $flow ),
					'config'      => ( $is_table && '' === $config_summary ) ? '—' : $config_summary,
					'schedule'    => $this->extractSchedule( $flow ),
					'max_items'   => $this->extractMaxItems( $flow ),
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
	 * Audit and optionally repair recurring flow schedule coverage.
	 *
	 * @param array $assoc_args WP-CLI arguments.
	 * @return void
	 */
	private function reconcileSchedules( array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( 'reconcile-schedules supports --format=table or --format=json.' );
			return;
		}

		$spread_hours = isset( $assoc_args['spread-hours'] ) ? (int) $assoc_args['spread-hours'] : null;
		if ( null !== $spread_hours && ( $spread_hours < 1 || $spread_hours > 24 ) ) {
			WP_CLI::error( '--spread-hours must be between 1 and 24.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/reconcile-flow-schedules' );
		$result  = $ability->execute(
			array(
				'apply'        => isset( $assoc_args['apply'] ),
				'spread_hours' => $spread_hours,
			)
		);

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( empty( $result['success'] ) || (int) ( $result['invalid'] ?? 0 ) > 0 ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		if ( empty( $result['success'] ) && isset( $result['error'] ) ) {
			WP_CLI::error( (string) $result['error'], false );
			WP_CLI::halt( 1 );
			return;
		}

		WP_CLI::log( ! empty( $result['applied'] ) ? 'Mode: apply' : 'Mode: dry-run' );
		WP_CLI::log(
			sprintf(
				'Eligible: %d | Covered: %d | Missing: %d | Blocked: %d | Invalid: %d | Repaired: %d | Failed: %d | Spread: %dh',
				(int) ( $result['eligible'] ?? 0 ),
				(int) ( $result['covered'] ?? 0 ),
				(int) ( $result['missing'] ?? 0 ),
				(int) ( $result['blocked'] ?? 0 ),
				(int) ( $result['invalid'] ?? 0 ),
				(int) ( $result['repaired'] ?? 0 ),
				(int) ( $result['failed'] ?? 0 ),
				(int) ( $result['distribution_window_hours'] ?? 1 )
			)
		);

		$details = $result['details'] ?? array();
		if ( ! empty( $details ) ) {
			WP_CLI\Utils\format_items( 'table', $details, array( 'flow_id', 'flow_name', 'interval', 'status', 'error' ) );
		}
		if ( ! empty( $result['details_truncated'] ) ) {
			WP_CLI::warning( 'Detail output was truncated; use summary counts to assess the full fleet.' );
		}
		if ( empty( $result['success'] ) || (int) ( $result['failed'] ?? 0 ) > 0 || (int) ( $result['invalid'] ?? 0 ) > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Run a flow synchronously for bounded local exploration.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args WP-CLI args.
	 */
	private function runFlowSync( int $flow_id, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'json' );
		$packet = ( new SyncRunner() )->runFlow( $flow_id, $this->build_sync_runner_options( $assoc_args ) );
		$this->output_sync_runner_packet( $packet, $format );
	}

	/**
	 * Show detailed view of a single flow including step configs.
	 *
	 * For JSON format: outputs the full flow data with flow_config intact.
	 * For table format: outputs key-value pairs followed by a step configs table.
	 *
	 * @param array  $flow   Full flow data from the get-flows ability.
	 * @param string $format Output format (table, json, csv, yaml).
	 */
	private function showFlowDetail( array $flow, string $format ): void {
		// Always surface prompt_queue / config_patch_queue + queue_mode
		// on queueable steps, even when unset, so every slot AIStep /
		// FetchStep reads at runtime is discoverable. Otherwise these
		// fields disappear from JSON output and the next reader can't
		// tell whether they're empty or looking at the wrong key — which
		// is exactly how the --set-prompt-writes-dead-key bug stayed
		// invisible.
		$flow = self::normalizeAiStepPromptSlots( $flow );

		// JSON/YAML: output the full flow data including flow_config.
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $flow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI\Utils\format_items( 'yaml', array( $flow ), array_keys( $flow ) );
			return;
		}

		// Table format: show flow summary, then step configs.
		$scheduling = $flow['scheduling_config'] ?? array();
		$interval   = $scheduling['interval'] ?? 'manual';

		$is_paused = isset( $scheduling['enabled'] ) && false === $scheduling['enabled'];

		WP_CLI::log( sprintf( 'Flow ID:      %d', $flow['flow_id'] ) );
		WP_CLI::log( sprintf( 'Name:         %s', $flow['flow_name'] ) );
		WP_CLI::log( sprintf( 'Pipeline ID:  %s', $flow['pipeline_id'] ?? 'N/A' ) );
		if ( 'cron' === $interval && ! empty( $scheduling['cron_expression'] ) ) {
			$cron_desc = \DataMachine\Engine\Tasks\RecurringScheduler::describeCronExpression( $scheduling['cron_expression'] );
			WP_CLI::log( sprintf( 'Scheduling:   cron (%s) — %s', $scheduling['cron_expression'], $cron_desc ) );
		} else {
			WP_CLI::log( sprintf( 'Scheduling:   %s', $interval ) );
		}
		if ( $is_paused ) {
			WP_CLI::log( 'Status:       PAUSED' );
		}
		WP_CLI::log( sprintf( 'Last run:     %s', $flow['last_run_display'] ?? 'Never' ) );
		WP_CLI::log( sprintf( 'Next run:     %s', $flow['next_run_display'] ?? 'Not scheduled' ) );
		WP_CLI::log( sprintf( 'Running:      %s', ( $flow['is_running'] ?? false ) ? 'Yes' : 'No' ) );
		WP_CLI::log( '' );

		// Step configs section.
		$config = $flow['flow_config'] ?? array();

		if ( empty( $config ) ) {
			WP_CLI::log( 'Steps: (none)' );
			return;
		}

		// Show memory files if attached.
		$memory_files = $config['memory_files'] ?? array();
		if ( ! empty( $memory_files ) ) {
			WP_CLI::log( sprintf( 'Memory files: %s', implode( ', ', $memory_files ) ) );
			WP_CLI::log( '' );
		}

		$rows = array();
		foreach ( $config as $step_id => $step_data ) {
			// Skip flow-level metadata keys — only display step configs.
			if ( ! is_array( $step_data ) || ! isset( $step_data['step_type'] ) ) {
				continue;
			}

			$step_type = (string) $step_data['step_type'];
			$order     = $step_data['execution_order'] ?? '';
			$slugs     = FlowStepConfig::getConfiguredHandlerSlugs( $step_data );
			$configs   = FlowStepConfig::getHandlerConfigs( $step_data );

			// Show pipeline-level prompt if set.
			$pipeline_prompt = $step_data['pipeline_config']['prompt'] ?? '';

			if ( empty( $slugs ) ) {
				// Step with no handlers (e.g. AI or system_task).
				$config_parts = array();
				foreach ( FlowStepConfig::getPrimaryHandlerConfig( $step_data ) as $key => $value ) {
					$config_parts[] = $key . '=' . $this->formatConfigValue( $value );
				}

				if ( $pipeline_prompt ) {
					$config_parts[] = 'system_prompt=' . $this->truncateValue( $pipeline_prompt, 60 );
				}

				if ( 'ai' === $step_type ) {
					// AI steps consume the prompt_queue slot under one of
					// three modes (drain | loop | static). Surface the
					// queue depth, mode, and the active prompt label so
					// the resolved per-flow user message is never invisible.
					$resolved = self::resolveAiStepActivePrompt( $step_data );

					$config_parts[] = sprintf(
						'queue=%d item(s), queue_mode=%s',
						$resolved['queue_depth'],
						$resolved['queue_mode']
					);

					$config_parts[] = 'active_prompt=' . self::formatActivePromptLabel( $resolved );
				}

				$rows[] = array(
					'step_id'   => $step_id,
					'order'     => $order,
					'step_type' => $step_type,
					'handler'   => '—',
					'config'    => empty( $config_parts ) ? '(default)' : implode( ', ', $config_parts ),
				);
				continue;
			}

			foreach ( $slugs as $slug ) {
				$handler_config = $configs[ $slug ] ?? array();
				$config_parts   = array();

				foreach ( $handler_config as $key => $value ) {
					$config_parts[] = $key . '=' . $this->formatConfigValue( $value );
				}

				// Fetch steps surface config_patch_queue depth + queue_mode
				// alongside their static handler config (#1291 / #1292).
				if ( 'fetch' === $step_type ) {
					$patch_queue = $step_data['config_patch_queue'] ?? array();
					$queue_depth = is_array( $patch_queue ) ? count( $patch_queue ) : 0;
					$queue_mode  = $step_data['queue_mode'] ?? 'static';
					if ( $queue_depth > 0 || 'static' !== $queue_mode ) {
						$config_parts[] = sprintf(
							'config_patch_queue=%d item(s), queue_mode=%s',
							$queue_depth,
							$queue_mode
						);
					}
				}

				$rows[] = array(
					'step_id'   => $step_id,
					'order'     => $order,
					'step_type' => $step_type,
					'handler'   => $slug,
					'config'    => implode( ', ', $config_parts ) ? implode( ', ', $config_parts ) : '(default)',
				);
			}
		}

		WP_CLI::log( 'Steps:' );

		$step_fields = array( 'step_id', 'order', 'step_type', 'handler', 'config' );
		WP_CLI\Utils\format_items( 'table', $rows, $step_fields );
	}

	/**
	 * Truncate a display value to a maximum length.
	 *
	 * @param string $value Value to truncate.
	 * @param int    $max   Maximum characters.
	 * @return string Truncated value.
	 */
	private function truncateValue( string $value, int $max = 40 ): string {
		$value = str_replace( array( "\n", "\r" ), ' ', $value );
		if ( mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 3 ) . '...';
		}
		return $value;
	}

	/**
	 * Ensure every queueable step in a flow exposes its queue slots.
	 *
	 * Post-#1291 collapse: AI and Fetch steps each consume one slot
	 * (`prompt_queue` for AI, `config_patch_queue` for Fetch) and share
	 * a single `queue_mode` enum. Default the slots to empty arrays and
	 * `queue_mode` to "static" so the keys are visible on `flow get`
	 * output even when never set, preserving the discoverability that
	 * caught the --set-prompt dead-key bug originally.
	 *
	 * @param array $flow Flow data with flow_config.
	 * @return array Flow data with queue slots normalized.
	 */
	private static function normalizeAiStepPromptSlots( array $flow ): array {
		if ( empty( $flow['flow_config'] ) || ! is_array( $flow['flow_config'] ) ) {
			return $flow;
		}

		foreach ( $flow['flow_config'] as $step_id => $step_data ) {
			if ( ! is_array( $step_data ) ) {
				continue;
			}
			$step_type = $step_data['step_type'] ?? '';

			if ( 'ai' === $step_type ) {
				if ( ! array_key_exists( 'prompt_queue', $step_data ) ) {
					$flow['flow_config'][ $step_id ]['prompt_queue'] = array();
				}
				if ( ! array_key_exists( 'queue_mode', $step_data ) ) {
					$flow['flow_config'][ $step_id ]['queue_mode'] = 'static';
				}
				continue;
			}

			if ( 'fetch' === $step_type ) {
				if ( ! array_key_exists( 'config_patch_queue', $step_data ) ) {
					$flow['flow_config'][ $step_id ]['config_patch_queue'] = array();
				}
				if ( ! array_key_exists( 'queue_mode', $step_data ) ) {
					$flow['flow_config'][ $step_id ]['queue_mode'] = 'static';
				}
			}
		}

		return $flow;
	}

	/**
	 * Resolve the active prompt slot for an AI step at runtime.
	 *
	 * Post-#1291 there is one slot — `prompt_queue` — and the access
	 * mode picks how the head is consumed. Reading the active prompt
	 * is just "look up queue_mode, peek the head".
	 *
	 * @param array $step_data AI step data from flow_config.
	 * @return array{slot:string, value:string, queue_depth:int, queue_mode:string}
	 *               slot is one of: 'queue_head', 'none'.
	 */
	private static function resolveAiStepActivePrompt( array $step_data ): array {
		$queue_mode   = $step_data['queue_mode'] ?? 'static';
		$prompt_queue = $step_data['prompt_queue'] ?? array();
		$queue_depth  = is_array( $prompt_queue ) ? count( $prompt_queue ) : 0;
		$queue_head   = is_array( $prompt_queue ) ? trim( (string) ( $prompt_queue[0]['prompt'] ?? '' ) ) : '';

		if ( '' !== $queue_head ) {
			return array(
				'slot'        => 'queue_head',
				'value'       => $queue_head,
				'queue_depth' => $queue_depth,
				'queue_mode'  => $queue_mode,
			);
		}

		return array(
			'slot'        => 'none',
			'value'       => '',
			'queue_depth' => $queue_depth,
			'queue_mode'  => $queue_mode,
		);
	}

	/**
	 * Render a short label for the slot AIStep will read at runtime.
	 *
	 * Output examples:
	 *   - "queue_head[1/3] (drain): \"Generate Q3 brief...\""
	 *   - "queue_head[1/1] (static): \"Single per-flow message...\""
	 *   - "queue_head[1/5] (loop): \"Cycling source A...\""
	 *   - "(none)"
	 *
	 * @param array{slot:string, value:string, queue_depth:int, queue_mode:string} $resolved
	 * @return string Formatted label suitable for table output.
	 */
	private static function formatActivePromptLabel( array $resolved ): string {
		switch ( $resolved['slot'] ) {
			case 'queue_head':
				return sprintf(
					'queue_head[1/%d] (%s): "%s"',
					$resolved['queue_depth'],
					$resolved['queue_mode'],
					self::truncateForLabel( $resolved['value'], 50 )
				);

			default:
				return '(none)';
		}
	}

	/**
	 * Static helper for truncating values inside formatActivePromptLabel.
	 *
	 * Mirrors truncateValue() but is static so resolveAiStepActivePrompt's
	 * companion formatter can stay static (callable from contexts without
	 * a FlowsCommand instance, e.g. tests).
	 *
	 * @param string $value Value to truncate.
	 * @param int    $max   Maximum characters.
	 * @return string Truncated value.
	 */
	private static function truncateForLabel( string $value, int $max = 40 ): string {
		$value = str_replace( array( "\n", "\r" ), ' ', $value );
		if ( mb_strlen( $value ) > $max ) {
			return mb_substr( $value, 0, $max - 3 ) . '...';
		}
		return $value;
	}

	/**
	 * Format a config value for display in the step configs table.
	 *
	 * @param mixed $value Config value.
	 * @return string Formatted value.
	 */
	private function formatConfigValue( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		$str = (string) $value;
		return $this->truncateValue( $str );
	}

	/**
	 * Create a new flow.
	 *
	 * @param array $assoc_args Associative arguments (pipeline_id, name, step_configs, scheduling, dry-run).
	 */
	private function createFlow( array $assoc_args ): void {
		$pipeline_id  = isset( $assoc_args['pipeline_id'] ) ? (int) $assoc_args['pipeline_id'] : null;
		$flow_name    = $assoc_args['name'] ?? null;
		$scheduling   = $assoc_args['scheduling'] ?? 'manual';
		$scheduled_at = $assoc_args['scheduled-at'] ?? null;
		$dry_run      = isset( $assoc_args['dry-run'] );
		$format       = $assoc_args['format'] ?? 'table';

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

		// Convert --handler-config to step_configs entries.
		// --handler-config accepts handler-keyed JSON, e.g. {"reddit":{"subreddit":"test"}}.
		// Each handler slug is resolved to its step type and merged into step_configs.
		if ( isset( $assoc_args['handler-config'] ) ) {
			$handler_config_input = json_decode( wp_unslash( $assoc_args['handler-config'] ), true );
			if ( ! is_array( $handler_config_input ) ) {
				WP_CLI::error( 'Invalid JSON in --handler-config. Must be a JSON object.' );
				return;
			}

			$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();
			$all_handlers      = $handler_abilities->getAllHandlers();

			foreach ( $handler_config_input as $handler_slug => $config ) {
				if ( ! isset( $all_handlers[ $handler_slug ] ) ) {
					WP_CLI::error( "Unknown handler '{$handler_slug}'. Use --handler-config with valid handler slugs." );
					return;
				}

				$step_type = $all_handlers[ $handler_slug ]['type'] ?? '';
				if ( empty( $step_type ) ) {
					WP_CLI::error( "Cannot determine step type for handler '{$handler_slug}'." );
					return;
				}

				$step_configs[ $step_type ] = array(
					'handler_slug'   => $handler_slug,
					'handler_config' => $config,
				);
			}
		}

		$scheduling_config = self::build_scheduling_config( $scheduling, $scheduled_at );

		$input = array(
			'pipeline_id'       => $pipeline_id,
			'flow_name'         => $flow_name,
			'scheduling_config' => $scheduling_config,
			'step_configs'      => $step_configs,
		);

		if ( $dry_run ) {
			$input['validate_only'] = true;
			$input['flows']         = array(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
					'step_configs'      => $step_configs,
				),
			);
		}

		$ability = wp_get_ability( 'datamachine/create-flow' );
		$result  = $ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create flow' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Validation passed.' );
			if ( isset( $result['would_create'] ) && 'json' === $format ) {
				WP_CLI::line( (string) wp_json_encode( $result['would_create'], JSON_PRETTY_PRINT ) );
			} elseif ( isset( $result['would_create'] ) ) {
				foreach ( $result['would_create'] as $preview ) {
					WP_CLI::log(
						sprintf(
							'Would create: "%s" on pipeline %d (scheduling: %s)',
							$preview['flow_name'],
							$preview['pipeline_id'],
							$preview['scheduling']
						)
					);
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
			WP_CLI::line( (string) wp_json_encode( $result['flow_data'], JSON_PRETTY_PRINT ) );
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
		$drain     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'drain', true );

		// Validate count range (1-10).
		if ( $count < 1 || $count > 10 ) {
			WP_CLI::error( 'Count must be between 1 and 10.' );
			return;
		}

		// Delayed execution → schedule-flow ability.
		if ( $timestamp && $timestamp > time() ) {
			if ( $count > 1 ) {
				WP_CLI::error( 'Cannot schedule multiple runs with a timestamp.' );
				return;
			}

			$ability = wp_get_ability( 'datamachine/schedule-flow' );
			if ( ! $ability ) {
				WP_CLI::error( 'Schedule flow ability not registered.' );
				return;
			}

			$result = $ability->execute(
				array(
					'flow_id'               => $flow_id,
					'interval_or_timestamp' => $timestamp,
				)
			);

			if ( ! ( $result['success'] ?? false ) ) {
				WP_CLI::error( $result['error'] ?? 'Failed to schedule flow' );
				return;
			}

			WP_CLI::success( sprintf( 'Flow %d scheduled for %s.', $flow_id, $result['scheduled_time'] ?? 'later' ) );
			return;
		}

		// Immediate execution → run-flow ability (loop for count).
		$ability = wp_get_ability( 'datamachine/run-flow' );
		if ( ! $ability ) {
			WP_CLI::error( 'Run flow ability not registered.' );
			return;
		}

		$job_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$result = $ability->execute( array( 'flow_id' => $flow_id ) );

			if ( ! ( $result['success'] ?? false ) ) {
				if ( empty( $job_ids ) ) {
					WP_CLI::error( $result['error'] ?? 'Failed to run flow' );
					return;
				}
				WP_CLI::warning( sprintf( 'Run %d/%d failed: %s', $i + 1, $count, $result['error'] ?? 'unknown' ) );
				break;
			}

			$job_ids[] = $result['job_id'] ?? null;
		}

		if ( 1 === $count ) {
			WP_CLI::success( sprintf( 'Flow %d execution started.', $flow_id ) );
			if ( ! empty( $job_ids[0] ) ) {
				WP_CLI::log( sprintf( 'Job ID: %d', $job_ids[0] ) );
			}
		} else {
			WP_CLI::success( sprintf( 'Flow %d: %d/%d runs started.', $flow_id, count( $job_ids ), $count ) );
			WP_CLI::log( sprintf( 'Job IDs: %s', implode( ', ', array_filter( $job_ids ) ) ) );
		}

		if ( $drain ) {
			$stats = DrainCommand::drain(
				array(
					'hooks' => array(
						DrainCommand::HOOK_BATCH_CHUNK,
						DrainCommand::HOOK_EXECUTE_STEP,
					),
				)
			);
			WP_CLI::log(
				sprintf(
					'Drained Data Machine actions: %d batch chunks, %d step executions, %d completions, %d failures, %d due pending remain.',
					$stats['batch_chunks'],
					$stats['step_executions'],
					$stats['completions'],
					$stats['failures'],
					$stats['remaining_pending']
				)
			);
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

		$ability = wp_get_ability( 'datamachine/delete-flow' );
		$result  = $ability->execute( array( 'flow_id' => $flow_id ) );

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
	 * Update a flow's name, scheduling, agent, user message, or handler config.
	 *
	 * Honors --dry-run on every write sub-path: when set, each requested change
	 * is validated and previewed ("[dry-run] would update ...") but NO database
	 * write occurs. Validation (bad JSON, unknown handler, step resolution)
	 * still runs in dry-run mode, so it is a full validate + preview.
	 *
	 * @param int   $flow_id    Flow ID to update.
	 * @param array $assoc_args Associative arguments (--name, --scheduling, --agent, --set-user-message, --handler-config, --dry-run).
	 */
	private function updateFlow( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		// Clean break: the old --set-prompt flag silently wrote to a dead
		// key (handler_configs.ai.prompt) that AIStep never reads. There is
		// no working consumer to migrate. Hard-fail with a pointer to the
		// correct flag rather than alias.
		if ( isset( $assoc_args['set-prompt'] ) ) {
			WP_CLI::error( '--set-prompt has been removed. Use --set-user-message=<text> for AI step per-flow context, or `pipeline update --set-system-prompt` for the shared pipeline system prompt.' );
			return;
		}

		$dry_run      = isset( $assoc_args['dry-run'] );
		$name         = $assoc_args['name'] ?? null;
		$scheduling   = $assoc_args['scheduling'] ?? null;
		$scheduled_at = $assoc_args['scheduled-at'] ?? null;
		$has_agent    = isset( $assoc_args['agent'] );
		$user_message = isset( $assoc_args['set-user-message'] )
			? wp_kses_post( wp_unslash( $assoc_args['set-user-message'] ) )
			: null;
		$step         = $assoc_args['step'] ?? null;

		// Track whether --handler-config was *passed* separately from whether it
		// decoded to a usable object. A provided-but-malformed flag must report
		// the real reason (bad JSON / wrong shape) instead of falling through to
		// the generic "no flag provided" guard below, which sent a user down a
		// wrong diagnosis when the JSON shape was the actual problem (#2673).
		$has_handler_config = isset( $assoc_args['handler-config'] );
		$handler_config     = null;
		if ( $has_handler_config ) {
			$handler_config = json_decode( wp_unslash( $assoc_args['handler-config'] ), true );
			if ( ! is_array( $handler_config ) ) {
				WP_CLI::error(
					'Invalid JSON in --handler-config. Must be a JSON object, e.g. '
					. "--handler-config='{\"params\":{\"message\":\"...\"}}' for a system_task/dispatch step, "
					. "or --handler-config='{\"<handler_slug>\":{\"<field>\":\"<value>\"}}' for a fetch/publish handler."
				);
				return;
			}
		}

		// --scheduled-at implies --scheduling=one_time.
		if ( $scheduled_at && null === $scheduling ) {
			$scheduling = 'one_time';
		}

		if ( null === $name && null === $scheduling && null === $user_message && ! $has_handler_config && ! $has_agent ) {
			WP_CLI::error( 'Must provide --name, --scheduling, --set-user-message, --scheduled-at, --handler-config, or --agent to update' );
			return;
		}

		// Update agent_id if --agent provided.
		if ( $has_agent ) {
			$new_agent_id = AgentResolver::resolve( $assoc_args );
			if ( null === $new_agent_id ) {
				WP_CLI::error( 'Could not resolve --agent to a valid agent.' );
				return;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] would set flow %d agent to agent_id=%d; no changes written', $flow_id, $new_agent_id ) );
			} else {
				$flows_repo = new \DataMachine\Core\Database\Flows\Flows();
				$success    = $flows_repo->update_flow( $flow_id, array( 'agent_id' => $new_agent_id ) );

				if ( ! $success ) {
					WP_CLI::error( 'Failed to update flow agent_id.' );
					return;
				}

				WP_CLI::success( sprintf( 'Flow %d agent set to agent_id=%d.', $flow_id, $new_agent_id ) );
			}
		}

		// Validate step resolution BEFORE any writes (atomic: fail fast, change nothing).
		// AI prompt updates target AI steps; handler config updates target handler-backed steps.
		$message_step = $step;
		$handler_step = $step;

		if ( null !== $user_message && null === $message_step ) {
			$resolved = $this->resolveAiStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$message_step = $resolved['step_id'];
		}

		if ( $has_handler_config && null === $handler_step ) {
			$resolved = $this->resolveHandlerStep( $flow_id );
			if ( ! empty( $resolved['error'] ) ) {
				WP_CLI::error( $resolved['error'] );
				return;
			}
			$handler_step = $resolved['step_id'];
		}

		// Phase 1: Flow-level updates (name, scheduling).
		$input = array( 'flow_id' => $flow_id );

		if ( null !== $name ) {
			$input['flow_name'] = $name;
		}

		if ( null !== $scheduling ) {
			$input['scheduling_config'] = self::build_scheduling_config( $scheduling, $scheduled_at );
		}

		if ( null !== $name || null !== $scheduling ) {
			if ( $dry_run ) {
				$preview = array();
				if ( null !== $name ) {
					$preview[] = sprintf( 'flow_name → "%s"', $name );
				}
				if ( null !== $scheduling ) {
					$preview[] = sprintf( 'scheduling → %s', $scheduling );
				}
				WP_CLI::log( sprintf( '[dry-run] would update flow %d: %s; no changes written', $flow_id, implode( ', ', $preview ) ) );
			} else {
				$ability = wp_get_ability( 'datamachine/update-flow' );
				$result  = $ability->execute( $input );

				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}

				if ( ! $result['success'] ) {
					WP_CLI::error( $result['error'] ?? 'Failed to update flow' );
					return;
				}

				WP_CLI::success( sprintf( 'Flow %d updated.', $flow_id ) );
				WP_CLI::log( sprintf( 'Name: %s', $result['flow_name'] ?? '' ) );

				$sched = $result['flow_data']['scheduling_config'] ?? array();
				if ( 'cron' === ( $sched['interval'] ?? '' ) && ! empty( $sched['cron_expression'] ) ) {
					WP_CLI::log( sprintf( 'Scheduling: cron (%s)', $sched['cron_expression'] ) );
				} elseif ( isset( $sched['interval'] ) ) {
					WP_CLI::log( sprintf( 'Scheduling: %s', $sched['interval'] ) );
				}
			}
		}

		// Phase 2: Step-level updates (user_message, handler config).
		if ( null !== $user_message ) {
			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] would update user_message for step %s (%d chars); no changes written', $message_step, mb_strlen( $user_message ) ) );
			} else {
				$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
				$step_result  = $step_ability->execute(
					array(
						'flow_step_id' => $message_step,
						'user_message' => $user_message,
					)
				);

				if ( ! $step_result['success'] ) {
					WP_CLI::error( $step_result['error'] ?? 'Failed to update user_message' );
					return;
				}

				WP_CLI::success( 'User message updated for step: ' . $message_step );
			}
		}

		if ( $has_handler_config ) {
			$step_config      = $this->loadFlowStepConfig( $flow_id, (string) $handler_step );
			$handler_slug     = null;
			$unwrapped_config = $handler_config;

			if ( null !== $step_config && ! FlowStepConfig::usesHandler( $step_config ) ) {
				// Handler-free step (e.g. system_task / dispatch). Its valid
				// settings fields are a fixed set (task_type, params), so we do
				// NOT treat a single wrapping key as a handler slug — that
				// heuristic is for fetch/publish handlers and would corrupt a
				// `{"params":{...}}` payload by flattening away the params
				// wrapper. Instead, accept the flat displayed shape from
				// `flows get` (top-level message/channel/recipient) by
				// auto-wrapping any non-reserved top-level keys into `params`,
				// so input matches output (#2673).
				$unwrapped_config = $this->normalizeHandlerFreeConfig( $handler_step, $handler_config );
			} else {
				// Handler-backed step: --handler-config accepts handler-keyed
				// JSON, e.g. {"reddit":{"subreddit":"test"}}. Unwrap: the single
				// top-level key is the handler slug, the value is its config.
				$handler_config_keys = array_keys( $handler_config );
				if ( count( $handler_config_keys ) === 1 && is_array( $handler_config[ $handler_config_keys[0] ] ) ) {
					$handler_slug     = $handler_config_keys[0];
					$unwrapped_config = $handler_config[ $handler_slug ];
				}
			}

			$step_input = array(
				'flow_step_id'   => $handler_step,
				'handler_config' => $unwrapped_config,
			);

			if ( $handler_slug ) {
				$step_input['handler_slug'] = $handler_slug;
			}

			$updated_keys = implode( ', ', array_keys( $unwrapped_config ) );

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '[dry-run] would update handler_config for step %s: %s; no changes written', $handler_step, $updated_keys ) );
			} else {
				$step_ability = new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
				$step_result  = $step_ability->execute( $step_input );

				if ( ! $step_result['success'] ) {
					WP_CLI::error( $step_result['error'] ?? 'Failed to update handler config' );
					return;
				}

				WP_CLI::success( sprintf( 'Handler config updated for step %s: %s', $handler_step, $updated_keys ) );
			}
		}
	}

	/**
	 * Load a single flow step's config array from storage.
	 *
	 * @param int    $flow_id      Flow ID.
	 * @param string $flow_step_id Flow step ID.
	 * @return array|null Step config, or null when not found.
	 */
	private function loadFlowStepConfig( int $flow_id, string $flow_step_id ): ?array {
		if ( '' === $flow_step_id ) {
			return null;
		}

		$flow = ( new \DataMachine\Core\Database\Flows\Flows() )->get_flow( $flow_id );
		if ( ! $flow ) {
			return null;
		}

		$flow_config = $flow['flow_config'] ?? array();
		$step        = $flow_config[ $flow_step_id ] ?? null;

		return is_array( $step ) ? $step : null;
	}

	/**
	 * Normalize a handler-free step's --handler-config payload.
	 *
	 * Handler-free steps (system_task / dispatch) store their settings under a
	 * fixed field set — for system_task that's `task_type` and `params`. The
	 * `flows get` output shows the live values flat under `params`, so callers
	 * intuitively pass the same flat keys back. This wraps any top-level key
	 * that is NOT a reserved settings field into `params`, so both the flat
	 * displayed shape and the explicit `{"params":{...}}` shape are accepted and
	 * land in the same place (#2673). Reserved fields (`task_type`, an explicit
	 * `params` object) pass through untouched.
	 *
	 * @param string $flow_step_id Flow step ID (used only to resolve the slug).
	 * @param array  $config       Raw --handler-config payload.
	 * @return array Normalized config keyed by valid settings fields.
	 */
	private function normalizeHandlerFreeConfig( string $flow_step_id, array $config ): array {
		// Resolve the settings slug for this handler-free step (its step_type).
		$parts   = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		$flow_id = is_array( $parts ) ? (int) ( $parts['flow_id'] ?? 0 ) : 0;
		$step    = $flow_id ? $this->loadFlowStepConfig( $flow_id, $flow_step_id ) : null;
		$slug    = is_array( $step ) ? FlowStepConfig::getEffectiveSlug( $step ) : '';

		$valid_fields = $slug
			? array_keys( ( new \DataMachine\Abilities\HandlerAbilities() )->getConfigFields( $slug ) )
			: array();

		// No schema to map against — pass the payload through unchanged.
		if ( empty( $valid_fields ) ) {
			return $config;
		}

		$normalized = array();
		$params     = array();

		if ( isset( $config['params'] ) && is_array( $config['params'] ) ) {
			$params = $config['params'];
		}

		foreach ( $config as $key => $value ) {
			if ( 'params' === $key ) {
				continue; // Folded in above.
			}
			if ( in_array( $key, $valid_fields, true ) ) {
				$normalized[ $key ] = $value; // Reserved field (e.g. task_type).
				continue;
			}
			// Non-reserved top-level key → fold into params (flat shape support).
			$params[ $key ] = $value;
		}

		if ( ! empty( $params ) || array_key_exists( 'params', $config ) ) {
			$normalized['params'] = $params;
		}

		return $normalized;
	}

	/**
	 * Resolve the AI step for a flow when --step is not provided.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{step_id: string|null, error: string|null}
	 */
	private function resolveAiStep( int $flow_id ): array {
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

		$ai_steps     = array();
		$non_ai_steps = array();
		foreach ( $flow_config as $step_id => $step_data ) {
			if ( ! is_array( $step_data ) || ! isset( $step_data['step_type'] ) ) {
				continue;
			}
			if ( 'ai' === $step_data['step_type'] ) {
				$ai_steps[] = $step_id;
			} else {
				$non_ai_steps[ $step_id ] = $step_data;
			}
		}

		if ( empty( $ai_steps ) ) {
			// --set-user-message only targets AI steps. When the flow has none,
			// point the user at the path that DOES update a non-AI step's
			// message instead of the bare "Flow has no AI steps" dead end
			// (#2673). For a single system_task/dispatch step we name the exact
			// command; otherwise we describe the general --handler-config form.
			return array(
				'step_id' => null,
				'error'   => self::describeNoAiStepError( $non_ai_steps ),
			);
		}

		if ( count( $ai_steps ) > 1 ) {
			return array(
				'step_id' => null,
				'error'   => sprintf(
					'Flow has multiple AI steps. Use --step=<id> to specify. Available: %s',
					implode( ', ', $ai_steps )
				),
			);
		}

		return array(
			'step_id' => $ai_steps[0],
			'error'   => null,
		);
	}

	/**
	 * Build a helpful error for --set-user-message when a flow has no AI step.
	 *
	 * --set-user-message only writes to an AI step's prompt_queue. Non-AI steps
	 * (system_task / dispatch / handler-backed) carry their "message" inside
	 * their own config, so the correct path is --handler-config. This produces a
	 * pointer to that path — naming the exact command for a single system_task
	 * step — instead of the bare "Flow has no AI steps" dead end (#2673).
	 *
	 * @param array<string, array> $non_ai_steps Non-AI steps keyed by flow_step_id.
	 * @return string Error message with a remediation pointer.
	 */
	private static function describeNoAiStepError( array $non_ai_steps ): string {
		if ( empty( $non_ai_steps ) ) {
			return '--set-user-message targets an AI step, but this flow has no AI steps.';
		}

		// Single non-AI step: name the exact --handler-config command for it.
		if ( 1 === count( $non_ai_steps ) ) {
			$step_id   = (string) array_key_first( $non_ai_steps );
			$step_data = $non_ai_steps[ $step_id ];
			$step_type = (string) ( $step_data['step_type'] ?? 'non-AI' );
			$task_type = '';
			$settings  = FlowStepConfig::getPrimaryHandlerConfig( $step_data );
			if ( isset( $settings['task_type'] ) && is_string( $settings['task_type'] ) ) {
				$task_type = $settings['task_type'];
			}

			$descriptor = '' !== $task_type
				? sprintf( '"%s" %s', $task_type, $step_type )
				: sprintf( '"%s"', $step_type );

			return sprintf(
				'--set-user-message only updates AI steps. This step is a %s step; update its message with '
				. "--handler-config '{\"params\":{\"message\":\"...\"}}' --step=%s "
				. '(partial params are deep-merged, so sibling keys like channel/recipient are preserved).',
				$descriptor,
				$step_id
			);
		}

		// Multiple non-AI steps: describe the general path + list candidates.
		return sprintf(
			'--set-user-message only updates AI steps, and this flow has none. To update a non-AI step\'s message use '
			. "--handler-config '{\"params\":{\"message\":\"...\"}}' --step=<flow_step_id>. Available steps: %s",
			implode( ', ', array_keys( $non_ai_steps ) )
		);
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
			$handlers = array_merge( $handlers, FlowStepConfig::getConfiguredHandlerSlugs( $step_data ) );
		}

		return implode( ', ', array_unique( $handlers ) );
	}

	/**
	 * Extract a concise config summary from flow handler configs.
	 *
	 * Domain-agnostic: reads raw config values without assuming any
	 * specific taxonomy, handler, or post type. Surfaces distinguishing
	 * values like coordinates, city names, URLs, and taxonomy selections.
	 *
	 * Returns the honest summary string — an EMPTY string when the flow has
	 * no distinguishing config — so the value is safe for every output format
	 * (json/csv/yaml). The human "—" empty-cell placeholder is a table-display
	 * concern and is applied only when building table rows, never here (#2754).
	 *
	 * @param array $flow Flow data.
	 * @return string Config summary (max ~60 chars), or '' when empty.
	 */
	private function extractConfigSummary( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$parts       = array();

		foreach ( $flow_config as $step_data ) {
			$handler_configs = FlowStepConfig::getHandlerConfigs( $step_data );

			foreach ( $handler_configs as $hconfig ) {
				// Coordinates (location field with lat,lon).
				if ( ! empty( $hconfig['location'] ) && strpos( $hconfig['location'], ',' ) !== false ) {
					$loc     = $hconfig['location'];
					$rad     = $hconfig['radius'] ?? '';
					$parts[] = $loc . ( $rad ? " r={$rad}" : '' );
				}

				// City name.
				if ( ! empty( $hconfig['city'] ) ) {
					$parts[] = "city={$hconfig['city']}";
				}

				// Source URL — show domain only.
				if ( ! empty( $hconfig['source_url'] ) ) {
					$host    = wp_parse_url( $hconfig['source_url'], PHP_URL_HOST );
					$parts[] = $host ? $host : $hconfig['source_url'];
				}

				// Venue/source name.
				if ( ! empty( $hconfig['venue_name'] ) ) {
					$parts[] = $hconfig['venue_name'];
				}

				// Feed URL — show domain only.
				$feed_url = $hconfig['feed_url'] ?? $hconfig['url'] ?? '';
				if ( $feed_url && empty( $hconfig['source_url'] ) ) {
					$host    = wp_parse_url( $feed_url, PHP_URL_HOST );
					$parts[] = $host ? $host : $feed_url;
				}

				// Taxonomy term selections (any taxonomy_*_selection key).
				foreach ( $hconfig as $key => $val ) {
					if ( strpos( $key, 'taxonomy_' ) === 0 && strpos( $key, '_selection' ) !== false ) {
						if ( ! empty( $val ) && 'skip' !== $val && 'ai_decides' !== $val ) {
							$tax_name = str_replace( array( 'taxonomy_', '_selection' ), '', $key );
							$parts[]  = "{$tax_name}={$val}";
						}
					}
				}
			}
		}

		$summary = implode( ' | ', array_unique( $parts ) );

		if ( mb_strlen( $summary ) > 60 ) {
			$summary = mb_substr( $summary, 0, 57 ) . '...';
		}

		return $summary;
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
			$primary_config = FlowStepConfig::getPrimaryHandlerConfig( $step_data );
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
	 * Extract scheduling summary from flow scheduling config.
	 *
	 * @param array $flow Flow data.
	 * @return string Scheduling summary for list view.
	 */
	private function extractSchedule( array $flow ): string {
		$scheduling_config = $flow['scheduling_config'] ?? array();
		$interval          = $scheduling_config['interval'] ?? 'manual';
		$is_paused         = isset( $scheduling_config['enabled'] ) && false === $scheduling_config['enabled'];

		$label = $interval;
		if ( 'cron' === $interval && ! empty( $scheduling_config['cron_expression'] ) ) {
			$label = 'cron:' . $scheduling_config['cron_expression'];
		}

		if ( $is_paused ) {
			$label .= ' (paused)';
		}

		return $label;
	}

	/**
	 * Extract max_items values from handler configs in a flow.
	 *
	 * @param array $flow Flow data.
	 * @return string Comma-separated handler=max_items pairs, or empty string.
	 */
	private function extractMaxItems( array $flow ): string {
		$flow_config = $flow['flow_config'] ?? array();
		$pairs       = array();

		foreach ( $flow_config as $step_data ) {
			if ( ! is_array( $step_data ) ) {
				continue;
			}

			$handler_configs = FlowStepConfig::getHandlerConfigs( $step_data );

			foreach ( $handler_configs as $handler_slug => $handler_config ) {
				if ( ! array_key_exists( 'max_items', $handler_config ) ) {
					continue;
				}

				$pairs[] = $handler_slug . '=' . (string) $handler_config['max_items'];
			}
		}

		$pairs = array_values( array_unique( $pairs ) );

		return implode( ', ', $pairs );
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

		$result = ( new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility() )->execute( $input );

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

		$result = ( new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility() )->execute(
			array(
				'flow_step_id'   => $step_id,
				'remove_handler' => $handler_slug,
			)
		);

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
			// Skip flow-level metadata keys.
			if ( ! is_array( $step ) || ! isset( $step['step_type'] ) ) {
				continue;
			}

			if ( $step_id && $sid !== $step_id ) {
				continue;
			}

			$step_type = (string) $step['step_type'];

			$slugs   = FlowStepConfig::getConfiguredHandlerSlugs( $step );
			$configs = FlowStepConfig::getHandlerConfigs( $step );

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
					$config_summary[] = "{$k}=" . ( is_array( $v ) ? (string) wp_json_encode( $v ) : $v );
				}

				$rows[] = array(
					'flow_step_id' => $sid,
					'step_type'    => $step_type,
					'handler'      => $slug,
					'config'       => implode( ', ', $config_summary ) ? implode( ', ', $config_summary ) : '(default)',
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
			if ( ! empty( FlowStepConfig::getConfiguredHandlerSlugs( $step_data ) ) ) {
				$handler_steps[] = $step_id;
			}
		}

		if ( empty( $handler_steps ) ) {
			return array(
				'step_id' => null,
				'error'   => 'Flow has no handler steps',
			);
		}

		if ( count( $handler_steps ) > 1 ) {
			return array(
				'step_id' => null,
				'error'   => sprintf(
					'Flow has multiple handler steps. Use --step=<id> to specify. Available: %s',
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
	 * Manage memory files attached to a flow.
	 *
	 * Without --add or --remove, lists current memory files.
	 * With --add, attaches a file. With --remove, detaches a file.
	 *
	 * @param int   $flow_id    Flow ID.
	 * @param array $assoc_args Arguments (add, remove, format).
	 */
	private function memoryFiles( int $flow_id, array $assoc_args ): void {
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$format   = $assoc_args['format'] ?? 'table';
		$add_file = $assoc_args['add'] ?? null;
		$rm_file  = $assoc_args['remove'] ?? null;

		$db = new \DataMachine\Core\Database\Flows\Flows();

		// Verify flow exists.
		$flow = $db->get_flow( $flow_id );
		if ( ! $flow ) {
			WP_CLI::error( "Flow {$flow_id} not found" );
			return;
		}

		$current_files = $db->get_flow_memory_files( $flow_id );

		// Add a file.
		if ( $add_file ) {
			$add_file = sanitize_file_name( $add_file );

			if ( in_array( $add_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is already attached to flow %d.', $add_file, $flow_id ) );
				return;
			}

			$current_files[] = $add_file;
			$result          = $db->update_flow_memory_files( $flow_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Added "%s" to flow %d. Files: %s', $add_file, $flow_id, implode( ', ', $current_files ) ) );
			return;
		}

		// Remove a file.
		if ( $rm_file ) {
			$rm_file = sanitize_file_name( $rm_file );

			if ( ! in_array( $rm_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is not attached to flow %d.', $rm_file, $flow_id ) );
				return;
			}

			$current_files = array_values( array_diff( $current_files, array( $rm_file ) ) );
			$result        = $db->update_flow_memory_files( $flow_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Removed "%s" from flow %d.', $rm_file, $flow_id ) );

			if ( ! empty( $current_files ) ) {
				WP_CLI::log( sprintf( 'Remaining: %s', implode( ', ', $current_files ) ) );
			} else {
				WP_CLI::log( 'No memory files attached.' );
			}
			return;
		}

		// List files.
		if ( empty( $current_files ) ) {
			WP_CLI::log( sprintf( 'Flow %d has no memory files attached.', $flow_id ) );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $current_files, JSON_PRETTY_PRINT ) );
			return;
		}

		$items = array_map(
			function ( $filename ) {
				return array( 'filename' => $filename );
			},
			$current_files
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'filename' ) );
	}

	/**
	 * Pause one or more flows.
	 *
	 * Preserves the original schedule so flows can be resumed later.
	 *
	 * ## USAGE
	 *
	 *     wp datamachine flows pause <flow_id>
	 *     wp datamachine flows pause --pipeline=<id>
	 *     wp datamachine flows pause --agent=<slug_or_id>
	 *
	 * @param array $args       Positional args (optional flow_id).
	 * @param array $assoc_args Associative args (--pipeline, --agent).
	 */
	private function pauseFlows( array $args, array $assoc_args ): void {
		$input = $this->buildPauseResumeInput( $args, $assoc_args );
		if ( null === $input ) {
			return; // Error already printed.
		}

		$ability = wp_get_ability( 'datamachine/pause-flow' );
		$result  = $ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to pause flows' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Flows paused.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			foreach ( $result['flows'] ?? array() as $detail ) {
				WP_CLI::log( sprintf( '  Flow %d: %s', $detail['flow_id'], $detail['status'] ) );
			}
		}
	}

	/**
	 * Resume one or more paused flows.
	 *
	 * Re-registers Action Scheduler hooks from the preserved schedule.
	 *
	 * ## USAGE
	 *
	 *     wp datamachine flows resume <flow_id>
	 *     wp datamachine flows resume --pipeline=<id>
	 *     wp datamachine flows resume --agent=<slug_or_id>
	 *
	 * @param array $args       Positional args (optional flow_id).
	 * @param array $assoc_args Associative args (--pipeline, --agent).
	 */
	private function resumeFlows( array $args, array $assoc_args ): void {
		$input = $this->buildPauseResumeInput( $args, $assoc_args );
		if ( null === $input ) {
			return; // Error already printed.
		}

		$ability = wp_get_ability( 'datamachine/resume-flow' );
		$result  = $ability->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to resume flows' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Flows resumed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		} else {
			foreach ( $result['flows'] ?? array() as $detail ) {
				$line = sprintf( '  Flow %d: %s', $detail['flow_id'], $detail['status'] );
				if ( ! empty( $detail['error'] ) ) {
					$line .= ' — ' . $detail['error'];
				}
				WP_CLI::log( $line );
			}
		}
	}

	/**
	 * Build input array for pause/resume from CLI args.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return array|null Input array, or null on validation error.
	 */
	private function buildPauseResumeInput( array $args, array $assoc_args ): ?array {
		$flow_id     = ! empty( $args[0] ) ? (int) $args[0] : null;
		$pipeline_id = isset( $assoc_args['pipeline'] ) ? (int) $assoc_args['pipeline'] : ( isset( $assoc_args['pipeline_id'] ) ? (int) $assoc_args['pipeline_id'] : null );
		$agent_id    = AgentResolver::resolve( $assoc_args );

		if ( null === $flow_id && null === $pipeline_id && null === $agent_id ) {
			WP_CLI::error( 'Must provide a flow ID, --pipeline=<id>, or --agent=<slug_or_id>.' );
			return null;
		}

		$input = array();
		if ( null !== $flow_id ) {
			$input['flow_id'] = $flow_id;
		} elseif ( null !== $pipeline_id ) {
			$input['pipeline_id'] = $pipeline_id;
		} elseif ( null !== $agent_id ) {
			$input['agent_id'] = $agent_id;
		}

		return $input;
	}

	/**
	 * Build a scheduling_config array from a CLI --scheduling value.
	 *
	 * Detects cron expressions and routes them correctly:
	 * - Cron expression (e.g. "0 * /3 * * *") → interval=cron + cron_expression
	 * - Interval key (e.g. "daily") → interval=<key>
	 * - One-time (scheduling=one_time) → interval=one_time + timestamp (requires $scheduled_at)
	 *
	 * @param string      $scheduling   Value from --scheduling CLI flag.
	 * @param string|null $scheduled_at ISO-8601 datetime for one-time scheduling.
	 * @return array Scheduling config array.
	 */
	private static function build_scheduling_config( string $scheduling, ?string $scheduled_at = null ): array {
		// If --scheduled-at is provided, treat as one_time regardless of --scheduling value.
		if ( $scheduled_at ) {
			$timestamp = strtotime( $scheduled_at );
			if ( ! $timestamp ) {
				\WP_CLI::error( "Invalid --scheduled-at value: {$scheduled_at}. Use ISO-8601 format (e.g. 2026-03-20T15:00:00Z)." );
			}
			return array(
				'interval'  => 'one_time',
				'timestamp' => $timestamp,
			);
		}

		if ( 'one_time' === $scheduling ) {
			\WP_CLI::error( 'one_time scheduling requires --scheduled-at=<datetime> (ISO-8601 format).' );
		}

		if ( \DataMachine\Engine\Tasks\RecurringScheduler::looksLikeCronExpression( $scheduling ) ) {
			return array(
				'interval'        => 'cron',
				'cron_expression' => $scheduling,
			);
		}

		return array( 'interval' => $scheduling );
	}

	/**
	 * Bulk-reassign agent_id on flows.
	 *
	 * Requires exactly one of --from-agent or --where-null, plus --to-agent.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function reassignFlows( array $assoc_args ): void {
		$has_from = isset( $assoc_args['from-agent'] );
		$has_null = isset( $assoc_args['where-null'] );
		$has_to   = isset( $assoc_args['to-agent'] );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$skip     = isset( $assoc_args['yes'] );
		$format   = $assoc_args['format'] ?? 'table';

		if ( ! $has_to ) {
			WP_CLI::error( '--to-agent is required.' );
			return;
		}

		if ( ! $has_from && ! $has_null ) {
			WP_CLI::error( 'Must provide --from-agent=<id> or --where-null to specify which flows to reassign.' );
			return;
		}

		if ( $has_from && $has_null ) {
			WP_CLI::error( '--from-agent and --where-null are mutually exclusive.' );
			return;
		}

		// Resolve --to-agent through AgentResolver (must be a valid agent).
		$to_agent_id = AgentResolver::resolve( array( 'agent' => $assoc_args['to-agent'] ) );
		if ( null === $to_agent_id ) {
			WP_CLI::error( 'Could not resolve --to-agent to a valid agent.' );
			return;
		}

		// Parse --from-agent as raw integer (may reference a stale/non-existent agent_id).
		$from_agent_id = null;
		if ( $has_from ) {
			$from_agent_id = absint( $assoc_args['from-agent'] );
			if ( $from_agent_id <= 0 ) {
				WP_CLI::error( '--from-agent must be a positive integer.' );
				return;
			}
			if ( $from_agent_id === $to_agent_id ) {
				WP_CLI::error( '--from-agent and --to-agent resolve to the same agent_id.' );
				return;
			}
		}

		$flows_repo = new \DataMachine\Core\Database\Flows\Flows();
		$flow_count = $flows_repo->count_by_agent_id( $from_agent_id );

		$source_label = null === $from_agent_id ? 'NULL' : (string) $from_agent_id;

		if ( 0 === $flow_count ) {
			WP_CLI::warning( sprintf( 'No flows found with agent_id=%s. Nothing to do.', $source_label ) );
			return;
		}

		WP_CLI::log( sprintf(
			'Found %d flow(s) with agent_id=%s → reassign to agent_id=%d.',
			$flow_count,
			$source_label,
			$to_agent_id
		) );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry-run complete. No changes made.' );
			return;
		}

		if ( ! $skip ) {
			WP_CLI::confirm( sprintf(
				'Reassign %d flow(s) from agent_id=%s to agent_id=%d?',
				$flow_count,
				$source_label,
				$to_agent_id
			) );
		}

		$flows_updated = $flows_repo->reassign_agent_id( $from_agent_id, $to_agent_id );

		if ( $flows_updated < 0 ) {
			WP_CLI::error( 'Database error during flow reassignment.' );
			return;
		}

		WP_CLI::success( sprintf(
			'Done. %d flow(s) reassigned from agent_id=%s to agent_id=%d.',
			$flows_updated,
			$source_label,
			$to_agent_id
		) );
	}

	/**
	 * List flows with NULL agent_id without mutating data.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function listOrphanedFlows( array $assoc_args ): void {
		$limit  = max( 1, min( 100, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $assoc_args['offset'] ?? 0 ) );
		$format = $assoc_args['format'] ?? 'table';

		$flows_repo = new \DataMachine\Core\Database\Flows\Flows();
		$rows       = $flows_repo->get_orphaned_flows( $limit, $offset );
		$total      = $flows_repo->count_by_agent_id( null );

		$items = array_map(
			static function ( array $flow ): array {
				return array(
					'id'            => (int) ( $flow['flow_id'] ?? 0 ),
					'name'          => (string) ( $flow['flow_name'] ?? '' ),
					'pipeline_id'   => (int) ( $flow['pipeline_id'] ?? 0 ),
					'user_id'       => (int) ( $flow['user_id'] ?? 0 ),
					'portable_slug' => (string) ( $flow['portable_slug'] ?? '' ),
					'created'       => (string) ( $flow['created_at'] ?? '' ),
				);
			},
			$rows
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array(
				'total' => $total,
				'flows' => $items,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No orphaned flows found.' );
			return;
		}

		WP_CLI::warning( sprintf( 'Found %d orphaned flow(s) with agent_id=NULL.', $total ) );
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'name', 'pipeline_id', 'user_id', 'portable_slug', 'created' ) );
		WP_CLI::log( 'Repair with: wp datamachine flows reassign --where-null --to-agent=<agent> --dry-run' );
	}
}
