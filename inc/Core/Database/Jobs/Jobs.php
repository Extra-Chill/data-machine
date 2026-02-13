<?php
/**
 * Jobs Database Operations - Job lifecycle management with engine data storage
 *
 * @package DataMachine
 * @subpackage Core\Database\Jobs
 */

namespace DataMachine\Core\Database\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jobs {

	private $table_name;
	private $operations;
	private $status;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'datamachine_jobs';

		$this->operations = new JobsOperations();
		$this->status     = new JobsStatus();
	}


	public function create_job( array $job_data ): int|false {
		return $this->operations->create_job( $job_data );
	}


	public function get_jobs_count( array $args = array() ): int {
		return $this->operations->get_jobs_count( $args );
	}

	public function get_jobs_for_list_table( array $args ): array {
		return $this->operations->get_jobs_for_list_table( $args );
	}

	public function start_job( int $job_id, string $status = 'processing' ): bool {
		return $this->status->start_job( $job_id, $status );
	}

	public function complete_job( int $job_id, string $status ): bool {
		return $this->status->complete_job( $job_id, $status );
	}

	public function update_job_status( int $job_id, string $status ): bool {
		return $this->status->update_job_status( $job_id, $status );
	}

	public function get_jobs_for_pipeline( int $pipeline_id ): array {
		return $this->operations->get_jobs_for_pipeline( $pipeline_id );
	}

	public function get_jobs_for_flow( int|string $flow_id ): array {
		return $this->operations->get_jobs_for_flow( $flow_id );
	}

	public function get_latest_jobs_by_flow_ids( array $flow_ids ): array {
		return $this->operations->get_latest_jobs_by_flow_ids( $flow_ids );
	}

	public function delete_jobs( array $criteria = array() ): int|false {
		return $this->operations->delete_jobs( $criteria );
	}

	public function store_engine_data( int $job_id, array $data ): bool {
		return $this->operations->store_engine_data( $job_id, $data );
	}

	public function retrieve_engine_data( int $job_id ): array {
		return $this->operations->retrieve_engine_data( $job_id );
	}

	public function get_job( int $job_id ): ?array {
		return $this->operations->get_job( $job_id );
	}

	public function get_flow_health( int|string $flow_id ): array {
		return $this->operations->get_flow_health( $flow_id );
	}

	public function get_problem_flow_ids( int $threshold = 3 ): array {
		return $this->operations->get_problem_flow_ids( $threshold );
	}


	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'datamachine_jobs';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// pipeline_id and flow_id are VARCHAR to support 'direct' execution mode
		// where these values store the string 'direct' instead of numeric IDs
		// status is VARCHAR(255) to support compound statuses with reasons
		$sql = "CREATE TABLE $table_name (
            job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pipeline_id varchar(20) NOT NULL,
            flow_id varchar(20) NOT NULL,
            source varchar(50) NOT NULL DEFAULT 'pipeline',
            label varchar(255) NULL DEFAULT NULL,
            status varchar(255) NOT NULL,
            engine_data longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (job_id),
            KEY status (status),
            KEY pipeline_id (pipeline_id),
            KEY flow_id (flow_id),
            KEY source (source)
        ) $charset_collate;";

		dbDelta( $sql );

		self::migrate_columns( $table_name );

		do_action(
			'datamachine_log',
			'debug',
			'Created jobs database table with pipeline+flow architecture',
			array(
				'table_name' => $table_name,
				'action'     => 'create_table',
			)
		);
	}

	/**
	 * Migrate existing table columns to current schema.
	 *
	 * Handles:
	 * - status column: varchar(20/100) -> varchar(255) for compound statuses with reasons
	 * - pipeline_id column: bigint -> varchar(20) for 'direct' execution support
	 * - flow_id column: bigint -> varchar(20) for 'direct' execution support
	 *
	 * Safe to run multiple times - only executes if columns need updating.
	 */
	private static function migrate_columns( string $table_name ): void {
		global $wpdb;

		// Get column info for all columns we might need to migrate
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$columns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                 FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s 
                 AND COLUMN_NAME IN ('status', 'pipeline_id', 'flow_id', 'source')",
				DB_NAME,
				$table_name
			),
			OBJECT_K
		);

		if ( empty( $columns ) ) {
			return;
		}

		// Migrate status column: varchar(20/100) -> varchar(255)
		if ( isset( $columns['status'] ) && (int) $columns['status']->CHARACTER_MAXIMUM_LENGTH < 255 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table_name} MODIFY status varchar(255) NOT NULL" );
			do_action(
				'datamachine_log',
				'info',
				'Migrated jobs.status column to varchar(255)',
				array(
					'table_name'    => $table_name,
					'previous_size' => $columns['status']->CHARACTER_MAXIMUM_LENGTH,
				)
			);
		}

		// Migrate pipeline_id column: bigint -> varchar(20) for 'direct' execution
		if ( isset( $columns['pipeline_id'] ) && $columns['pipeline_id']->DATA_TYPE === 'bigint' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table_name} MODIFY pipeline_id varchar(20) NOT NULL" );
			do_action(
				'datamachine_log',
				'info',
				'Migrated jobs.pipeline_id column to varchar(20) for direct execution support',
				array(
					'table_name' => $table_name,
				)
			);
		}

		// Migrate flow_id column: bigint -> varchar(20) for 'direct' execution
		if ( isset( $columns['flow_id'] ) && $columns['flow_id']->DATA_TYPE === 'bigint' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table_name} MODIFY flow_id varchar(20) NOT NULL" );
			do_action(
				'datamachine_log',
				'info',
				'Migrated jobs.flow_id column to varchar(20) for direct execution support',
				array(
					'table_name' => $table_name,
				)
			);
		}

		// Add source and label columns for pipeline decoupling.
		if ( ! isset( $columns['source'] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query(
				"ALTER TABLE {$table_name}
				 ADD COLUMN source varchar(50) NOT NULL DEFAULT 'pipeline' AFTER flow_id,
				 ADD COLUMN label varchar(255) NULL DEFAULT NULL AFTER source,
				 ADD KEY source (source)"
			);

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add source/label columns to jobs table',
					array(
						'table_name' => $table_name,
						'db_error'   => $wpdb->last_error,
					)
				);
				return;
			}

			// Backfill existing rows.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "UPDATE {$table_name} SET source = 'direct' WHERE pipeline_id = 'direct'" );

			do_action(
				'datamachine_log',
				'info',
				'Added source and label columns to jobs table for pipeline decoupling',
				array( 'table_name' => $table_name )
			);
		}
	}
}
