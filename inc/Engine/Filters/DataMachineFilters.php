<?php
/**
 * Backend processing filters for Data Machine engine.
 *
 * Core filter registration for handler services, scheduling,
 * and step configuration discovery.
 *
 * @package DataMachine
 * @subpackage Engine\Filters
 * @since 0.1.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Register import/export filters for Data Machine.
 *
 * Registers filters for importer service discovery and initialization.
 *
 * @since 0.1.0
 */
function datamachine_register_importexport_filters() {

	add_filter(
		'datamachine_importer',
		function ( $service ) {
			if ( null === $service ) {
				require_once DATAMACHINE_PATH . 'inc/Engine/Actions/ImportExport.php';
				return new \DataMachine\Engine\Actions\ImportExport();
			}
			return $service;
		},
		10,
		1
	);
}

datamachine_register_importexport_filters();

/**
 * Register backend processing filters for engine operations.
 *
 * Registers filters for service discovery, scheduling,
 * and step configuration. Does not handle UI/admin logic.
 *
 * @since 0.1.0
 */
function datamachine_register_utility_filters() {

	add_filter(
		'datamachine_generate_flow_step_id',
		function ( $existing_id, $pipeline_step_id, $flow_id ) {
			if ( empty( $pipeline_step_id ) || empty( $flow_id ) ) {
				do_action(
					'datamachine_log',
					'error',
					'Invalid flow step ID generation parameters',
					array(
						'pipeline_step_id' => $pipeline_step_id,
						'flow_id'          => $flow_id,
					)
				);
				return '';
			}

			return $pipeline_step_id . '_' . $flow_id;
		},
		10,
		3
	);

	add_filter(
		'datamachine_split_pipeline_step_id',
		function ( $default, $pipeline_step_id ) {
			if ( empty( $pipeline_step_id ) || strpos( $pipeline_step_id, '_' ) === false ) {
				return null; // Old UUID4 format or invalid
			}

			$parts = explode( '_', $pipeline_step_id, 2 );
			if ( count( $parts ) !== 2 ) {
				return null;
			}

			return array(
				'pipeline_id' => $parts[0],
				'uuid'        => $parts[1],
			);
		},
		10,
		2
	);

	// Split composite flow_step_id: {pipeline_step_id}_{flow_id}
	add_filter(
		'datamachine_split_flow_step_id',
		function ( $null, $flow_step_id ) {
			if ( empty( $flow_step_id ) || ! is_string( $flow_step_id ) ) {
				return null;
			}

			// Split on last underscore to handle UUIDs with dashes
			$last_underscore_pos = strrpos( $flow_step_id, '_' );
			if ( false === $last_underscore_pos ) {
				return null;
			}

			$pipeline_step_id = substr( $flow_step_id, 0, $last_underscore_pos );
			$flow_id          = substr( $flow_step_id, $last_underscore_pos + 1 );

			// Validate flow_id is numeric
			if ( ! is_numeric( $flow_id ) ) {
				return null;
			}

			return array(
				'pipeline_step_id' => $pipeline_step_id,
				'flow_id'          => (int) $flow_id,
			);
		},
		10,
		2
	);
}
