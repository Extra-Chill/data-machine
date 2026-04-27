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
		$user_id              = isset( $pipeline_data['user_id'] ) ? absint( $pipeline_data['user_id'] ) : 0;
		$agent_id             = isset( $pipeline_data['agent_id'] ) ? absint( $pipeline_data['agent_id'] ) : null;

		$data = array(
			'user_id'         => $user_id,
			'pipeline_name'   => $pipeline_name,
			'pipeline_config' => $pipeline_config_json,
			'created_at'      => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		);

		$format = array( '%d', '%s', '%s', '%s', '%s' );

		if ( null !== $agent_id && $agent_id > 0 ) {
			$data['agent_id'] = $agent_id;
			$format[]         = '%d';
		}

		if ( isset( $pipeline_data['portable_slug'] ) && '' !== trim( (string) $pipeline_data['portable_slug'] ) ) {
			$data['portable_slug'] = sanitize_title( (string) $pipeline_data['portable_slug'] );
			$format[]              = '%s';
		}

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
	 * When $per_page is a positive integer, results are paginated at the SQL layer.
	 * When $per_page is null (default), all matching pipelines are returned.
	 *
	 * @param int|null    $user_id  Optional user ID to filter by.
	 * @param int|null    $agent_id Optional agent ID to filter by.
	 * @param string|null $search   Optional search term to filter by pipeline name (LIKE).
	 * @param int|null    $per_page Optional SQL LIMIT. Null returns all rows.
	 * @param int         $offset   SQL OFFSET (only honored when $per_page is set).
	 * @return array Array of pipeline records
	 */
	public function get_all_pipelines(
		?int $user_id = null,
		?int $agent_id = null,
		?string $search = null,
		?int $per_page = null,
		int $offset = 0
	): array {
		$where_clauses = array();
		$where_values  = array();

		if ( null !== $agent_id ) {
			$where_clauses[] = 'agent_id = %d';
			$where_values[]  = $agent_id;
		} elseif ( null !== $user_id ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = $user_id;
		}

		if ( null !== $search && '' !== $search ) {
			$where_clauses[] = 'pipeline_name LIKE %s';
			$where_values[]  = '%' . $this->wpdb->esc_like( $search ) . '%';
		}

		$where = '';
		if ( ! empty( $where_clauses ) ) {
			$where = ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		$limit_clause = '';
		$limit_values = array();
		if ( null !== $per_page && $per_page > 0 ) {
			$limit_clause   = ' LIMIT %d OFFSET %d';
			$limit_values[] = (int) $per_page;
			$limit_values[] = max( 0, (int) $offset );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i{$where} ORDER BY updated_at DESC{$limit_clause}",
				array_merge( array( $this->table_name ), $where_values, $limit_values )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $results as &$pipeline ) {
			if ( ! empty( $pipeline['pipeline_config'] ) ) {
				$pipeline['pipeline_config'] = json_decode( $pipeline['pipeline_config'], true ) ?? array();
			}
		}

		return $results ? $results : array();
	}

	/**
	 * Get lightweight pipelines list for UI dropdowns.
	 *
	 * @param int|null $user_id  Optional user ID to filter by.
	 * @param int|null $agent_id Optional agent ID to filter by.
	 */
	public function get_pipelines_list( ?int $user_id = null, ?int $agent_id = null ): array {
		$where        = '';
		$where_values = array();

		if ( null !== $agent_id ) {
			$where          = ' WHERE agent_id = %d';
			$where_values[] = $agent_id;
		} elseif ( null !== $user_id ) {
			$where          = ' WHERE user_id = %d';
			$where_values[] = $user_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pipeline_id, pipeline_name FROM %i{$where} ORDER BY pipeline_name ASC",
				array_merge( array( $this->table_name ), $where_values )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

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
	 *
	 * @param int|null    $user_id  Optional user ID to filter by.
	 * @param int|null    $agent_id Optional agent ID to filter by.
	 * @param string|null $search   Optional search term to filter by pipeline name (LIKE).
	 */
	public function get_pipelines_count( ?int $user_id = null, ?int $agent_id = null, ?string $search = null ): int {
		$where_clauses = array();
		$where_values  = array();

		if ( null !== $agent_id ) {
			$where_clauses[] = 'agent_id = %d';
			$where_values[]  = $agent_id;
		} elseif ( null !== $user_id ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = $user_id;
		}

		if ( null !== $search && '' !== $search ) {
			$where_clauses[] = 'pipeline_name LIKE %s';
			$where_values[]  = '%' . $this->wpdb->esc_like( $search ) . '%';
		}

		$where = '';
		if ( ! empty( $where_clauses ) ) {
			$where = ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(pipeline_id) FROM %i{$where}",
				array_merge( array( $this->table_name ), $where_values )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

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
		$user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : null;
		$agent_id = isset( $args['agent_id'] ) ? absint( $args['agent_id'] ) : null;
		$is_asc   = ( 'ASC' === $order );

		$where = '';
		if ( null !== $agent_id ) {
			$where = $this->wpdb->prepare( ' WHERE agent_id = %d', $agent_id );
		} elseif ( null !== $user_id ) {
			$where = $this->wpdb->prepare( ' WHERE user_id = %d', $user_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$results = match ( $orderby ) {
			'pipeline_name' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY pipeline_name ASC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY pipeline_name DESC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A ),
			'created_at' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY created_at ASC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A ),
			'updated_at' => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY updated_at ASC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A ),
			default => $is_asc
				? $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY pipeline_id ASC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A )
				: $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY pipeline_id DESC LIMIT %d OFFSET %d", $this->table_name, $per_page, $offset ), ARRAY_A ),
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
	 * Get pipeline memory files from pipeline config.
	 *
	 * @param int $pipeline_id Pipeline ID.
	 * @return array Array of memory filenames.
	 */
	public function get_pipeline_memory_files( int $pipeline_id ): array {
		$pipeline_config = $this->get_pipeline_config( $pipeline_id );
		return $pipeline_config['memory_files'] ?? array();
	}

	/**
	 * Update pipeline memory files in pipeline config.
	 *
	 * @param int   $pipeline_id  Pipeline ID.
	 * @param array $memory_files Array of memory filenames.
	 * @return bool True on success, false on failure.
	 */
	public function update_pipeline_memory_files( int $pipeline_id, array $memory_files ): bool {
		if ( empty( $pipeline_id ) ) {
			return false;
		}

		$pipeline_config                 = $this->get_pipeline_config( $pipeline_id );
		$pipeline_config['memory_files'] = $memory_files;

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

	/**
	 * Migrate existing table columns to current schema.
	 *
	 * Handles:
	 * - user_id column: added for multi-agent support
	 *
	 * Safe to run multiple times - only executes if columns need updating.
	 */
	public function migrate_columns(): void {
		// Check if user_id column already exists.
		if ( ! self::column_exists( $this->table_name, 'user_id', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
			// is cosmetic — both engines accept the bare ADD COLUMN form.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				 ADD KEY user_id (user_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add user_id column to pipelines table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added user_id column to pipelines table for multi-agent support',
				array( 'table_name' => $this->table_name )
			);
		}

		// Add agent_id column for agent-first scoping (#735).
		if ( ! self::column_exists( $this->table_name, 'agent_id', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN agent_id bigint(20) unsigned DEFAULT NULL,
				 ADD KEY agent_id (agent_id)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add agent_id column to pipelines table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added agent_id column to pipelines table for agent-first scoping',
				array( 'table_name' => $this->table_name )
			);
		}

		// Stable bundle filename/reference for portable agent exports (#1303).
		if ( ! self::column_exists( $this->table_name, 'portable_slug', $this->wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it.
			$result = $this->wpdb->query(
				"ALTER TABLE {$this->table_name}
				 ADD COLUMN portable_slug varchar(191) DEFAULT NULL,
				 ADD UNIQUE KEY agent_portable_slug (agent_id, portable_slug)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( false === $result ) {
				do_action(
					'datamachine_log',
					'error',
					'Failed to add portable_slug column to pipelines table',
					array(
						'table_name' => $this->table_name,
						'db_error'   => $this->wpdb->last_error,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'info',
				'Added portable_slug column to pipelines table for agent bundles',
				array( 'table_name' => $this->table_name )
			);
		}
	}

	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'datamachine_pipelines';
		$charset_collate = $wpdb->get_charset_collate();

		// We need dbDelta()
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			pipeline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			agent_id bigint(20) unsigned DEFAULT NULL,
			pipeline_name varchar(255) NOT NULL,
			portable_slug varchar(191) DEFAULT NULL,
			pipeline_config longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (pipeline_id),
			KEY user_id (user_id),
			KEY agent_id (agent_id),
			KEY pipeline_name (pipeline_name),
			UNIQUE KEY agent_portable_slug (agent_id, portable_slug),
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
