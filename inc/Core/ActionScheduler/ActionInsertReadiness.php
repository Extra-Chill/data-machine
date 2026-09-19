<?php
/**
 * Read-only Action Scheduler action-insert diagnostics.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes bounded table metadata without attempting a diagnostic write.
 */
class ActionInsertReadiness {

	/** @var \wpdb */
	private $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		$this->wpdb = $wpdb;
	}

	/** @return array<string,mixed> */
	public function inspect(): array {
		$table = $this->wpdb->prefix . 'actionscheduler_actions';
		$base  = array(
			'success'              => false,
			'status'               => 'inspection_failed',
			'table'                => $table,
			'write_test_performed' => false,
			'limitations'          => array(
				'This read-only snapshot cannot prove that the next insert will succeed or establish the cause of an earlier storage-engine failure.',
			),
			'errors'               => array(),
		);

		// SQLite does not expose MySQL/MariaDB auto-increment storage-engine metadata.
		if ( str_contains( strtolower( get_class( $this->wpdb ) ), 'sqlite' ) ) {
			$base['status']   = 'unsupported';
			$base['errors'][] = 'Action insert metadata inspection is only available for MySQL or MariaDB.';
			return $base;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		if ( '' === $database ) {
			$base['errors'][] = 'Unable to determine the active database.';
			return $base;
		}

		// This uses only table metadata visible to the WordPress database user; PROCESS is not required.
		$table_info_query = $this->wpdb->prepare(
			'SELECT ENGINE, TABLE_ROWS, AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME, CHECK_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$database,
			$table
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with scalar schema and table values.
		$table_info = $this->wpdb->get_row(
			$table_info_query,
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $table_info ) ) {
			$base['errors'][] = '' !== (string) $this->wpdb->last_error
				? 'Unable to inspect the actions table: ' . (string) $this->wpdb->last_error
				: 'The Action Scheduler actions table was not found.';
			return $base;
		}

		// MAX(action_id) is a bounded primary-key lookup, not a table scan.
		$max_action_id_query = $this->wpdb->prepare( 'SELECT MAX(action_id) FROM %i', $table );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with an identifier placeholder.
		$max_action_id = $this->wpdb->get_var( $max_action_id_query );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $this->wpdb->last_error ) {
			$base['errors'][] = 'Unable to read the maximum action ID: ' . (string) $this->wpdb->last_error;
			return $base;
		}

		$engine         = strtoupper( (string) ( $table_info['ENGINE'] ?? '' ) );
		$auto_increment = null === ( $table_info['AUTO_INCREMENT'] ?? null ) ? null : (int) $table_info['AUTO_INCREMENT'];
		$max_action_id  = null === $max_action_id ? 0 : max( 0, (int) $max_action_id );
		$next_id_ahead  = null !== $auto_increment && $auto_increment > $max_action_id;
		$metadata_ready = 'INNODB' === $engine && $next_id_ahead;

		return array_merge(
			$base,
			array(
				'success'        => true,
				'status'         => $metadata_ready ? 'metadata_coherent' : 'metadata_warning',
				'database'       => $database,
				'engine'         => $engine,
				'rows_estimate'  => max( 0, (int) ( $table_info['TABLE_ROWS'] ?? 0 ) ),
				'auto_increment' => $auto_increment,
				'max_action_id'  => $max_action_id,
				'next_id_ahead'  => $next_id_ahead,
				'metadata_ready' => $metadata_ready,
				'create_time'    => $table_info['CREATE_TIME'] ?? null,
				'update_time'    => $table_info['UPDATE_TIME'] ?? null,
				'check_time'     => $table_info['CHECK_TIME'] ?? null,
				'recommendation' => $metadata_ready
					? 'Metadata is coherent at inspection time. Correlate any insert exception with database server logs before choosing an operator-controlled repair.'
					: 'Metadata is not coherent enough to infer insert readiness. Escalate with this snapshot and database server evidence; do not repair automatically.',
			)
		);
	}
}
