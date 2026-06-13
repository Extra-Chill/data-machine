<?php
/**
 * WP-CLI fixture commands.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Fixtures\AdminScaleFixture;
use InvalidArgumentException;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Provides reusable Data Machine fixture setup commands.
 */
class FixturesCommand extends BaseCommand {

	/**
	 * Manage fixture data.
	 *
	 * ## OPTIONS
	 *
	 * <fixture>
	 * : Fixture name. Accepts: admin-scale.
	 *
	 * [<action>]
	 * : Fixture action. Accepts: setup, cleanup.
	 * ---
	 * default: setup
	 * ---
	 *
	 * [--seed-slug=<slug>]
	 * : Seed slug used for idempotent replacement and cleanup.
	 * ---
	 * default: admin-scale
	 * ---
	 *
	 * [--pipeline-count=<number>]
	 * : Number of pipelines to create.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--flows-per-pipeline=<number>]
	 * : Number of flows to create for each pipeline.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--steps-per-flow=<number>]
	 * : Number of steps to create for each pipeline and flow config.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--payload-size=<bytes>]
	 * : Fixture payload bytes attached to each step config.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--[no-]replace]
	 * : Delete existing records for the seed before setup.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine fixtures admin-scale setup --seed-slug=profile --pipeline-count=25 --flows-per-pipeline=10 --steps-per-flow=4 --payload-size=512 --format=json
	 *     wp datamachine fixtures admin-scale cleanup --seed-slug=profile
	 *
	 * @param array<int,string>   $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$fixture = $args[0] ?? '';
		$action  = $args[1] ?? 'setup';

		if ( 'admin-scale' !== $fixture ) {
			WP_CLI::error( 'Usage: wp datamachine fixtures admin-scale [setup|cleanup] [--seed-slug=<slug>]' );
		}

		try {
			$service = new AdminScaleFixture();
			$result  = $this->run_admin_scale_action( $service, $action, $assoc_args );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
		}

		$this->output_result( $result, (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * @param array<string,mixed> $assoc_args CLI associative args.
	 * @return array<string,mixed>
	 */
	private function run_admin_scale_action( AdminScaleFixture $service, string $action, array $assoc_args ): array {
		$config = array(
			'seed_slug'          => $assoc_args['seed-slug'] ?? $assoc_args['seed_slug'] ?? 'admin-scale',
			'pipeline_count'     => $assoc_args['pipeline-count'] ?? $assoc_args['pipeline_count'] ?? 10,
			'flows_per_pipeline' => $assoc_args['flows-per-pipeline'] ?? $assoc_args['flows_per_pipeline'] ?? 10,
			'steps_per_flow'     => $assoc_args['steps-per-flow'] ?? $assoc_args['steps_per_flow'] ?? 3,
			'payload_size'       => $assoc_args['payload-size'] ?? $assoc_args['payload_size'] ?? 0,
		);

		if ( 'cleanup' === $action ) {
			return $service->cleanup( (string) $config['seed_slug'] );
		}

		if ( 'setup' !== $action ) {
			throw new InvalidArgumentException( 'Action must be setup or cleanup.' );
		}

		$replace = ! isset( $assoc_args['no-replace'] ) && ! isset( $assoc_args['no_replace'] );
		return $replace ? $service->replace( $config ) : $service->create( $config );
	}

	/**
	 * @param array<string,mixed> $result Result packet.
	 */
	private function output_result( array $result, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( 'yaml', array( $result ), array_keys( $result ) );
			return;
		}

		$rows = array(
			array(
				'mode'      => $result['mode'] ?? '',
				'seed_slug' => $result['config']['seed_slug'] ?? $result['seed_slug'] ?? '',
				'pipelines' => $result['created']['pipelines'] ?? $result['deleted_pipelines'] ?? 0,
				'flows'     => $result['created']['flows'] ?? $result['deleted_flows'] ?? 0,
				'steps'     => $result['created']['steps'] ?? 0,
			),
		);

		$this->format_items( $rows, array( 'mode', 'seed_slug', 'pipelines', 'flows', 'steps' ), array( 'format' => 'table' ) );
	}
}
