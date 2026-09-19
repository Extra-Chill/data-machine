<?php
/**
 * Durable work items for batch fan-out.
 *
 * @package DataMachine\Core\Database\BatchItems
 */

namespace DataMachine\Core\Database\BatchItems;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\TransactionScope;

defined( 'ABSPATH' ) || exit;

class BatchItems extends BaseRepository {

	public const TABLE_NAME            = 'datamachine_batch_items';
	public const STATE_READY           = 'ready';
	public const STATE_CLAIMED         = 'claimed';
	public const STATE_CANCEL_PENDING  = 'cancel_pending';
	public const STATE_COMPLETED       = 'completed';
	public const STATE_DISCARDED       = 'discarded';
	public const STATE_FAILED          = 'failed';
	public const DEFAULT_LEASE_SECONDS = 60;
	public const DEFAULT_MAX_ATTEMPTS  = 3;
	private const INSERT_CHUNK_SIZE    = 100;
	private const READ_CHUNK_SIZE      = 100;

	/** Resolve the durable per-item attempt budget. */
	public static function maxAttempts( string $context = '' ): int {
		/**
		 * Filter the maximum claim attempts for one batch item.
		 *
		 * @param int    $max     Maximum attempts, default 3.
		 * @param string $context Consumer context ('pipeline', 'task', or custom).
		 */
		return max( 1, (int) apply_filters( 'datamachine_batch_item_max_attempts', self::DEFAULT_MAX_ATTEMPTS, $context ) );
	}

