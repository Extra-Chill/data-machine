<?php
/**
 * Generic revenue ledger storage.
 *
 * One table holds normalized revenue rows from every source reader
 * (ad networks, payment processors, affiliate programs, ...). Source-native
 * numbers live in the `metrics` JSON column so the column set stays small and
 * source-agnostic. Coverage is derived from the stored periods; there is no
 * second table.
 *
 * Writes go exclusively through RevenueLedgerAbilities::ingest()
 * (`datamachine/revenue-ingest`), which calls replace_window().
 *
 * @package DataMachineBusiness\Abilities\Revenue
 */

namespace DataMachineBusiness\Abilities\Revenue;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

// All identifiers and values below pass through wpdb::prepare(). WPCS does not
// recognize prepare() calls that use WordPress's %i identifier placeholder with
// dynamically built IN() lists.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

class RevenueLedger extends BaseRepository {

	public const TABLE_NAME     = 'datamachine_revenue_ledger';
	public const SCHEMA_VERSION = 1;
	public const SCHEMA_OPTION  = 'datamachine_revenue_ledger_schema_version';

	/** Columns written by an ingest, in insert order. */
	public const COLUMNS = array( 'source', 'account', 'kind', 'period_start', 'period_end', 'grain', 'amount', 'currency', 'url', 'object_ref', 'blog_id', 'metrics', 'import_batch', 'imported_at' );

