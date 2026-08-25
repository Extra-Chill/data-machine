<?php
/**
 * Data Machine Import/Export Actions
 *
 * Handles pipeline import/export operations including CSV generation and parsing.
 * All logic is contained here - no separate service class needed.
 *
 * @package DataMachine
 * @since 1.0.0
 */

namespace DataMachine\Engine\Actions;

use DataMachine\Api\Flows\FlowScheduling;
use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\FlowStepConfigFactory;
use DataMachine\Engine\Bundle\AuthRefHandlerConfig;
use DataMachine\Engine\PortableFlowStepFields;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
	die;
}

class ImportExport {

	private const CSV_FORMAT_VERSION = '1.0';
	private const CSV_HEADER         = array( 'format_version', 'row_type', 'pipeline_id', 'pipeline_name', 'step_position', 'step_type', 'step_config', 'flow_id', 'flow_name', 'settings' );

	/**
	 * Import canonical pipeline CSV.
	 *
	 * Three-pass CSV import:
	 *   Pass 1 — pipeline rows: create pipelines and add or reuse steps
	 *            with their full step_config (#1133 step 1).
	 *   Pass 2 — flow metadata rows: create or update every named flow with its
	 *            canonical scheduling configuration.
	 *   Pass 3 — flow-step rows: write
	 *            canonical handler fields into each flow_config entry keyed by the
	 *            freshly-generated flow_step_id (#1133 step 2, #1293 shape cleanup).
	 *
	 * Handler configs are restored as exported. Default exports contain portable auth refs
	 * and ordinary settings, never inline credentials.
	 */
	public function handle_import( $type, $data ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			do_action( 'datamachine_log', 'error', 'Import requires manage_options capability' );
			return false;
		}

		if ( 'pipelines' !== $type ) {
			do_action( 'datamachine_log', 'error', "Unknown import type: {$type}" );
			return false;
		}

		try {
			$parsed_rows = $this->parse_csv_rows( $data );
		} catch ( \InvalidArgumentException $e ) {
			do_action( 'datamachine_log', 'error', 'Import: ' . $e->getMessage() );
			return new \WP_Error(
				'invalid_pipeline_csv',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		}
		if ( ! $this->validate_destination_compatibility( $parsed_rows ) ) {
			return false;
		}

		$imported_pipelines = array();
		// pipeline_name => imported pipeline_id.
		$pipeline_ids_by_name = array();
		// [pipeline_name][(int) step_position] => imported pipeline_step_id.
		$step_id_map = array();
		// [pipeline_name][source_flow_id] => imported flow_id.
		$flow_id_map          = array();
		$expected_step_counts = array();
		foreach ( $parsed_rows as $row ) {
			if ( 'pipeline_step' === $row['row_type'] ) {
				$expected_step_counts[ $row['pipeline_name'] ] = ( $expected_step_counts[ $row['pipeline_name'] ] ?? 0 ) + 1;
			}
		}

		// Pass 1: pipelines + steps.
		foreach ( $parsed_rows as $row ) {
			$pipeline_name = $row['pipeline_name'];

			if ( ! isset( $pipeline_ids_by_name[ $pipeline_name ] ) ) {
				$pipeline_id = $this->ensure_pipeline( $pipeline_name );
				if ( ! $pipeline_id ) {
					return false;
				}
				$pipeline_ids_by_name[ $pipeline_name ] = $pipeline_id;
				$imported_pipelines[]                   = $pipeline_id;
				if ( ! $this->trim_pipeline_steps( $pipeline_id, (int) ( $expected_step_counts[ $pipeline_name ] ?? 0 ) ) ) {
					return false;
				}
			}

			if ( 'pipeline_step' !== $row['row_type'] ) {
				continue;
			}

			$pipeline_step_id = $this->ensure_pipeline_step(
				$pipeline_ids_by_name[ $pipeline_name ],
				$row['step_position'],
				$row['step_type'],
				$row['step_config']
			);
			if ( $pipeline_step_id ) {
				$step_id_map[ $pipeline_name ][ $row['step_position'] ] = $pipeline_step_id;
			} else {
				return false;
			}
		}

		// Pass 2: durable flow metadata.
		foreach ( $parsed_rows as $row ) {
			if ( 'flow' !== $row['row_type'] ) {
				continue;
			}

			$pipeline_name = $row['pipeline_name'];
			if ( ! isset( $pipeline_ids_by_name[ $pipeline_name ] ) ) {
				continue;
			}

			$imported_pipeline_id = $pipeline_ids_by_name[ $pipeline_name ];
			$source_flow_id       = $row['flow_id'];
			$flow_name            = $row['flow_name'];

			$new_flow_id = $this->ensure_flow( $imported_pipeline_id, $flow_name, $row['metadata'] );
			if ( ! $new_flow_id ) {
				return false;
			}
			$flow_id_map[ $pipeline_name ][ $source_flow_id ] = $new_flow_id;
		}

		// Pass 3: flow-step settings.
		foreach ( $parsed_rows as $row ) {
			if ( 'flow_step' !== $row['row_type'] ) {
				continue;
			}

			$pipeline_name  = $row['pipeline_name'];
			$source_flow_id = $row['flow_id'];
			if ( ! isset( $flow_id_map[ $pipeline_name ][ $source_flow_id ] ) ) {
				return false;
			}

			$imported_flow_id = $flow_id_map[ $pipeline_name ][ $source_flow_id ];
			$pipeline_step_id = $step_id_map[ $pipeline_name ][ $row['step_position'] ] ?? null;
			if ( ! $pipeline_step_id ) {
				return false;
			}

			$settings = $row['settings'];

			try {
				$restored = $this->restore_flow_step_config(
					$imported_flow_id,
					$pipeline_step_id,
					$row['step_type'],
					$settings
				);
				if ( ! $restored ) {
					return false;
				}
			} catch ( \InvalidArgumentException $e ) {
				do_action( 'datamachine_log', 'error', 'Import: malformed portable flow-step settings: ' . $e->getMessage() );
				return false;
			}
		}

		$result = array( 'imported' => array_unique( $imported_pipelines ) );

		do_action( 'datamachine_log', 'debug', 'Pipeline import completed', array( 'count' => count( $result['imported'] ) ) );
		return $result;
	}

