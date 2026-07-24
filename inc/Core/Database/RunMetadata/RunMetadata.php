<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns this custom operational index table.
/**
 * Indexed run metadata repository.
 *
 * @package DataMachine\Core\Database\RunMetadata
 */

namespace DataMachine\Core\Database\RunMetadata;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\ExecutionQuery;

defined( 'ABSPATH' ) || exit;

final class RunMetadata extends BaseRepository {

	public const TABLE_NAME = 'datamachine_run_metadata';

	private const MAX_PATH_LENGTH  = 191;
	private const MAX_VALUE_LENGTH = 191;

	/**
	 * Create indexed run metadata table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			metadata_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT(20) UNSIGNED NOT NULL,
			metadata_path VARCHAR(191) NOT NULL,
			metadata_value VARCHAR(191) NOT NULL,
			value_type VARCHAR(20) NOT NULL DEFAULT 'string',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (metadata_id),
			UNIQUE KEY job_path (job_id, metadata_path),
			KEY path_value (metadata_path, metadata_value),
			KEY job_id (job_id)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Replace indexed metadata for a job.
	 *
	 * @param int                 $job_id   Job ID.
	 * @param array<string,mixed> $metadata Metadata keyed by dot-path.
	 * @return bool
	 */
	public function replace_for_job( int $job_id, array $metadata ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$rows = $this->normalize_rows( $job_id, $metadata );
		$this->wpdb->delete( $this->table_name, array( 'job_id' => $job_id ), array( '%d' ) );
		if ( empty( $rows ) ) {
			return true;
		}

		foreach ( $rows as $row ) {
			$result = $this->wpdb->replace(
				$this->table_name,
				$row,
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Index configured metadata paths from an engine snapshot.
	 *
	 * @param int                 $job_id      Job ID.
	 * @param array<string,mixed> $engine_data Engine data snapshot.
	 * @return bool
	 */
	public function replace_for_engine_data( int $job_id, array $engine_data ): bool {
		$paths = apply_filters( 'datamachine_run_metadata_index_paths', array(), $job_id, $engine_data );
		if ( ! is_array( $paths ) ) {
			$paths = array();
		}
		$paths = array_values( array_filter( array_map( 'strval', $paths ) ) );
		if ( empty( $paths ) ) {
			return true;
		}

		$metadata = array();
		foreach ( $paths as $path ) {
			$path = sanitize_text_field( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			$value = ExecutionQuery::get_path_value( $engine_data, $path );
			if ( null !== $value && is_scalar( $value ) ) {
				$metadata[ $path ] = $value;
			}
		}

		return $this->replace_for_job( $job_id, $metadata );
	}

	/**
	 * Query job IDs that match every exact metadata filter.
	 *
	 * @param array<string,mixed> $metadata_filters Metadata filters keyed by dot-path.
	 * @param int                 $limit            Max rows.
	 * @param int                 $offset           Offset.
	 * @return array<int,int>
	 */
	public function query_job_ids( array $metadata_filters, int $limit = 50, int $offset = 0 ): array {
		$filters = ExecutionQuery::normalize_metadata_filters( $metadata_filters );
		if ( empty( $filters ) ) {
			return array();
		}

		$limit        = max( 1, min( 500, $limit ) );
		$offset       = max( 0, $offset );
		$where_parts  = array();
		$where_values = array();
		foreach ( $filters as $path => $value ) {
			$normalized = $this->normalize_value( $value );
			if ( null === $normalized ) {
				return array();
			}

			$where_parts[]  = '(metadata_path = %s AND metadata_value = %s)';
			$where_values[] = $this->bounded_path( $path );
			$where_values[] = $normalized['metadata_value'];
		}

		$where_sql = implode( ' OR ', $where_parts );
		$needed    = count( $filters );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$query = $this->wpdb->prepare(
			"SELECT job_id
			 FROM {$this->table_name}
			 WHERE {$where_sql}
			 GROUP BY job_id
			 HAVING COUNT(DISTINCT metadata_path) = %d
			 ORDER BY job_id DESC
			 LIMIT %d OFFSET %d",
			array_merge( $where_values, array( $needed, $limit, $offset ) )
		);
		$rows  = $this->wpdb->get_col( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count jobs that match every exact metadata filter.
	 *
	 * @param array<string,mixed> $metadata_filters Metadata filters keyed by dot-path.
	 * @return int
	 */
	public function count_jobs( array $metadata_filters ): int {
		$filters = ExecutionQuery::normalize_metadata_filters( $metadata_filters );
		if ( empty( $filters ) ) {
			return 0;
		}

		$where_parts  = array();
		$where_values = array();
		foreach ( $filters as $path => $value ) {
			$normalized = $this->normalize_value( $value );
			if ( null === $normalized ) {
				return 0;
			}

			$where_parts[]  = '(metadata_path = %s AND metadata_value = %s)';
			$where_values[] = $this->bounded_path( $path );
			$where_values[] = $normalized['metadata_value'];
		}

		$where_sql = implode( ' OR ', $where_parts );
		$needed    = count( $filters );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$query = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT job_id
				FROM {$this->table_name}
				WHERE {$where_sql}
				GROUP BY job_id
				HAVING COUNT(DISTINCT metadata_path) = %d
			) matches",
			array_merge( $where_values, array( $needed ) )
		);
		$count = $this->wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * @param array<string,mixed> $metadata Metadata keyed by dot-path.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_rows( int $job_id, array $metadata ): array {
		$rows = array();
		$now  = gmdate( 'Y-m-d H:i:s' );
		foreach ( ExecutionQuery::normalize_metadata_filters( $metadata ) as $path => $value ) {
			$normalized = $this->normalize_value( $value );
			if ( null === $normalized ) {
				continue;
			}

			$rows[] = array(
				'job_id'         => $job_id,
				'metadata_path'  => $this->bounded_path( $path ),
				'metadata_value' => $normalized['metadata_value'],
				'value_type'     => $normalized['value_type'],
				'created_at'     => $now,
				'updated_at'     => $now,
			);
		}

		return $rows;
	}

	/**
	 * @return array{metadata_value:string,value_type:string}|null
	 */
	private function normalize_value( mixed $value ): ?array {
		if ( is_bool( $value ) ) {
			return array(
				'metadata_value' => $value ? 'true' : 'false',
				'value_type'     => 'bool',
			);
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return array(
				'metadata_value' => substr( (string) $value, 0, self::MAX_VALUE_LENGTH ),
				'value_type'     => is_int( $value ) ? 'int' : 'float',
			);
		}

		if ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
			if ( '' === $value ) {
				return null;
			}

			return array(
				'metadata_value' => substr( $value, 0, self::MAX_VALUE_LENGTH ),
				'value_type'     => 'string',
			);
		}

		return null;
	}

	private function bounded_path( string $path ): string {
		return substr( sanitize_text_field( $path ), 0, self::MAX_PATH_LENGTH );
	}
}