	/**
	 * Network-scoped: revenue accounts (an ad network site, a payment account)
	 * are not owned by one blog. Per-blog attribution lives in `blog_id`.
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	public static function table_name(): string {
		return static::get_table_prefix() . self::TABLE_NAME;
	}

	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(64) NOT NULL,
			account varchar(191) NOT NULL DEFAULT '',
			kind varchar(64) NOT NULL,
			period_start date NOT NULL,
			period_end date NOT NULL,
			grain varchar(16) NOT NULL,
			amount decimal(20,6) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL,
			url varchar(2048) DEFAULT NULL,
			object_ref varchar(191) DEFAULT NULL,
			blog_id bigint(20) unsigned DEFAULT NULL,
			metrics longtext DEFAULT NULL,
			import_batch varchar(64) NOT NULL,
			imported_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_window (source, account, kind, period_start, period_end),
			KEY period (period_start, period_end),
			KEY import_batch (import_batch)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( BaseRepository::database_table_exists( $table_name, $wpdb ) || self::describe_ok( $table_name ) ) {
			update_site_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}
	}

	/** Create or upgrade the table once per schema version. */
	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION === (int) get_site_option( self::SCHEMA_OPTION, 0 ) ) {
			return;
		}
		self::create_table();
	}

	/** Temporary tables (test suites) are invisible to SHOW TABLES; DESCRIBE still sees them. */
	private static function describe_ok( string $table_name ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table_name ) );
		$wpdb->suppress_errors( $suppress );
		return ! empty( $columns );
	}

	/**
	 * Count rows replace_window() would delete.
	 *
	 * @param string[] $kinds Kinds in the replace scope.
	 */
	public function count_window( string $source, string $account, array $kinds, string $start, string $end ): int {
		if ( empty( $kinds ) ) {
			return 0;
		}
		list( $where, $args ) = $this->window_where( $source, $account, $kinds, $start, $end );
		return $this->count_rows( $where, $args );
	}

	/**
	 * Count rows of the same scope that overlap the window without being
	 * contained in it. Replacing around them would double count.
	 *
	 * @param string[] $kinds Kinds in the replace scope.
	 */
	public function count_straddling( string $source, string $account, array $kinds, string $start, string $end ): int {
		if ( empty( $kinds ) ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$where = "source = %s AND account = %s AND kind IN ({$in}) AND period_start <= %s AND period_end >= %s AND ( period_start < %s OR period_end > %s )";
		return $this->count_rows( $where, array_merge( array( $source, $account ), $kinds, array( $end, $start, $start, $end ) ) );
	}

	/**
	 * Atomically replace every row of (source, account, kind ∈ kinds) contained
	 * in [start, end] with the given normalized rows.
	 *
	 * @param string[] $kinds Kinds in the replace scope.
	 * @param array[]  $rows  Normalized rows keyed by self::COLUMNS.
	 * @return array{replaced:int,inserted:int}|\WP_Error
	 */
	public function replace_window( string $source, string $account, array $kinds, string $start, string $end, array $rows ) {
		$scope = class_exists( '\\DataMachine\\Core\\Database\\TransactionScope' ) ? \DataMachine\Core\Database\TransactionScope::begin( $this->wpdb ) : null;

		$replaced = 0;
		if ( ! empty( $kinds ) ) {
			list( $where, $args ) = $this->window_where( $source, $account, $kinds, $start, $end );
			$deleted              = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE {$where}", $this->table_name, ...$args ) );
			if ( false === $deleted ) {
				return $this->fail( $scope, 'delete' );
			}
			$replaced = (int) $deleted;
		}

		$inserted = 0;
		foreach ( $rows as $row ) {
			$data = array();
			foreach ( self::COLUMNS as $column ) {
				$data[ $column ] = $row[ $column ] ?? null;
			}
			$formats = array_map( static fn( string $column ): string => 'blog_id' === $column ? '%d' : '%s', self::COLUMNS );
			if ( null === $data['blog_id'] ) {
				$formats[ array_search( 'blog_id', self::COLUMNS, true ) ] = '%s';
			}
			if ( false === $this->wpdb->insert( $this->table_name, $data, $formats ) ) {
				return $this->fail( $scope, 'insert' );
			}
			++$inserted;
		}

		if ( null !== $scope && ! $scope->commit() ) {
			return $this->fail( $scope, 'commit' );
		}

		return array(
			'replaced' => $replaced,
			'inserted' => $inserted,
		);
	}

	/**
	 * Fetch rows overlapping an optional window, filtered by optional scope.
	 *
	 * @param array $filters start_date, end_date, source, account, kind, currency.
	 * @return array[]
	 */
	public function find( array $filters ): array {
		$where = array( '1=1' );
		$args  = array( $this->table_name );
		foreach ( array( 'source', 'account', 'kind', 'currency' ) as $column ) {
			if ( isset( $filters[ $column ] ) && '' !== $filters[ $column ] ) {
				$where[] = "{$column} = %s";
				$args[]  = (string) $filters[ $column ];
			}
		}
		if ( ! empty( $filters['end_date'] ) ) {
			$where[] = 'period_start <= %s';
			$args[]  = $filters['end_date'];
		}
		if ( ! empty( $filters['start_date'] ) ) {
			$where[] = 'period_end >= %s';
			$args[]  = $filters['start_date'];
		}
		$sql  = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY period_start ASC, source ASC, account ASC, kind ASC, id ASC';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$this->log_db_error( 'revenue ledger find' );
		return is_array( $rows ) ? array_map( array( self::class, 'hydrate' ), $rows ) : array();
	}

	/** Decode a stored row into its public shape. */
	public static function hydrate( array $row ): array {
		$metrics = null;
		if ( isset( $row['metrics'] ) && '' !== $row['metrics'] ) {
			$decoded = json_decode( (string) $row['metrics'], true );
			$metrics = is_array( $decoded ) ? $decoded : null;
		}
		return array(
			'id'           => (int) $row['id'],
			'source'       => (string) $row['source'],
			'account'      => (string) $row['account'],
			'kind'         => (string) $row['kind'],
			'period_start' => substr( (string) $row['period_start'], 0, 10 ),
			'period_end'   => substr( (string) $row['period_end'], 0, 10 ),
			'grain'        => (string) $row['grain'],
			'amount'       => RevenueDecimal::from_storage( $row['amount'] ),
			'currency'     => (string) $row['currency'],
			'url'          => isset( $row['url'] ) && '' !== $row['url'] ? (string) $row['url'] : null,
			'object_ref'   => isset( $row['object_ref'] ) && '' !== $row['object_ref'] ? (string) $row['object_ref'] : null,
			'blog_id'      => isset( $row['blog_id'] ) && '' !== (string) $row['blog_id'] ? (int) $row['blog_id'] : null,
			'metrics'      => $metrics,
			'import_batch' => (string) $row['import_batch'],
			'imported_at'  => (string) $row['imported_at'],
		);
	}

	/** @return array{0:literal-string,1:array} */
	private function window_where( string $source, string $account, array $kinds, string $start, string $end ): array {
		$in = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		return array(
			"source = %s AND account = %s AND kind IN ({$in}) AND period_start >= %s AND period_end <= %s",
			array_merge( array( $source, $account ), array_values( $kinds ), array( $start, $end ) ),
		);
	}

	/** Roll back and surface a storage failure without leaking SQL. */
	private function fail( $scope, string $operation ): \WP_Error {
		$this->log_db_error( 'revenue ledger ' . $operation );
		if ( null !== $scope ) {
			$scope->rollback();
		}
		return new \WP_Error( 'revenue_ledger_write_failed', 'Revenue ledger ' . $operation . ' failed; no rows were changed.' );
	}
}
