<?php
/**
 * Pipeline Database Operations
 *
 * CRUD operations for reusable pipeline workflow templates.
 *
 * @package DataMachine
 */

namespace DataMachine\Core\Database\Pipelines;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

class Pipelines extends BaseRepository {

	const TABLE_NAME = 'datamachine_pipelines';

	/**
	 * Create a new pipeline in the database.
	 *
	 * @param array $pipeline_data Pipeline data including name and config
	 * @return int|false Pipeline ID on success, false on failure
	 */
	public function create_pipeline( array $pipeline_data ): int|false {
		if ( ! isset( $pipeline_data['pipeline_name'] ) || empty( trim( $pipeline_data['pipeline_name'] ) ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Cannot create pipeline - missing or empty pipeline name',
				array(
					'pipeline_data' => $pipeline_data,
				)
			);
			return false;
		}

		$pipeline_name        = sanitize_text_field( $pipeline_data['pipeline_name'] );
		$pipeline_config      = $pipeline_data['pipeline_config'] ?? array();
		$pipeline_config_json = wp_json_encode( $pipeline_config );

		$data = array(
			'pipeline_name'   => $pipeline_name,
			'pipeline_config' => $pipeline_config_json,
			'created_at'      => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		);

		$format = array( '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $this->wpdb->insert( $this->table_name, $data, $format );

		if ( false === $inserted ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to insert pipeline',
				array(
					'pipeline_name' => $pipeline_name,
					'db_error'      => $this->wpdb->last_error,
				)
			);
			return false;
		}

		$pipeline_id = $this->wpdb->insert_id;
		do_action(
			'datamachine_log',
			'debug',
			'Successfully created pipeline',
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
			)
		);

