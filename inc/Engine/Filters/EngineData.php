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
	$persisted = \DataMachine\Core\EngineData::persist( $job_id, $snapshot );
	if ( $persisted && datamachine_engine_data_should_write_artifact_files( $snapshot ) ) {
		datamachine_write_engine_data_artifact_files( $job_id );
	}

	return $persisted;
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

/**
 * Append a replayable engine state event and persist its patch as the current snapshot projection.
 *
 * @param int    $job_id   Job ID.
 * @param string $type     Generic event type.
 * @param array  $patch    Engine data patch to project onto the snapshot.
 * @param array  $metadata Optional event metadata.
 * @return array|null Appended ledger entry on success, null on failure.
 */
function datamachine_append_engine_state_event( int $job_id, string $type, array $patch, array $metadata = array() ): ?array {
	$entry = \DataMachine\Core\EngineData::appendStateEvent( $job_id, $type, $patch, $metadata );
	if ( is_array( $entry ) ) {
		$snapshot = \DataMachine\Core\EngineData::retrieve( $job_id );
		if ( datamachine_engine_data_should_write_artifact_files( $snapshot ) ) {
			datamachine_write_engine_data_artifact_files( $job_id );
		}
	}

	return $entry;
}

/**
 * Append a replayable engine state event once per deterministic operation id.
 *
 * @param int    $job_id   Job ID.
 * @param string $op_id    Deterministic operation id.
 * @param string $type     Generic event type.
 * @param array  $patch    Engine data patch to project onto the snapshot.
 * @param array  $metadata Optional event metadata.
 * @return array|null Appended ledger entry, existing entry for duplicate op_id, or null on failure.
 */
function datamachine_append_engine_state_event_once( int $job_id, string $op_id, string $type, array $patch, array $metadata = array() ): ?array {
	$existing = \DataMachine\Core\EngineStateLedger::findByOpId( \DataMachine\Core\EngineData::retrieve( $job_id ), $op_id );
	if ( null !== $existing ) {
		return $existing;
	}

	$entry = \DataMachine\Core\EngineData::appendStateEventOnce( $job_id, $op_id, $type, $patch, $metadata );
	if ( is_array( $entry ) ) {
		$snapshot = \DataMachine\Core\EngineData::retrieve( $job_id );
		if ( datamachine_engine_data_should_write_artifact_files( $snapshot ) ) {
			datamachine_write_engine_data_artifact_files( $job_id );
		}
	}

	return $entry;
}

/**
 * Determine whether an engine data snapshot has enough runtime artifact data to
 * emit first-class transcript/tool-trace files.
 *
 * @param array $snapshot Engine data snapshot.
 * @return bool Whether artifact files should be written.
 */
function datamachine_engine_data_should_write_artifact_files( array $snapshot ): bool {
	return ! empty( $snapshot['transcript_session_id'] ) || ! empty( $snapshot['tool_execution_summary'] );
}

/**
 * Write first-class runtime artifact files and store their refs in engine data.
 *
 * @param int $job_id Job ID.
 * @return void
 */
function datamachine_write_engine_data_artifact_files( int $job_id ): void {
	$artifact_file_result = ( new \DataMachine\Core\JobArtifacts() )->write_artifact_files( $job_id );
	if ( ! empty( $artifact_file_result['success'] ) && is_array( $artifact_file_result['artifact_files'] ?? null ) && ! empty( $artifact_file_result['artifact_files'] ) ) {
		\DataMachine\Core\EngineData::merge(
			$job_id,
			array( 'artifact_files' => $artifact_file_result['artifact_files'] )
		);
		return;
	}

	if ( empty( $artifact_file_result['success'] ) ) {
		do_action(
			'datamachine_log',
			'warning',
			'EngineData: Failed to write first-class job artifact files.',
			array(
				'job_id' => $job_id,
				'error'  => (string) ( $artifact_file_result['error'] ?? 'unknown_error' ),
			)
		);
	}
}
