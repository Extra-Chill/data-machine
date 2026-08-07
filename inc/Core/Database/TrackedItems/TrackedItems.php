<?php
/**
 * Durable tracked item ledger for source/entity coverage state.
 *
 * @package DataMachine\Core\Database\TrackedItems
 */

namespace DataMachine\Core\Database\TrackedItems;

use DataMachine\Core\Database\BaseRepository;
defined( 'ABSPATH' ) || exit;

class TrackedItems extends BaseRepository {

	public const TABLE_NAME = 'datamachine_tracked_items';

	public const STATE_DISCOVERED = 'discovered';
	public const STATE_QUEUED     = 'queued';
	public const STATE_GENERATED  = 'generated';
	public const STATE_REVIEWED   = 'reviewed';
	public const STATE_EXCLUDED   = 'excluded';
	public const STATE_STALE      = 'stale';
	public const STATE_FAILED     = 'failed';

	/**
	 * Upsert a tracked item by namespace and stable item ID.
	 *
	 * @param array<string,mixed> $item Item payload.
	 * @return array<string,mixed>|null Stored row, or null on failure.
	 */
	public function upsert( array $item ): ?array {
		$normalized = $this->normalize_item( $item );
		if ( '' === $normalized['namespace'] || '' === $normalized['item_id'] ) {
			return null;
		}

		$existing = $this->get( $normalized['namespace'], $normalized['item_id'] );
		$now      = current_time( 'mysql', true );
		$row      = array(
			'namespace'       => $normalized['namespace'],
			'item_id'         => $normalized['item_id'],
			'item_type'       => $normalized['item_type'],
			'state'           => $normalized['state'],
			'source_ref'      => $normalized['source_ref'],
			'source_revision' => $normalized['source_revision'],
			'source_path'     => $normalized['source_path'],
			'source_line'     => $normalized['source_line'],
			'output_ref'      => $normalized['output_ref'],
			'metadata_json'   => wp_json_encode( $normalized['metadata'], JSON_UNESCAPED_SLASHES ),
			'last_seen_at'    => '' !== $normalized['last_seen_at'] ? $normalized['last_seen_at'] : $now,
			'last_job_id'     => $normalized['last_job_id'],
			'updated_at'      => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->update(
				$this->table_name,
				$row,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);
			$stored = array_merge( $existing, $row );
		} else {
			$row['first_seen_at'] = '' !== $normalized['first_seen_at'] ? $normalized['first_seen_at'] : $now;
			$formats[]            = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->insert( $this->table_name, $row, $formats );
			$stored = array_merge( array( 'id' => (int) $this->wpdb->insert_id ), $row );
		}

		if ( false === $result ) {
			$this->log_db_error(
				'TrackedItems::upsert',
				array(
					'namespace' => $normalized['namespace'],
					'item_id'   => $normalized['item_id'],
				)
			);
			return null;
		}

		return self::normalize_row( $stored );
	}

	/**
	 * Register tracked-item completion with the generic claim lifecycle.
	 *
	 * @param array<string,callable> $handlers Registered completion handlers.
	 * @return array<string,callable> Registered completion handlers.
	 */
	public static function registerClaimCompletionHandler( array $handlers ): array {
		$handlers['tracked_item'] = array( self::class, 'completeClaim' );
		return $handlers;
	}

	/**
	 * Persist a tracked item inside the owning claim transaction.
	 *
	 * @param array<string,mixed> $payload Completion payload.
	 * @param int                 $job_id Completing job ID.
	 * @return bool Whether the tracked item was persisted.
	 */
	public static function completeClaim( array $payload, int $job_id ): bool {
		$item = is_array( $payload['item'] ?? null ) ? $payload['item'] : array();
		if ( empty( $item ) ) {
			return false;
		}

		$item['last_job_id'] = $job_id;
		return null !== ( new self() )->upsert( $item );
	}

	/**
	 * Get one tracked item.
	 */
	public function get( string $item_namespace, string $item_id ): ?array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE namespace = %s AND item_id = %s LIMIT 1',
				$this->table_name,
				$item_namespace,
				$item_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	/**
	 * List tracked items by optional filters.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function list( array $filters = array() ): array {
		$parts  = $this->where_parts( $filters );
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM %i {$parts['where']} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$args   = array_merge( array( $this->table_name ), $parts['values'], array( $limit, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_values( array_map( array( self::class, 'normalize_row' ), is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Summarize tracked items by type and state.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @return array<string,mixed>
	 */
	public function summary( array $filters = array() ): array {
		$parts = $this->where_parts( $filters );
		$sql   = "SELECT item_type, state, COUNT(*) AS total FROM %i {$parts['where']} GROUP BY item_type, state ORDER BY item_type ASC, state ASC";
		$args  = array_merge( array( $this->table_name ), $parts['values'] );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$total    = 0;
		$by_type  = array();
		$by_state = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$item_type = (string) ( $row['item_type'] ?? '' );
			$state     = (string) ( $row['state'] ?? '' );
			$count     = (int) ( $row['total'] ?? 0 );
			$total    += $count;

			$by_type[ $item_type ][ $state ] = $count;
			$by_state[ $state ]              = ( $by_state[ $state ] ?? 0 ) + $count;
		}

