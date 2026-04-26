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

use DataMachine\Core\Steps\FlowStepConfig;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
	die;
}

class ImportExport {

	/**
	 * Register import/export action hooks
	 */
	public static function register() {
		$instance = new self();
		add_action( 'datamachine_import', array( $instance, 'handle_import' ), 10, 2 );
		add_action( 'datamachine_export', array( $instance, 'handle_export' ), 10, 2 );
	}

	/**
	 * Handle datamachine_import action.
	 *
	 * Two-pass CSV import:
	 *   Pass 1 — pipeline-structure rows (flow_id empty): create pipelines and add steps
	 *            with their full step_config (#1133 step 1).
	 *   Pass 2 — flow-step rows (flow_id present): ensure target flows exist, then write
	 *            canonical handler fields into each flow_config entry keyed by the
	 *            freshly-generated flow_step_id (#1133 step 2, #1293 shape cleanup).
	 *
	 * NOTE on secrets: handler_configs is restored verbatim. Any auth tokens / API keys in
	 * the exported CSV will land in the imported flow's config. A scrub-or-reference policy
	 * is orthogonal to the lossiness fix and is tracked separately.
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

		$parsed_rows = $this->parse_csv_rows( $data );

		$imported_pipelines = array();
		// pipeline_name => imported pipeline_id.
		$pipeline_ids_by_name = array();
		// [pipeline_name][(int) step_position] => imported pipeline_step_id.
		$step_id_map = array();
		// [pipeline_name][source_flow_id] => imported flow_id.
		$flow_id_map = array();

		// Pass 1: pipelines + steps.
		foreach ( $parsed_rows as $row ) {
			if ( '' !== $row['flow_id'] ) {
				continue;
			}

			$pipeline_name = $row['pipeline_name'];

			if ( ! isset( $pipeline_ids_by_name[ $pipeline_name ] ) ) {
				$pipeline_id = $this->ensure_pipeline( $pipeline_name );
				if ( ! $pipeline_id ) {
					continue;
				}
				$pipeline_ids_by_name[ $pipeline_name ] = $pipeline_id;
				$imported_pipelines[]                   = $pipeline_id;
			}

			if ( ! $row['step_type'] ) {
				continue;
			}

			$pipeline_step_id = $this->add_step_to_pipeline(
				$pipeline_ids_by_name[ $pipeline_name ],
				$row['step_type'],
				$row['step_config']
			);
			if ( $pipeline_step_id ) {
				$step_id_map[ $pipeline_name ][ $row['step_position'] ] = $pipeline_step_id;
			}
		}

		// Pass 2: flows + handler configs.
		foreach ( $parsed_rows as $row ) {
			if ( '' === $row['flow_id'] ) {
				continue;
			}

			$pipeline_name = $row['pipeline_name'];
			if ( ! isset( $pipeline_ids_by_name[ $pipeline_name ] ) ) {
				continue;
			}

			$imported_pipeline_id = $pipeline_ids_by_name[ $pipeline_name ];
			$source_flow_id       = $row['flow_id'];
			$flow_name            = '' !== $row['flow_name'] ? $row['flow_name'] : 'Flow';

			if ( ! isset( $flow_id_map[ $pipeline_name ][ $source_flow_id ] ) ) {
				$new_flow_id = $this->ensure_flow( $imported_pipeline_id, $flow_name );
				if ( ! $new_flow_id ) {
					continue;
				}
				$flow_id_map[ $pipeline_name ][ $source_flow_id ] = $new_flow_id;
			}

			$imported_flow_id = $flow_id_map[ $pipeline_name ][ $source_flow_id ];
			$pipeline_step_id = $step_id_map[ $pipeline_name ][ $row['step_position'] ] ?? null;
			if ( ! $pipeline_step_id ) {
				continue;
			}

			$settings = $row['settings'];
			if ( '' !== $row['handler'] && empty( $settings['handler_slug'] ) && empty( $settings['handler_slugs'] ) ) {
				$settings['handler_slug'] = $row['handler'];
			}

			$this->restore_flow_step_config(
				$imported_flow_id,
				$pipeline_step_id,
				$row['step_type'],
				$settings
			);
		}

		// Ensure every imported pipeline has at least one flow (fallback for exports with
		// no flow rows — e.g. a pipeline containing only AI steps with no handlers).
		foreach ( $pipeline_ids_by_name as $pipeline_name => $imported_pipeline_id ) {
			if ( ! isset( $flow_id_map[ $pipeline_name ] ) ) {
				$this->ensure_flow( $imported_pipeline_id, 'Default Flow' );
			}
		}

		$result = array( 'imported' => array_unique( $imported_pipelines ) );
		add_filter(
			'datamachine_import_result',
			function () use ( $result ) {
				return $result;
			}
		);

		do_action( 'datamachine_log', 'debug', 'Pipeline import completed', array( 'count' => count( $result['imported'] ) ) );
		return $result;
	}

	/**
	 * Parse the import CSV into a normalized row list.
	 *
	 * @param string $data Raw CSV content.
	 * @return array<int, array{
	 *     pipeline_name:string, step_position:int, step_type:string, step_config:array,
	 *     flow_id:string, flow_name:string, handler:string, settings:array
	 * }>
	 */
	private function parse_csv_rows( string $data ): array {
		$rows   = str_getcsv( $data, "\n" );
		$parsed = array();

		foreach ( $rows as $index => $row ) {
			if ( 0 === $index ) {
				continue;
			}

			$cols = str_getcsv( $row );
			if ( count( $cols ) < 5 ) {
				continue;
			}

			$step_config = json_decode( $cols[4], true );
			if ( ! is_array( $step_config ) ) {
				$step_config = array();
			}

			$settings_raw = $cols[8] ?? '';
			$settings     = json_decode( $settings_raw, true );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$parsed[] = array(
				'pipeline_name' => (string) $cols[1],
				'step_position' => (int) $cols[2],
				'step_type'     => (string) $cols[3],
				'step_config'   => $step_config,
				'flow_id'       => (string) ( $cols[5] ?? '' ),
				'flow_name'     => (string) ( $cols[6] ?? '' ),
				'handler'       => (string) ( $cols[7] ?? '' ),
				'settings'      => $settings,
			);
		}

		return $parsed;
	}