	/**
	 * Persist a complete worklist in bounded statements.
	 *
	 * Item zero atomically establishes ownership. Exact retries only verify the
	 * existing worklist and never mutate or delete it.
	 *
	 * @return array{success:bool,created:bool,existing:bool,ownership_token:string}
	 */
	public function insert_batch( int $batch_job_id, array $items, array $cleanup_contexts ): array {
		$wpdb = $this->wpdb;

		if ( $batch_job_id <= 0 ) {
			return $this->insert_result( false );
		}

		$items = array_values( $items );
		$total = count( $items );
		if ( 0 === $total ) {
			return $this->insert_result( false );
		}
		$preexisting_token = $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT worklist_token FROM %i WHERE batch_job_id = %d AND item_index = 0',
				$this->table_name,
				$batch_job_id
			)
		);
		$preexisting       = is_string( $preexisting_token ) && '' !== $preexisting_token;
		$scope             = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return $this->insert_result( false, false, $preexisting || '' !== (string) $this->wpdb->last_error );
		}

		$token = bin2hex( random_bytes( 16 ) );
		$first = $this->encode_item( $items[0], $cleanup_contexts[0] ?? array() );
		if ( null === $first ) {
			$scope->rollback();
			return $this->insert_result( false, false, $preexisting );
		}

		$inserted = $this->insert_encoded_rows( $batch_job_id, array( 0 => $first ), $token );
		if ( false === $inserted ) {
			$scope->rollback();
			return $this->insert_result( false, false, true );
		}

		$owner_token = (string) $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT worklist_token FROM %i WHERE batch_job_id = %d AND item_index = 0',
				$this->table_name,
				$batch_job_id
			)
		);
		$created     = '' !== $owner_token && hash_equals( $token, $owner_token );
		if ( '' === $owner_token ) {
			$scope->rollback();
			return $this->insert_result( false, false, true );
		}

		for ( $start = 0; $start < $total; $start += self::INSERT_CHUNK_SIZE ) {
			$chunk_items = array_slice( $items, $start, self::INSERT_CHUNK_SIZE );
			$encoded     = array();
			foreach ( $chunk_items as $relative_index => $item ) {
				$index = $start + $relative_index;
				$row   = 0 === $index ? $first : $this->encode_item( $item, $cleanup_contexts[ $index ] ?? array() );
				if ( null === $row ) {
					$scope->rollback();
					return $this->insert_result( false, false, ! $created );
				}
				$encoded[ $index ] = $row;
			}

			if ( $created ) {
				$to_insert = $encoded;
				unset( $to_insert[0] );
				if ( $to_insert && false === $this->insert_encoded_rows( $batch_job_id, $to_insert, $token ) ) {
					$scope->rollback();
					return $this->insert_result( false );
				}
			}
			if ( ! $this->verify_encoded_rows( $batch_job_id, $encoded, $owner_token ) ) {
				$scope->rollback();
				return $this->insert_result( false, false, ! $created );
			}
		}

		$count = (int) $this->wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d', $this->table_name, $batch_job_id )
		);
		if ( $count !== $total ) {
			$scope->rollback();
			return $this->insert_result( false, false, ! $created );
		}
		if ( ! $scope->commit() ) {
			$scope->rollback();
			return $this->insert_result( false, false, ! $created );
		}

		return $this->insert_result( true, $created, ! $created, $created ? $token : '' );
	}

	/** Encode one work item and its cleanup context. */
	private function encode_item( mixed $item, mixed $cleanup_context ): ?array {
		$payload = wp_json_encode( $item );
		$cleanup = wp_json_encode( $cleanup_context );
		if ( false === $payload || false === $cleanup ) {
			return null;
		}
		return array(
			'payload'  => $payload,
			'checksum' => hash( 'sha256', $payload ),
			'cleanup'  => $cleanup,
		);
	}

	/** Insert at most INSERT_CHUNK_SIZE encoded rows in one typed statement. */
	private function insert_encoded_rows( int $batch_job_id, array $rows, string $token ): int|false {
		$wpdb = $this->wpdb;

		if ( empty( $rows ) || count( $rows ) > self::INSERT_CHUNK_SIZE ) {
			return false;
		}
		$now          = current_time( 'mysql', true );
		$placeholders = array();
		$args         = array( $this->table_name );
		foreach ( $rows as $index => $row ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s, %s)';
			array_push( $args, $batch_job_id, $index, $row['payload'], $row['checksum'], $row['cleanup'], self::STATE_READY, $token, $now, $now );
		}
		return $this->wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (batch_job_id, item_index, payload, payload_checksum, cleanup_context, state, worklist_token, created_at, updated_at) VALUES '
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The bounded fragment contains placeholders only; all row values are supplied to prepare().
				. implode( ', ', $placeholders )
				. ' ON DUPLICATE KEY UPDATE batch_job_id = batch_job_id',
				...$args
			)
		);
	}

	/** Verify one bounded index range without hydrating payload LONGTEXT. */
	private function verify_encoded_rows( int $batch_job_id, array $expected, string $token ): bool {
		$wpdb         = $this->wpdb;
		$indexes      = array_keys( $expected );
		$placeholders = implode( ', ', array_fill( 0, count( $indexes ), '%d' ) );
		$sql          = "SELECT item_index, payload_checksum, cleanup_context, worklist_token FROM %i WHERE batch_job_id = %d AND item_index IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains generated %d placeholders only; every identifier and value is supplied here.
		$rows = $this->wpdb->get_results( $wpdb->prepare( $sql, $this->table_name, $batch_job_id, ...$indexes ), ARRAY_A );
		if ( count( (array) $rows ) !== count( $expected ) ) {
			return false;
		}
		foreach ( (array) $rows as $row ) {
			$index = (int) $row['item_index'];
			if ( ! isset( $expected[ $index ] )
				|| ! hash_equals( $expected[ $index ]['checksum'], (string) $row['payload_checksum'] )
				|| ! hash_equals( $expected[ $index ]['cleanup'], (string) $row['cleanup_context'] )
				|| ! hash_equals( $token, (string) $row['worklist_token'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array{success:bool,created:bool,existing:bool,ownership_token:string} */
	private function insert_result( bool $success, bool $created = false, bool $existing = false, string $token = '' ): array {
		return array(
			'success'         => $success,
			'created'         => $created,
			'existing'        => $existing,
			'ownership_token' => $token,
		);
	}

	/**
	 * Atomically claim ready or expired rows in one chunk boundary.
	 *
	 * The optional owner callback runs after rows are locked and updated but
	 * before commit. A false result rolls the claim back, allowing callers to
	 * establish an external recovery path before the lease becomes durable.
	 */
	public function claim_chunk( int $batch_job_id, int $offset, int $limit, int $lease_seconds = self::DEFAULT_LEASE_SECONDS, ?callable $owner = null ): array {
		$wpdb = $this->wpdb;

		$scope = TransactionScope::begin( $this->wpdb );
		if ( $batch_job_id <= 0 || $limit < 1 || null === $scope ) {
			return array();
		}

		$end  = $offset + $limit;
		$now  = current_time( 'mysql', true );
		$rows = $this->wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE batch_job_id = %d AND item_index >= %d AND item_index < %d ORDER BY item_index ASC FOR UPDATE',
				$this->table_name,
				$batch_job_id,
				$offset,
				$end
			),
			ARRAY_A
		);

		$claimed = array();
		foreach ( (array) $rows as $row ) {
			$available = self::STATE_READY === $row['state']
				|| ( self::STATE_CLAIMED === $row['state'] && ! empty( $row['lease_expires_at'] ) && $row['lease_expires_at'] <= $now );
			if ( ! $available ) {
				continue;
			}

			$token      = bin2hex( random_bytes( 16 ) );
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $lease_seconds ) );
			$attempts   = (int) ( $row['attempts'] ?? 0 ) + 1;
			$updated    = $this->wpdb->update(
				$this->table_name,
				array(
					'state'            => self::STATE_CLAIMED,
					'lease_token'      => $token,
					'lease_expires_at' => $expires_at,
					'attempts'         => $attempts,
					'updated_at'       => $now,
				),
				array(
					'batch_job_id' => $batch_job_id,
					'item_index'   => (int) $row['item_index'],
				),
				array( '%s', '%s', '%s', '%d', '%s' ),
				array( '%d', '%d' )
			);
			if ( 1 !== $updated ) {
				$scope->rollback();
				return array();
			}

			$row['lease_token']      = $token;
			$row['lease_expires_at'] = $expires_at;
			$row['attempts']         = $attempts;
			$claimed[]               = $this->decode_row( $row );
		}

		if ( $claimed && null !== $owner && ! $owner() ) {
			$scope->rollback();
			return array();
		}

		if ( ! $scope->commit() ) {
			$scope->rollback();
			return array();
		}
		return $claimed;
	}

	/** Complete an item only while the caller still owns its lease. */
	public function complete( int $batch_job_id, int $item_index, string $token, int|string|null $result_id = null ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'            => self::STATE_COMPLETED,
				'lease_token'      => null,
				'lease_expires_at' => null,
				'child_result_id'  => null === $result_id ? null : (string) $result_id,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CLAIMED,
				'lease_token'  => $token,
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Preserve successful in-flight work that crossed a cancellation boundary. */
	public function complete_cancel_pending( int $batch_job_id, int $item_index, string $token, int|string|null $result_id = null ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'           => self::STATE_COMPLETED,
				'child_result_id' => null === $result_id ? null : (string) $result_id,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CANCEL_PENDING,
				'lease_token'  => $token,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Discard cancellation-fenced work only when the worker proves no callback ran. */
	public function discard_cancel_pending( int $batch_job_id, int $item_index, string $token ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'      => self::STATE_DISCARDED,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CANCEL_PENDING,
				'lease_token'  => $token,
			),
			array( '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Release an owned item for partial retry. */
	public function release( int $batch_job_id, int $item_index, string $token ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'            => self::STATE_READY,
				'lease_token'      => null,
				'lease_expires_at' => null,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CLAIMED,
				'lease_token'  => $token,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Terminally fail an owned claim after the attempt budget is exhausted. */
	public function fail_claim( int $batch_job_id, int $item_index, string $token ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'            => self::STATE_FAILED,
				'lease_token'      => null,
				'lease_expires_at' => null,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CLAIMED,
				'lease_token'  => $token,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Discard an item only while the caller still owns its lease. */
	public function discard_claim( int $batch_job_id, int $item_index, string $token ): bool {
		return 1 === $this->wpdb->update(
			$this->table_name,
			array(
				'state'            => self::STATE_DISCARDED,
				'lease_token'      => null,
				'lease_expires_at' => null,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'batch_job_id' => $batch_job_id,
				'item_index'   => $item_index,
				'state'        => self::STATE_CLAIMED,
				'lease_token'  => $token,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/** Mark all non-terminal rows discarded and return their cleanup payloads. */
	public function discard_outstanding( int $batch_job_id ): array {
		$wpdb = $this->wpdb;

		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return array(
				'success' => false,
				'rows'    => array(),
			);
		}
		$rows    = $this->wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_index, payload, payload_checksum, cleanup_context, state FROM %i WHERE batch_job_id = %d AND state IN (%s, %s, %s) ORDER BY item_index ASC LIMIT %d FOR UPDATE',
				$this->table_name,
				$batch_job_id,
				self::STATE_READY,
				self::STATE_CLAIMED,
				self::STATE_CANCEL_PENDING,
				self::READ_CHUNK_SIZE
			),
			ARRAY_A
		);
		$indexes = array_map( static fn( array $row ): int => (int) $row['item_index'], (array) $rows );
		$updated = $this->discard_indexes( $batch_job_id, $indexes );
		if ( false === $updated || ! $scope->commit() ) {
			$scope->rollback();
			return array(
				'success' => false,
				'rows'    => array(),
			);
		}

		return array(
			'success'   => true,
			'rows'      => array_map( array( $this, 'decode_row' ), (array) $rows ),
			'remaining' => null !== $this->first_outstanding_index( $batch_job_id ),
		);
	}

	/**
	 * Fence cancellation without releasing cleanup owned by an active callback.
	 *
	 * Ready rows become terminal immediately. Claimed rows become
	 * cancel_pending so the active worker or its recovery action performs the
	 * cleanup after observing the parent cancellation flag.
	 */
	public function request_cancellation( int $batch_job_id ): array {
		$wpdb = $this->wpdb;

		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return array(
				'success'   => false,
				'rows'      => array(),
				'remaining' => false,
			);
		}
		$rows            = $this->wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_index, payload, payload_checksum, cleanup_context, state FROM %i WHERE batch_job_id = %d AND state IN (%s, %s) ORDER BY item_index ASC LIMIT %d FOR UPDATE',
				$this->table_name,
				$batch_job_id,
				self::STATE_READY,
				self::STATE_CLAIMED,
				self::READ_CHUNK_SIZE
			),
			ARRAY_A
		);
		$ready_indexes   = array();
		$claimed_indexes = array();
		foreach ( (array) $rows as $row ) {
			if ( self::STATE_READY === (string) $row['state'] ) {
				$ready_indexes[] = (int) $row['item_index'];
			} else {
				$claimed_indexes[] = (int) $row['item_index'];
			}
		}

		$ready_updated = $this->discard_indexes( $batch_job_id, $ready_indexes );
		$claim_updated = $this->transition_claims_to_cancel_pending( $batch_job_id, $claimed_indexes );
		if ( false === $ready_updated || false === $claim_updated || ! $scope->commit() ) {
			$scope->rollback();
			return array(
				'success'   => false,
				'rows'      => array(),
				'remaining' => false,
			);
		}

		$ready_lookup = array_fill_keys( $ready_indexes, true );
		$ready_rows   = array_values(
			array_filter(
				(array) $rows,
				static fn( array $row ): bool => isset( $ready_lookup[ (int) $row['item_index'] ] )
			)
		);
		$remaining    = (int) $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d AND state IN (%s, %s)',
				$this->table_name,
				$batch_job_id,
				self::STATE_READY,
				self::STATE_CLAIMED
			)
		) > 0;

		return array(
			'success'   => true,
			'rows'      => array_map( array( $this, 'decode_row' ), $ready_rows ),
			'remaining' => $remaining,
		);
	}

	/** Fence callbacks by state while preserving the current lease generation. */
	private function transition_claims_to_cancel_pending( int $batch_job_id, array $indexes ): int|false {
		$wpdb = $this->wpdb;

		if ( empty( $indexes ) ) {
			return 0;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $indexes ), '%d' ) );
		$sql          = "UPDATE %i SET state = %s, updated_at = %s WHERE batch_job_id = %d AND state = %s AND item_index IN ({$placeholders})";
		return $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains generated %d placeholders only; every identifier and value is supplied here.
			$wpdb->prepare( $sql, $this->table_name, self::STATE_CANCEL_PENDING, current_time( 'mysql', true ), $batch_job_id, self::STATE_CLAIMED, ...$indexes )
		);
	}

	/** Discard only rows created by one insertion owner. */
	public function discard_owned( int $batch_job_id, string $token ): array {
		$wpdb = $this->wpdb;

		$scope = TransactionScope::begin( $this->wpdb );
		if ( '' === $token || null === $scope ) {
			return array(
				'success' => false,
				'rows'    => array(),
			);
		}
		$rows    = $this->wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_index, payload, payload_checksum, cleanup_context FROM %i WHERE batch_job_id = %d AND worklist_token = %s AND state <> %s ORDER BY item_index ASC LIMIT %d FOR UPDATE',
				$this->table_name,
				$batch_job_id,
				$token,
				self::STATE_DISCARDED,
				self::READ_CHUNK_SIZE
			),
			ARRAY_A
		);
		$indexes = array_map( static fn( array $row ): int => (int) $row['item_index'], (array) $rows );
		$updated = $this->discard_indexes( $batch_job_id, $indexes );
		if ( false === $updated || ! $scope->commit() ) {
			$scope->rollback();
			return array(
				'success' => false,
				'rows'    => array(),
			);
		}
		$remaining = (int) $this->wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d AND worklist_token = %s AND state <> %s', $this->table_name, $batch_job_id, $token, self::STATE_DISCARDED )
		) > 0;
		return array(
			'success'   => true,
			'rows'      => array_map( array( $this, 'decode_row' ), (array) $rows ),
			'remaining' => $remaining,
		);
	}

	/** Mark one bounded, locked index set discarded. */
	private function discard_indexes( int $batch_job_id, array $indexes ): int|false {
		return $this->transition_indexes( $batch_job_id, $indexes, self::STATE_DISCARDED );
	}

	/** Transition one bounded, locked index set and clear lease ownership. */
	private function transition_indexes( int $batch_job_id, array $indexes, string $state ): int|false {
		$wpdb = $this->wpdb;

		if ( empty( $indexes ) ) {
			return 0;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $indexes ), '%d' ) );
		$sql          = "UPDATE %i SET state = %s, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE batch_job_id = %d AND item_index IN ({$placeholders})";
		return $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains generated %d placeholders only; every identifier and value is supplied here.
			$wpdb->prepare( $sql, $this->table_name, $state, current_time( 'mysql', true ), $batch_job_id, ...$indexes )
		);
	}

	/** Discard rows that have not yet been handed to a worker. */
	public function first_outstanding_index( int $batch_job_id ): ?int {
		$wpdb = $this->wpdb;

		$value = $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(item_index) FROM %i WHERE batch_job_id = %d AND state IN (%s, %s, %s)',
				$this->table_name,
				$batch_job_id,
				self::STATE_READY,
				self::STATE_CLAIMED,
				self::STATE_CANCEL_PENDING
			)
		);
		return null === $value ? null : (int) $value;
	}

	public function count_completed( int $batch_job_id ): int {
		$wpdb = $this->wpdb;

		return (int) $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d AND state = %s',
				$this->table_name,
				$batch_job_id,
				self::STATE_COMPLETED
			)
		);
	}

	public function count_failed( int $batch_job_id ): int {
		$wpdb = $this->wpdb;

		return (int) $this->wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE batch_job_id = %d AND state = %s',
				$this->table_name,
				$batch_job_id,
				self::STATE_FAILED
			)
		);
	}

	/** Delete only rows created by the supplied insertion owner. */
	public function delete_owned_batch( int $batch_job_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		return false !== $this->wpdb->delete(
			$this->table_name,
			array(
				'batch_job_id'   => $batch_job_id,
				'worklist_token' => $token,
			),
			array( '%d', '%s' )
		);
	}

	public function delete_batch( int $batch_job_id ): bool {
		return false !== $this->wpdb->delete( $this->table_name, array( 'batch_job_id' => $batch_job_id ), array( '%d' ) );
	}

	/** Decode persisted JSON without allowing corrupt payloads into callbacks. */
	private function decode_row( array $row ): array {
		$raw_payload            = (string) ( $row['payload'] ?? '' );
		$payload                = json_decode( $raw_payload, true );
		$valid                  = JSON_ERROR_NONE === json_last_error()
			&& is_array( $payload )
			&& ! empty( $row['payload_checksum'] )
			&& hash_equals( (string) $row['payload_checksum'], hash( 'sha256', $raw_payload ) );
		$cleanup                = json_decode( (string) ( $row['cleanup_context'] ?? '' ), true );
		$row['payload_valid']   = $valid;
		$row['payload']         = $valid ? $payload : array();
		$row['cleanup_context'] = is_array( $cleanup ) ? $cleanup : array();
		$row['attempts']        = (int) ( $row['attempts'] ?? 0 );
		return $row;
	}

	public static function create_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			batch_job_id BIGINT(20) UNSIGNED NOT NULL,
			item_index BIGINT(20) UNSIGNED NOT NULL,
			payload LONGTEXT NOT NULL,
			payload_checksum CHAR(64) NOT NULL,
			cleanup_context LONGTEXT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'ready',
			worklist_token VARCHAR(64) NOT NULL DEFAULT '',
			lease_token VARCHAR(64) NULL,
			lease_expires_at DATETIME NULL,
			child_result_id VARCHAR(191) NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (batch_job_id, item_index),
			KEY claimable (batch_job_id, state, lease_expires_at, item_index)
		) ENGINE=InnoDB {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::ensure_attempts_column( $table_name );
	}

	/** Add the attempts column on existing installs where dbDelta leaves it missing. */
	public static function ensure_attempts_column( string $table_name = '' ): void {
		global $wpdb;

		if ( '' === $table_name ) {
			$table_name = $wpdb->prefix . self::TABLE_NAME;
		}
		if ( self::column_exists( $table_name, 'attempts', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Deploy-time schema convergence for existing batch-item tables.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0', $table_name ) );
	}
}
