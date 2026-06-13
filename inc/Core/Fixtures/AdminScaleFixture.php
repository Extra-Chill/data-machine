<?php
/**
 * Admin scale fixture for profiling Data Machine admin surfaces.
 *
 * @package DataMachine\Core\Fixtures
 */

namespace DataMachine\Core\Fixtures;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bounded pipeline/flow scale fixtures owned by Data Machine.
 */
class AdminScaleFixture {

	private const NAME_PREFIX = 'Data Machine admin scale fixture';
	private const SLUG_PREFIX = 'admin-scale';
	private const MAX_PIPELINES = 500;
	private const MAX_FLOWS_PER_PIPELINE = 1000;
	private const MAX_STEPS_PER_FLOW = 100;
	private const MAX_PAYLOAD_SIZE = 1048576;

	private Pipelines $pipelines;
	private Flows $flows;

	public function __construct( ?Pipelines $pipelines = null, ?Flows $flows = null ) {
		$this->pipelines = $pipelines ?? new Pipelines();
		$this->flows     = $flows ?? new Flows();
	}

	/**
	 * Replace any fixture records for the seed and recreate them.
	 *
	 * @param array<string,mixed> $config Fixture config.
	 * @return array<string,mixed> Result packet.
	 */
	public function replace( array $config ): array {
		$config  = self::normalize_config( $config );
		$cleanup = $this->cleanup( $config['seed_slug'] );
		$created = $this->create( $config );

		$created['cleanup'] = $cleanup;
		$created['mode']    = 'replace';

		return $created;
	}

	/**
	 * Create fixture records without deleting existing rows first.
	 *
	 * @param array<string,mixed> $config Fixture config.
	 * @return array<string,mixed> Result packet.
	 */
	public function create( array $config ): array {
		$config       = self::normalize_config( $config );
		$payload      = self::payload( $config['payload_size'] );
		$pipeline_ids = array();
		$flow_ids     = array();

		for ( $pipeline_index = 1; $pipeline_index <= $config['pipeline_count']; $pipeline_index++ ) {
			$pipeline_slug = self::pipeline_slug( $config['seed_slug'], $pipeline_index );
			$pipeline_name = self::NAME_PREFIX . ' ' . $config['seed_slug'] . ' pipeline ' . $pipeline_index;

			$pipeline_id = $this->pipelines->create_pipeline(
				array(
					'pipeline_name'   => $pipeline_name,
					'pipeline_config' => array(),
					'portable_slug'   => $pipeline_slug,
				)
			);

			if ( false === $pipeline_id ) {
				throw new InvalidArgumentException( 'Failed to create admin scale fixture pipeline.' );
			}

			$pipeline_config = $this->build_pipeline_config( (int) $pipeline_id, $config, $pipeline_index, $payload );
			$pipeline_updated = $this->pipelines->update_pipeline(
				(int) $pipeline_id,
				array(
					'pipeline_config' => $pipeline_config,
					'portable_slug'   => $pipeline_slug,
				)
			);
			if ( ! $pipeline_updated ) {
				throw new InvalidArgumentException( 'Failed to update admin scale fixture pipeline config.' );
			}

			$pipeline_ids[] = (int) $pipeline_id;

			for ( $flow_index = 1; $flow_index <= $config['flows_per_pipeline']; $flow_index++ ) {
				$flow_slug = self::flow_slug( $config['seed_slug'], $pipeline_index, $flow_index );
				$flow_name = self::NAME_PREFIX . ' ' . $config['seed_slug'] . ' pipeline ' . $pipeline_index . ' flow ' . $flow_index;

				$flow_id = $this->flows->create_flow(
					array(
						'pipeline_id'       => (int) $pipeline_id,
						'flow_name'         => $flow_name,
						'flow_config'       => array(),
						'scheduling_config' => array( 'type' => 'manual' ),
						'portable_slug'     => $flow_slug,
					)
				);

				if ( false === $flow_id ) {
					throw new InvalidArgumentException( 'Failed to create admin scale fixture flow.' );
				}

				$flow_updated = $this->flows->update_flow(
					(int) $flow_id,
					array(
						'flow_config'       => $this->build_flow_config( (int) $pipeline_id, (int) $flow_id, $pipeline_config, $config, $pipeline_index, $flow_index, $payload ),
						'scheduling_config' => array( 'type' => 'manual' ),
						'portable_slug'     => $flow_slug,
					)
				);
				if ( ! $flow_updated ) {
					throw new InvalidArgumentException( 'Failed to update admin scale fixture flow config.' );
				}

				$flow_ids[] = (int) $flow_id;
			}
		}

		return array(
			'success'      => true,
			'mode'         => 'create',
			'config'       => $config,
			'created'      => array(
				'pipelines' => count( $pipeline_ids ),
				'flows'     => count( $flow_ids ),
				'steps'     => count( $flow_ids ) * $config['steps_per_flow'],
			),
			'pipeline_ids' => $pipeline_ids,
			'flow_ids'     => $flow_ids,
		);
	}