	/**
	 * Ensure a pipeline with the given name exists; return its id.
	 *
	 * Reuses any existing pipeline with a matching name. When a new pipeline is created we
	 * deliberately skip auto-flow-creation (no flow_config) — flows are created in pass 2
	 * from the CSV, with a final "Default Flow" fallback for exports that carry no flow rows.
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
	 * Add a step to a pipeline with its full step_config; return the new pipeline_step_id.
	 */
	private function add_step_to_pipeline( int $pipeline_id, string $step_type, array $step_config ): ?string {
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

	/**
	 * Ensure a flow matching the given name exists on the pipeline; return its id.
	 *
	 * Reuses an existing flow by exact name match (case-sensitive) so repeated imports of
	 * the same export don't spawn duplicate flows. Otherwise creates a new flow.
	 */
	private function ensure_flow( int $pipeline_id, string $flow_name ): ?int {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();

		$existing = $db_flows->get_flows_for_pipeline( $pipeline_id );
		foreach ( $existing as $flow ) {
			if ( isset( $flow['flow_name'] ) && $flow['flow_name'] === $flow_name ) {
				return (int) $flow['flow_id'];
			}
		}

		$create_flow = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $create_flow ) {
			do_action( 'datamachine_log', 'error', 'Import: create-flow ability not available' );
			return null;
		}

		$result = $create_flow->execute(
			array(
				'pipeline_id' => $pipeline_id,
				'flow_name'   => $flow_name,
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

		return (int) $result['flow_id'];
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
			$flow_config[ $flow_step_id ] = array(
				'flow_step_id'     => $flow_step_id,
				'pipeline_step_id' => $pipeline_step_id,
				'flow_id'          => $flow_id,
				'step_type'        => $step_type,
			);
		}
		if ( empty( $flow_config[ $flow_step_id ]['step_type'] ) ) {
			$flow_config[ $flow_step_id ]['step_type'] = $step_type;
		}


		$step = FlowStepConfig::normalizeHandlerShape(
			array_merge(
				$flow_config[ $flow_step_id ],
				$settings
			)
		);

		$primary_handler = FlowStepConfig::getPrimaryHandlerSlug( $step );
		if ( null !== $primary_handler ) {
			$step['handler'] = $primary_handler;
		} else {
			unset( $step['handler'] );
		}

		$flow_config[ $flow_step_id ] = $step;

		$flow_config[ $flow_step_id ]['enabled'] = true;

		return (bool) $db_flows->update_flow(
			$flow_id,
			array( 'flow_config' => $flow_config )
		);
	}

	/**
	 * Handle datamachine_export action
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
		$csv_rows[] = array( 'pipeline_id', 'pipeline_name', 'step_position', 'step_type', 'step_config', 'flow_id', 'flow_name', 'handler', 'settings' );

		foreach ( $ids as $pipeline_id ) {
			$pipeline = $db_pipelines->get_pipeline( $pipeline_id );
			if ( ! $pipeline ) {
				continue;
			}

			$pipeline_config = is_string( $pipeline['pipeline_config'] )
			? ( json_decode( $pipeline['pipeline_config'], true ) ?? array() )
			: ( $pipeline['pipeline_config'] ?? array() );
			$flows           = $db_flows->get_flows_for_pipeline( $pipeline_id );

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
					$pipeline_id,
					$pipeline['pipeline_name'],
					$position++,
					$step['step_type'] ?? '',
					wp_json_encode( $step ),
					'',
					'',
					'',
					'',
				);

				// Export flow configurations
				foreach ( $flows as $flow ) {
					$flow_config  = json_decode( $flow['flow_config'], true ) ?? array();
					$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $step['pipeline_step_id'], $flow['flow_id'] );
					$flow_step    = $flow_config[ $flow_step_id ] ?? array();

					$settings        = array();
					$primary_handler = FlowStepConfig::getPrimaryHandlerSlug( $flow_step ) ?? '';

					if ( FlowStepConfig::isMultiHandler( $flow_step ) && ! empty( $primary_handler ) ) {
						$settings = array(
							'handler_slugs'   => FlowStepConfig::getHandlerSlugs( $flow_step ),
							'handler_configs' => FlowStepConfig::getHandlerConfigs( $flow_step ),
						);
					} elseif ( FlowStepConfig::usesHandler( $flow_step ) && ! empty( $primary_handler ) ) {
						$settings = array(
							'handler_slug'   => $primary_handler,
							'handler_config' => FlowStepConfig::getPrimaryHandlerConfig( $flow_step ),
						);
					} elseif ( ! FlowStepConfig::usesHandler( $flow_step ) && ! empty( $flow_step['handler_config'] ) ) {
						$settings = array(
							'handler_config' => $flow_step['handler_config'],
						);
					}

					if ( ! empty( $settings ) ) {
						$csv_rows[] = array(
							$pipeline_id,
							$pipeline['pipeline_name'],
							$position - 1,
							$step['step_type'] ?? '',
							wp_json_encode( $step ),
							$flow['flow_id'],
							$flow['flow_name'],
							$primary_handler,
							wp_json_encode( $settings ),
						);
					}
				}
			}
		}

		// Convert rows to CSV string
		$csv = $this->array_to_csv( $csv_rows );

		// Store result for filter access
		add_filter(
			'datamachine_export_result',
			function () use ( $csv ) {
				return $csv;
			}
		);

		do_action( 'datamachine_log', 'debug', 'Pipeline export completed', array( 'count' => count( $ids ) ) );
		return $csv;
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
