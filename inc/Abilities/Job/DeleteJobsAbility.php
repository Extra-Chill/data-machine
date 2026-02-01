<?php
/**
 * Delete Jobs Ability
 *
 * Handles job deletion with optional processed items cleanup.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

defined( 'ABSPATH' ) || exit;

class DeleteJobsAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-jobs',
				array(
					'label'               => __( 'Delete Jobs', 'data-machine' ),
					'description'         => __( 'Delete jobs by type (all or failed). Optionally cleanup processed items tracking.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'type' ),
						'properties' => array(
							'type'              => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'failed' ),
								'description' => __( 'Which jobs to delete: all or failed', 'data-machine' ),
							),
							'cleanup_processed' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Also clear processed items tracking for deleted jobs', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'                 => array( 'type' => 'boolean' ),
							'deleted_count'           => array( 'type' => 'integer' ),
							'processed_items_cleaned' => array( 'type' => 'integer' ),
							'message'                 => array( 'type' => 'string' ),
							'error'                   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute delete-jobs ability.
	 *
	 * @param array $input Input parameters with type and cleanup_processed.
	 * @return array Result with deleted count.
	 */
	public function execute( array $input ): array {
		$type              = $input['type'] ?? null;
		$cleanup_processed = (bool) ( $input['cleanup_processed'] ?? false );

		if ( ! in_array( $type, array( 'all', 'failed' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'type is required and must be "all" or "failed"',
			);
		}

		$criteria = array();
		if ( 'failed' === $type ) {
			$criteria['failed'] = true;
		} else {
			$criteria['all'] = true;
		}

		$result = $this->deleteJobs( $criteria, $cleanup_processed );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete jobs',
			);
		}

		$message_parts = array();
		/* translators: %d: number of jobs deleted */
		$message_parts[] = sprintf( __( 'Deleted %d jobs', 'data-machine' ), $result['jobs_deleted'] );

		if ( $cleanup_processed && $result['processed_items_cleaned'] > 0 ) {
			$message_parts[] = __( 'and their associated processed items', 'data-machine' );
		}

		$message = implode( ' ', $message_parts ) . '.';

		do_action(
			'datamachine_log',
			'info',
			'Jobs deleted via ability',
			array(
				'type'                    => $type,
				'jobs_deleted'            => $result['jobs_deleted'],
				'processed_items_cleaned' => $result['processed_items_cleaned'],
			)
		);

		return array(
			'success'                 => true,
			'deleted_count'           => $result['jobs_deleted'],
			'processed_items_cleaned' => $result['processed_items_cleaned'],
			'message'                 => $message,
		);
	}
}