	/**
	 * Delete fixture records for a seed.
	 *
	 * @param string $seed_slug Seed slug.
	 * @return array<string,mixed> Cleanup result packet.
	 */
	public function cleanup( string $seed_slug ): array {
		$seed_slug = self::normalize_seed_slug( $seed_slug );
		$pipelines = $this->find_seed_pipelines( $seed_slug );

		$deleted_flows     = 0;
		$deleted_pipelines = 0;

		foreach ( $pipelines as $pipeline ) {
			$pipeline_id = (int) ( $pipeline['pipeline_id'] ?? 0 );
			if ( $pipeline_id <= 0 ) {
				continue;
			}

			foreach ( $this->flows->get_flows_for_pipeline( $pipeline_id ) as $flow ) {
				$flow_slug = (string) ( $flow['portable_slug'] ?? '' );
				if ( '' !== $flow_slug && ! str_starts_with( $flow_slug, self::SLUG_PREFIX . '-' . $seed_slug . '-flow-' ) ) {
					continue;
				}

				if ( $this->flows->delete_flow( (int) $flow['flow_id'] ) ) {
					$deleted_flows++;
				}
			}

			if ( $this->pipelines->delete_pipeline( $pipeline_id ) ) {
				$deleted_pipelines++;
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
	 * Normalize and bound fixture config.
	 *
	 * @param array<string,mixed> $config Raw config.
	 * @return array<string,int|string> Normalized config.
	 */
	public static function normalize_config( array $config ): array {
		return array(
			'seed_slug'          => self::normalize_seed_slug( (string) ( $config['seed_slug'] ?? 'admin-scale' ) ),
			'pipeline_count'     => self::bounded_int( $config['pipeline_count'] ?? 10, 1, self::MAX_PIPELINES, 'pipeline_count' ),
			'flows_per_pipeline' => self::bounded_int( $config['flows_per_pipeline'] ?? 10, 0, self::MAX_FLOWS_PER_PIPELINE, 'flows_per_pipeline' ),
			'steps_per_flow'     => self::bounded_int( $config['steps_per_flow'] ?? 3, 1, self::MAX_STEPS_PER_FLOW, 'steps_per_flow' ),
			'payload_size'       => self::bounded_int( $config['payload_size'] ?? 0, 0, self::MAX_PAYLOAD_SIZE, 'payload_size' ),
		);
	}

	private function build_pipeline_config( int $pipeline_id, array $config, int $pipeline_index, string $payload ): array {
		$pipeline_config = array();

		for ( $step_index = 1; $step_index <= $config['steps_per_flow']; $step_index++ ) {
			$step_id                     = self::pipeline_step_id( $pipeline_id, $step_index );
			$pipeline_config[ $step_id ] = array(
				'pipeline_step_id' => $step_id,
				'step_type'        => 'system_task',
				'execution_order'  => $step_index - 1,
				'label'            => 'Fixture step ' . $step_index,
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

	private function build_flow_config( int $pipeline_id, int $flow_id, array $pipeline_config, array $config, int $pipeline_index, int $flow_index, string $payload ): array {
		$flow_config = array();

		foreach ( array_values( $pipeline_config ) as $step_index => $pipeline_step ) {
			$pipeline_step_id               = (string) $pipeline_step['pipeline_step_id'];
			$flow_step_id                   = $pipeline_step_id . '_' . $flow_id;
			$flow_config[ $flow_step_id ] = array(
				'flow_step_id'     => $flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'pipeline_id'      => $pipeline_id,
				'flow_id'          => $flow_id,
				'step_type'        => 'system_task',
				'execution_order'  => $step_index,
				'label'            => $pipeline_step['label'] ?? 'Fixture step',
				'flow_step_settings' => array(
					'task_type'       => 'retention_logs',
					'fixture_seed'    => $config['seed_slug'],
					'pipeline_index'  => $pipeline_index,
					'flow_index'      => $flow_index,
					'step_index'      => $step_index + 1,
					'fixture_payload' => $payload,
				),
				'pipeline_config' => $pipeline_step,
			);
		}

		return $flow_config;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function find_seed_pipelines( string $seed_slug ): array {
		$search    = self::NAME_PREFIX . ' ' . $seed_slug;
		$pipelines = $this->pipelines->get_all_pipelines( null, null, $search );

		return array_values(
			array_filter(
				$pipelines,
				static function ( array $pipeline ) use ( $seed_slug ): bool {
					$portable_slug = (string) ( $pipeline['portable_slug'] ?? '' );
					return str_starts_with( $portable_slug, self::SLUG_PREFIX . '-' . $seed_slug . '-pipeline-' );
				}
			)
		);
	}

	private static function normalize_seed_slug( string $seed_slug ): string {
		$seed_slug = sanitize_title( $seed_slug );
		if ( '' === $seed_slug ) {
			throw new InvalidArgumentException( 'Seed slug must contain at least one URL-safe character.' );
		}

		return $seed_slug;
	}

	private static function bounded_int( mixed $value, int $min, int $max, string $field ): int {
		$value = is_numeric( $value ) ? (int) $value : $min;
		if ( $value < $min || $value > $max ) {
			throw new InvalidArgumentException( sprintf( '%s must be between %d and %d.', $field, $min, $max ) );
		}

		return $value;
	}

	private static function payload( int $payload_size ): string {
		return $payload_size > 0 ? str_repeat( 'x', $payload_size ) : '';
	}

	private static function pipeline_slug( string $seed_slug, int $pipeline_index ): string {
		return self::SLUG_PREFIX . '-' . $seed_slug . '-pipeline-' . $pipeline_index;
	}

	private static function flow_slug( string $seed_slug, int $pipeline_index, int $flow_index ): string {
		return self::SLUG_PREFIX . '-' . $seed_slug . '-flow-' . $pipeline_index . '-' . $flow_index;
	}

	private static function pipeline_step_id( int $pipeline_id, int $step_index ): string {
		return $pipeline_id . '_fixture_step_' . $step_index;
	}
}
