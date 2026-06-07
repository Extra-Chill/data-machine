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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class ProcessedItems extends BaseRepository {

	const TABLE_NAME                = 'datamachine_processed_items';
	const STATUS_CLAIMED            = 'claimed';
	const STATUS_PROCESSED          = 'processed';
	const DEFAULT_CLAIM_TTL_SECONDS = 3600;


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
		$this->delete_expired_claims();

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
	 * Checks if a flow step has any processed items history.
	 *
	 * Used to determine if a flow has ever successfully processed items,
	 * which helps distinguish "no new items" from "first run with nothing".
	 *
	 * @param string $flow_step_id The ID of the flow step (composite: pipeline_step_id_flow_id).
	 * @return bool True if any processed items exist for this flow step.
	 */
	public function has_processed_items( string $flow_step_id ): bool {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE flow_step_id = %s AND status = %s LIMIT 1', $this->table_name, $flow_step_id, self::STATUS_PROCESSED ) );

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

		// Convert an existing in-flight claim to final processed state, or refresh
		// an existing processed row idempotently. The unique key guarantees one row
		// per source item, so this is safe across child-job races.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET job_id = %d, status = %s, processed_timestamp = %s, claim_expires_at = NULL WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s',
				$this->table_name,
				$job_id,
				self::STATUS_PROCESSED,
				$now,
				$flow_step_id,
				$source_type,
				$item_identifier
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( false !== $updated && $updated > 0 ) {
			return true;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->insert(
			$this->table_name,
			array(
				'flow_step_id'    => $flow_step_id,
				'source_type'     => $source_type,
				'item_identifier' => $item_identifier,
				'job_id'          => $job_id,
				'status'          => self::STATUS_PROCESSED,
				// processed_timestamp defaults to NOW().
			),
			array(
				'%s', // flow_step_id
				'%s', // source_type
				'%s', // item_identifier
				'%d', // job_id
				'%s', // status
			)
		);

		if ( false === $result ) {
			// Log error - but check if it's a duplicate key error first
			$db_error = $this->wpdb->last_error;

			// If it's a duplicate key error, treat as success (race condition handling)
			if ( false !== strpos( $db_error, 'Duplicate entry' ) ) {
				return true; // Treat duplicate as success
			}

			// Use Logger Service if available for actual errors
			do_action(
				'datamachine_log',
				'error',
				'Failed to insert processed item.',
				array(
					'flow_step_id'    => $flow_step_id,
					'source_type'     => $source_type,
					'item_identifier' => substr( $item_identifier, 0, 100 ) . '...', // Avoid logging potentially huge identifiers
					'job_id'          => $job_id,
					'db_error'        => $db_error,
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
		if ( $ttl_seconds < 1 ) {
			$ttl_seconds = self::DEFAULT_CLAIM_TTL_SECONDS;
		}

		$this->delete_expired_claims();

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->insert(
			$this->table_name,
			array(
				'flow_step_id'     => $flow_step_id,
				'source_type'      => $source_type,
				'item_identifier'  => $item_identifier,
				'job_id'           => $job_id,
				'status'           => self::STATUS_CLAIMED,
				'claim_expires_at' => $expires_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false !== $result ) {
			return true;
		}

		// If the reprocess filter let a completed row through, atomically convert
		// that processed row into a claim. Active claims remain untouched, so a
		// parallel fetch still loses the race.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$claimed_existing_processed = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET job_id = %d, status = %s, claim_expires_at = %s WHERE flow_step_id = %s AND source_type = %s AND item_identifier = %s AND status = %s',
				$this->table_name,
				$job_id,
				self::STATUS_CLAIMED,
				$expires_at,
				$flow_step_id,
				$source_type,
				$item_identifier,
				self::STATUS_PROCESSED
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return false !== $claimed_existing_processed && $claimed_existing_processed > 0;
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
	 * Delete expired in-flight claims.
	 *
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_expired_claims(): int|false {
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Prepared with %i table placeholder.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND claim_expires_at IS NOT NULL AND claim_expires_at <= %s',
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
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
		} elseif ( ! empty( $criteria['pipeline_step_id'] ) && empty( $criteria['flow_step_id'] ) ) {
			// Handle pipeline_step_id (delete processed items for this pipeline step across all flows)
			$pattern = $criteria['pipeline_step_id'] . '_%';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
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
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$deleted = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE flow_step_id LIKE %s', $this->table_name, $pattern ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'flow_source_item'" );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'flow_source_ts'" );

		if ( $index ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$wpdb->query(
			"ALTER TABLE {$table_name}
			 ADD KEY `flow_source_ts` (flow_step_id, source_type, processed_timestamp)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_column = $wpdb->get_var( "SHOW COLUMNS FROM {$table_name} LIKE 'status'" );
		if ( ! $status_column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'processed'" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claim_column = $wpdb->get_var( "SHOW COLUMNS FROM {$table_name} LIKE 'claim_expires_at'" );
		if ( ! $claim_column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN claim_expires_at DATETIME NULL" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_row( "SHOW INDEX FROM {$table_name} WHERE Key_name = 'status_claim_expires'" );
		if ( ! $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table_name} ADD KEY `status_claim_expires` (status, claim_expires_at)" );
		}
	}
}
