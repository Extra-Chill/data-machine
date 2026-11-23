<?php
/**
 * Storage for imported Amazon Associates report lines.
 *
 * One row per report line. The table is network-scoped (base prefix) because
 * one Associates account and its tracking IDs serve every site in a network.
 *
 * @package DataMachineBusiness\Abilities\Analytics\AmazonAssociates
 */

namespace DataMachineBusiness\Abilities\Analytics\AmazonAssociates;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

class ReportRowsRepository extends BaseRepository {

	const TABLE_NAME     = 'datamachine_amazon_associates_rows';
	const SCHEMA_VERSION = 1;
	const SCHEMA_OPTION  = 'datamachine_amazon_associates_rows_schema';
	const INSERT_CHUNK   = 200;

	const COLUMNS = array(
		'report_type'  => '%s',
		'report_date'  => '%s',
		'tracking_id'  => '%s',
		'asin'         => '%s',
		'name'         => '%s',
		'category'     => '%s',
		'seller'       => '%s',
		'price'        => '%s',
		'items'        => '%d',
		'returns'      => '%d',
		'revenue'      => '%s',
		'ad_fees'      => '%s',
		'device_type'  => '%s',
		'link_type'    => '%s',
		'indirect'     => '%s',
		'currency'     => '%s',
		'marketplace'  => '%s',
		'import_batch' => '%s',
		'imported_at'  => '%s',
	);

	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->base_prefix . self::TABLE_NAME;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			row_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			report_type VARCHAR(20) NOT NULL,
			report_date DATE NOT NULL,
			tracking_id VARCHAR(64) NOT NULL DEFAULT '',
			asin VARCHAR(20) NOT NULL DEFAULT '',
			name TEXT NULL,
			category VARCHAR(191) NOT NULL DEFAULT '',
			seller VARCHAR(191) NOT NULL DEFAULT '',
			price DECIMAL(15,2) NOT NULL DEFAULT 0,
			items INT(11) NOT NULL DEFAULT 0,
			returns INT(11) NOT NULL DEFAULT 0,
			revenue DECIMAL(15,2) NOT NULL DEFAULT 0,
			ad_fees DECIMAL(15,2) NOT NULL DEFAULT 0,
			device_type VARCHAR(64) NOT NULL DEFAULT '',
			link_type VARCHAR(64) NOT NULL DEFAULT '',
			indirect TINYINT(1) NULL DEFAULT NULL,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			marketplace VARCHAR(64) NOT NULL DEFAULT '',
			import_batch VARCHAR(64) NOT NULL DEFAULT '',
			imported_at DATETIME NOT NULL,
			PRIMARY KEY  (row_id),
			KEY type_window (report_type, marketplace, report_date),
			KEY tracking_date (tracking_id, report_date),
			KEY import_batch (import_batch)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/** Create or upgrade both Associates tables once per schema version. */
	public static function ensure_schema(): void {
		if ( self::SCHEMA_VERSION === (int) get_site_option( self::SCHEMA_OPTION, 0 ) ) {
			return;
		}
		self::create_table();
		ImportsRepository::create_table();
		update_site_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/** Delete every row of one report type/marketplace inside an inclusive date window. */
	public function delete_window( string $report_type, string $marketplace, string $start, string $end ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Idempotent window replacement on a plugin-owned table; fully prepared.
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE report_type = %s AND marketplace = %s AND report_date BETWEEN %s AND %s',
				$this->table_name,
				$report_type,
				$marketplace,
				$start,
				$end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$this->log_db_error( 'delete Amazon Associates window' );
		return (int) $deleted;
	}

	/**
	 * Insert normalized rows in chunks.
	 *
	 * @param array  $rows        Normalized parser rows.
	 * @param string $batch       Import batch identifier.
	 * @param string $imported_at MySQL datetime (UTC).
	 * @return int|false Inserted rows or false on database error.
	 */
	public function insert_rows( array $rows, string $batch, string $imported_at ) {
		$inserted = 0;
		$columns  = '`' . implode( '`, `', array_keys( self::COLUMNS ) ) . '`';
		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(' . implode( ', ', self::placeholders( $row ) ) . ')';
				foreach ( $this->storageValues( $row, $batch, $imported_at ) as $value ) {
					if ( null !== $value ) {
						$values[] = $value;
					}
				}
			}
			// Column list is a class constant; placeholders are generated per row.
			$query = "INSERT INTO {$this->table_name} ({$columns}) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders -- See above.
			$sql = $this->wpdb->prepare( $query, $values ); // @phpstan-ignore argument.type
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$result = $this->wpdb->query( $sql );
			if ( false === $result ) {
				$this->log_db_error( 'insert Amazon Associates rows', array( 'batch' => $batch ) );
				return false;
			}
			$inserted += count( $chunk );
		}
		return $inserted;
	}

	/**
	 * Read rows for a window with optional filters.
	 *
	 * @param array $filters report_type, start, end, tracking_id, marketplace.
	 * @param int   $limit   Max rows (0 = unbounded, for aggregation).
	 * @return array<int,array<string,mixed>>
	 */
	public function find_rows( array $filters, int $limit = 0 ): array {
		list( $where, $args ) = $this->where( $filters );
		/** @var literal-string $sql */
		$sql = "SELECT * FROM {$this->table_name} WHERE {$where} ORDER BY report_date DESC, row_id ASC";
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- WHERE built from fixed fragments with placeholders.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function count_matching( array $filters ): int {
		list( $where, $args ) = $this->where( $filters );
		return $this->count_rows( $where, $args );
	}

	/**
	 * Amount columns needed for summaries. Summing happens in integer cents in
	 * PHP so totals stay exact on MySQL and SQLite (whose SUM() is floating point).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function summary_rows( array $filters ): array {
		list( $where, $args ) = $this->where( $filters );
		/** @var literal-string $sql */
		$sql = "SELECT report_type, tracking_id, currency, ad_fees, revenue, items, returns FROM {$this->table_name} WHERE {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- WHERE built from fixed fragments with placeholders.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Per-tracking-ID date coverage derived from stored rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function tracking_coverage( array $filters ): array {
		list( $where, $args ) = $this->where( $filters );
		/** @var literal-string $sql */
		$sql = "SELECT report_type, marketplace, tracking_id, MIN(report_date) AS first_date, MAX(report_date) AS last_date, COUNT(*) AS row_count FROM {$this->table_name} WHERE {$where} GROUP BY report_type, marketplace, tracking_id ORDER BY report_type, marketplace, tracking_id";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- WHERE built from fixed fragments with placeholders.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array{0:string,1:array} */
	private function where( array $filters ): array {
		$where = array( 'report_type IN (%s, %s)' );
		$args  = array( 'earnings', 'orders' );
		$map   = array(
			'report_type' => 'report_type = %s',
			'tracking_id' => 'tracking_id = %s',
			'marketplace' => 'marketplace = %s',
			'start'       => 'report_date >= %s',
			'end'         => 'report_date <= %s',
		);
		foreach ( $map as $key => $fragment ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[] = $fragment;
				$args[]  = (string) $filters[ $key ];
			}
		}
		return array( implode( ' AND ', $where ), $args );
	}

	private static function placeholders( array $row ): array {
		$placeholders = array();
		foreach ( self::COLUMNS as $column => $format ) {
			$placeholders[] = ( 'indirect' === $column && null === ( $row['indirect'] ?? null ) ) ? 'NULL' : $format;
		}
		return $placeholders;
	}

	private static function indirectValue( array $row ): ?string {
		$indirect = $row['indirect'] ?? null;
		if ( null === $indirect ) {
			return null;
		}
		return $indirect ? '1' : '0';
	}

	private function storageValues( array $row, string $batch, string $imported_at ): array {
		return array(
			$row['report_type'],
			$row['date'],
			(string) $row['tracking_id'],
			(string) $row['asin'],
			(string) $row['name'],
			(string) $row['category'],
			(string) $row['seller'],
			(string) $row['price'],
			(int) $row['items'],
			(int) $row['returns'],
			(string) $row['revenue'],
			(string) $row['ad_fees'],
			(string) $row['device_type'],
			(string) $row['link_type'],
			self::indirectValue( $row ),
			(string) $row['currency'],
			(string) $row['marketplace'],
			$batch,
			$imported_at,
		);
	}
}
