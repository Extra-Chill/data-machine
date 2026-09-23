<?php
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
use DataMachine\Core\Database\TransactionScope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class ProcessedItems extends BaseRepository {
	use ProcessedItemDeferrals;

	const TABLE_NAME                  = 'datamachine_processed_items';
	const STATUS_CLAIMED              = 'claimed';
	const STATUS_DEFERRED             = 'deferred';
	const STATUS_PROCESSED            = 'processed';
	const DEFAULT_CLAIM_TTL_SECONDS   = 3600;
	const MAX_DEFERRAL_ATTEMPTS       = 3;
	const CLAIM_METADATA_KEY          = '_datamachine_item_claim';
	const CLAIMS_METADATA_KEY         = '_datamachine_item_claims';
	const DISPOSITION_ID_METADATA_KEY = '_datamachine_packet_disposition_id';
	const DISPOSITION_HANDLE_METADATA_KEY = '_datamachine_packet_disposition_handle';
	const DISPOSITION_HANDLE_PREFIX       = 'p';
	private const DISPOSITION_HANDLE_MIN_CHARS = 6;
	private const READ_CHUNK_SIZE     = 500;

	/** Return a stable, non-secret packet disposition identity. */
	public static function disposition_identity( string $identity_scope, string $source_type, string $item_identifier ): string {
		return hash( 'sha256', implode( "\0", array( $identity_scope, $source_type, $item_identifier ) ) );
	}

	/**
	 * Return valid owned claims keyed by their non-secret disposition identity.
	 *
	 * @param array $container Engine data or packet metadata.
	 * @return array<string,array<string,mixed>>
	 */
	public static function disposition_claims( array $container ): array {
		$candidates = array();
		if ( is_array( $container[ self::CLAIM_METADATA_KEY ] ?? null ) ) {
			$candidates[] = $container[ self::CLAIM_METADATA_KEY ];
		}
		if ( is_array( $container[ self::CLAIMS_METADATA_KEY ] ?? null ) ) {
			$candidates = array_merge( $candidates, $container[ self::CLAIMS_METADATA_KEY ] );
		}

		$claims = array();
		foreach ( $candidates as $claim ) {
			if ( ! is_array( $claim ) || false === ( $claim['persisted'] ?? true ) ) {
				continue;
			}
			foreach ( array( 'identity_scope', 'source_type', 'item_identifier', 'ownership_token' ) as $key ) {
				if ( ! is_string( $claim[ $key ] ?? null ) || '' === $claim[ $key ] ) {
					continue 2;
				}
			}

			$disposition_id = self::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
			if ( isset( $claim['disposition_id'] ) && ( ! is_string( $claim['disposition_id'] ) || ! hash_equals( $disposition_id, $claim['disposition_id'] ) ) ) {
				continue;
			}
			$claim['disposition_id']   = $disposition_id;
			$claims[ $disposition_id ] = $claim;
		}

		return $claims;
	}

	/**
	 * Return short model-facing packet handles keyed to canonical disposition IDs.
	 *
	 * A handle is a deterministic short prefix of the canonical identity, extended
	 * only when two claims in the same container would collide. Handles are
	 * presentation labels: resolution compares them exactly and never prefix-matches
	 * a partial or truncated value.
	 *
	 * @param array $container Engine data or packet metadata.
	 * @return array<string,string> Handle => canonical disposition ID.
	 */
	public static function disposition_handles( array $container ): array {
		$handles = array();
		foreach ( array_keys( self::disposition_claims( $container ) ) as $disposition_id ) {
			$length = self::DISPOSITION_HANDLE_MIN_CHARS;
			$handle = self::DISPOSITION_HANDLE_PREFIX . substr( $disposition_id, 0, $length );
			while ( isset( $handles[ $handle ] ) && ! hash_equals( $handles[ $handle ], $disposition_id ) ) {
				++$length;
				$handle = self::DISPOSITION_HANDLE_PREFIX . substr( $disposition_id, 0, $length );
			}
			$handles[ $handle ] = $disposition_id;
		}

		return $handles;
	}

	/** Describe the valid model-facing handles for unresolved-ID error text. */
	public static function describe_disposition_handles( array $container ): string {
		$handles = array_keys( self::disposition_handles( $container ) );
		if ( empty( $handles ) ) {
			return '';
		}

		return 'Valid packet handles: ' . implode( ', ', $handles ) . '.';
	}

	/** Build the fail-closed error text for a model-supplied identity with no active claim. */
	public static function unresolved_disposition_error( array $container, string $provided_id = '' ): string {
		$base = '' === $provided_id
			? 'disposition_id is required when more than one packet claim is active'
			: 'disposition_id does not identify an active packet claim';
		$handles = self::describe_disposition_handles( $container );

		return '' === $handles ? $base : $base . ' ' . $handles;
	}

	/**
	 * Resolve a model-supplied packet identity to an active claim.
	 *
	 * With exactly one active claim and inference enabled, any supplied value
	 * (missing, mismatched, or garbled) binds to the sole claim because it can
	 * only mean that packet. With multiple claims, resolution accepts the full
	 * canonical ID or a short model-facing handle from disposition_handles() and
	 * fails closed on anything else.
	 *
	 * @param array  $container    Engine data or packet metadata.
	 * @param string $disposition_id Model-supplied identity, handle, or empty string.
	 * @param bool   $infer_single  Bind unconditionally to a sole active claim.
	 */
	public static function resolve_disposition_claim( array $container, string $disposition_id = '', bool $infer_single = true ): ?array {
		$claims = self::disposition_claims( $container );

		if ( 1 === count( $claims ) && $infer_single ) {
			return reset( $claims );
		}

		if ( '' === $disposition_id ) {
			return null;
		}

		foreach ( $claims as $claim_id => $claim ) {
			if ( hash_equals( $claim_id, $disposition_id ) ) {
				return $claim;
			}
		}

		$handle = strtolower( $disposition_id );
		foreach ( self::disposition_handles( $container ) as $handle_id => $claim_id ) {
			if ( hash_equals( $handle_id, $handle ) ) {
				return $claims[ $claim_id ];
			}
		}

		return null;
	}

	/** Replace packet claim metadata with an exact, canonical claim set. */
	public static function replace_disposition_claims( array $container, array $claims ): array {
		unset(
			$container[ self::CLAIM_METADATA_KEY ],
			$container[ self::CLAIMS_METADATA_KEY ],
			$container[ self::DISPOSITION_ID_METADATA_KEY ],
			$container['disposition_id']
		);

		$claims = self::disposition_claims( array( self::CLAIMS_METADATA_KEY => array_values( $claims ) ) );
		if ( 1 === count( $claims ) ) {
			$claim                                 = reset( $claims );
			$container[ self::CLAIM_METADATA_KEY ] = $claim;
			$container[ self::DISPOSITION_ID_METADATA_KEY ] = $claim['disposition_id'];
			$container['disposition_id']                    = $claim['disposition_id'];
			$container['source_type']                       = $claim['source_type'];
			$container['item_identifier']                   = $claim['item_identifier'];
			if ( is_array( $container['_engine_data'] ?? null ) ) {
				$container['_engine_data']['source_type']     = $claim['source_type'];
				$container['_engine_data']['item_identifier'] = $claim['item_identifier'];
			}
		} else {
			if ( ! empty( $claims ) ) {
				$container[ self::CLAIMS_METADATA_KEY ] = array_values( $claims );
			}
			unset( $container['item_identifier'], $container['source_item_id'], $container['source_type'] );
			if ( is_array( $container['_engine_data'] ?? null ) ) {
				unset( $container['_engine_data']['item_identifier'], $container['_engine_data']['source_type'] );
			}
		}

		return $container;
	}

	/** Whether a container explicitly carries singular or collection claim metadata. */
	public static function has_claim_metadata( array $container ): bool {
		return array_key_exists( self::CLAIM_METADATA_KEY, $container )
			|| array_key_exists( self::CLAIMS_METADATA_KEY, $container );
	}

	/** Whether every explicitly supplied claim descriptor is valid and uniquely identified. */
	public static function has_valid_claim_metadata( array $container ): bool {
		if ( ! self::has_claim_metadata( $container ) ) {
			return true;
		}

		$candidates = array();
		if ( array_key_exists( self::CLAIM_METADATA_KEY, $container ) ) {
			if ( ! is_array( $container[ self::CLAIM_METADATA_KEY ] ) ) {
				return false;
			}
			$candidates[] = $container[ self::CLAIM_METADATA_KEY ];
		}
		if ( array_key_exists( self::CLAIMS_METADATA_KEY, $container ) ) {
			if ( ! is_array( $container[ self::CLAIMS_METADATA_KEY ] ) ) {
				return false;
			}
			$candidates = array_merge( $candidates, $container[ self::CLAIMS_METADATA_KEY ] );
		}

		if ( empty( $candidates ) ) {
			return false;
		}
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				return false;
			}
		}

		$persisted = array_values(
			array_filter(
				$candidates,
				static fn( mixed $claim ): bool => is_array( $claim ) && false !== ( $claim['persisted'] ?? true )
			)
		);
		if ( empty( $persisted ) ) {
			return true;
		}

		return count( $persisted ) === count( self::disposition_claims( $container ) );
	}


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
		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		$upsert_query = $this->wpdb->prepare(
			'INSERT INTO %i (flow_step_id, source_type, item_identifier, job_id, status, claim_expires_at, claim_token, last_seen_at)
				VALUES (%s, %s, %s, %d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE id = id',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier,
			$job_id,
			self::STATUS_CLAIMED,
			$expires_at,
			$claim_token,
			$now
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$upserted = $this->wpdb->query( $upsert_query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $upserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$scope->rollback();
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
			$scope->rollback();
			return false;
		}

		$owned_token = is_string( $row['claim_token'] ?? null ) ? $row['claim_token'] : '';
		$inserted    = hash_equals( $claim_token, $owned_token );
		$expired     = self::STATUS_CLAIMED === ( $row['status'] ?? '' )
			&& ! empty( $row['claim_expires_at'] )
			&& $now >= $row['claim_expires_at'];
		$available   = in_array( $row['status'] ?? '', array( self::STATUS_PROCESSED, self::STATUS_DEFERRED ), true ) || $expired;

		if ( ! $inserted && ! $available ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$scope->rollback();
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
					'last_seen_at'     => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated || 1 > $updated ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$scope->rollback();
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $scope->commit() ? $claim_token : false;
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

	/** Lock and validate one token-owned claim inside a caller-managed transaction. */
	public function lock_owned_claim_in_transaction( array $claim ): bool {
		$identity_scope  = (string) ( $claim['identity_scope'] ?? '' );
		$source_type     = (string) ( $claim['source_type'] ?? '' );
		$item_identifier = (string) ( $claim['item_identifier'] ?? '' );
		$token           = (string) ( $claim['ownership_token'] ?? '' );
		if ( '' === $identity_scope || '' === $source_type || '' === $item_identifier || '' === $token ) {
			return false;
		}

		$query = $this->wpdb->prepare(
			'SELECT claim_token FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s FOR UPDATE',
			$this->table_name,
			$identity_scope,
			$source_type,
			$item_identifier,
			$token,
			self::STATUS_CLAIMED
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above with an escaped identifier and typed values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional ownership lock on the plugin table.
		$owned = $this->wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return is_string( $owned ) && hash_equals( $token, $owned );
	}

	/** Renew one locked token-owned claim inside a caller-managed transaction. */
	public function renew_owned_claim_in_transaction( array $claim, int $job_id, int $ttl_seconds = self::DEFAULT_CLAIM_TTL_SECONDS ): bool {
		if ( $job_id <= 0 || ! $this->lock_owned_claim_in_transaction( $claim ) ) {
			return false;
		}

		$ttl_seconds = max( 1, $ttl_seconds );
		$updated     = $this->wpdb->update(
			$this->table_name,
			array(
				'job_id'           => $job_id,
				'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds ),
			),
			array(
				'flow_step_id'    => $claim['identity_scope'],
				'source_type'     => $claim['source_type'],
				'item_identifier' => $claim['item_identifier'],
				'claim_token'     => $claim['ownership_token'],
				'status'          => self::STATUS_CLAIMED,
			),
			array( '%d', '%s' ),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// A renewal in the same second may be a no-op after the ownership lock.
		return false !== $updated;
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

		$scope = TransactionScope::begin( $this->wpdb );
		if ( null === $scope ) {
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
			$scope->rollback();
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = $scope->commit();
		if ( ! $committed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$scope->rollback();
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
		$now            = current_time( 'mysql', true );
		$claim_identity = array(
			'identity_scope'  => $identity_scope,
			'source_type'     => $source_type,
			'item_identifier' => $item_identifier,
			'claim_token'     => $claim_token,
		);
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
			$transition_query = $this->prepare_owned_claim_transition_query( $claim_identity, $job_id, $now );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
			$transition_query = $this->wpdb->prepare(
				'DELETE FROM %i WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s',
				$this->table_name,
				$identity_scope,
				$source_type,
				$item_identifier,
				$claim_token,
				self::STATUS_CLAIMED
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier is prepared with %i; every value uses a typed placeholder.
		$transitioned = $this->wpdb->query( $transition_query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $transitioned || 1 > $transitioned ) {
			return false;
		}

		return true;
	}

	/** Prepare the processed transition for one exact token-owned identity. */
	private function prepare_owned_claim_transition_query( array $claim, int $job_id, string $now ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier uses %i; all values use typed placeholders.
		return $this->wpdb->prepare(
			'UPDATE %i SET status = %s, job_id = %d, processed_timestamp = %s, claim_expires_at = NULL WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND claim_token = %s AND status = %s',
			$this->table_name,
			self::STATUS_PROCESSED,
			$job_id,
			$now,
			$claim['identity_scope'],
			$claim['source_type'],
			$claim['item_identifier'],
			$claim['claim_token'],
			self::STATUS_CLAIMED
		);
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

		$where = array(
			'flow_step_id'    => $identity_scope,
			'source_type'     => $source_type,
			'item_identifier' => $item_identifier,
			'claim_token'     => $claim_token,
			'status'          => $completed ? self::STATUS_PROCESSED : self::STATUS_CLAIMED,
		);
		if ( $completed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->wpdb->delete( $this->table_name, $where, array( '%s', '%s', '%s', '%s', '%s' ) );
		}

		return $this->release_claimed_rows( $where, array( '%s', '%s', '%s', '%s', '%s' ) );
	}

	/** Release claimed rows while retaining any durable deferral history. */
	private function release_claimed_rows( array $where, array $where_format ): int|false {
		$fresh_where                   = $where;
		$fresh_where['deferral_count'] = 0;
		$where_format[]                = '%d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->delete( $this->table_name, $fresh_where, $where_format );
		if ( false === $deleted ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$redeferred = $this->wpdb->update(
			$this->table_name,
			array(
				'status'           => self::STATUS_DEFERRED,
				'deferred_at'      => current_time( 'mysql', true ),
				'claim_expires_at' => null,
				'claim_token'      => null,
			),
			$where,
			array( '%s', '%s', '%s', '%s' ),
			array_slice( $where_format, 0, -1 )
		);
		return false === $redeferred ? false : (int) $redeferred + (int) $deleted;
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
		return $this->release_claimed_rows(
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
		return $this->release_claimed_rows(
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
			deferral_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			last_deferral_job_id BIGINT(20) UNSIGNED NULL,
			deferred_at DATETIME NULL,
			last_seen_at DATETIME NULL,
            processed_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY `flow_source_item` (flow_step_id, source_type, item_identifier(191)),
            KEY `flow_step_id` (flow_step_id),
            KEY `source_type` (source_type),
            KEY `job_id` (job_id),
			KEY `status_claim_expires` (status, claim_expires_at),
			KEY `status_deferred_at` (status, deferred_at),
            KEY `flow_source_ts` (flow_step_id, source_type, processed_timestamp)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

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
}
