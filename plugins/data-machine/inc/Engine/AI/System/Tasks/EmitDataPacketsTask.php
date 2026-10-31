<?php
/**
 * Emit Data Packets System Task.
 *
 * Bridges deterministic task output into the pipeline DataPacket handoff
 * contract. Useful for workflow boundaries where a task produces zero or
 * more packets that should drive downstream continuation/fan-out.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 */

namespace DataMachine\Engine\AI\System\Tasks;

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class EmitDataPacketsTask extends SystemTask {

	public function getTaskType(): string {
		return 'emit_data_packets';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Emit Data Packets',
			'description'     => 'Emit configured DataPackets for downstream workflow continuation or fan-out.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via workflow system_task step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$packets = is_array( $params['packets'] ?? null ) ? array_values( $params['packets'] ) : array();

		$result = array(
			'output_data_packets'    => $packets,
			'replace_data_packets'   => array_key_exists( 'replace_data_packets', $params ) ? (bool) $params['replace_data_packets'] : true,
			'suppress_result_packet' => array_key_exists( 'suppress_result_packet', $params ) ? (bool) $params['suppress_result_packet'] : true,
			'packet_count'           => count( $packets ),
			'completed_at'           => current_time( 'mysql' ),
		);

		if ( empty( $packets ) && ! empty( $params['complete_no_items'] ) ) {
			$result['job_status'] = JobStatus::COMPLETED_NO_ITEMS;
		}

		$this->completeJob( $jobId, $result );
	}
}
