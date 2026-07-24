<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns the datamachine_processed_items custom table; duplicate/claim checks require fresh state and schema methods perform one-time table maintenance.
/**
 * ProcessedItems database service - prevents duplicate processing at flow step level.
 *
 * Simple, focused service that tracks processed items by flow_step_id to prevent
 * duplicate processing. Core responsibility: duplicate prevention only.
 *
 * @package    Data_Machine
 * @subpackage Core\Database\ProcessedItems
 * @since      0.16.0
 */

namespace DataMachine\Core\Database\ProcessedItems;

use DataMachine\Core\Database\BaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class ProcessedItems extends BaseRepository {

	const TABLE_NAME                = 'datamachine_processed_items';
	const STATUS_CLAIMED            = 'claimed';
	const STATUS_PROCESSED          = 'processed';
	const DEFAULT_CLAIM_TTL_SECONDS = 3600;
	const CLAIM_METADATA_KEY        = '_datamachine_item_claim';
	const CLAIMS_METADATA_KEY       = '_datamachine_item_claims';
	private const READ_CHUNK_SIZE   = 500;


	/**
	 * Checks if a specific item has already been processed for a given flow step and source type.
	 *
	 * @param string $flow_step_id   The ID of the flow step (composite: pipeline_step_id_flow_id).
	 * @param string $source_type    The type of the data source (e.g., 'rss', 'reddit').
	 * @param string $item_identifier The unique identifier for the item (e.g., GUID, post ID).
	 * @return bool True if the item has been processed, false otherwise.
	 */
	public function has_item_been_processed( string $flow_step_id, string $source_type, string $item_identifier ): bool {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND status = %s', $this->table_name, $flow_step_id, $source_type, $item_identifier, self::STATUS_PROCESSED ) );

		return $count > 0;
	}

	/**
	 * Checks if a source item is actively claimed by an in-flight job.
	 *
	 * Expired claims are ignored and cleaned before checking.
	 *
	 * @param string $flow_step_id    Flow step ID.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @return bool True when an active claim exists.
	 */
	public function has_active_claim( string $flow_step_id, string $source_type, string $item_identifier ): bool {
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND status = %s AND (claim_expires_at IS NULL OR claim_expires_at > %s)',
				$this->table_name,
				$flow_step_id,
				$source_type,
				$item_identifier,
				self::STATUS_CLAIMED,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $count > 0;
	}

	/**
	 * Read persisted lifecycle state for a bounded set of source identifiers.
	 *
	 * Missing identifiers are omitted. Expired claims are returned as inactive
	 * state and are never deleted by this observational read.
	 *
	 * @param string   $flow_step_id    Flow step ID.
	 * @param string   $source_type     Source type.
	 * @param string[] $item_identifiers Candidate identifiers.
	 * @return array<string,array{processed:bool,actively_claimed:bool}>
	 */
	public function get_item_lifecycle_states( string $flow_step_id, string $source_type, array $item_identifiers ): array {
		$identifiers = array_values( array_unique( array_map( 'strval', $item_identifiers ) ) );
		if ( empty( $identifiers ) ) {
			return array();
		}

		$now    = current_time( 'mysql', true );
		$states = array();

		foreach ( array_chunk( $identifiers, self::READ_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = sprintf(
				'SELECT item_identifier, status, claim_expires_at FROM %%i WHERE flow_step_id = %%s AND source_type = %%s AND item_identifier IN (%s)',
				$placeholders
			);
			/** @var literal-string $sql */
			$prepare_args = array_merge( array( $this->table_name, $flow_step_id, $source_type ), $chunk );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN() list is bounded and every value uses a placeholder.
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			foreach ( (array) $rows as $row ) {
				$identifier = (string) $row['item_identifier'];
				$status     = (string) $row['status'];
				$expires_at = $row['claim_expires_at'] ?? null;

				$states[ $identifier ] = array(
					'processed'        => self::STATUS_PROCESSED === $status,
					'actively_claimed' => self::STATUS_CLAIMED === $status
						&& ( empty( $expires_at ) || $expires_at > $now ),
				);
			}
		}

		return $states;
	}

	/**
	 * Checks if a flow step has any processed items history.
	 *
	 * Used to determine if a flow has ever successfully processed items,
	 * which helps distinguish "no new items" from "first run with nothing".
	 *
	 * @param string $flow_step_id The ID of the flow step (composite: pipeline_step_id_flow_id).
	 * @return bool True if any processed items exist for this flow step.
	 */
	public function has_processed_items( string $flow_step_id ): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
		$count = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE flow_step_id = %s AND status = %s LIMIT 1', $this->table_name, $flow_step_id, self::STATUS_PROCESSED ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $count > 0;
	}

	/**
	 * Get the last-processed timestamp for an item.
	 *
	 * Exposes the `processed_timestamp` column populated on every insert,
	 * enabling time-windowed revisit semantics for consumers that want
	 * "have I touched this recently?" instead of "have I ever seen it?".
	 *
	 * @since 0.71.0
	 *
	 * @param string $flow_step_id    Flow step ID (composite: pipeline_step_id_flow_id).
	 * @param string $source_type     Source type (e.g. 'rss', 'wiki_post', 'venue').
	 * @param string $item_identifier Unique identifier for the item.
	 * @return int|null Unix timestamp, or null when the item has never been processed.
	 */
	public function get_processed_at( string $flow_step_id, string $source_type, string $item_identifier ): ?int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT UNIX_TIMESTAMP(processed_timestamp) FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND status = %s',
				$this->table_name,
				$flow_step_id,
				$source_type,
				$item_identifier,
				self::STATUS_PROCESSED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $value || '' === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Check whether an item has been processed within a given time window.
	 *
	 * Returns true only when the item exists in the table AND its
	 * `processed_timestamp` is newer than (now - $max_age_days).
	 *
	 * @since 0.71.0
	 *
	 * @param string $flow_step_id    Flow step ID.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique identifier for the item.
	 * @param int    $max_age_days    Window in days; must be >= 1.
	 * @return bool True when item is present and fresh. False otherwise.
	 */
	public function has_been_processed_within( string $flow_step_id, string $source_type, string $item_identifier, int $max_age_days ): bool {
		if ( $max_age_days < 1 ) {
			return false;
		}

		$processed_at = $this->get_processed_at( $flow_step_id, $source_type, $item_identifier );

		if ( null === $processed_at ) {
			return false;
		}

		return $processed_at >= ( time() - ( $max_age_days * DAY_IN_SECONDS ) );
	}

	/**
	 * Find candidate identifiers that exist in the table but are stale.
	 *
	 * Given a candidate list, returns the subset that:
	 *   (a) has a row for (flow_step_id, source_type), AND
	 *   (b) whose `processed_timestamp` is older than (now - $max_age_days).
	 *
	 * Enables maintenance pipelines: "which of these posts haven't I
	 * reviewed in the last N days?"
	 *
	 * @since 0.71.0
	 *
	 * @param string   $flow_step_id          Flow step ID.
	 * @param string   $source_type           Source type.
	 * @param string[] $candidate_identifiers Candidate item identifiers to check.
	 * @param int      $max_age_days          Staleness threshold in days; must be >= 1.
	 * @param int      $limit                 Maximum number of identifiers returned. Default 100.
	 * @return string[] Subset of $candidate_identifiers that are stale. Empty array on bad input.
	 */
	public function find_stale( string $flow_step_id, string $source_type, array $candidate_identifiers, int $max_age_days, int $limit = 100 ): array {
		if ( empty( $candidate_identifiers ) || $max_age_days < 1 || $limit < 1 ) {
			return array();
		}

		// Normalize to unique strings to keep the IN() list bounded.
		$candidates = array_values( array_unique( array_map( 'strval', $candidate_identifiers ) ) );

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );

		$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );

		$sql = sprintf(
			'SELECT item_identifier FROM %%i WHERE flow_step_id = %%s AND source_type = %%s AND status = %%s AND processed_timestamp < %%s AND item_identifier IN (%s) ORDER BY processed_timestamp ASC LIMIT %%d',
			$placeholders
		);
		/** @var literal-string $sql */

		$prepare_args = array_merge(
			array( $this->table_name, $flow_step_id, $source_type, self::STATUS_PROCESSED, $cutoff_datetime ),
			$candidates,
			array( $limit )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN() placeholder list.
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$prepare_args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Find candidate identifiers that have never been processed.
	 *
	 * Given a candidate list, returns the subset with no row in the table
	 * for (flow_step_id, source_type). Enables backfill on the first run
	 * of a maintenance pipeline over an existing corpus.
	 *
	 * @since 0.71.0
	 *
	 * @param string   $flow_step_id          Flow step ID.
	 * @param string   $source_type           Source type.
	 * @param string[] $candidate_identifiers Candidate item identifiers to check.
	 * @param int      $limit                 Maximum number of identifiers returned. Default 100.
	 * @return string[] Subset of $candidate_identifiers with no processed row. Empty array on bad input.
	 */
	public function find_never_processed( string $flow_step_id, string $source_type, array $candidate_identifiers, int $limit = 100 ): array {
		if ( empty( $candidate_identifiers ) || $limit < 1 ) {
			return array();
		}

		// Preserve input order while deduping.
		$seen       = array();
		$candidates = array();
		foreach ( $candidate_identifiers as $candidate ) {
			$candidate = (string) $candidate;
			if ( isset( $seen[ $candidate ] ) ) {
				continue;
			}
			$seen[ $candidate ] = true;
			$candidates[]       = $candidate;
		}

		$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );

		$sql = sprintf(
			'SELECT item_identifier FROM %%i WHERE flow_step_id = %%s AND source_type = %%s AND status = %%s AND item_identifier IN (%s)',
			$placeholders
		);
		/** @var literal-string $sql */

		$prepare_args = array_merge(
			array( $this->table_name, $flow_step_id, $source_type, self::STATUS_PROCESSED ),
			$candidates
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN() placeholder list.
		$existing = $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$prepare_args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$existing = array_flip( array_map( 'strval', (array) $existing ) );

		$result = array();
		foreach ( $candidates as $candidate ) {
			if ( isset( $existing[ $candidate ] ) ) {
				continue;
			}
			$result[] = $candidate;
			if ( count( $result ) >= $limit ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Adds a record indicating an item has been processed.
	 *
	 * @param string $flow_step_id   The ID of the flow step (composite: pipeline_step_id_flow_id).
	 * @param string $source_type    The type of the data source.
	 * @param string $item_identifier The unique identifier for the item.
	 * @param int    $job_id The ID of the job that processed this item.
	 * @return bool True on successful insertion, false otherwise.
	 */
	public function add_processed_item( string $flow_step_id, string $source_type, string $item_identifier, int $job_id ): bool {
		$now = current_time( 'mysql', true );

		// Single atomic upsert keyed on the `flow_source_item` unique index. This
		// records a fresh processed row, OR — when an in-flight claim or an
		// already-processed row exists — converts it to final processed state with
		// the latest job_id/timestamp and clears any claim. A bare INSERT (or a
		// check-then-insert) races between concurrent Action Scheduler workers and
		// makes the loser log a hard "Duplicate entry" DB error on every collision.
		// INSERT ... ON DUPLICATE KEY UPDATE turns that race into a normal no-op:
		// the unique key still guarantees exactly one row per source item, and no
		// duplicate-key error is ever raised.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (flow_step_id, source_type, item_identifier, job_id, status, processed_timestamp, claim_expires_at, claim_token)
				VALUES (%s, %s, %s, %d, %s, %s, NULL, NULL)
				ON DUPLICATE KEY UPDATE job_id = VALUES(job_id), status = VALUES(status), processed_timestamp = VALUES(processed_timestamp), claim_expires_at = NULL, claim_token = NULL',
				$this->table_name,
				$flow_step_id,
				$source_type,
				$item_identifier,
				$job_id,
				self::STATUS_PROCESSED,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			// A genuine failure (not a duplicate — the upsert never raises one).
			do_action(
				'datamachine_log',
				'error',
				'Failed to insert processed item.',
				array(
					'flow_step_id'    => $flow_step_id,
					'source_type'     => $source_type,
					'item_identifier' => substr( $item_identifier, 0, 100 ) . '...', // Avoid logging potentially huge identifiers
					'job_id'          => $job_id,
					'db_error'        => $this->wpdb->last_error,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Atomically claim a source item for in-flight processing.
	 *
	 * @param string $flow_step_id     Flow step ID.
	 * @param string $source_type      Source type.
	 * @param string $item_identifier  Unique item identifier.
	 * @param int    $job_id           Job creating the claim.
	 * @param int    $ttl_seconds      Claim TTL in seconds.
	 * @return bool True when this caller owns the claim; false when already processed or actively claimed.
	 */
	public function claim_item( string $flow_step_id, string $source_type, string $item_identifier, int $job_id, int $ttl_seconds = self::DEFAULT_CLAIM_TTL_SECONDS ): bool {
		return false !== $this->claim_item_owned( $flow_step_id, $source_type, $item_identifier, $job_id, $ttl_seconds );
	}

	/**
	 * Atomically claim a source identity and return its ownership token.
	 *
	 * The first argument is an identity scope. Existing callers use their flow
	 * step ID; cross-flow consumers may provide a stable shared scope.
	 *
	 * @param string $identity_scope  Caller-provided identity scope.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @param int    $job_id          Job creating the claim.
	 * @param int    $ttl_seconds     Claim TTL in seconds.
	 * @return string|false Opaque ownership token, or false when unavailable.
	 */
	public function claim_item_owned( string $identity_scope, string $source_type, string $item_identifier, int $job_id, int $ttl_seconds = self::DEFAULT_CLAIM_TTL_SECONDS ): string|false {
		if ( 1 > $ttl_seconds ) {
			$ttl_seconds = self::DEFAULT_CLAIM_TTL_SECONDS;
		}

		$now         = current_time( 'mysql', true );
		$expires_at  = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		$claim_token = bin2hex( random_bytes( 16 ) );

		// Insert-or-lock avoids duplicate-key errors under contention. The no-op
		// duplicate update acquires the existing row lock on both MySQL and MariaDB;
		// the locked read below then decides whether this generation may take over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$upsert_query = $this->wpdb->prepare(
			'INSERT INTO %i (flow_step_id, source_type, item_identifier, job_id, status, claim_expires_at, claim_token)
				VALUES (%s, %s, %s, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE id = id',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier,
			$job_id,
			self::STATUS_CLAIMED,
			$expires_at,
			$claim_token
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$upserted = $this->wpdb->query( $upsert_query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $upserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$lock_query = $this->wpdb->prepare(
			'SELECT id, status, claim_expires_at, claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s FOR UPDATE',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$row = $this->wpdb->get_row( $lock_query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			// A different full identifier may share the 191-character unique-key prefix.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		$owned_token = is_string( $row['claim_token'] ?? null ) ? $row['claim_token'] : '';
		$inserted    = hash_equals( $claim_token, $owned_token );
		$expired     = self::STATUS_CLAIMED === ( $row['status'] ?? '' )
			&& ! empty( $row['claim_expires_at'] )
			&& $now >= $row['claim_expires_at'];
		$available   = self::STATUS_PROCESSED === ( $row['status'] ?? '' ) || $expired;

		if ( ! $inserted && ! $available ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		if ( ! $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $this->wpdb->update(
				$this->table_name,
				array(
					'job_id'           => $job_id,
					'status'           => self::STATUS_CLAIMED,
					'claim_expires_at' => $expires_at,
					'claim_token'      => $claim_token,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated || 1 > $updated ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->wpdb->query( 'COMMIT' ) ? $claim_token : false;
	}

	/** Validate that one persisted descriptor is still actively owned by a job. */
	public function owns_active_claim( array $claim, int $job_id ): bool {
		$identity_scope  = (string) ( $claim['identity_scope'] ?? '' );
		$source_type     = (string) ( $claim['source_type'] ?? '' );
		$item_identifier = (string) ( $claim['item_identifier'] ?? '' );
		$token           = (string) ( $claim['ownership_token'] ?? '' );
		if ( $job_id <= 0 || '' === $identity_scope || '' === $source_type || '' === $item_identifier || '' === $token ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$query = $this->wpdb->prepare(
			'SELECT claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND job_id = %d AND status = %s AND claim_expires_at > %s LIMIT 1',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier,
			$job_id,
			self::STATUS_CLAIMED,
			current_time( 'mysql', true )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above with an escaped identifier and typed values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Evidence-only exact ownership query.
		$owned = $this->wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_string( $owned ) && hash_equals( $token, $owned );
	}

	/**
	 * Complete a descriptor-less claim while the completing job still owns it.
	 *
	 * Reacquisition replaces job_id, so a stale legacy completion cannot mutate
	 * a token-owned replacement generation.
	 *
	 * @param string $flow_step_id    Flow step identity scope.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @param int    $job_id          Completing legacy job ID.
	 * @return int|false Number of completed claims, or false on error.
	 */
	public function complete_claim_for_job( string $flow_step_id, string $source_type, string $item_identifier, int $job_id ): int|false {
		if ( 1 > $job_id ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$query = $this->wpdb->prepare(
			'UPDATE %i SET status = %s, processed_timestamp = %s, claim_expires_at = NULL, claim_token = NULL WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND job_id = %d AND status = %s',
			$this->table_name,
			self::STATUS_PROCESSED,
			$now,
			$flow_step_id,
			$source_type,
			$item_identifier,
			$job_id,
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$result = $this->wpdb->query( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return $result;
	}

	/**
	 * Atomically run completion work and transition an owned claim.
	 *
	 * The callback runs after an ownership-locking SELECT and before the claim
	 * transition in the same transaction. Returning false or a failed transition
	 * rolls back both the callback's database writes and the claim mutation.
	 *
	 * @param string $identity_scope  Claim identity scope.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @param string $claim_token     Opaque ownership token.
	 * @param int    $job_id          Completing job ID.
	 * @param callable|null $completion Optional callback returning true on success.
	 * @param bool   $retain_processed Whether to retain a processed row after completion.
	 * @return bool Whether the token completed its owned claim.
	 */
	public function complete_owned_claim( string $identity_scope, string $source_type, string $item_identifier, string $claim_token, int $job_id, ?callable $completion = null, bool $retain_processed = true ): bool {
		if ( '' === $claim_token ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transaction_started = $this->wpdb->query( 'START TRANSACTION' );
		if ( false === $transaction_started ) {
			return false;
		}
		$completed = $this->complete_owned_claim_in_transaction(
			$identity_scope,
			$source_type,
			$item_identifier,
			$claim_token,
			$job_id,
			$completion,
			$retain_processed
		);
		if ( ! $completed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = false !== $this->wpdb->query( 'COMMIT' );
		if ( ! $committed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
		}
		return $committed;
	}

	/**
	 * Complete an owned claim inside a caller-managed transaction.
	 *
	 * This method never starts, commits, or rolls back a transaction, allowing
	 * every claim callback and transition to share the job terminal boundary.
	 *
	 * @param string $identity_scope  Claim identity scope.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @param string $claim_token     Opaque ownership token.
	 * @param int    $job_id          Completing job ID.
	 * @param callable|null $completion Optional callback returning true on success.
	 * @param bool   $retain_processed Whether to retain a processed row after completion.
	 * @return bool Whether the token completed its owned claim.
	 */
	public function complete_owned_claim_in_transaction( string $identity_scope, string $source_type, string $item_identifier, string $claim_token, int $job_id, ?callable $completion = null, bool $retain_processed = true ): bool {
		if ( '' === $claim_token ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$ownership_query = $this->wpdb->prepare(
			'SELECT claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier,
			$claim_token,
			self::STATUS_CLAIMED
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$owned = $this->wpdb->get_var( $ownership_query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_string( $owned ) || ! hash_equals( $claim_token, $owned ) ) {
			return false;
		}

		try {
			if ( null !== $completion && true !== $completion() ) {
				return false;
			}
		} catch ( \Throwable $exception ) {
			do_action( 'datamachine_log', 'error', 'Item claim completion callback failed.', array( 'exception' => $exception->getMessage() ) );
			return false;
		}

		if ( $retain_processed ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
			$transition_query = $this->wpdb->prepare(
				'UPDATE %i SET status = %s, job_id = %d, processed_timestamp = %s, claim_expires_at = NULL WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s',
				$this->table_name,
				self::STATUS_PROCESSED,
				$job_id,
				$now,
				$identity_scope,
				$source_type,
				$item_identifier,
				$claim_token,
				self::STATUS_CLAIMED
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
			$transitioned = $this->wpdb->query( $transition_query );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$transitioned = $this->wpdb->delete(
				$this->table_name,
				array(
					'flow_step_id'    => $identity_scope,
					'source_type'     => $source_type,
					'item_identifier' => $item_identifier,
					'claim_token'     => $claim_token,
					'status'          => self::STATUS_CLAIMED,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}

		if ( false === $transitioned || 1 > $transitioned ) {
			return false;
		}

		return true;
	}

	/**
	 * Release a claim only while the supplied token owns it.
	 *
	 * @param string $identity_scope  Claim identity scope.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @param string $claim_token     Opaque ownership token.
	 * @param bool   $completed       Whether to remove an owned processed row.
	 * @return int|false Number of rows released, or false on error.
	 */
	public function release_owned_claim( string $identity_scope, string $source_type, string $item_identifier, string $claim_token, bool $completed = false ): int|false {
		if ( '' === $claim_token ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete(
			$this->table_name,
			array(
				'flow_step_id'    => $identity_scope,
				'source_type'     => $source_type,
				'item_identifier' => $item_identifier,
				'claim_token'     => $claim_token,
				'status'          => $completed ? self::STATUS_PROCESSED : self::STATUS_CLAIMED,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Release an in-flight claim so failed downstream work can retry later.
	 *
	 * @param string $flow_step_id    Flow step ID.
	 * @param string $source_type     Source type.
	 * @param string $item_identifier Unique item identifier.
	 * @return int|false Number of rows released, or false on error.
	 */
	public function release_claim( string $flow_step_id, string $source_type, string $item_identifier ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete(
			$this->table_name,
			array(
				'flow_step_id'    => $flow_step_id,
				'source_type'     => $source_type,
				'item_identifier' => $item_identifier,
				'status'          => self::STATUS_CLAIMED,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Release all in-flight claims owned by a job.
	 *
	 * @param int $job_id Job ID.
	 * @return int|false Number of rows released, or false on error.
	 */
	public function release_claims_for_job( int $job_id ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->delete(
			$this->table_name,
			array(
				'job_id' => $job_id,
				'status' => self::STATUS_CLAIMED,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete expired legacy claims that have no ownership generation.
	 *
	 * Token-owned rows remain available for either completion by the current
	 * generation or atomic takeover by a replacement generation.
	 *
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_expired_claims(): int|false {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND claim_token IS NULL AND claim_expires_at IS NOT NULL AND claim_expires_at <= %s',
				$this->table_name,
				self::STATUS_CLAIMED,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? false : (int) $result;
	}

	/**
	 * Delete processed items based on various criteria.
	 *
	 * Provides flexible deletion of processed items by job_id, flow_id,
	 * source_type, or flow_step_id. Used for cleanup operations and
	 * maintenance tasks.
	 *
	 * @param array $criteria Deletion criteria with keys:
	 *                        - job_id: Delete by job ID
	 *                        - flow_id: Delete by flow ID
	 *                        - source_type: Delete by source type
	 *                        - flow_step_id: Delete by flow step ID
	 * @return int|false Number of rows deleted or false on error
	 */
	public function delete_processed_items( array $criteria = array() ): int|false {

		if ( empty( $criteria ) ) {
			do_action( 'datamachine_log', 'warning', 'No criteria provided for processed items deletion' );
			return false;
		}

		$where        = array();
		$where_format = array();

		// Build WHERE conditions based on criteria
		if ( ! empty( $criteria['job_id'] ) ) {
			$where['job_id'] = $criteria['job_id'];
			$where_format[]  = '%d';
		}

		if ( ! empty( $criteria['flow_step_id'] ) ) {
			$where['flow_step_id'] = $criteria['flow_step_id'];
			$where_format[]        = '%s';
		}

		if ( ! empty( $criteria['source_type'] ) ) {
			$where['source_type'] = $criteria['source_type'];
			$where_format[]       = '%s';
		}

		// Handle flow_id (needs LIKE query since flow_step_id contains it)
		if ( ! empty( $criteria['flow_id'] ) && empty( $criteria['flow_step_id'] ) ) {
			$pattern = '%_' . $criteria['flow_id'];
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( ! empty( $criteria['pipeline_step_id'] ) && empty( $criteria['flow_step_id'] ) ) {
			// Handle pipeline_step_id (delete processed items for this pipeline step across all flows)
			$pattern = $criteria['pipeline_step_id'] . '_%';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( ! empty( $criteria['pipeline_id'] ) && empty( $criteria['flow_step_id'] ) ) {
			// Handle pipeline_id (get all flows for pipeline and delete their processed items)
			// Get all flows for this pipeline using the existing filter
			$db_flows       = new \DataMachine\Core\Database\Flows\Flows();
			$pipeline_flows = $db_flows->get_flows_for_pipeline( $criteria['pipeline_id'] );
			$flow_ids       = array_column( $pipeline_flows, 'flow_id' );

			if ( empty( $flow_ids ) ) {
				do_action(
					'datamachine_log',
					'debug',
					'No flows found for pipeline, nothing to delete',
					array(
						'pipeline_id' => $criteria['pipeline_id'],
					)
				);
				return 0;
			}

			// Build IN clause for multiple flow IDs
			$flow_patterns = array_map(
				function ( $flow_id ) {
					return '%_' . $flow_id;
				},
				$flow_ids
			);

			// Execute individual DELETE queries for each pattern
			$total_deleted = 0;
			foreach ( $flow_patterns as $pattern ) {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
				$deleted = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
				if ( false !== $deleted ) {
					$total_deleted += $deleted;
				}
			}
			$result = $total_deleted;
		} elseif ( ! empty( $where ) ) {
			// Standard delete with WHERE conditions
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->delete( $this->table_name, $where, $where_format );
		} else {
			do_action( 'datamachine_log', 'warning', 'No valid criteria provided for processed items deletion' );
			return false;
		}

		// Log the operation
		do_action(
			'datamachine_log',
			'debug',
			'Deleted processed items',
			array(
				'criteria'      => $criteria,
				'items_deleted' => false !== $result ? $result : 0,
				'success'       => false !== $result,
			)
		);

		return false === $result ? false : (int) $result;
	}

	/**
	 * Delete processed items older than a given number of days.
	 *
	 * Used by the scheduled retention cleanup to prevent unbounded growth
	 * of dedup records. Items older than the threshold are unlikely to be
	 * re-encountered and can be safely removed.
	 *
	 * @since 0.40.0
	 *
	 * @param int $older_than_days Delete items older than this many days.
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_old_processed_items( int $older_than_days ): int|false {
		if ( $older_than_days < 1 ) {
			return false;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND processed_timestamp < %s',
				$this->table_name,
				self::STATUS_PROCESSED,
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
		$result = false === $result ? false : (int) $result;

		do_action(
			'datamachine_log',
			'info',
			'Deleted old processed items',
			array(
				'older_than_days' => $older_than_days,
				'cutoff_datetime' => $cutoff_datetime,
				'items_deleted'   => false !== $result ? $result : 0,
				'success'         => false !== $result,
			)
		);

		return $result;
	}

	/**
	 * Count processed items older than a given number of days.
	 *
	 * @since 0.40.0
	 *
	 * @param int $older_than_days Count items older than this many days.
	 * @return int Number of matching items.
	 */
	public function count_old_processed_items( int $older_than_days ): int {
		if ( $older_than_days < 1 ) {
			return 0;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND processed_timestamp < %s',
				$this->table_name,
				self::STATUS_PROCESSED,
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return (int) $count;
	}

	/**
	 * Creates or updates the database table schema.
	 * Should be called on plugin activation.
	 */
	public function create_table() {
		$charset_collate = $this->wpdb->get_charset_collate();

		// Use dbDelta for proper table creation/updates
		$sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_step_id VARCHAR(255) NOT NULL,
            source_type VARCHAR(50) NOT NULL,
            item_identifier VARCHAR(255) NOT NULL,
            job_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'processed',
			claim_expires_at DATETIME NULL,
			claim_token VARCHAR(64) NULL,
            processed_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY `flow_source_item` (flow_step_id, source_type, item_identifier(191)),
            KEY `flow_step_id` (flow_step_id),
            KEY `source_type` (source_type),
            KEY `job_id` (job_id),
			KEY `status_claim_expires` (status, claim_expires_at),
            KEY `flow_source_ts` (flow_step_id, source_type, processed_timestamp)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta may not add UNIQUE indexes to existing tables — ensure it exists.
		self::ensure_unique_index( $this->table_name );

		// dbDelta is also unreliable at adding regular indexes to existing tables.
		self::ensure_flow_source_ts_index( $this->table_name );
		self::ensure_claim_columns( $this->table_name );

		// Log table creation
		do_action(
			'datamachine_log',
			'debug',
			'Created processed items database table',
			array(
				'table_name' => $this->table_name,
				'action'     => 'create_table',
			)
		);
	}

	/**
	 * Ensure the UNIQUE index on (flow_step_id, source_type, item_identifier) exists.
	 *
	 * dbDelta is unreliable at adding indexes to existing tables. This method
	 * deduplicates any existing rows and adds the UNIQUE index if missing.
	 *
	 * @since 0.35.0
	 *
	 * @param string $table_name Full table name.
	 */
	private static function ensure_unique_index( string $table_name ): void {
		global $wpdb;

		// Check if the index already exists.
		// phpcs:disable WordPress.DB.PreparedSQL -- table name is code-defined ($wpdb->prefix), not user input.
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'flow_source_item'" );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( $index ) {
			return;
		}

		// Remove duplicate rows, keeping the earliest (lowest id) for each combo.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$deleted = $wpdb->query(
			"DELETE t1 FROM {$table_name} t1
			 INNER JOIN {$table_name} t2
			 WHERE t1.id > t2.id
			   AND t1.flow_step_id = t2.flow_step_id
			   AND t1.source_type = t2.source_type
			   AND t1.item_identifier = t2.item_identifier"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Deduplicated processed_items before adding UNIQUE index',
				array(
					'table_name'   => $table_name,
					'rows_removed' => $deleted,
				)
			);
		}

		// Add the UNIQUE index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD UNIQUE KEY `flow_source_item` (flow_step_id, source_type, item_identifier(191))"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		do_action(
			'datamachine_log',
			'info',
			'Added UNIQUE index flow_source_item to processed_items table',
			array( 'table_name' => $table_name )
		);
	}

	/**
	 * Ensure the composite (flow_step_id, source_type, processed_timestamp) index exists.
	 *
	 * Supports the time-windowed query methods added in 0.71.0 (`find_stale`,
	 * `has_been_processed_within`). Range scans on `processed_timestamp`
	 * scoped by flow_step_id + source_type benefit from a covering composite
	 * index; the existing UNIQUE key on `item_identifier` does not help.
	 *
	 * @since 0.71.0
	 *
	 * @param string $table_name Full table name.
	 */
	private static function ensure_flow_source_ts_index( string $table_name ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL -- table name is code-defined ($wpdb->prefix), not user input.
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'flow_source_ts'" );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( $index ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$wpdb->prepare(
				'ALTER TABLE %i ADD KEY `flow_source_ts` (flow_step_id, source_type, processed_timestamp)',
				$table_name
			)
		);

		do_action(
			'datamachine_log',
			'info',
			'Added composite index flow_source_ts to processed_items table',
			array( 'table_name' => $table_name )
		);
	}

	/**
	 * Ensure in-flight claim columns and index exist on upgraded installs.
	 *
	 * @param string $table_name Full table name.
	 */
	public static function ensure_claim_columns( string $table_name ): void {
		global $wpdb;

		$status_column = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'status' )
		);
		if ( ! $status_column ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
				$wpdb->prepare( "ALTER TABLE %i ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'processed'", $table_name )
			);
		}

		$claim_column = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'claim_expires_at' )
		);
		if ( ! $claim_column ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
				$wpdb->prepare( 'ALTER TABLE %i ADD COLUMN claim_expires_at DATETIME NULL', $table_name )
			);
		}

		$token_column = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, 'claim_token' )
		);
		if ( ! $token_column ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
				$wpdb->prepare( 'ALTER TABLE %i ADD COLUMN claim_token VARCHAR(64) NULL', $table_name )
			);
		}

		$index = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, 'status_claim_expires' )
		);
		if ( ! $index ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- uses %i identifier placeholder; WPCS does not recognize %i (false positive).
				$wpdb->prepare( 'ALTER TABLE %i ADD KEY `status_claim_expires` (status, claim_expires_at)', $table_name )
			);
		}
	}
}
