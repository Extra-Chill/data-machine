<?php
/**
 * WP-CLI Pipelines Command
 *
 * Provides CLI access to pipeline listing and management operations.
 * Wraps PipelineAbilities API primitives.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.16.0 Added create, update, delete subcommands.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class PipelinesCommand extends BaseCommand {

	/**
	 * Default fields for pipeline list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'steps', 'step_types', 'flows', 'updated' );

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
	 * [--name=<name>]
	 * : Pipeline name (create/update subcommands).
	 *
	 * [--steps=<json>]
	 * : JSON array of steps (create subcommand). Each step: {step_type, label?}.
	 *
	 * [--config=<json>]
	 * : JSON object with pipeline configuration (update subcommand).
	 *
	 * [--force]
	 * : Skip confirmation prompt (delete subcommand).
	 *
	 * [--dry-run]
	 * : Validate without creating (create subcommand).
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
	 *     # Delete a pipeline (skip confirmation)
	 *     wp datamachine pipelines delete 5 --force
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$pipeline_id = null;

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

		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$ability = new \DataMachine\Abilities\PipelineAbilities();

		if ( $pipeline_id ) {
			$result = $ability->executeGetPipelines(
				array(
					'pipeline_id' => $pipeline_id,
					'output_mode' => 'full',
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
			$result = $ability->executeGetPipelines(
				array(
					'per_page'    => $per_page,
					'offset'      => $offset,
					'output_mode' => 'full',
				)
			);

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

			// Transform pipelines to flat row format.
			$items = array_map(
				function ( $pipeline ) {
					$config = $pipeline['pipeline_config'] ?? array();
					$flows  = $pipeline['flows'] ?? array();
					return array(
						'id'         => $pipeline['pipeline_id'],
						'name'       => $pipeline['pipeline_name'],
						'steps'      => count( $config ),
						'step_types' => $this->extractStepTypes( $config ),
						'flows'      => count( $flows ),
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
			$decoded = json_decode( $assoc_args['steps'], true );
			if ( null === $decoded && '' !== $assoc_args['steps'] ) {
				WP_CLI::error( 'Invalid JSON in --steps' );
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

		$ability = new \DataMachine\Abilities\PipelineAbilities();
		$result  = $ability->executeCreatePipeline( $input );

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

		$input = array( 'pipeline_id' => $pipeline_id );

		if ( isset( $assoc_args['name'] ) ) {
			$input['pipeline_name'] = $assoc_args['name'];
		}

		if ( ! isset( $input['pipeline_name'] ) ) {
			WP_CLI::error( 'Must provide --name to update' );
			return;
		}

		$ability = new \DataMachine\Abilities\PipelineAbilities();
		$result  = $ability->executeUpdatePipeline( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to update pipeline' );
			return;
		}

		WP_CLI::success( sprintf( 'Pipeline %d updated.', $result['pipeline_id'] ) );
		WP_CLI::log( sprintf( 'Name: %s', $result['pipeline_name'] ) );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		}
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
		$ability = new \DataMachine\Abilities\PipelineAbilities();
		$info    = $ability->executeGetPipelines( array( 'pipeline_id' => $pipeline_id ) );

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

		$result = $ability->executeDeletePipeline( array( 'pipeline_id' => $pipeline_id ) );

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
}
