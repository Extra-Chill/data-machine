<?php
/**
 * Source inventory system task.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 */

namespace DataMachine\Engine\AI\System\Tasks;

use DataMachine\Abilities\SourceInventoryAbility;

defined( 'ABSPATH' ) || exit;

class SourceInventoryTask extends SystemTask {

	public function getTaskType(): string {
		return 'source_inventory';
	}

	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Source Inventory',
			'description'     => 'Inventory a configured source descriptor and optionally upsert tracked-items coverage state.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'On-demand via workflow system_task step',
			'trigger_type'    => 'manual',
			'supports_run'    => true,
			'mutates'         => true,
			'params_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'source' ),
				'properties' => array(
					'source'      => array(
						'type'        => 'object',
						'description' => 'Source descriptor. The source.kind field selects the registered inventory provider.',
					),
					'scan'        => array(
						'type'        => 'boolean',
						'description' => 'Whether to page through the source and collect items.',
					),
					'pagination'  => array( 'type' => 'object' ),
					'group_by'    => array( 'type' => 'array' ),
					'track_items' => array(
						'type'        => 'object',
						'description' => 'Optional tracked-items mapping used when scan=true.',
					),
				),
			),
		);
	}

	public function executeTask( int $jobId, array $params ): void {
		$input = $this->normalizeInput( $params );
		if ( empty( $input['source'] ) || ! is_array( $input['source'] ) ) {
			$this->failJob( $jobId, 'source_inventory requires a source descriptor.' );
			return;
		}

		$result = ( new SourceInventoryAbility() )->execute( $input );
		if ( empty( $result['success'] ) ) {
			$this->failJob( $jobId, (string) ( $result['error'] ?? 'Source inventory failed.' ) );
			return;
		}

		$source = $input['source'];
		$this->completeJob(
			$jobId,
			array(
				'output_data_packets'  => array(
					array(
						'type'     => 'source_inventory_result',
						'data'     => array(
							'title'  => 'Source Inventory Completed',
							'body'   => wp_json_encode( $result ),
							'result' => $result,
						),
						'metadata' => array(
							'source_type' => 'source_inventory',
							'source_kind' => (string) ( $source['kind'] ?? '' ),
							'source_ref'  => (string) ( $source['ref'] ?? $source['handle'] ?? $source['repo'] ?? '' ),
							'source_path' => (string) ( $source['path'] ?? '' ),
							'success'     => true,
						),
					),
				),
				'replace_data_packets' => false,
				'source_inventory'     => $result,
				'completed_at'         => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $params Task params.
	 * @return array<string,mixed>
	 */
	private function normalizeInput( array $params ): array {
		if ( isset( $params['input'] ) && is_array( $params['input'] ) ) {
			return $params['input'];
		}

		$input = array();
		foreach ( array( 'source', 'scan', 'pagination', 'group_by', 'sample_limit_per_bucket', 'max_items', 'max_pages', 'track_items' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$input[ $key ] = $params[ $key ];
			}
		}

		return $input;
	}
}