	/**
	 * Parse the import CSV into a normalized row list.
	 *
	 * @param string $data Raw CSV content.
	 * @return array<int, array{
	 *     row_type:string, pipeline_name:string, step_position:int, step_type:string,
	 *     step_config:array, flow_id:string, flow_name:string, settings:array, metadata:array
	 * }>
	 */
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are logged, not rendered.
	private function parse_csv_rows( string $data ): array {
		$rows   = str_getcsv( $data, "\n" );
		$parsed = array();
		$header = isset( $rows[0] ) ? str_getcsv( $rows[0] ) : array();
		if ( self::CSV_HEADER !== $header ) {
			throw $this->csv_error( 'CSV header does not match the Data Machine 1.0 pipeline format.' );
		}

		foreach ( $rows as $index => $row ) {
			if ( 0 === $index ) {
				continue;
			}

			$cols = str_getcsv( $row );
			if ( 1 === count( $cols ) && '' === trim( (string) $cols[0] ) ) {
				continue;
			}
			if ( count( $cols ) !== count( self::CSV_HEADER ) ) {
				throw $this->csv_error( sprintf( 'CSV row %d must contain exactly %d columns.', $index + 1, count( self::CSV_HEADER ) ) );
			}
			if ( self::CSV_FORMAT_VERSION !== $cols[0] ) {
				throw $this->csv_error( sprintf( 'CSV row %d uses unsupported format version %s.', $index + 1, (string) $cols[0] ) );
			}

			$row_type = (string) $cols[1];
			if ( ! in_array( $row_type, array( 'pipeline', 'pipeline_step', 'flow', 'flow_step' ), true ) ) {
				throw $this->csv_error( sprintf( 'CSV row %d has invalid row_type.', $index + 1 ) );
			}

			$pipeline_name = (string) $cols[3];
			if ( '' === trim( $pipeline_name ) ) {
				throw $this->csv_error( sprintf( 'CSV row %d is missing pipeline_name.', $index + 1 ) );
			}
			$source_pipeline_id = (string) $cols[2];
			if ( '' === $source_pipeline_id ) {
				throw $this->csv_error( sprintf( 'CSV row %d is missing pipeline_id.', $index + 1 ) );
			}

			$step_config = array();
			if ( 'pipeline_step' === $row_type ) {
				if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', (string) $cols[4] ) ) {
					throw $this->csv_error( sprintf( 'CSV pipeline_step row %d has invalid step_position.', $index + 1 ) );
				}
				$step_config = json_decode( $cols[6], true );
				if ( ! is_array( $step_config ) || ( ! empty( $step_config ) && array_is_list( $step_config ) ) ) {
					throw $this->csv_error( sprintf( 'CSV pipeline_step row %d has malformed step_config.', $index + 1 ) );
				}
				if ( '' === (string) $cols[5] ) {
					throw $this->csv_error( sprintf( 'CSV pipeline_step row %d is missing step_type.', $index + 1 ) );
				}
			}

			$settings = array();
			if ( in_array( $row_type, array( 'flow', 'flow_step' ), true ) ) {
				$settings = json_decode( $cols[9], true );
				if ( ! is_array( $settings ) || ( ! empty( $settings ) && array_is_list( $settings ) ) ) {
					throw $this->csv_error( sprintf( 'CSV %s row %d has malformed settings.', $row_type, $index + 1 ) );
				}
				if ( '' === (string) $cols[7] ) {
					throw $this->csv_error( sprintf( 'CSV %s row %d is missing flow_id.', $row_type, $index + 1 ) );
				}
				if ( 'flow_step' === $row_type && empty( $settings ) ) {
					throw $this->csv_error( sprintf( 'CSV flow_step row %d must contain portable settings.', $index + 1 ) );
				}
				if ( 'flow_step' === $row_type ) {
					if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/', (string) $cols[4] ) ) {
						throw $this->csv_error( sprintf( 'CSV flow_step row %d has invalid step_position.', $index + 1 ) );
					}
					foreach ( array( 'handler_configs', 'flow_step_settings' ) as $object_field ) {
						if ( array_key_exists( $object_field, $settings ) && ( ! is_array( $settings[ $object_field ] ) || ( ! empty( $settings[ $object_field ] ) && array_is_list( $settings[ $object_field ] ) ) ) ) {
							throw $this->csv_error( sprintf( 'CSV flow_step row %d field %s must be an object.', $index + 1, $object_field ) );
						}
					}
					if ( isset( $settings['handler_configs'] ) ) {
						foreach ( $settings['handler_configs'] as $handler_slug => $handler_config ) {
							if ( ! is_string( $handler_slug ) || ! is_array( $handler_config ) || ( ! empty( $handler_config ) && array_is_list( $handler_config ) ) ) {
								throw $this->csv_error( sprintf( 'CSV flow_step row %d handler_configs entries must be objects keyed by handler slug.', $index + 1 ) );
							}
						}
					}
					$this->normalize_portable_flow_step_settings( $settings );
				}
			}

