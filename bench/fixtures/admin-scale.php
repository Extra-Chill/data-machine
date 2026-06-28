<?php
/**
 * Benchmark fixture for profiling Data Machine admin surfaces at scale.
 *
 * Run with:
 * wp eval-file bench/fixtures/admin-scale.php -- setup --seed-slug=profile --pipeline-count=25 --flows-per-pipeline=10 --steps-per-flow=4 --payload-size=512
 * wp eval-file bench/fixtures/admin-scale.php -- cleanup --seed-slug=profile
 *
 * @package DataMachine\Bench\Fixtures
 */

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( Pipelines::class ) || ! class_exists( Flows::class ) ) {
	WP_CLI::error( 'Data Machine must be active before running the admin scale benchmark fixture.' );
}

$datamachine_admin_scale_fixture_args = isset( $args ) && is_array( $args ) ? $args : array();

datamachine_admin_scale_fixture_main( $datamachine_admin_scale_fixture_args );

/**
 * Run the fixture action.
 *
 * @param array<int,string> $args Positional and option args passed by wp eval-file.
 */
function datamachine_admin_scale_fixture_main( array $args ): void {
	$action  = $args[0] ?? 'setup';
	$options = datamachine_admin_scale_fixture_parse_options( array_slice( $args, 1 ) );

	try {
		if ( 'cleanup' === $action ) {
			$result = datamachine_admin_scale_fixture_cleanup( (string) ( $options['seed-slug'] ?? 'admin-scale' ) );
		} elseif ( 'setup' === $action ) {
			$config = datamachine_admin_scale_fixture_normalize_config( $options );
			$result = datamachine_admin_scale_fixture_setup( $config );
		} else {
			WP_CLI::error( 'Action must be setup or cleanup.' );
		}
	} catch ( InvalidArgumentException $exception ) {
		WP_CLI::error( $exception->getMessage() );
	}

	WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

/**
 * @param array<int,string> $args Raw option args.
 * @return array<string,string|bool>
 */
function datamachine_admin_scale_fixture_parse_options( array $args ): array {
	$options = array();

	foreach ( $args as $arg ) {
		if ( ! str_starts_with( $arg, '--' ) ) {
			continue;
		}

		$arg = substr( $arg, 2 );
		if ( str_contains( $arg, '=' ) ) {
			list( $key, $value ) = explode( '=', $arg, 2 );
			$options[ $key ]     = $value;
			continue;
		}

		$options[ $arg ] = true;
	}

	return $options;
}

/**
 * @param array<string,string|bool> $options Raw options.
 * @return array<string,int|string> Normalized config.
 */
function datamachine_admin_scale_fixture_normalize_config( array $options ): array {
	return array(
		'seed_slug'          => datamachine_admin_scale_fixture_seed_slug( (string) ( $options['seed-slug'] ?? 'admin-scale' ) ),
		'pipeline_count'     => datamachine_admin_scale_fixture_bounded_int( $options['pipeline-count'] ?? 10, 1, 500, 'pipeline-count' ),
		'flows_per_pipeline' => datamachine_admin_scale_fixture_bounded_int( $options['flows-per-pipeline'] ?? 10, 0, 1000, 'flows-per-pipeline' ),
		'steps_per_flow'     => datamachine_admin_scale_fixture_bounded_int( $options['steps-per-flow'] ?? 3, 1, 100, 'steps-per-flow' ),
		'payload_size'       => datamachine_admin_scale_fixture_bounded_int( $options['payload-size'] ?? 0, 0, 1048576, 'payload-size' ),
	);
}

/**
 * @param array<string,int|string> $config Fixture config.
 * @return array<string,mixed> Result packet.
 */
function datamachine_admin_scale_fixture_setup( array $config ): array {
	$cleanup      = datamachine_admin_scale_fixture_cleanup( (string) $config['seed_slug'] );
	$payload      = datamachine_admin_scale_fixture_payload( (int) $config['payload_size'] );
	$pipelines    = new Pipelines();
	$flows        = new Flows();
	$pipeline_ids = array();
	$flow_ids     = array();

	for ( $pipeline_index = 1; $pipeline_index <= $config['pipeline_count']; $pipeline_index++ ) {
		$pipeline_slug = datamachine_admin_scale_fixture_pipeline_slug( (string) $config['seed_slug'], $pipeline_index );
		$pipeline_id   = $pipelines->create_pipeline(
			array(
				'pipeline_name'   => datamachine_admin_scale_fixture_pipeline_name( (string) $config['seed_slug'], $pipeline_index ),
				'pipeline_config' => array(),
				'portable_slug'   => $pipeline_slug,
			)
		);

		if ( false === $pipeline_id ) {
			throw new InvalidArgumentException( 'Failed to create benchmark fixture pipeline.' );
		}

		$pipeline_config = datamachine_admin_scale_fixture_pipeline_config( (int) $pipeline_id, $config, $pipeline_index, $payload );
		if ( ! $pipelines->update_pipeline( (int) $pipeline_id, array( 'pipeline_config' => $pipeline_config ) ) ) {
			throw new InvalidArgumentException( 'Failed to update benchmark fixture pipeline config.' );
		}

		$pipeline_ids[] = (int) $pipeline_id;

		for ( $flow_index = 1; $flow_index <= $config['flows_per_pipeline']; $flow_index++ ) {
			$flow_slug = datamachine_admin_scale_fixture_flow_slug( (string) $config['seed_slug'], $pipeline_index, $flow_index );
			$flow_id   = $flows->create_flow(
				array(
					'pipeline_id'       => (int) $pipeline_id,
					'flow_name'         => datamachine_admin_scale_fixture_flow_name( (string) $config['seed_slug'], $pipeline_index, $flow_index ),
					'flow_config'       => array(),
					'scheduling_config' => array( 'type' => 'manual' ),
					'portable_slug'     => $flow_slug,
				)
			);

			if ( false === $flow_id ) {
				throw new InvalidArgumentException( 'Failed to create benchmark fixture flow.' );
			}

			$flow_config = datamachine_admin_scale_fixture_flow_config( (int) $pipeline_id, (int) $flow_id, $pipeline_config, $config, $pipeline_index, $flow_index, $payload );
			if ( ! $flows->update_flow( (int) $flow_id, array( 'flow_config' => $flow_config ) ) ) {
				throw new InvalidArgumentException( 'Failed to update benchmark fixture flow config.' );
			}

			$flow_ids[] = (int) $flow_id;
		}
	}

	return array(
		'success'      => true,
		'mode'         => 'setup',
		'config'       => $config,
		'cleanup'      => $cleanup,
		'created'      => array(
			'pipelines' => count( $pipeline_ids ),
			'flows'     => count( $flow_ids ),
			'steps'     => count( $flow_ids ) * (int) $config['steps_per_flow'],
		),
		'pipeline_ids' => $pipeline_ids,
		'flow_ids'     => $flow_ids,
	);
}

/**
 * @return array<string,mixed>
 */
function datamachine_admin_scale_fixture_cleanup( string $seed_slug ): array {
	$seed_slug         = datamachine_admin_scale_fixture_seed_slug( $seed_slug );
	$pipelines         = new Pipelines();
	$flows             = new Flows();
	$deleted_pipelines = 0;
	$deleted_flows     = 0;

	foreach ( datamachine_admin_scale_fixture_seed_pipelines( $pipelines, $seed_slug ) as $pipeline ) {
		$pipeline_id = (int) ( $pipeline['pipeline_id'] ?? 0 );
		if ( $pipeline_id <= 0 ) {
			continue;
		}

		foreach ( $flows->get_flows_for_pipeline( $pipeline_id ) as $flow ) {
			$flow_slug = (string) ( $flow['portable_slug'] ?? '' );
			if ( '' !== $flow_slug && ! str_starts_with( $flow_slug, 'admin-scale-' . $seed_slug . '-flow-' ) ) {
				continue;
			}

			if ( $flows->delete_flow( (int) $flow['flow_id'] ) ) {
				++$deleted_flows;
			}
		}

		if ( $pipelines->delete_pipeline( $pipeline_id ) ) {
			++$deleted_pipelines;
		}
	}

	return array(
		'success'           => true,
		'mode'              => 'cleanup',
		'seed_slug'         => $seed_slug,
		'deleted_pipelines' => $deleted_pipelines,
		'deleted_flows'     => $deleted_flows,
	);
}

/**
 * @return array<int,array<string,mixed>>
 */
function datamachine_admin_scale_fixture_seed_pipelines( Pipelines $pipelines, string $seed_slug ): array {
	$matches = $pipelines->get_all_pipelines( null, null, 'Data Machine admin scale benchmark ' . $seed_slug );

	return array_values(
		array_filter(
			$matches,
			static function ( array $pipeline ) use ( $seed_slug ): bool {
				return str_starts_with( (string) ( $pipeline['portable_slug'] ?? '' ), 'admin-scale-' . $seed_slug . '-pipeline-' );
			}
		)
	);
}

/**
 * @param array<string,int|string> $config Fixture config.
 * @return array<string,array<string,mixed>>
 */
function datamachine_admin_scale_fixture_pipeline_config( int $pipeline_id, array $config, int $pipeline_index, string $payload ): array {
	$pipeline_config = array();

	for ( $step_index = 1; $step_index <= $config['steps_per_flow']; $step_index++ ) {
		$step_id                     = $pipeline_id . '_bench_step_' . $step_index;
		$pipeline_config[ $step_id ] = array(
			'pipeline_step_id'   => $step_id,
			'step_type'          => 'system_task',
			'execution_order'    => $step_index - 1,
			'label'              => 'Benchmark fixture step ' . $step_index,
			'flow_step_settings' => array(
				'task_type'       => 'retention_logs',
				'fixture_seed'    => $config['seed_slug'],
				'pipeline_index'  => $pipeline_index,
				'step_index'      => $step_index,
				'fixture_payload' => $payload,
			),
		);
	}

	return $pipeline_config;
}

/**
 * @param array<string,array<string,mixed>> $pipeline_config Pipeline config.
 * @param array<string,int|string>          $config Fixture config.
 * @return array<string,array<string,mixed>>
 */
function datamachine_admin_scale_fixture_flow_config( int $pipeline_id, int $flow_id, array $pipeline_config, array $config, int $pipeline_index, int $flow_index, string $payload ): array {
	$flow_config = array();

	foreach ( array_values( $pipeline_config ) as $step_index => $pipeline_step ) {
		$pipeline_step_id             = (string) $pipeline_step['pipeline_step_id'];
		$flow_step_id                 = $pipeline_step_id . '_' . $flow_id;
		$flow_config[ $flow_step_id ] = array(
			'flow_step_id'       => $flow_step_id,
			'pipeline_step_id'   => $pipeline_step_id,
			'pipeline_id'        => $pipeline_id,
			'flow_id'            => $flow_id,
			'step_type'          => 'system_task',
			'execution_order'    => $step_index,
			'label'              => $pipeline_step['label'] ?? 'Benchmark fixture step',
			'flow_step_settings' => array(
				'task_type'       => 'retention_logs',
				'fixture_seed'    => $config['seed_slug'],
				'pipeline_index'  => $pipeline_index,
				'flow_index'      => $flow_index,
				'step_index'      => $step_index + 1,
				'fixture_payload' => $payload,
			),
			'pipeline_config'    => $pipeline_step,
		);
	}

	return $flow_config;
}

function datamachine_admin_scale_fixture_seed_slug( string $seed_slug ): string {
	$seed_slug = sanitize_title( $seed_slug );
	if ( '' === $seed_slug ) {
		throw new InvalidArgumentException( 'Seed slug must contain at least one URL-safe character.' );
	}

	return $seed_slug;
}

function datamachine_admin_scale_fixture_bounded_int( mixed $value, int $min, int $max, string $field ): int {
	$value = is_numeric( $value ) ? (int) $value : $min;
	if ( $value < $min || $value > $max ) {
		throw new InvalidArgumentException( sprintf( '%s must be between %d and %d.', esc_html( $field ), esc_html( (string) $min ), esc_html( (string) $max ) ) );
	}

	return $value;
}

function datamachine_admin_scale_fixture_payload( int $payload_size ): string {
	return $payload_size > 0 ? str_repeat( 'x', $payload_size ) : '';
}

function datamachine_admin_scale_fixture_pipeline_slug( string $seed_slug, int $pipeline_index ): string {
	return 'admin-scale-' . $seed_slug . '-pipeline-' . $pipeline_index;
}

function datamachine_admin_scale_fixture_flow_slug( string $seed_slug, int $pipeline_index, int $flow_index ): string {
	return 'admin-scale-' . $seed_slug . '-flow-' . $pipeline_index . '-' . $flow_index;
}

function datamachine_admin_scale_fixture_pipeline_name( string $seed_slug, int $pipeline_index ): string {
	return 'Data Machine admin scale benchmark ' . $seed_slug . ' pipeline ' . $pipeline_index;
}

function datamachine_admin_scale_fixture_flow_name( string $seed_slug, int $pipeline_index, int $flow_index ): string {
	return 'Data Machine admin scale benchmark ' . $seed_slug . ' pipeline ' . $pipeline_index . ' flow ' . $flow_index;
}