		return array(
			'total'    => $total,
			'by_type'  => $by_type,
			'by_state' => $by_state,
		);
	}

	/**
	 * Create or update the tracked items table.
	 */
	public function create_table(): void {
		if ( self::is_sqlite() && self::database_table_exists( $this->table_name, $this->wpdb ) ) {
			return;
		}

		$charset_collate = $this->wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			namespace VARCHAR(191) NOT NULL,
			item_id VARCHAR(191) NOT NULL,
			item_type VARCHAR(100) NOT NULL DEFAULT '',
			state VARCHAR(40) NOT NULL DEFAULT 'discovered',
			source_ref VARCHAR(255) NOT NULL DEFAULT '',
			source_revision VARCHAR(100) NOT NULL DEFAULT '',
			source_path VARCHAR(255) NOT NULL DEFAULT '',
			source_line BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			output_ref VARCHAR(255) NOT NULL DEFAULT '',
			metadata_json LONGTEXT NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			last_job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY namespace_item (namespace, item_id),
			KEY namespace_type_state (namespace, item_type, state),
			KEY namespace_state (namespace, state),
			KEY source_ref (source_ref(191)),
			KEY updated_at (updated_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param array<string,mixed> $filters Query filters.
	 * @return array{where:string,values:array<int,mixed>}
	 */
	private function where_parts( array $filters ): array {
		$where  = array( '1=1' );
		$values = array();
		foreach ( array( 'namespace', 'item_type', 'state', 'source_ref', 'output_ref' ) as $key ) {
			$value = trim( (string) ( $filters[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$where[]  = "{$key} = %s";
			$values[] = $value;
		}

		return array(
			'where'  => 'WHERE ' . implode( ' AND ', $where ),
			'values' => $values,
		);
	}

	/**
	 * @param array<string,mixed> $item Raw item.
	 * @return array<string,mixed>
	 */
	private function normalize_item( array $item ): array {
		return array(
			'namespace'       => self::clean_token( (string) ( $item['namespace'] ?? $item['scope'] ?? '' ), 191 ),
			'item_id'         => self::clean_string( (string) ( $item['item_id'] ?? '' ), 191 ),
			'item_type'       => self::clean_token( (string) ( $item['item_type'] ?? '' ), 100 ),
			'state'           => self::normalize_state( (string) ( $item['state'] ?? self::STATE_DISCOVERED ) ),
			'source_ref'      => self::clean_string( (string) ( $item['source_ref'] ?? '' ), 255 ),
			'source_revision' => self::clean_string( (string) ( $item['source_revision'] ?? '' ), 100 ),
			'source_path'     => self::clean_string( (string) ( $item['source_path'] ?? '' ), 255 ),
			'source_line'     => max( 0, (int) ( $item['source_line'] ?? 0 ) ),
			'output_ref'      => self::clean_string( (string) ( $item['output_ref'] ?? '' ), 255 ),
			'metadata'        => is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array(),
			'first_seen_at'   => self::clean_datetime( (string) ( $item['first_seen_at'] ?? '' ) ),
			'last_seen_at'    => self::clean_datetime( (string) ( $item['last_seen_at'] ?? '' ) ),
			'last_job_id'     => max( 0, (int) ( $item['last_job_id'] ?? 0 ) ),
		);
	}

	private static function normalize_state( string $state ): string {
		$state = self::clean_token( $state, 40 );
		return in_array( $state, self::states(), true ) ? $state : self::STATE_DISCOVERED;
	}

	/**
	 * @return string[]
	 */
	public static function states(): array {
		return array(
			self::STATE_DISCOVERED,
			self::STATE_QUEUED,
			self::STATE_GENERATED,
			self::STATE_REVIEWED,
			self::STATE_EXCLUDED,
			self::STATE_STALE,
			self::STATE_FAILED,
		);
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private static function normalize_row( array $row ): array {
		$metadata        = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
		$row['metadata'] = is_array( $metadata ) ? $metadata : array();
		unset( $row['metadata_json'] );

		foreach ( array( 'id', 'source_line', 'last_job_id' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		return $row;
	}

	private static function clean_token( string $value, int $length ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_:\/\.-]+/', '-', $value );
		return substr( trim( (string) $value, '-' ), 0, $length );
	}

	private static function clean_string( string $value, int $length ): string {
		return substr( trim( wp_strip_all_tags( $value ) ), 0, $length );
	}

	private static function clean_datetime( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