			$metadata = array();
			if ( 'flow' === $row_type ) {
				if ( '' === trim( (string) $cols[8] ) ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d is missing flow_name.', $index + 1 ) );
				}
				if ( ! array_key_exists( 'scheduling_config', $settings ) || ! is_array( $settings['scheduling_config'] ) || ( ! empty( $settings['scheduling_config'] ) && array_is_list( $settings['scheduling_config'] ) ) ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d must contain an object scheduling_config.', $index + 1 ) );
				}
				if ( empty( $settings['portable_slug'] ) || ! is_string( $settings['portable_slug'] ) ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d must contain a non-empty portable_slug.', $index + 1 ) );
				}
				$settings['portable_slug'] = sanitize_title( $settings['portable_slug'] );
				if ( '' === $settings['portable_slug'] ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d portable_slug is invalid.', $index + 1 ) );
				}
				$unsupported_metadata = array_diff( array_keys( $settings ), array( 'scheduling_config', 'portable_slug' ) );
				if ( ! empty( $unsupported_metadata ) ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d contains unsupported metadata: %s.', $index + 1, implode( ', ', $unsupported_metadata ) ) );
				}
				$schedule_validation = datamachine_validate_interval( (string) ( $settings['scheduling_config']['interval'] ?? 'manual' ), $settings['scheduling_config'] );
				if ( empty( $schedule_validation['valid'] ) ) {
					throw $this->csv_error( sprintf( 'CSV flow row %d has invalid scheduling_config: %s.', $index + 1, (string) ( $schedule_validation['error'] ?? 'invalid interval' ) ) );
				}
				$settings['scheduling_config']['interval'] = (string) $schedule_validation['resolved'];
				foreach ( array( 'enabled', 'paused' ) as $boolean_field ) {
					if ( array_key_exists( $boolean_field, $settings['scheduling_config'] ) && ! is_bool( $settings['scheduling_config'][ $boolean_field ] ) ) {
						throw $this->csv_error( sprintf( 'CSV flow row %d scheduling_config field %s must be a boolean.', $index + 1, $boolean_field ) );
					}
				}
				$metadata = $settings;
			}

