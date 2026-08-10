<?php
/**
 * Operator-controlled Action Scheduler claim index migration.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects and explicitly installs the index required by the default claim order.
 */
class ClaimIndexMigration {

	public const INDEX_NAME       = 'claim_id_status_priority_attempts_scheduled_date_gmt';
	public const REQUIRED_COLUMNS = array(
		'claim_id',
		'status',
		'priority',
		'attempts',
		'scheduled_date_gmt',
		'action_id',
	);

	private const MINIMUM_FREE_BYTES      = 1073741824;
	private const ESTIMATED_BYTES_PER_ROW = 512;
	private const METADATA_LOCK_TIMEOUT   = 10;

	/** @var \wpdb */
	private $wpdb;

	private \Closure $disk_free_space;
	private string $database_host;

	/**
	 * @param \wpdb|null    $wpdb            Database connection. Defaults to the WordPress global.
	 * @param callable|null $disk_free_space Disk probe override for tests.
	 * @param string|null   $database_host   Database host override for tests.
	 */
	public function __construct( ?\wpdb $wpdb = null, ?callable $disk_free_space = null, ?string $database_host = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		$this->wpdb            = $wpdb;
		$this->disk_free_space = \Closure::fromCallable(
			$disk_free_space ?? static fn ( string $path ) => @disk_free_space( $path ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
		$this->database_host   = $database_host ?? ( defined( 'DB_HOST' ) ? (string) DB_HOST : '' );
	}

	/**
	 * Inspect readiness and every precondition needed for an online build.
	 *
	 * @param int|null $available_disk_bytes Operator-established database disk headroom for remote databases.
	 * @return array<string,mixed>
	 */
	public function inspect( ?int $available_disk_bytes = null ): array {
		$table = $this->wpdb->prefix . 'actionscheduler_actions';
		$base  = array(
			'success'          => false,
			'table'            => $table,
			'index'            => self::INDEX_NAME,
			'expected_columns' => self::REQUIRED_COLUMNS,
			'ready'            => false,
			'can_apply'        => false,
			'applied'          => false,
			'blockers'         => array(),
		);

		if ( BaseRepository::is_sqlite() ) {
			$base['status']     = 'unsupported';
			$base['blockers'][] = 'SQLite does not support this MySQL/MariaDB online DDL migration.';
			return $base;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		if ( '' === $database ) {
			$base['status']     = 'inspection_failed';
			$base['blockers'][] = 'Unable to determine the active database.';
			return $base;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_info = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$database,
				$table
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $table_info ) ) {
			$base['status']     = 'inspection_failed';
			$base['blockers'][] = 'The Action Scheduler actions table was not found.';
			return $base;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$index_rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( ! is_array( $index_rows ) || '' !== (string) $this->wpdb->last_error ) {
			$base['status']     = 'inspection_failed';
			$base['blockers'][] = 'Unable to inspect Action Scheduler indexes.';
			return $base;
		}

		$indexes        = self::normalizeIndexes( $index_rows );
		$usable_indexes = self::normalizeUsableIndexes( $index_rows );
		$matching_index = self::findCoveringIndex( $usable_indexes );
		$claim_plan     = null !== $matching_index ? $this->inspectClaimPlan( $table, $matching_index ) : null;
		$ready          = null !== $matching_index && $claim_plan['ready'];
		$name_collision = isset( $indexes[ self::INDEX_NAME ] ) && null === self::findCoveringIndex( array( self::INDEX_NAME => $usable_indexes[ self::INDEX_NAME ] ?? array() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$server = $this->wpdb->get_row( 'SELECT VERSION() AS version, @@version_comment AS version_comment, @@datadir AS datadir, @@tmpdir AS tmpdir', ARRAY_A );
		$server = is_array( $server ) ? $server : array();
		// SHOW VARIABLES is portable to releases predating @@innodb_tmpdir.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$innodb_tmpdir           = $this->wpdb->get_row( "SHOW VARIABLES LIKE 'innodb_tmpdir'", ARRAY_A );
		$server['innodb_tmpdir'] = is_array( $innodb_tmpdir ) ? (string) ( $innodb_tmpdir['Value'] ?? $innodb_tmpdir['value'] ?? '' ) : '';
		$runtime                 = self::detectOnlineDdlSupport(
			(string) ( $server['version'] ?? '' ),
			(string) ( $server['version_comment'] ?? '' ),
			(string) ( $table_info['ENGINE'] ?? '' )
		);

		$rows          = max( 0, (int) ( $table_info['TABLE_ROWS'] ?? 0 ) );
		$data_bytes    = max( 0, (int) ( $table_info['DATA_LENGTH'] ?? 0 ) );
		$index_bytes   = max( 0, (int) ( $table_info['INDEX_LENGTH'] ?? 0 ) );
		$required_disk = self::MINIMUM_FREE_BYTES + max( $rows * self::ESTIMATED_BYTES_PER_ROW, (int) ceil( $data_bytes / 2 ) );
		$disk          = null !== $matching_index
			? array(
				'source'         => 'not_required',
				'free_bytes'     => null,
				'required_bytes' => $required_disk,
				'sufficient'     => true,
				'message'        => 'No index build is required.',
			)
			: $this->inspectDiskHeadroom( $server, $required_disk, $available_disk_bytes );

		$ddl      = $this->buildDdl( $table );
		$blockers = array();
		if ( ! $ready && ! $runtime['supported'] ) {
			$blockers[] = $runtime['reason'];
		}
		if ( ! $ready && ! $disk['sufficient'] ) {
			$blockers[] = $disk['message'];
		}
		if ( ! $ready && $name_collision ) {
			$blockers[] = sprintf( 'Index name %s already exists with a different definition; no automatic drop is permitted.', self::INDEX_NAME );
		}
		if ( null !== $claim_plan && ! $claim_plan['ready'] ) {
			$blockers[] = $claim_plan['message'];
		}

		return array_merge(
			$base,
			array(
				'success'        => true,
				'status'         => $ready ? 'ready' : ( empty( $blockers ) ? 'migration_required' : 'blocked' ),
				'database'       => $database,
				'engine'         => strtoupper( (string) ( $table_info['ENGINE'] ?? '' ) ),
				'rows_estimate'  => $rows,
				'data_bytes'     => $data_bytes,
				'index_bytes'    => $index_bytes,
				'indexes'        => $indexes,
				'usable_indexes' => $usable_indexes,
				'matching_index' => $matching_index,
				'claim_plan'     => $claim_plan,
				'name_collision' => $name_collision,
				'ready'          => $ready,
				'runtime'        => $runtime,
				'disk'           => $disk,
				'ddl'            => $ddl,
				'can_apply'      => null === $matching_index && empty( $blockers ),
				'blockers'       => $blockers,
			)
		);
	}

	/**
	 * Apply the preflighted DDL and verify the resulting physical index.
	 *
	 * @param int|null $available_disk_bytes Operator-established database disk headroom.
	 * @return array<string,mixed>
	 */
	public function apply( ?int $available_disk_bytes = null ): array {
		$inspection = $this->inspect( $available_disk_bytes );
		if ( ! $inspection['success'] || $inspection['ready'] || ! $inspection['can_apply'] ) {
			return $inspection;
		}

		$lock_name = 'datamachine-as-claim-index-' . substr( hash( 'sha256', $inspection['database'] . ':' . $inspection['table'] ), 0, 32 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$lock = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== (string) $lock ) {
			$inspection['status']     = 'blocked';
			$inspection['can_apply']  = false;
			$inspection['blockers'][] = 'Another claim-index migration is already running.';
			return $inspection;
		}

		try {
			// Fail quickly on a metadata-lock conflict rather than waiting behind live traffic indefinitely.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $this->wpdb->query( 'SET SESSION lock_wait_timeout = ' . self::METADATA_LOCK_TIMEOUT ) ) {
				throw new \RuntimeException( 'Unable to set a bounded metadata lock timeout: ' . $this->wpdb->last_error );
			}

			// Explicit clauses prevent the server from silently falling back to a copying or blocking alter.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $this->wpdb->query( $inspection['ddl'] ) ) {
				throw new \RuntimeException( 'Online index creation failed: ' . $this->wpdb->last_error );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		$result            = $this->inspect( $available_disk_bytes );
		$result['applied'] = true;
		if ( ! $result['ready'] ) {
			throw new \RuntimeException( 'Online DDL completed but the required claim index could not be verified.' );
		}

		return $result;
	}

	/** @return array<string,array<int,string>> */
	public static function normalizeIndexes( array $rows ): array {
		$indexes = array();
		foreach ( $rows as $row ) {
			$name     = (string) ( $row['Key_name'] ?? $row['key_name'] ?? '' );
			$sequence = (int) ( $row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0 );
			$column   = (string) ( $row['Column_name'] ?? $row['column_name'] ?? '' );
			if ( '' === $name || $sequence < 1 || '' === $column ) {
				continue;
			}
			$indexes[ $name ][ $sequence ] = $column;
		}

		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );
			$indexes[ $name ] = array_values( $columns );
		}

		return $indexes;
	}

	/**
	 * Normalize only full-column, ascending, visible BTREE indexes usable by the claim query.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function normalizeUsableIndexes( array $rows ): array {
		$invalid = array();
		foreach ( $rows as $row ) {
			$name                 = (string) ( $row['Key_name'] ?? $row['key_name'] ?? '' );
			$sequence             = (int) ( $row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0 );
			$sub_part             = $row['Sub_part'] ?? $row['sub_part'] ?? null;
			$index_type           = strtoupper( (string) ( $row['Index_type'] ?? $row['index_type'] ?? '' ) );
			$collation            = strtoupper( (string) ( $row['Collation'] ?? $row['collation'] ?? '' ) );
			$visible              = strtoupper( (string) ( $row['Visible'] ?? $row['visible'] ?? 'YES' ) );
			$ignored              = strtoupper( (string) ( $row['Ignored'] ?? $row['ignored'] ?? 'NO' ) );
			$expression           = $row['Expression'] ?? $row['expression'] ?? null;
			$required_prefix_part = $sequence >= 1 && $sequence <= count( self::REQUIRED_COLUMNS );
			if ( '' === $name || ( $required_prefix_part && ( null !== $sub_part || 'BTREE' !== $index_type || 'A' !== $collation || 'NO' === $visible || 'YES' === $ignored || null !== $expression ) ) ) {
				$invalid[ $name ] = true;
			}
		}

		return array_diff_key( self::normalizeIndexes( $rows ), $invalid );
	}

	/** @param array<string,array<int,string>> $indexes */
	public static function findCoveringIndex( array $indexes ): ?string {
		foreach ( $indexes as $name => $columns ) {
			if ( self::REQUIRED_COLUMNS === array_slice( $columns, 0, count( self::REQUIRED_COLUMNS ) ) ) {
				return $name;
			}
		}
		return null;
	}

	/** @return array{ready:bool,key:string,rows:int,extra:string,message:string} */
	private function inspectClaimPlan( string $table, string $matching_index ): array {
		// Match Action Scheduler's default claim predicate and ordering without acquiring locks.
		$query = $this->wpdb->prepare(
			'EXPLAIN SELECT action_id FROM %i WHERE claim_id = 0 AND scheduled_date_gmt <= UTC_TIMESTAMP() AND status = %s ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC LIMIT 50',
			$table,
			'pending'
		);
		// The table identifier and status value are both prepared above; WPCS cannot trace the prepared variable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$plan = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $plan ) || '' !== (string) $this->wpdb->last_error ) {
			return array(
				'ready'   => false,
				'key'     => '',
				'rows'    => 0,
				'extra'   => '',
				'message' => 'Unable to verify the Action Scheduler claim query plan.',
			);
		}

		$key       = (string) ( $plan['key'] ?? $plan['Key'] ?? '' );
		$rows      = max( 0, (int) ( $plan['rows'] ?? $plan['Rows'] ?? 0 ) );
		$extra     = (string) ( $plan['Extra'] ?? $plan['extra'] ?? '' );
		$filesorts = str_contains( strtolower( $extra ), 'filesort' );
		$ready     = $matching_index === $key && ! $filesorts;

		return array(
			'ready'   => $ready,
			'key'     => $key,
			'rows'    => $rows,
			'extra'   => $extra,
			'message' => $ready
				? sprintf( 'Claim plan uses %s without filesort; LIMIT 50 can stop the ordered index walk.', $matching_index )
				: sprintf( 'Claim plan must use %s without filesort; optimizer chose %s across an estimated %s rows (%s).', $matching_index, $key ? $key : 'no index', number_format( $rows ), $extra ? $extra : 'no Extra detail' ),
		);
	}

	/** @return array{supported:bool,vendor:string,version:string,reason:string} */
	public static function detectOnlineDdlSupport( string $version, string $comment, string $engine ): array {
		$is_mariadb = str_contains( strtolower( $version . ' ' . $comment ), 'mariadb' );
		$vendor     = $is_mariadb ? 'MariaDB' : 'MySQL';
		if ( ! preg_match_all( '/\d+\.\d+\.\d+/', $version, $matches ) || empty( $matches[0] ) ) {
			return array(
				'supported' => false,
				'vendor'    => $vendor,
				'version'   => $version,
				'reason'    => 'Unable to establish the database server version for online DDL.',
			);
		}

		$normalized_version = $matches[0][0];
		if ( $is_mariadb && '5.5.5' === $normalized_version && isset( $matches[0][1] ) ) {
			// Older clients expose MariaDB's compatibility prefix before the real version.
			$normalized_version = $matches[0][1];
		}
		$minimum_version = $is_mariadb ? '10.0.0' : '5.6.0';
		$supported       = 'INNODB' === strtoupper( $engine ) && version_compare( $normalized_version, $minimum_version, '>=' );
		$reason          = $supported
			? sprintf( '%s %s with InnoDB supports explicit ALGORITHM=INPLACE, LOCK=NONE secondary-index creation.', $vendor, $normalized_version )
			: sprintf( 'Online DDL is not established for %s %s with table engine %s.', $vendor, $normalized_version, $engine ? $engine : 'unknown' );

		return array(
			'supported' => $supported,
			'vendor'    => $vendor,
			'version'   => $normalized_version,
			'reason'    => $reason,
		);
	}

	private function buildDdl( string $table ): string {
		$columns = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', self::REQUIRED_COLUMNS ) );
		return $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"ALTER TABLE %i ADD INDEX %i ({$columns}), ALGORITHM=INPLACE, LOCK=NONE",
			$table,
			self::INDEX_NAME
		);
	}

	/** @return array{source:string,free_bytes:int|null,required_bytes:int,sufficient:bool,message:string} */
	private function inspectDiskHeadroom( array $server, int $required_bytes, ?int $available_disk_bytes ): array {
		if ( null !== $available_disk_bytes ) {
			$sufficient = $available_disk_bytes >= $required_bytes;
			return array(
				'source'         => 'operator',
				'free_bytes'     => $available_disk_bytes,
				'required_bytes' => $required_bytes,
				'sufficient'     => $sufficient,
				'message'        => $sufficient ? 'Operator-supplied database disk headroom is sufficient.' : 'Operator-supplied database disk headroom is insufficient.',
			);
		}

		if ( ! self::isLocalDatabaseHost( $this->database_host ) ) {
			return self::unavailableDiskResult( $required_bytes, 'Database disk headroom cannot be measured for a remote DB_HOST; provide --available-disk-bytes.' );
		}

		$temp_path = (string) ( $server['innodb_tmpdir'] ?? '' );
		if ( '' === $temp_path ) {
			$temp_path = (string) ( $server['tmpdir'] ?? '' );
		}
		$paths = array_filter( array_unique( array( (string) ( $server['datadir'] ?? '' ), $temp_path ) ) );
		$free  = array();
		foreach ( $paths as $path ) {
			$bytes = ( $this->disk_free_space )( $path );
			if ( false !== $bytes ) {
				$free[] = (int) $bytes;
			}
		}

		if ( count( $free ) !== count( $paths ) || empty( $free ) ) {
			return self::unavailableDiskResult( $required_bytes, 'Database data/tmp disk headroom could not be established; provide --available-disk-bytes.' );
		}

		$available  = min( $free );
		$sufficient = $available >= $required_bytes;
		return array(
			'source'         => 'local_database_filesystems',
			'free_bytes'     => $available,
			'required_bytes' => $required_bytes,
			'sufficient'     => $sufficient,
			'message'        => $sufficient ? 'Database data/tmp disk headroom is sufficient.' : 'Database data/tmp disk headroom is insufficient.',
		);
	}

	/** @return array{source:string,free_bytes:null,required_bytes:int,sufficient:false,message:string} */
	private static function unavailableDiskResult( int $required_bytes, string $message ): array {
		return array(
			'source'         => 'unavailable',
			'free_bytes'     => null,
			'required_bytes' => $required_bytes,
			'sufficient'     => false,
			'message'        => $message,
		);
	}

	public static function isLocalDatabaseHost( string $host ): bool {
		$host = strtolower( trim( $host ) );
		return '' === $host
			|| str_starts_with( $host, '/' )
			|| 1 === preg_match( '#^(?:localhost|127\.0\.0\.1)(?::(?:\d+|/.*))?$#', $host )
			|| 1 === preg_match( '/^(?:\[::1\]|::1)(?::\d+)?$/', $host );
	}
}