		return $pipeline_id;
	}

	/**
	 * Get a pipeline by ID.
	 *
	 * @param int $pipeline_id Pipeline ID to retrieve
	 * @return array|null Pipeline data or null if not found
	 */
	public function get_pipeline( int $pipeline_id ): ?array {

		if ( empty( $pipeline_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pipeline = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE pipeline_id = %d', $this->table_name, $pipeline_id ), ARRAY_A );

		if ( $pipeline && ! empty( $pipeline['pipeline_config'] ) ) {
			$pipeline['pipeline_config'] = json_decode( $pipeline['pipeline_config'], true ) ?? array();
		}

		return $pipeline;
	}

	/**
	 * Get all pipelines from the database.
	 *
	 * @return array Array of all pipeline records
	 */
	public function get_all_pipelines(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC', $this->table_name ), ARRAY_A );

		foreach ( $results as &$pipeline ) {
			if ( ! empty( $pipeline['pipeline_config'] ) ) {
				$pipeline['pipeline_config'] = json_decode( $pipeline['pipeline_config'], true ) ?? array();
			}
		}

		return $results ? $results : array();
	}

	/**
	 * Get lightweight pipelines list for UI dropdowns.
	 */
	public function get_pipelines_list(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT pipeline_id, pipeline_name FROM %i ORDER BY pipeline_name ASC', $this->table_name ), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Update pipeline with validation and caching.
	 */
	/**
	 * Update an existing pipeline.
	 *
	 * @param int   $pipeline_id Pipeline ID to update
	 * @param array $pipeline_data Updated pipeline data
	 * @return bool True on success, false on failure
	 */
	public function update_pipeline( int $pipeline_id, array $pipeline_data ): bool {

		if ( empty( $pipeline_id ) ) {
			do_action( 'datamachine_log', 'error', 'Cannot update pipeline - missing pipeline ID' );
			return false;
		}

		// Build update data array
		$update_data = array();
		$format      = array();

		if ( isset( $pipeline_data['pipeline_name'] ) ) {
			$update_data['pipeline_name'] = sanitize_text_field( $pipeline_data['pipeline_name'] );
			$format[]                     = '%s';
		}

		if ( isset( $pipeline_data['pipeline_config'] ) ) {
			$update_data['pipeline_config'] = wp_json_encode( $pipeline_data['pipeline_config'] );
			$format[]                       = '%s';
		}

		// Always update the updated_at timestamp
		$update_data['updated_at'] = current_time( 'mysql', true );
		$format[]                  = '%s';

		if ( empty( $update_data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'No valid data provided for pipeline update',
				array(
					'pipeline_id' => $pipeline_id,
				)
			);
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'pipeline_id' => $pipeline_id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update pipeline',
				array(
					'pipeline_id' => $pipeline_id,
					'db_error'    => $this->wpdb->last_error,
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Successfully updated pipeline',
			array(
				'pipeline_id'    => $pipeline_id,
				'updated_fields' => array_keys( $update_data ),
			)
		);

		return true;
	}

	/**
	 * Delete pipeline with logging.
	 */
	/**
	 * Delete a pipeline from the database.
	 *
	 * @param int $pipeline_id Pipeline ID to delete
	 * @return bool True on success, false on failure
	 */
	public function delete_pipeline( int $pipeline_id ): bool {

		if ( empty( $pipeline_id ) ) {
			do_action( 'datamachine_log', 'error', 'Cannot delete pipeline - missing pipeline ID' );
			return false;
		}

		// Get pipeline info for logging before deletion
		$pipeline      = $this->get_pipeline( $pipeline_id );
		$pipeline_name = $pipeline ? $pipeline['pipeline_name'] : 'Unknown';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->delete(
			$this->table_name,
			array( 'pipeline_id' => $pipeline_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to delete pipeline',
				array(
					'pipeline_id'   => $pipeline_id,
					'pipeline_name' => $pipeline_name,
					'db_error'      => $this->wpdb->last_error,
				)
			);
			return false;
		}

		if ( 0 === $deleted ) {
			do_action(
				'datamachine_log',
				'warning',
				'Pipeline not found for deletion',
				array(
					'pipeline_id' => $pipeline_id,
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Successfully deleted pipeline',
			array(
				'pipeline_id'   => $pipeline_id,
				'pipeline_name' => $pipeline_name,
			)
		);

		// Delete pipeline filesystem directory (cascade deletion)
		$dir_manager  = new \DataMachine\Core\FilesRepository\DirectoryManager();
		$pipeline_dir = $dir_manager->get_pipeline_directory( $pipeline_id );

		if ( is_dir( $pipeline_dir ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$wp_filesystem->rmdir( $pipeline_dir, true );

				do_action(
					'datamachine_log',
					'debug',
					'Deleted pipeline directory',
					array(
						'pipeline_id' => $pipeline_id,
						'directory'   => $pipeline_dir,
					)
				);
			}
		}

		return true;
	}


	/**
	 * Get decoded pipeline configuration.
	 */
	public function get_pipeline_config( int $pipeline_id ): array {

		if ( empty( $pipeline_id ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pipeline_config_json = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT pipeline_config FROM %i WHERE pipeline_id = %d', $this->table_name, $pipeline_id ) );

		if ( empty( $pipeline_config_json ) ) {
			return array();
		}

		return json_decode( $pipeline_config_json, true ) ?? array();
	}


	/**
	 * Get pipeline count.
	 */
	public function get_pipelines_count(): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(pipeline_id) FROM %i', $this->table_name ) );

		return (int) $count;
	}

	/**
	 * Get pipelines for admin list table with ordering.
	 */
	public function get_pipelines_for_list_table( array $args ): array {

		$orderby  = $args['orderby'] ?? 'pipeline_id';
		$order    = strtoupper( $args['order'] ?? 'DESC' );
		$per_page = (int) ( $args['per_page'] ?? 20 );
		$offset   = (int) ( $args['offset'] ?? 0 );
		$is_asc   = ( 'ASC' === $order );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$results = match ( $orderby ) {
			'pipeline_name' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY pipeline_name ASC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY pipeline_name DESC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A ),
			'created_at' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at ASC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A ),
			'updated_at' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at ASC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A ),
			default => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY pipeline_id ASC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY pipeline_id DESC LIMIT %d OFFSET %d', $this->table_name, $per_page, $offset ), ARRAY_A ),
		};
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

		foreach ( $results as &$pipeline ) {
			if ( ! empty( $pipeline['pipeline_config'] ) ) {
				$pipeline['pipeline_config'] = json_decode( $pipeline['pipeline_config'], true ) ?? array();
			}
		}

		return $results ? $results : array();
	}

	/**
	 * Create pipelines database table.
	 */
	/**
	 * Get pipeline context files from pipeline config
	 *
	 * @param int $pipeline_id Pipeline ID
	 * @return array Context files array
	 */
	public function get_pipeline_context_files( int $pipeline_id ): array {
		$pipeline_config = $this->get_pipeline_config( $pipeline_id );
		return $pipeline_config['context_files'] ?? array( 'uploaded_files' => array() );
	}

	/**
	 * Update pipeline context files in pipeline config
	 *
	 * @param int   $pipeline_id Pipeline ID
	 * @param array $files_data Context files data
	 * @return bool True on success, false on failure
	 */
	public function update_pipeline_context_files( int $pipeline_id, array $files_data ): bool {
		if ( empty( $pipeline_id ) ) {
			return false;
		}

		$pipeline_config                  = $this->get_pipeline_config( $pipeline_id );
		$pipeline_config['context_files'] = $files_data;

		$result = $this->wpdb->update(
			$this->table_name,
			array( 'pipeline_config' => wp_json_encode( $pipeline_config ) ),
			array( 'pipeline_id' => $pipeline_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get configuration for a specific pipeline step.
	 *
	 * Retrieves step configuration from pipeline config and adds pipeline_id.
	 *
	 * @param string $pipeline_step_id Pipeline step ID (format: {pipeline_id}_{uuid})
	 * @return array Step configuration with pipeline_id, or empty array on failure
	 */
	public function get_pipeline_step_config( string $pipeline_step_id ): array {
		if ( empty( $pipeline_step_id ) ) {
			return array();
		}

		// Extract pipeline_id from pipeline-prefixed step ID
		$parts = apply_filters( 'datamachine_split_pipeline_step_id', null, $pipeline_step_id );
		if ( ! $parts || empty( $parts['pipeline_id'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Invalid pipeline step ID format',
				array(
					'pipeline_step_id' => $pipeline_step_id,
				)
			);
			return array();
		}

		$pipeline_id = (int) $parts['pipeline_id'];
		$pipeline    = $this->get_pipeline( $pipeline_id );

		if ( ! $pipeline ) {
			do_action(
				'datamachine_log',
				'error',
				'Pipeline not found',
				array(
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'      => $pipeline_id,
				)
			);
			return array();
		}

		$pipeline_config = $pipeline['pipeline_config'] ?? array();

		if ( ! isset( $pipeline_config[ $pipeline_step_id ] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Pipeline step not found in pipeline config',
				array(
					'pipeline_step_id' => $pipeline_step_id,
					'pipeline_id'      => $pipeline_id,
				)
			);
			return array();
		}

		$step_config                = $pipeline_config[ $pipeline_step_id ];
		$step_config['pipeline_id'] = $pipeline_id;

		return $step_config;
	}

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'datamachine_pipelines';
		$charset_collate = $wpdb->get_charset_collate();

		// We need dbDelta()
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			pipeline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_name varchar(255) NOT NULL,
			pipeline_config longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (pipeline_id),
			KEY pipeline_name (pipeline_name),
			KEY created_at (created_at),
			KEY updated_at (updated_at)
		) $charset_collate;";

		dbDelta( $sql );

		// Log table creation
		do_action(
			'datamachine_log',
			'debug',
			'Created pipelines database table',
			array(
				'table_name' => $table_name,
				'action'     => 'create_table',
			)
		);
	}
}