			$parsed[] = array(
				'row_type'      => $row_type,
				'pipeline_id'   => $source_pipeline_id,
				'pipeline_name' => $pipeline_name,
				'step_position' => '' === $cols[4] ? -1 : (int) $cols[4],
				'step_type'     => (string) $cols[5],
				'step_config'   => $step_config,
				'flow_id'       => (string) $cols[7],
				'flow_name'     => (string) $cols[8],
				'settings'      => $settings,
				'metadata'      => $metadata,
			);
		}

		$this->validate_csv_relationships( $parsed );
		return $parsed;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Build a CSV validation exception whose text is logged, never rendered.
	 */
	private function csv_error( string $message ): \InvalidArgumentException {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is logged, not rendered.
		return new \InvalidArgumentException( $message );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exceptions are logged, never rendered.
	/** Validate all row relationships before import performs its first write. */
	private function validate_csv_relationships( array $rows ): void {
		$steps            = array();
		$flows            = array();
		$flow_slugs       = array();
		$pipeline_sources = array();
		$source_names     = array();
		$flow_steps       = array();
		foreach ( $rows as $row ) {
			$pipeline = $row['pipeline_name'];
			if ( isset( $pipeline_sources[ $pipeline ] ) && $pipeline_sources[ $pipeline ] !== $row['pipeline_id'] ) {
				throw $this->csv_error( sprintf( 'CSV pipeline_name %s maps to multiple source pipeline IDs.', $pipeline ) );
			}
			$pipeline_sources[ $pipeline ] = $row['pipeline_id'];
			if ( isset( $source_names[ $row['pipeline_id'] ] ) && $source_names[ $row['pipeline_id'] ] !== $pipeline ) {
				throw $this->csv_error( sprintf( 'CSV source pipeline ID %s maps to multiple pipeline names.', $row['pipeline_id'] ) );
			}
			$source_names[ $row['pipeline_id'] ] = $pipeline;
			if ( 'pipeline_step' === $row['row_type'] ) {
				$position = $row['step_position'];
				if ( isset( $steps[ $pipeline ][ $position ] ) ) {
					throw $this->csv_error( sprintf( 'CSV contains duplicate pipeline step position %d for %s.', $position, $pipeline ) );
				}
				$steps[ $pipeline ][ $position ] = $row['step_type'];
			}
			if ( 'flow' === $row['row_type'] ) {
				$flow_id = $row['flow_id'];
				$slug    = $row['metadata']['portable_slug'];
				if ( isset( $flows[ $pipeline ][ $flow_id ] ) ) {
					throw $this->csv_error( sprintf( 'CSV contains duplicate flow metadata for %s.', $flow_id ) );
				}
				if ( isset( $flow_slugs[ $pipeline ][ $slug ] ) ) {
					throw $this->csv_error( sprintf( 'CSV contains duplicate portable_slug %s for %s.', $slug, $pipeline ) );
				}
				$flows[ $pipeline ][ $flow_id ]   = $row['flow_name'];
				$flow_slugs[ $pipeline ][ $slug ] = true;
			}
			if ( 'flow_step' === $row['row_type'] ) {
				$identity = $row['flow_id'] . ':' . $row['step_position'];
				if ( isset( $flow_steps[ $pipeline ][ $identity ] ) ) {
					throw $this->csv_error( sprintf( 'CSV contains duplicate flow_step metadata for %s.', $identity ) );
				}
				$flow_steps[ $pipeline ][ $identity ] = true;
			}
		}

		foreach ( $steps as $pipeline => $positions ) {
			$actual = array_map( 'intval', array_keys( $positions ) );
			sort( $actual, SORT_NUMERIC );
			$expected = range( 0, count( $actual ) - 1 );
			if ( $actual !== $expected ) {
				throw $this->csv_error( sprintf( 'CSV pipeline step positions for %s must be contiguous from zero.', $pipeline ) );
			}
		}

		foreach ( $rows as $row ) {
			if ( 'flow_step' !== $row['row_type'] ) {
				continue;
			}
			$pipeline = $row['pipeline_name'];
			if ( empty( $flows[ $pipeline ][ $row['flow_id'] ] ) || ! isset( $steps[ $pipeline ][ $row['step_position'] ] ) ) {
				throw $this->csv_error( sprintf( 'CSV flow_step row references missing flow or pipeline step metadata for %s.', $pipeline ) );
			}
			if ( (string) $steps[ $pipeline ][ $row['step_position'] ] !== (string) $row['step_type'] ) {
				throw $this->csv_error( sprintf( 'CSV flow_step row step_type does not match pipeline step metadata for %s.', $pipeline ) );
			}
			if ( (string) $flows[ $pipeline ][ $row['flow_id'] ] !== (string) $row['flow_name'] ) {
				throw $this->csv_error( sprintf( 'CSV flow_step row flow_name does not match flow metadata for %s.', $pipeline ) );
			}
		}
	}

	/** Prove existing positional step types are compatible before any trimming or writes. */
	private function validate_destination_compatibility( array $rows ): bool {
		$expected = array();
		foreach ( $rows as $row ) {
			if ( 'pipeline_step' === $row['row_type'] ) {
				$expected[ $row['pipeline_name'] ][ $row['step_position'] ] = $row['step_type'];
			}
		}

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		foreach ( $expected as $pipeline_name => $step_types ) {
			$pipeline_id = $this->find_pipeline_by_name( $pipeline_name );
			if ( ! $pipeline_id ) {
				continue;
			}
			$pipeline = $db_pipelines->get_pipeline( $pipeline_id );
			$steps    = is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array();
			uasort( $steps, static fn( $a, $b ) => ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 ) );
			$steps = array_values( $steps );
			foreach ( $step_types as $position => $step_type ) {
				if ( isset( $steps[ $position ] ) && (string) ( $steps[ $position ]['step_type'] ?? '' ) !== (string) $step_type ) {
						do_action( 'datamachine_log', 'error', 'Import: existing pipeline step type does not match CSV', array(
							'pipeline_id'   => $pipeline_id,
							'step_position' => $position,
						) );
					return false;
				}
			}
		}
		return true;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Ensure a pipeline with the given name exists; return its id.
	 *
	 * Reuses any existing pipeline with a matching name. New pipelines deliberately skip
	 * auto-flow-creation because durable flow metadata rows are authoritative in pass 2.
	 */
	private function ensure_pipeline( string $pipeline_name ): ?int {
		$existing_id = $this->find_pipeline_by_name( $pipeline_name );
		if ( $existing_id ) {
			return (int) $existing_id;
		}

		$ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $ability ) {
			do_action( 'datamachine_log', 'error', 'Import: create-pipeline ability not available' );
			return null;
		}

		$result = $ability->execute(
			array(
				'pipeline_name' => $pipeline_name,
			)
		);

		if ( is_wp_error( $result ) ) {
			do_action( 'datamachine_log', 'error', 'Import: create-pipeline failed: ' . $result->get_error_message() );
			return null;
		}

		if ( empty( $result['success'] ) || empty( $result['pipeline_id'] ) ) {
			return null;
		}

		return (int) $result['pipeline_id'];
	}

	/**
	 * Reuse the step already at this position or add it with its full step_config.
	 */
	private function ensure_pipeline_step( int $pipeline_id, int $position, string $step_type, array $step_config ): ?string {
		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$pipeline     = $db_pipelines->get_pipeline( $pipeline_id );
		$existing     = is_array( $pipeline['pipeline_config'] ?? null ) ? $pipeline['pipeline_config'] : array();
		uasort(
			$existing,
			static fn( $a, $b ) => ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 )
		);
		$existing_step = array_values( $existing )[ $position ] ?? null;
		if ( is_array( $existing_step ) ) {
			if ( ( $existing_step['step_type'] ?? '' ) !== $step_type ) {
				do_action(
					'datamachine_log',
					'error',
					'Import: existing pipeline step type does not match CSV',
					array(
						'pipeline_id'   => $pipeline_id,
						'step_position' => $position,
					)
				);
				return null;
			}
			$pipeline_step_id = isset( $existing_step['pipeline_step_id'] ) ? (string) $existing_step['pipeline_step_id'] : '';
			if ( '' === $pipeline_step_id ) {
				return null;
			}
			$step_config['pipeline_step_id'] = $pipeline_step_id;
			$step_config['pipeline_id']      = $pipeline_id;
			$step_config['step_type']        = $step_type;
			$step_config['execution_order']  = $existing_step['execution_order'] ?? $position;
			$existing[ $pipeline_step_id ]   = $step_config;
			if ( ! $db_pipelines->update_pipeline( $pipeline_id, array( 'pipeline_config' => $existing ) ) ) {
				return null;
			}
			return $pipeline_step_id;
		}

		$ability = wp_get_ability( 'datamachine/add-pipeline-step' );
		if ( ! $ability ) {
			return null;
		}

		$result = $ability->execute(
			array(
				'pipeline_id' => $pipeline_id,
				'step_type'   => $step_type,
				'step_config' => $step_config,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['pipeline_step_id'] ) ) {
			return null;
		}

		return (string) $result['pipeline_step_id'];
	}

	/** Remove destination-only trailing steps through the canonical deletion ability. */
	private function trim_pipeline_steps( int $pipeline_id, int $expected_count ): bool {
		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$steps        = $db_pipelines->get_pipeline_config( $pipeline_id );
		uasort( $steps, static fn( $a, $b ) => ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 ) );
		$steps = array_values( $steps );
		if ( count( $steps ) <= $expected_count ) {
			return true;
		}

		$delete_step = wp_get_ability( 'datamachine/delete-pipeline-step' );
		if ( ! $delete_step ) {
			return false;
		}
		for ( $index = count( $steps ) - 1; $index >= $expected_count; --$index ) {
			$result = $delete_step->execute(
				array(
					'pipeline_id'      => $pipeline_id,
					'pipeline_step_id' => (string) ( $steps[ $index ]['pipeline_step_id'] ?? '' ),
				)
			);
			if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensure a flow matching the given name exists on the pipeline; return its id.
	 *
	 * Reuses stable portable identity first. The name fallback claims only an unkeyed flow,
	 * allowing one pre-format import to converge without collapsing duplicate names.
	 */
	private function ensure_flow( int $pipeline_id, string $flow_name, array $metadata ): ?int {
		$db_flows      = new \DataMachine\Core\Database\Flows\Flows();
		$portable_slug = $metadata['portable_slug'];
		$matched_flow  = $db_flows->get_by_portable_slug( $pipeline_id, $portable_slug );
		$flow_id       = $matched_flow ? (int) $matched_flow['flow_id'] : null;

		if ( null === $flow_id ) {
			$existing = $db_flows->get_flows_for_pipeline( $pipeline_id );
			foreach ( $existing as $flow ) {
				if ( empty( $flow['portable_slug'] ) && isset( $flow['flow_name'] ) && $flow['flow_name'] === $flow_name ) {
					$flow_id = (int) $flow['flow_id'];
					break;
				}
			}
		}

		$scheduling_config = $metadata['scheduling_config'];
		if ( null === $flow_id ) {
			$create_flow = wp_get_ability( 'datamachine/create-flow' );
			if ( ! $create_flow ) {
				do_action( 'datamachine_log', 'error', 'Import: create-flow ability not available' );
				return null;
			}

			$result = $create_flow->execute(
				array(
					'pipeline_id'       => $pipeline_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
				)
			);

			if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['flow_id'] ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Import: failed to create flow',
					array(
						'pipeline_id' => $pipeline_id,
						'flow_name'   => $flow_name,
					)
				);
				return null;
			}
			$flow_id = (int) $result['flow_id'];
		} else {
			$update_flow = wp_get_ability( 'datamachine/update-flow' );
			if ( ! $update_flow ) {
				do_action( 'datamachine_log', 'error', 'Import: update-flow ability not available' );
				return null;
			}
			$current_flow       = $db_flows->get_flow( $flow_id );
			$current_scheduling = is_array( $current_flow['scheduling_config'] ?? null ) ? $current_flow['scheduling_config'] : array();
			$scheduling_config  = AuthRefHandlerConfig::preserve_local_secrets( $scheduling_config, $current_scheduling );
			$result             = $update_flow->execute(
				array(
					'flow_id'           => $flow_id,
					'flow_name'         => $flow_name,
					'scheduling_config' => $scheduling_config,
				)
			);
			if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
				do_action( 'datamachine_log', 'error', 'Import: failed to reconcile existing flow schedule', array( 'flow_id' => $flow_id ) );
				return null;
			}
		}

		if ( ! $db_flows->update_flow( $flow_id, array( 'portable_slug' => $portable_slug ) ) ) {
			do_action( 'datamachine_log', 'error', 'Import: failed to restore flow portable_slug', array( 'flow_id' => $flow_id ) );
			return null;
		}

		return $flow_id;
	}

	/**
	 * Write canonical handler config into the flow_config entry for this step.
	 *
	 * `create-flow` (and the step-sync pipeline) already populate the flow_config entry
	 * keyed by flow_step_id with structural fields (step_type, pipeline_step_id, etc.).
	 * This overlays the handler fields verbatim — no handler validation, no auth rewiring,
	 * no secret scrubbing. Secret policy is a separate concern (see #1133).
	 */
	private function restore_flow_step_config(
		int $flow_id,
		string $pipeline_step_id,
		string $step_type,
		array $settings
	): bool {
		if ( empty( $settings ) ) {
			return false;
		}

		/** @phpstan-ignore-next-line WordPress filters accept context arguments beyond the filtered value. */
		$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $pipeline_step_id, $flow_id );
		if ( empty( $flow_step_id ) ) {
			return false;
		}

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flow     = $db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			return false;
		}

		$flow_config = $flow['flow_config'] ?? array();
		if ( ! isset( $flow_config[ $flow_step_id ] ) ) {
			// Defensive seed — should have been created by create-flow's step sync, but
			// fall back to a minimal entry so the handler restore still lands.
			$flow_config[ $flow_step_id ] = FlowStepConfigFactory::build(
				array(
					'flow_step_id'     => $flow_step_id,
					'pipeline_step_id' => $pipeline_step_id,
					'flow_id'          => $flow_id,
					'step_type'        => $step_type,
				)
			);
		}
		if ( empty( $flow_config[ $flow_step_id ]['step_type'] ) ) {
			$flow_config[ $flow_step_id ]['step_type'] = $step_type;
		}
		$existing_step = $flow_config[ $flow_step_id ];
		if ( isset( $settings['handler_configs'] ) && is_array( $settings['handler_configs'] ) ) {
			$local_configs = FlowStepConfig::getHandlerConfigs( $existing_step );
			foreach ( $settings['handler_configs'] as $handler_slug => $portable_config ) {
				if ( is_array( $portable_config ) ) {
					$local_config                                 = is_array( $local_configs[ $handler_slug ] ?? null ) ? $local_configs[ $handler_slug ] : array();
					$settings['handler_configs'][ $handler_slug ] = AuthRefHandlerConfig::preserve_local_secrets( $portable_config, $local_config );
				}
			}
		}

		$step = FlowStepConfigFactory::withHandlerFields( PortableFlowStepFields::clear_settings( $existing_step ), $settings );
		$step = array_merge( $step, $this->normalize_portable_flow_step_settings( $settings ) );

		$flow_config[ $flow_step_id ] = $step;

		return (bool) $db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);
	}

	/**
	 * Export canonical pipeline CSV.
	 */
	public function handle_export( $type, $ids ) {
		// Capability check
		if ( ! current_user_can( 'manage_options' ) ) {
			do_action( 'datamachine_log', 'error', 'Export requires manage_options capability' );
			return false;
		}

		if ( 'pipelines' !== $type ) {
			do_action( 'datamachine_log', 'error', "Unknown export type: {$type}" );
			return false;
		}

		// Generate CSV
		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$db_flows     = new \DataMachine\Core\Database\Flows\Flows();

		// Build CSV using WordPress-compliant string approach
		$csv_rows   = array();
		$csv_rows[] = self::CSV_HEADER;

		foreach ( $ids as $pipeline_id ) {
			$pipeline = $db_pipelines->get_pipeline( $pipeline_id );
			if ( ! $pipeline ) {
				continue;
			}

			$pipeline_config = is_string( $pipeline['pipeline_config'] )
			? ( json_decode( $pipeline['pipeline_config'], true ) ?? array() )
			: ( $pipeline['pipeline_config'] ?? array() );
			$flows           = $db_flows->get_flows_for_pipeline( $pipeline_id );
			$used_flow_slugs = array();
			$csv_rows[]      = array_merge(
				$this->csv_row_prefix( 'pipeline', (string) $pipeline_id, (string) $pipeline['pipeline_name'] ),
				array(
					'',
					'',
					'',
					'',
					'',
					'',
				)
			);

			foreach ( $flows as $flow ) {
				$portable_slug = ! empty( $flow['portable_slug'] ) ? sanitize_title( (string) $flow['portable_slug'] ) : sanitize_title( (string) $flow['flow_name'] );
				if ( '' === $portable_slug ) {
					$portable_slug = 'flow';
				}
				$slug_base   = $portable_slug;
				$slug_suffix = (int) $flow['flow_id'];
				while ( in_array( $portable_slug, $used_flow_slugs, true ) ) {
					$portable_slug = $slug_base . '-' . $slug_suffix;
					++$slug_suffix;
				}
				$used_flow_slugs[] = $portable_slug;
				$metadata          = array(
					'scheduling_config' => $this->export_scheduling_config( is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array() ),
					'portable_slug'     => $portable_slug,
				);
				$csv_rows[]        = array_merge(
					$this->csv_row_prefix( 'flow', (string) $pipeline_id, (string) $pipeline['pipeline_name'] ),
					array(
						'',
						'',
						'',
						$flow['flow_id'],
						$flow['flow_name'],
						wp_json_encode( $metadata ),
					)
				);
			}

			$position = 0;
			// Sort steps by execution_order for consistent export
			$sorted_steps = $pipeline_config;
			if ( is_array( $sorted_steps ) ) {
				uasort(
					$sorted_steps,
					function ( $a, $b ) {
						return ( $a['execution_order'] ?? 0 ) <=> ( $b['execution_order'] ?? 0 );
					}
				);
			}

			foreach ( $sorted_steps as $step ) {
				// Export pipeline structure
				$csv_rows[] = array(
					self::CSV_FORMAT_VERSION,
					'pipeline_step',
					$pipeline_id,
					$pipeline['pipeline_name'],
					$position++,
					$step['step_type'] ?? '',
					wp_json_encode( $step ),
					'',
					'',
					'',
				);

				// Export flow configurations
				foreach ( $flows as $flow ) {
					$flow_config = is_string( $flow['flow_config'] ?? null )
						? ( json_decode( $flow['flow_config'], true ) ?? array() )
						: ( $flow['flow_config'] ?? array() );
					/** @phpstan-ignore-next-line WordPress filters accept context arguments beyond the filtered value. */
					$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $step['pipeline_step_id'], $flow['flow_id'] );
					$flow_step    = $flow_config[ $flow_step_id ] ?? array();

					$settings = $this->export_flow_step_settings(
						$flow_step,
						array(
							'pipeline_id' => (int) $pipeline_id,
							'flow_id'     => (int) $flow['flow_id'],
						)
					);

					if ( ! empty( $settings ) ) {
						$csv_rows[] = array(
							self::CSV_FORMAT_VERSION,
							'flow_step',
							$pipeline_id,
							$pipeline['pipeline_name'],
							$position - 1,
							$step['step_type'] ?? '',
							wp_json_encode( $step ),
							$flow['flow_id'],
							$flow['flow_name'],
							wp_json_encode( $settings ),
						);
					}
				}
			}
		}

		// Convert rows to CSV string
		$csv = $this->array_to_csv( $csv_rows );

		do_action( 'datamachine_log', 'debug', 'Pipeline export completed', array( 'count' => count( $ids ) ) );
		return $csv;
	}

	/**
	 * Build portable flow-step settings for CSV export.
	 *
	 * Handler selection/config remains in its canonical handler fields. Queue and
	 * AI tool-policy state is stored beside those fields because it is flow-scoped
	 * runtime state, not pipeline structure.
	 */
	private function export_flow_step_settings( array $flow_step, array $context = array() ): array {
		$settings        = array();
		$primary_handler = FlowStepConfig::getPrimaryHandlerSlug( $flow_step ) ?? '';

		if ( FlowStepConfig::usesHandler( $flow_step ) && ! empty( $primary_handler ) ) {
			$settings = array(
				'handler_slugs'   => FlowStepConfig::getHandlerSlugs( $flow_step ),
				'handler_configs' => AuthRefHandlerConfig::project_for_export( FlowStepConfig::getHandlerConfigs( $flow_step ), $context ),
			);
		} elseif ( ! FlowStepConfig::usesHandler( $flow_step ) && ! empty( FlowStepConfig::getPrimaryHandlerConfig( $flow_step ) ) ) {
			$settings = array(
				'flow_step_settings' => FlowStepConfig::getPrimaryHandlerConfig( $flow_step ),
			);
		}

		return array_merge( $settings, $this->normalize_portable_flow_step_settings( $flow_step ) );
	}

	/**
	 * Normalize portable flow-step fields for import/export.
	 */
	private function normalize_portable_flow_step_settings( array $source ): array {
		return PortableFlowStepFields::normalize_settings( $source );
	}

	/**
	 * Remove scheduler-owned runtime observations from portable desired state.
	 */
	private function export_scheduling_config( array $scheduling_config ): array {
		return AuthRefHandlerConfig::strip_secrets_for_export( FlowScheduling::portable_desired_config( $scheduling_config ) );
	}

	/** Build the shared identity prefix for typed CSV rows. */
	private function csv_row_prefix( string $row_type, string $pipeline_id, string $pipeline_name ): array {
		return array( self::CSV_FORMAT_VERSION, $row_type, $pipeline_id, $pipeline_name );
	}

	/**
	 * Find pipeline by name
	 */
	private function find_pipeline_by_name( $name ) {
		$db_pipelines  = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$all_pipelines = $db_pipelines->get_all_pipelines();
		foreach ( $all_pipelines as $pipeline ) {
			if ( $pipeline['pipeline_name'] === $name ) {
				return $pipeline['pipeline_id'];
			}
		}
		return null;
	}

	/**
	 * Convert array of rows to CSV string
	 *
	 * @param array $rows Array of CSV rows
	 * @return string CSV formatted string
	 */
	private function array_to_csv( array $rows ): string {
		$csv_content = '';
		foreach ( $rows as $row ) {
			$escaped_row  = array_map(
				function ( $field ) {
					// Escape quotes and wrap in quotes if field contains comma, quote, or newline
					if ( strpos( $field, ',' ) !== false || strpos( $field, '"' ) !== false || strpos( $field, "\n" ) !== false ) {
						return '"' . str_replace( '"', '""', $field ) . '"';
					}
					return $field;
				},
				$row
			);
			$csv_content .= implode( ',', $escaped_row ) . "\n";
		}
		return $csv_content;
	}
}
