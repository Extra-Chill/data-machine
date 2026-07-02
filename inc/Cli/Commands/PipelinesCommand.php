<?php
/**
 * WP-CLI Pipelines Command
 *
 * Provides CLI access to pipeline listing and management operations.
 * Wraps concrete Pipeline ability primitives.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.16.0 Added create, update, delete subcommands.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Cli\UserResolver;
use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Abilities\Pipeline\DeletePipelineAbility;
use DataMachine\Abilities\Pipeline\GetPipelinesAbility;
use DataMachine\Abilities\Pipeline\UpdatePipelineAbility;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Engine\Debug\SyncRunner;

defined( 'ABSPATH' ) || exit;

class PipelinesCommand extends BaseCommand {

	/**
	 * Default fields for pipeline list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'steps', 'step_types', 'flows', 'location', 'updated' );

	/**
	 * Get pipelines with optional filtering.
	 *
	 * ## OPTIONS
	 *
	 * [<args>...]
	 * : Subcommand and arguments. Accepts: list [pipeline_id], get <pipeline_id>, create, update <pipeline_id>, delete <pipeline_id>.
	 *
	 * [--per_page=<number>]
	 * : Number of pipelines to return.
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
	 * [--search=<search>]
	 * : Filter pipelines by name (substring match).
	 *
	 * [--name=<name>]
	 * : Pipeline name (create/update subcommands).
	 *
	 * [--steps=<json>]
	 * : JSON array of steps (create subcommand). Each step: {step_type, label?}.
	 *
	 * [--config=<json>]
	 * : JSON object with pipeline configuration (update subcommand).
	 *
	 * [--set-system-prompt=<text>]
	 * : Update the system prompt for an AI step (update subcommand).
	 *   Auto-resolves to the only AI step if pipeline has exactly one.
	 *   Use --step to target a specific step when multiple AI steps exist.
	 *
	 * [--step=<pipeline_step_id>]
	 * : Target a specific pipeline step for system prompt update.
	 *   Use --step to target a specific step when multiple exist.
	 *
 * [--agent=<slug_or_id>]
 * : Agent slug or ID. For update: set the pipeline's agent_id.
 *   For reassign: see --from-agent / --to-agent instead.
 *   For list: filter by agent.
 *
 * [--cascade-flows]
 * : When updating a pipeline's agent, also reassign its child flows (update subcommand).
 *   Default: off for single update, on for bulk reassign.
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
 * [--force]
 * : Skip confirmation prompt (delete/reassign subcommands).
 *
 * [--dry-run]
 * : Show what would change without committing (create/reassign subcommands).
	 *
	 * [--add=<filename>]
	 * : Attach a memory file to a pipeline (memory-files subcommand).
	 *
	 * [--remove=<filename>]
	 * : Detach a memory file from a pipeline (memory-files subcommand).
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
	 * ## EXAMPLES
	 *
	 *     # List all pipelines
	 *     wp datamachine pipelines
	 *
	 *     # Get a specific pipeline by ID
	 *     wp datamachine pipelines 5
	 *
	 *     # Alias: pipelines get <id>
	 *     wp datamachine pipelines get 5
	 *
	 *     # List with pagination
	 *     wp datamachine pipelines --per_page=10 --offset=20
	 *
	 *     # Output as CSV
	 *     wp datamachine pipelines --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine pipelines --format=ids
	 *
	 *     # Count total pipelines
	 *     wp datamachine pipelines --format=count
	 *
	 *     # Select specific fields
	 *     wp datamachine pipelines --fields=id,name,flows
	 *
	 *     # JSON output
	 *     wp datamachine pipelines --format=json
	 *
	 *     # Create a new pipeline (minimal)
	 *     wp datamachine pipelines create --name="My Pipeline"
	 *
	 *     # Create a pipeline with steps
	 *     wp datamachine pipelines create --name="Event Pipeline" \
	 *       --steps='[{"step_type":"event_import"},{"step_type":"ai_enrich"}]'
	 *
	 *     # Dry-run validation
	 *     wp datamachine pipelines create --name="Test" --dry-run
	 *
	 *     # Update a pipeline name
	 *     wp datamachine pipelines update 5 --name="New Pipeline Name"
	 *
	 *     # Delete a pipeline (with confirmation)
	 *     wp datamachine pipelines delete 5
	 *
	 *     # Update system prompt (auto-resolves if one AI step)
	 *     wp datamachine pipelines update 12 --set-system-prompt="Write a blog post..."
	 *
	 *     # Update system prompt on specific step
	 *     wp datamachine pipelines update 12 --step=12_abc123 --set-system-prompt="Write a blog post..."
	 *
	 *     # Delete a pipeline (skip confirmation)
	 *     wp datamachine pipelines delete 5 --force
	 *
	 *     # List memory files for a pipeline
	 *     wp datamachine pipelines memory-files 5
	 *
	 *     # Attach a memory file
	 *     wp datamachine pipelines memory-files 5 --add=content-briefing.md
	 *
	 *     # Detach a memory file
	 *     wp datamachine pipelines memory-files 5 --remove=content-briefing.md
	 *
	 *     # Run the first flow in a pipeline inline for bounded local exploration
	 *     wp datamachine pipelines run-sync 5 --input-file=packets.json --show-packets --format=json
	 *
	 *     # Update pipeline agent
	 *     wp datamachine pipelines update 13 --agent=events-bot
	 *
	 *     # Update pipeline agent and cascade to child flows
	 *     wp datamachine pipelines update 13 --agent=events-bot --cascade-flows
	 *
	 *     # Bulk reassign: move orphan pipelines → events-bot
	 *     wp datamachine pipelines reassign --where-null --to-agent=events-bot
	 *
	 *     # Bulk reassign: move pipelines from agent_id=1 → events-bot
	 *     wp datamachine pipelines reassign --from-agent=1 --to-agent=events-bot
	 *
	 *     # Dry-run reassign
	 *     wp datamachine pipelines reassign --where-null --to-agent=events-bot --dry-run
	 *
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$pipeline_id = null;

		// Handle 'run-sync' subcommand.
		if ( ! empty( $args ) && 'run-sync' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine pipelines run-sync <pipeline_id> [--max-steps=N] [--max-items=N] [--timeout=N] [--show-packets] [--input-file=<path>] [--format=json]' );
				return;
			}
			$this->runPipelineSync( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'create' subcommand.
		if ( ! empty( $args ) && 'create' === $args[0] ) {
			$this->createPipeline( $assoc_args );
			return;
		}

		// Handle 'update' subcommand.
		if ( ! empty( $args ) && 'update' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine pipelines update <pipeline_id> [--name=<name>] [--config=<json>]' );
				return;
			}
			$this->updatePipeline( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'delete' subcommand.
		if ( ! empty( $args ) && 'delete' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine pipelines delete <pipeline_id> [--force]' );
				return;
			}
			$this->deletePipeline( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'reassign' subcommand.
		if ( ! empty( $args ) && 'reassign' === $args[0] ) {
			$this->reassignPipelines( $assoc_args );
			return;
		}

		// Handle 'orphans' subcommand.
		if ( ! empty( $args ) && 'orphans' === $args[0] ) {
			$this->listOrphanedPipelines( $assoc_args );
			return;
		}

		// Handle 'memory-files' subcommand.
		if ( ! empty( $args ) && 'memory-files' === $args[0] ) {
			if ( ! isset( $args[1] ) ) {
				WP_CLI::error( 'Usage: wp datamachine pipelines memory-files <pipeline_id> [--add=<filename>] [--remove=<filename>]' );
				return;
			}
			$this->memoryFiles( (int) $args[1], $assoc_args );
			return;
		}

		// Handle 'get' subcommand: `pipelines get 5`.
		if ( ! empty( $args ) && 'get' === $args[0] ) {
			if ( isset( $args[1] ) ) {
				$pipeline_id = (int) $args[1];
			}
		} elseif ( ! empty( $args ) && 'list' !== $args[0] ) {
			$pipeline_id = (int) $args[0];
		}

		$per_page = (int) ( $assoc_args['per_page'] ?? 20 );
		$offset   = (int) ( $assoc_args['offset'] ?? 0 );
		$format   = $assoc_args['format'] ?? 'table';
		$search   = $assoc_args['search'] ?? null;

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$scoping = AgentResolver::buildScopingInput( $assoc_args );

		if ( $pipeline_id ) {
			$result = ( new GetPipelinesAbility() )->execute(
				array_merge(
					$scoping,
					array(
						'pipeline_id' => $pipeline_id,
						'output_mode' => 'full',
					)
				)
			);

			if ( ! $result['success'] || empty( $result['pipelines'] ) ) {
				WP_CLI::error( $result['error'] ?? 'Pipeline not found' );
				return;
			}

			$pipeline_data = $result['pipelines'][0];
			$flows         = $pipeline_data['flows'] ?? array();
			unset( $pipeline_data['flows'] );
			$single_result = array(
				'success'  => true,
				'pipeline' => $pipeline_data,
				'flows'    => $flows,
			);
			$this->outputSinglePipeline( $single_result, $format );
		} else {
			$ability_input = array_merge(
				$scoping,
				array(
					'per_page'    => $per_page,
					'offset'      => $offset,
					'output_mode' => 'full',
				)
			);

			if ( null !== $search && '' !== $search ) {
				$ability_input['search'] = $search;
			}

			$result = ( new GetPipelinesAbility() )->execute( $ability_input );

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['error'] ?? 'Failed to get pipelines' );
				return;
			}

			$pipelines = $result['pipelines'] ?? array();
			$total     = $result['total'] ?? 0;

			if ( empty( $pipelines ) ) {
				WP_CLI::warning( 'No pipelines found.' );
				return;
			}

			// Apply the human empty-cell placeholder ("—") ONLY for the table
			// display. Structured formats (json/csv/yaml/ids/count) must carry
			// the real value (empty string when there's no summary) — mirrors
			// the flows-list fix; a display dash in structured output is a
			// silent data-integrity bug (#2754).
			$is_table = 'table' === $format;

			// Transform pipelines to flat row format.
			$items = array_map(
				function ( $pipeline ) use ( $is_table ) {
					$config   = $pipeline['pipeline_config'] ?? array();
					$flows    = $pipeline['flows'] ?? array();
					$location = $this->extractPipelineLocation( $flows );
					return array(
						'id'         => $pipeline['pipeline_id'],
						'name'       => $pipeline['pipeline_name'],
						'steps'      => count( $config ),
						'step_types' => $this->extractStepTypes( $config ),
						'flows'      => count( $flows ),
						'location'   => ( $is_table && '' === $location ) ? '—' : $location,
						'updated'    => $pipeline['updated_at_display'] ?? $pipeline['updated_at'] ?? 'N/A',
					);
				},
				$pipelines
			);

			$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );
			$this->output_pagination( $offset, count( $pipelines ), $total, $format, 'pipelines' );
		}
	}

	/**
	 * Run the first flow attached to a pipeline synchronously.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $assoc_args  WP-CLI args.
	 */
	private function runPipelineSync( int $pipeline_id, array $assoc_args ): void {
		$result = ( new GetPipelinesAbility() )->execute(
			array(
				'pipeline_id'   => $pipeline_id,
				'output_mode'   => 'full',
				'include_flows' => true,
			)
		);

		if ( empty( $result['success'] ) || empty( $result['pipelines'][0] ) ) {
			WP_CLI::error( $result['error'] ?? 'Pipeline not found' );
			return;
		}

		$flows = is_array( $result['pipelines'][0]['flows'] ?? null ) ? $result['pipelines'][0]['flows'] : array();
		if ( empty( $flows[0]['flow_id'] ) ) {
			WP_CLI::error( sprintf( 'Pipeline %d has no flows to run synchronously.', $pipeline_id ) );
			return;
		}

		$format                = (string) ( $assoc_args['format'] ?? 'json' );
		$packet                = ( new SyncRunner() )->runFlow( (int) $flows[0]['flow_id'], $this->build_sync_runner_options( $assoc_args ) );
		$packet['pipeline_id'] = $pipeline_id;
		$this->output_sync_runner_packet( $packet, $format );
	}

	/**
	 * Output single pipeline result.
	 *
	 * @param array  $result Result with pipeline and flows.
	 * @param string $format Output format.
	 */
	private function outputSinglePipeline( array $result, string $format ): void {
		$pipeline = $result['pipeline'] ?? array();
		$flows    = $result['flows'] ?? array();

		if ( empty( $pipeline ) ) {
			WP_CLI::warning( 'Pipeline not found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Output pipeline info.
		WP_CLI::log( sprintf( 'Pipeline ID: %d', $pipeline['pipeline_id'] ) );
		WP_CLI::log( sprintf( 'Name: %s', $pipeline['pipeline_name'] ) );
		WP_CLI::log( sprintf( 'Created: %s', $pipeline['created_at_display'] ?? $pipeline['created_at'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Updated: %s', $pipeline['updated_at_display'] ?? $pipeline['updated_at'] ?? 'N/A' ) );

		// Memory files.
		$memory_files = $pipeline['pipeline_config']['memory_files'] ?? array();
		if ( ! empty( $memory_files ) ) {
			WP_CLI::log( sprintf( 'Memory files: %s', implode( ', ', $memory_files ) ) );
		}

		WP_CLI::log( '' );

		// Output steps.
		$config = $pipeline['pipeline_config'] ?? array();
		if ( ! empty( $config ) ) {
			WP_CLI::log( 'Steps:' );
			$step_rows = array();
			foreach ( $config as $step_id => $step ) {
				$step_rows[] = array(
					'Order'     => $step['execution_order'] ?? 0,
					'Step Type' => $step['step_type'] ?? 'N/A',
					'Label'     => $step['label'] ?? $step['step_type'] ?? 'N/A',
				);
			}
			usort( $step_rows, fn( $a, $b ) => $a['Order'] <=> $b['Order'] );
			\WP_CLI\Utils\format_items( 'table', $step_rows, array( 'Order', 'Step Type', 'Label' ) );
		} else {
			WP_CLI::log( 'Steps: None' );
		}

		WP_CLI::log( '' );

		// Output flows.
		if ( ! empty( $flows ) ) {
			WP_CLI::log( sprintf( 'Flows (%d):', count( $flows ) ) );
			$flow_rows = array();
			foreach ( $flows as $flow ) {
				$flow_rows[] = array(
					'Flow ID'   => $flow['flow_id'],
					'Flow Name' => $flow['flow_name'],
					'Interval'  => $flow['scheduling_config']['interval'] ?? 'manual',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $flow_rows, array( 'Flow ID', 'Flow Name', 'Interval' ) );
		} else {
			WP_CLI::log( 'Flows: None' );
		}
	}

	/**
	 * Extract a config summary from a pipeline's flows.
	 *
	 * Scans flow handler configs for distinguishing values — coordinates,
	 * city names, URLs, taxonomy term IDs. Domain-agnostic: reads raw
	 * config keys without assuming any specific taxonomy or handler.
	 *
	 * Returns the honest summary string — an EMPTY string when no
	 * distinguishing config is found — so the value is safe for every output
	 * format. The "—" empty-cell placeholder is applied only when building
	 * table rows, never here (#2754).
	 *
	 * @param array $flows Pipeline's flows array.
	 * @return string Config summary, or '' when empty.
	 */
	private function extractPipelineLocation( array $flows ): string {
		$parts = array();

		foreach ( $flows as $flow ) {
			$config = $flow['flow_config'] ?? array();
			if ( is_string( $config ) ) {
				$config = json_decode( $config, true ) ?? array();
			}

			foreach ( $config as $step ) {
				$handler_configs = is_array( $step ) ? FlowStepConfig::getHandlerConfigs( $step ) : array();

				foreach ( $handler_configs as $hconfig ) {
					// Coordinates (any handler that has a location field with lat,lon).
					if ( ! empty( $hconfig['location'] ) && strpos( $hconfig['location'], ',' ) !== false ) {
						$parts[] = $hconfig['location'];
					}

					// City name (any handler that has a city field).
					if ( ! empty( $hconfig['city'] ) ) {
						$parts[] = $hconfig['city'];
					}

					// Taxonomy term selections (any taxonomy_*_selection config).
					foreach ( $hconfig as $key => $val ) {
						if ( strpos( $key, 'taxonomy_' ) === 0 && strpos( $key, '_selection' ) !== false ) {
							if ( ! empty( $val ) && 'skip' !== $val && 'ai_decides' !== $val ) {
								$parts[] = $val;
							}
						}
					}
				}
			}

			// Stop after first flow with useful config.
			if ( ! empty( $parts ) ) {
				break;
			}
		}

		$summary = implode( ' | ', array_unique( $parts ) );
		return $summary;
	}

	/**
	 * Extract step types from pipeline config.
	 *
	 * @param array $config Pipeline configuration.
	 * @return string Comma-separated step types.
	 */
	private function extractStepTypes( array $config ): string {
		$types = array();
		foreach ( $config as $step ) {
			if ( ! empty( $step['step_type'] ) ) {
				$types[] = $step['step_type'];
			}
		}
		return implode( ', ', array_unique( $types ) );
	}

	/**
	 * Create a new pipeline.
	 *
	 * @param array $assoc_args Associative arguments (name, steps, dry-run).
	 */
	private function createPipeline( array $assoc_args ): void {
		$pipeline_name = $assoc_args['name'] ?? null;
		$dry_run       = isset( $assoc_args['dry-run'] );
		$format        = $assoc_args['format'] ?? 'table';

		if ( ! $pipeline_name ) {
			WP_CLI::error( 'Required: --name=<name>' );
			return;
		}

		$steps = array();
		if ( isset( $assoc_args['steps'] ) ) {
			$decoded = json_decode( wp_unslash( $assoc_args['steps'] ), true );
			if ( null === $decoded && '' !== $assoc_args['steps'] ) {
				WP_CLI::error( 'Invalid JSON in --steps' );
				return;
			}
			if ( null !== $decoded && ! is_array( $decoded ) ) {
				WP_CLI::error( '--steps must be a JSON array' );
				return;
			}
			$steps = $decoded ?? array();
		}

		$input = array(
			'pipeline_name' => $pipeline_name,
			'steps'         => $steps,
		);

		if ( $dry_run ) {
			$input['validate_only'] = true;
			$input['pipelines']     = array(
				array(
					'name'  => $pipeline_name,
					'steps' => $steps,
				),
			);
		}

		$result = ( new CreatePipelineAbility() )->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create pipeline' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Validation passed.' );
			if ( isset( $result['would_create'] ) && 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $result['would_create'], JSON_PRETTY_PRINT ) );
			} elseif ( isset( $result['would_create'] ) ) {
				foreach ( $result['would_create'] as $preview ) {
					WP_CLI::log( sprintf(
						'Would create: "%s" with %d step(s)',
						$preview['name'],
						$preview['steps']
					) );
				}
			}
			return;
		}

		WP_CLI::success( sprintf( 'Pipeline created: ID %d', $result['pipeline_id'] ) );
		WP_CLI::log( sprintf( 'Name: %s', $result['pipeline_name'] ) );
		WP_CLI::log( sprintf( 'Steps created: %d', $result['steps_created'] ?? 0 ) );

		if ( isset( $result['flow_id'] ) ) {
			WP_CLI::log( sprintf( 'Default flow ID: %d', $result['flow_id'] ) );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Update an existing pipeline.
	 *
	 * @param int   $pipeline_id Pipeline ID to update.
	 * @param array $assoc_args  Associative arguments (name, config).
	 */
	private function updatePipeline( int $pipeline_id, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		if ( $pipeline_id <= 0 ) {
			WP_CLI::error( 'pipeline_id must be a positive integer' );
			return;
		}

		$has_name          = isset( $assoc_args['name'] );
		$has_config        = isset( $assoc_args['config'] );
		$has_system_prompt = isset( $assoc_args['set-system-prompt'] );
		$has_agent         = isset( $assoc_args['agent'] );

		if ( ! $has_name && ! $has_config && ! $has_system_prompt && ! $has_agent ) {
			WP_CLI::error( 'Must provide --name, --config, --set-system-prompt, and/or --agent to update' );
			return;
		}

		$result       = null;
		$step_results = array();

		// Update agent_id if --agent provided.
		if ( $has_agent ) {
			$new_agent_id = AgentResolver::resolve( $assoc_args );
			if ( null === $new_agent_id ) {
				WP_CLI::error( 'Could not resolve --agent to a valid agent.' );
				return;
			}

			$pipelines_repo = new \DataMachine\Core\Database\Pipelines\Pipelines();
			$success        = $pipelines_repo->update_pipeline( $pipeline_id, array( 'agent_id' => $new_agent_id ) );

			if ( ! $success ) {
				WP_CLI::error( 'Failed to update pipeline agent_id.' );
				return;
			}

			WP_CLI::log( sprintf( 'Agent: set to agent_id=%d', $new_agent_id ) );

			// Cascade to child flows if requested.
			if ( isset( $assoc_args['cascade-flows'] ) ) {
				$flows_repo    = new \DataMachine\Core\Database\Flows\Flows();
				$flows_updated = $flows_repo->reassign_agent_id_for_pipeline( $pipeline_id, null, $new_agent_id );
				if ( $flows_updated >= 0 ) {
					WP_CLI::log( sprintf( 'Cascade: %d child flow(s) reassigned to agent_id=%d.', $flows_updated, $new_agent_id ) );
				} else {
					WP_CLI::warning( 'Failed to cascade agent_id to child flows.' );
				}
			}
		}

		// Update name if provided.
		if ( $has_name ) {
			$result = ( new UpdatePipelineAbility() )->execute(
				array(
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $assoc_args['name'],
				)
			);

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['error'] ?? 'Failed to update pipeline name' );
				return;
			}

			WP_CLI::log( sprintf( 'Name: %s', $result['pipeline_name'] ) );
		}

		// Update step configs if --config provided.
		if ( $has_config ) {
			$config_json = wp_unslash( $assoc_args['config'] );
			$config      = json_decode( $config_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				WP_CLI::error( 'Invalid JSON in --config: ' . json_last_error_msg() );
				return;
			}

			if ( ! is_array( $config ) ) {
				WP_CLI::error( '--config must be a JSON object' );
				return;
			}

			$step_ability = new \DataMachine\Abilities\PipelineStepAbilities();

			foreach ( $config as $step_id => $step_config ) {
				// Skip if not a valid step config array.
				if ( ! is_array( $step_config ) ) {
					WP_CLI::warning( "Skipping invalid config for key: {$step_id}" );
					continue;
				}

				// Build input for step update.
				$step_input = array(
					'pipeline_id'      => $pipeline_id,
					'pipeline_step_id' => $step_id,
				);

				// Map known step config fields.
				$field_map = array(
					'system_prompt'  => 'system_prompt',
					'disabled_tools' => 'disabled_tools',
				);

				$has_update = false;
				foreach ( $field_map as $config_key => $input_key ) {
					if ( isset( $step_config[ $config_key ] ) ) {
						$step_input[ $input_key ] = $step_config[ $config_key ];
						$has_update               = true;
					}
				}

				if ( ! $has_update ) {
					continue;
				}

				$step_result = $step_ability->executeUpdatePipelineStep( $step_input );

				if ( ! $step_result['success'] ) {
					WP_CLI::warning( "Failed to update step {$step_id}: " . ( $step_result['error'] ?? 'Unknown error' ) );
					$step_results[ $step_id ] = $step_result;
				} else {
					$fields = implode( ', ', $step_result['updated_fields'] ?? array() );
					WP_CLI::log( sprintf( 'Updated step %s: %s', $step_id, $fields ) );
					$step_results[ $step_id ] = $step_result;
				}
			}
		}

		// Handle --set-system-prompt shorthand.
		if ( $has_system_prompt ) {
			$system_prompt = wp_kses_post( wp_unslash( $assoc_args['set-system-prompt'] ) );
			$step_id       = $assoc_args['step'] ?? null;

			if ( null === $step_id ) {
				$resolved = $this->resolveAiStep( $pipeline_id );
				if ( ! empty( $resolved['error'] ) ) {
					WP_CLI::error( $resolved['error'] );
					return;
				}
				$step_id = $resolved['step_id'];
			}

			$step_ability  = new \DataMachine\Abilities\PipelineStepAbilities();
			$prompt_result = $step_ability->executeUpdatePipelineStep(
				array(
					'pipeline_id'      => $pipeline_id,
					'pipeline_step_id' => $step_id,
					'system_prompt'    => $system_prompt,
				)
			);

			if ( ! $prompt_result['success'] ) {
				WP_CLI::warning( 'Failed to update system prompt: ' . ( $prompt_result['error'] ?? 'Unknown error' ) );
				$step_results[ $step_id ] = $prompt_result;
			} else {
				WP_CLI::log( sprintf( 'System prompt updated for step: %s', $step_id ) );
				$step_results[ $step_id ] = $prompt_result;
			}
		}

		// Determine if any updates succeeded.
		$any_success = $has_agent || ( $result && $result['success'] ) ||
			array_filter( $step_results, fn( $r ) => $r['success'] ?? false );

		if ( ! $any_success ) {
			WP_CLI::warning( 'No changes were made' );
		} else {
			WP_CLI::success( sprintf( 'Pipeline %d updated.', $pipeline_id ) );
		}

		// Output JSON format: return ability response payload.
		if ( 'json' === $format ) {
			// If we have a pipeline update result, add step_results to it.
			if ( $result ) {
				if ( ! empty( $step_results ) ) {
					$result['step_results'] = $step_results;
				}
				WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			} elseif ( ! empty( $step_results ) ) {
				// Only step updates, no name update.
				$output = array(
					'success'      => (bool) $any_success,
					'pipeline_id'  => $pipeline_id,
					'step_results' => $step_results,
				);
				WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT ) );
			}
		}
	}

	/**
	 * Resolve the AI step for a pipeline.
	 *
	 * If the pipeline has exactly one AI step, returns its ID.
	 * If multiple AI steps exist, returns an error listing available step IDs.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @return array{step_id?: string, error?: string}
	 */
	private function resolveAiStep( int $pipeline_id ): array {
		$result = ( new GetPipelinesAbility() )->execute(
			array(
				'pipeline_id' => $pipeline_id,
				'output_mode' => 'full',
			)
		);

		if ( ! $result['success'] || empty( $result['pipelines'] ) ) {
			return array( 'error' => 'Pipeline not found' );
		}

		$config   = $result['pipelines'][0]['pipeline_config'] ?? array();
		$ai_steps = array();

		foreach ( $config as $step_id => $step ) {
			if ( 'ai' === ( $step['step_type'] ?? '' ) ) {
				$ai_steps[] = array(
					'id'    => $step_id,
					'label' => $step['label'] ?? $step['step_type'] ?? 'AI',
				);
			}
		}

		if ( empty( $ai_steps ) ) {
			return array( 'error' => 'Pipeline has no AI steps' );
		}

		if ( count( $ai_steps ) > 1 ) {
			$ids = array_map( fn( $s ) => sprintf( '  %s (%s)', $s['id'], $s['label'] ), $ai_steps );
			return array(
				'error' => "Pipeline has multiple AI steps. Use --step=<pipeline_step_id> to target one:\n" . implode( "\n", $ids ),
			);
		}

		return array( 'step_id' => $ai_steps[0]['id'] );
	}

	/**
	 * Manage memory files attached to a pipeline.
	 *
	 * Without --add or --remove, lists current memory files.
	 * With --add, attaches a file. With --remove, detaches a file.
	 *
	 * @param int   $pipeline_id Pipeline ID.
	 * @param array $assoc_args  Arguments (add, remove, format).
	 */
	private function memoryFiles( int $pipeline_id, array $assoc_args ): void {
		if ( $pipeline_id <= 0 ) {
			WP_CLI::error( 'pipeline_id must be a positive integer' );
			return;
		}

		$format   = $assoc_args['format'] ?? 'table';
		$add_file = $assoc_args['add'] ?? null;
		$rm_file  = $assoc_args['remove'] ?? null;

		$db = new \DataMachine\Core\Database\Pipelines\Pipelines();

		// Verify pipeline exists.
		$pipeline = $db->get_pipeline( $pipeline_id );
		if ( ! $pipeline ) {
			WP_CLI::error( "Pipeline {$pipeline_id} not found" );
			return;
		}

		$current_files = $db->get_pipeline_memory_files( $pipeline_id );

		// Add a file.
		if ( $add_file ) {
			$add_file = sanitize_file_name( $add_file );

			if ( in_array( $add_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is already attached to pipeline %d.', $add_file, $pipeline_id ) );
				return;
			}

			$current_files[] = $add_file;
			$result          = $db->update_pipeline_memory_files( $pipeline_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Added "%s" to pipeline %d. Files: %s', $add_file, $pipeline_id, implode( ', ', $current_files ) ) );
			return;
		}

		// Remove a file.
		if ( $rm_file ) {
			$rm_file = sanitize_file_name( $rm_file );

			if ( ! in_array( $rm_file, $current_files, true ) ) {
				WP_CLI::warning( sprintf( '"%s" is not attached to pipeline %d.', $rm_file, $pipeline_id ) );
				return;
			}

			$current_files = array_values( array_diff( $current_files, array( $rm_file ) ) );
			$result        = $db->update_pipeline_memory_files( $pipeline_id, $current_files );

			if ( ! $result ) {
				WP_CLI::error( 'Failed to update memory files' );
				return;
			}

			WP_CLI::success( sprintf( 'Removed "%s" from pipeline %d.', $rm_file, $pipeline_id ) );

			if ( ! empty( $current_files ) ) {
				WP_CLI::log( sprintf( 'Remaining: %s', implode( ', ', $current_files ) ) );
			} else {
				WP_CLI::log( 'No memory files attached.' );
			}
			return;
		}

		// List files.
		if ( empty( $current_files ) ) {
			WP_CLI::log( sprintf( 'Pipeline %d has no memory files attached.', $pipeline_id ) );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $current_files, JSON_PRETTY_PRINT ) );
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
	 * Delete a pipeline.
	 *
	 * @param int   $pipeline_id Pipeline ID to delete.
	 * @param array $assoc_args  Associative arguments (force).
	 */
	private function deletePipeline( int $pipeline_id, array $assoc_args ): void {
		$force  = isset( $assoc_args['force'] );
		$format = $assoc_args['format'] ?? 'table';

		if ( $pipeline_id <= 0 ) {
			WP_CLI::error( 'pipeline_id must be a positive integer' );
			return;
		}

		// First, get pipeline info for confirmation.
		$info = ( new GetPipelinesAbility() )->execute( array( 'pipeline_id' => $pipeline_id ) );

		if ( ! $info['success'] || empty( $info['pipelines'] ) ) {
			WP_CLI::error( 'Pipeline not found' );
			return;
		}

		$pipeline      = $info['pipelines'][0];
		$pipeline_name = $pipeline['pipeline_name'] ?? 'Unknown';
		$flow_count    = count( $pipeline['flows'] ?? array() );

		// Confirm deletion unless --force is used.
		if ( ! $force ) {
			WP_CLI::confirm( sprintf(
				'Delete pipeline "%s" (ID: %d) and its %d flow(s)?',
				$pipeline_name,
				$pipeline_id,
				$flow_count
			) );
		}

		$result = ( new DeletePipelineAbility() )->execute( array( 'pipeline_id' => $pipeline_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete pipeline' );
			return;
		}

		WP_CLI::success( sprintf(
			'Pipeline "%s" (ID: %d) deleted. %d flow(s) also removed.',
			$result['pipeline_name'],
			$result['pipeline_id'],
			$result['deleted_flows']
		) );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Bulk-reassign agent_id on pipelines.
	 *
	 * Requires exactly one of --from-agent or --where-null, plus --to-agent.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function reassignPipelines( array $assoc_args ): void {
		$has_from   = isset( $assoc_args['from-agent'] );
		$has_null   = isset( $assoc_args['where-null'] );
		$has_to     = isset( $assoc_args['to-agent'] );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$force      = isset( $assoc_args['force'] );
		$no_cascade = isset( $assoc_args['no-cascade-flows'] );
		$format     = $assoc_args['format'] ?? 'table';

		if ( ! $has_to ) {
			WP_CLI::error( '--to-agent is required.' );
			return;
		}

		if ( ! $has_from && ! $has_null ) {
			WP_CLI::error( 'Must provide --from-agent=<id> or --where-null to specify which pipelines to reassign.' );
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

		$pipelines_repo = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$flows_repo     = new \DataMachine\Core\Database\Flows\Flows();

		// Count matching pipelines.
		$pipeline_count = $pipelines_repo->count_by_agent_id( $from_agent_id );
		$flow_count     = $no_cascade ? 0 : $flows_repo->count_by_agent_id( $from_agent_id );

		$source_label = null === $from_agent_id ? 'NULL' : (string) $from_agent_id;

		if ( 0 === $pipeline_count && 0 === $flow_count ) {
			WP_CLI::warning( sprintf( 'No pipelines or flows found with agent_id=%s. Nothing to do.', $source_label ) );
			return;
		}

		WP_CLI::log( sprintf(
			'Found %d pipeline(s) and %d flow(s) with agent_id=%s → reassign to agent_id=%d.',
			$pipeline_count,
			$flow_count,
			$source_label,
			$to_agent_id
		) );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry-run complete. No changes made.' );
			return;
		}

		if ( ! $force ) {
			WP_CLI::confirm( sprintf(
				'Reassign %d pipeline(s)%s from agent_id=%s to agent_id=%d?',
				$pipeline_count,
				$no_cascade ? '' : sprintf( ' and %d flow(s)', $flow_count ),
				$source_label,
				$to_agent_id
			) );
		}

		// Execute pipeline reassignment.
		$pipelines_updated = $pipelines_repo->reassign_agent_id( $from_agent_id, $to_agent_id );

		if ( $pipelines_updated < 0 ) {
			WP_CLI::error( 'Database error during pipeline reassignment.' );
			return;
		}

		WP_CLI::log( sprintf( 'Pipelines reassigned: %d', $pipelines_updated ) );

		// Cascade to flows unless --no-cascade-flows.
		$flows_updated = 0;
		if ( ! $no_cascade ) {
			$flows_updated = $flows_repo->reassign_agent_id( $from_agent_id, $to_agent_id );

			if ( $flows_updated < 0 ) {
				WP_CLI::warning( 'Database error during flow cascade reassignment.' );
			} else {
				WP_CLI::log( sprintf( 'Flows reassigned (cascade): %d', $flows_updated ) );
			}
		}

		WP_CLI::success( sprintf(
			'Done. %d pipeline(s) and %d flow(s) reassigned from agent_id=%s to agent_id=%d.',
			$pipelines_updated,
			$flows_updated,
			$source_label,
			$to_agent_id
		) );
	}

	/**
	 * List pipelines with NULL agent_id without mutating data.
	 *
	 * @param array $assoc_args Associative arguments.
	 */
	private function listOrphanedPipelines( array $assoc_args ): void {
		$limit  = max( 1, min( 100, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $assoc_args['offset'] ?? 0 ) );
		$format = $assoc_args['format'] ?? 'table';

		$pipelines_repo = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$rows           = $pipelines_repo->get_orphaned_pipelines( $limit, $offset );
		$total          = $pipelines_repo->count_by_agent_id( null );

		$items = array_map(
			static function ( array $pipeline ): array {
				return array(
					'id'            => (int) ( $pipeline['pipeline_id'] ?? 0 ),
					'name'          => (string) ( $pipeline['pipeline_name'] ?? '' ),
					'user_id'       => (int) ( $pipeline['user_id'] ?? 0 ),
					'portable_slug' => (string) ( $pipeline['portable_slug'] ?? '' ),
					'updated'       => (string) ( $pipeline['updated_at'] ?? '' ),
				);
			},
			$rows
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array(
				'total'     => $total,
				'pipelines' => $items,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No orphaned pipelines found.' );
			return;
		}

		WP_CLI::warning( sprintf( 'Found %d orphaned pipeline(s) with agent_id=NULL.', $total ) );
		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'name', 'user_id', 'portable_slug', 'updated' ) );
		WP_CLI::log( 'Repair with: wp datamachine pipelines reassign --where-null --to-agent=<agent> --dry-run' );
	}
}
