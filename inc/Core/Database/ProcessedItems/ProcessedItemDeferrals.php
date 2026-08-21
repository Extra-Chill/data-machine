<?php
/** Durable bounded-deferral behavior for the processed-item ledger. */

namespace DataMachine\Core\Database\ProcessedItems;

use DataMachine\Core\Database\TransactionScope;

defined( 'ABSPATH' ) || exit;

trait ProcessedItemDeferrals {
	/** Persist a job-idempotent paid deferral attempt for the exact owned claim. */
	public function record_owned_deferral_attempt( array $claim, int $job_id ): array|false {
		if ( $job_id <= 0 ) {
			return false;
		}
		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return false;
		}
		$query = $this->wpdb->prepare(
			'SELECT deferral_count, last_deferral_job_id FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact token-owned row lock.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			$scope->rollback();
			return false;
		}
		$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) ( $row['deferral_count'] ?? 0 ) ) );
		if ( (int) ( $row['last_deferral_job_id'] ?? 0 ) !== $job_id ) {
			$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, $attempts + 1 );
			$updated = $this->wpdb->update(
				$this->table_name,
				array(
					'deferral_count'       => $attempts,
					'last_deferral_job_id' => $job_id,
				),
				array(
					'flow_step_id'    => $claim['identity_scope'],
					'source_type'     => $claim['source_type'],
					'item_identifier' => $claim['item_identifier'],
					'claim_token'     => $claim['ownership_token'],
					'status'          => self::STATUS_CLAIMED,
				),
				array( '%d', '%d' ),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
			if ( 1 !== $updated ) {
				$scope->rollback();
				return false;
			}
		}
		if ( ! $scope->commit() ) {
			$scope->rollback();
			return false;
		}
		return array(
			'attempts'  => $attempts,
			'exhausted' => $attempts >= self::MAX_DEFERRAL_ATTEMPTS,
		);
	}

	/** Finalize the paid attempt while the caller owns the surrounding transaction. */
	public function finalize_owned_deferral_in_transaction( array $claim, int $job_id, ?callable $completion = null ): array|false {
		$query = $this->wpdb->prepare(
			'SELECT deferral_count, last_deferral_job_id FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Caller owns the transaction.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$attempts  = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) $row['deferral_count'] ) );
		$exhausted = $attempts >= self::MAX_DEFERRAL_ATTEMPTS;
		if ( ! $exhausted && (int) ( $row['last_deferral_job_id'] ?? 0 ) !== $job_id ) {
			return false;
		}
		if ( $exhausted && null !== $completion ) {
			try {
				if ( true !== $completion() ) {
					return false;
				}
			} catch ( \Throwable $exception ) {
				do_action( 'datamachine_log', 'error', 'Deferred item completion callback failed.', array( 'exception' => $exception->getMessage() ) );
				return false;
			}
		}
		$now     = current_time( 'mysql', true );
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'job_id'              => $job_id,
				'status'              => $exhausted ? self::STATUS_PROCESSED : self::STATUS_DEFERRED,
				'processed_timestamp' => $now,
				'deferred_at'         => $now,
				'claim_expires_at'    => null,
				'claim_token'         => null,
			),
			array(
				'flow_step_id'    => $claim['identity_scope'],
				'source_type'     => $claim['source_type'],
				'item_identifier' => $claim['item_identifier'],
				'claim_token'     => $claim['ownership_token'],
				'status'          => self::STATUS_CLAIMED,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return 1 === $updated
			? array(
				'attempts'  => $attempts,
				'exhausted' => $exhausted,
			)
			: false;
	}

	/** Read bounded deferral state for an exact claim already locked by the caller. */
	public function owned_deferral_state_in_transaction( array $claim ): array|false {
		$query = $this->wpdb->prepare(
			'SELECT deferral_count FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s',
			$this->table_name,
			(string) ( $claim['identity_scope'] ?? '' ),
			(string) ( $claim['source_type'] ?? '' ),
			(string) ( $claim['item_identifier'] ?? '' ),
			(string) ( $claim['ownership_token'] ?? '' ),
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Caller already locked the exact token-owned row.
		$row = $this->wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$attempts = min( self::MAX_DEFERRAL_ATTEMPTS, max( 0, (int) ( $row['deferral_count'] ?? 0 ) ) );
		return array(
			'attempts'  => $attempts,
			'exhausted' => $attempts >= self::MAX_DEFERRAL_ATTEMPTS,
		);
	}

	/** Return one bounded page of stale deferred identities. */
	public function find_stale_deferrals( int $max_age_hours, int $limit = 100, int $after_id = 0 ): array {
		if ( $max_age_hours < 1 || $limit < 1 || $after_id < 0 ) {
			return array(
				'items'         => array(),
				'has_more'      => false,
				'next_after_id' => 0,
			);
		}
		$limit       = min( 500, $limit );
		$query_limit = $limit + 1;
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_hours * HOUR_IN_SECONDS ) );
		$query       = $this->wpdb->prepare(
			'SELECT id, flow_step_id, source_type, item_identifier, job_id, deferral_count, deferred_at, last_seen_at FROM %i WHERE status = %s AND deferred_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
			$this->table_name,
			self::STATUS_DEFERRED,
			$cutoff,
			$after_id,
			$query_limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded operational ledger read.
		$items    = (array) $this->wpdb->get_results( $query, ARRAY_A );
		$has_more = count( $items ) > $limit;
		if ( $has_more ) {
			array_pop( $items );
		}
		$last_item = ! empty( $items ) ? end( $items ) : array();
		return array(
			'items'         => $items,
			'has_more'      => $has_more,
			'next_after_id' => $has_more ? (int) ( $last_item['id'] ?? 0 ) : 0,
		);
	}

	/** Ensure durable deferral columns and the operational index exist. */
	public static function ensure_deferral_schema( string $table_name ): void {
		global $wpdb;
		foreach ( self::deferral_column_definitions() as $column => $definition ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema inspection.
			$actual = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $table_name, $column ), ARRAY_A );
			if ( self::valid_deferral_column( $column, $actual ) ) {
				continue;
			}
			$operation = is_array( $actual ) ? 'MODIFY COLUMN' : 'ADD COLUMN';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column names and definitions come from the fixed map above.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed migration definition and prepared table identifier.
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i {$operation} `{$column}` {$definition}", $table_name ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! self::validate_deferral_index( $table_name ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema inspection.
			$index = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'status_deferred_at' ) );
			if ( $index ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Malformed same-name index must be replaced.
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX `status_deferred_at`', $table_name ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Required operational index.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY `status_deferred_at` (status, deferred_at)', $table_name ) );
		}
	}

	/** Verify the complete durable-deferral schema before migration completion. */
	public static function validate_deferral_schema( string $table_name ): bool {
		global $wpdb;
		foreach ( self::deferral_column_definitions() as $column => $definition ) {
			unset( $definition );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema validation.
			$actual = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $table_name, $column ), ARRAY_A );
			if ( ! self::valid_deferral_column( $column, $actual ) ) {
				return false;
			}
		}
		return self::validate_deferral_index( $table_name );
	}

	/** Canonical SQL definitions for durable deferral columns. */
	private static function deferral_column_definitions(): array {
		return array(
			'deferral_count'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
			'last_deferral_job_id' => 'BIGINT(20) UNSIGNED NULL',
			'deferred_at'          => 'DATETIME NULL',
			'last_seen_at'         => 'DATETIME NULL',
		);
	}

	/** Validate one column's semantic type, nullability, and default. */
	private static function valid_deferral_column( string $column, mixed $actual ): bool {
		if ( ! is_array( $actual ) || ( $actual['Field'] ?? null ) !== $column ) {
			return false;
		}
		$type  = strtolower( (string) ( $actual['Type'] ?? '' ) );
		$rules = array(
			'deferral_count'       => array( '/^int(?:\(\d+\))? unsigned$/', 'NO', '0' ),
			'last_deferral_job_id' => array( '/^bigint(?:\(\d+\))? unsigned$/', 'YES', null ),
			'deferred_at'          => array( '/^datetime$/', 'YES', null ),
			'last_seen_at'         => array( '/^datetime$/', 'YES', null ),
		);
		if ( ! isset( $rules[ $column ] ) ) {
			return false;
		}
		[ $type_pattern, $nullable, $default ] = $rules[ $column ];
		return 1 === preg_match( $type_pattern, $type )
			&& ( $actual['Null'] ?? null ) === $nullable
			&& ( $actual['Default'] ?? null ) === $default;
	}

	/** Verify the exact non-unique, unprefixed BTREE operational index. */
	private static function validate_deferral_index( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Schema validation.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s ORDER BY Seq_in_index ASC', $table_name, 'status_deferred_at' ), ARRAY_A );
		if ( 2 !== count( (array) $rows ) || array( 'status', 'deferred_at' ) !== array_column( $rows, 'Column_name' ) ) {
			return false;
		}
		foreach ( $rows as $offset => $row ) {
			if ( 1 !== (int) ( $row['Non_unique'] ?? 0 )
				|| (int) ( $row['Seq_in_index'] ?? 0 ) !== $offset + 1
				|| 'BTREE' !== strtoupper( (string) ( $row['Index_type'] ?? '' ) )
				|| null !== ( $row['Sub_part'] ?? null ) ) {
				return false;
			}
		}
		return true;
	}
}
