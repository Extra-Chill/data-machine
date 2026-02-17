<?php
/**
 * Engine data snapshot helpers.
 *
 * Thin wrappers around \DataMachine\Core\EngineData static methods.
 * Kept for backward compatibility.
 *
 * @package DataMachine\Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve engine data snapshot for a job.
 *
 * @param int $job_id Job ID.
 * @return array Engine data array.
 */
function datamachine_get_engine_data( int $job_id ): array {
	return \DataMachine\Core\EngineData::retrieve( $job_id );
}

/**
 * Persist a complete engine data snapshot for a job.
 *
 * @param int   $job_id   Job ID.
 * @param array $snapshot Engine data snapshot.
 * @return bool True on success.
 */
function datamachine_set_engine_data( int $job_id, array $snapshot ): bool {
	return \DataMachine\Core\EngineData::persist( $job_id, $snapshot );
}

/**
 * Merge new data into the stored engine snapshot.
 *
 * @param int   $job_id Job ID.
 * @param array $data   Data to merge.
 * @return bool True on success.
 */
function datamachine_merge_engine_data( int $job_id, array $data ): bool {
	return \DataMachine\Core\EngineData::merge( $job_id, $data );
}
