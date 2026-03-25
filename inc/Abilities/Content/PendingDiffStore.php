<?php
/**
 * PendingDiffStore — server-side storage for preview diffs awaiting resolution.
 *
 * When an edit/replace ability runs in preview mode, the pending edit is
 * stored here instead of being applied. The resolve endpoint later retrieves
 * and applies (or discards) the stored edit.
 *
 * Uses WordPress transients with a 1-hour TTL. Pending diffs auto-expire
 * if never resolved.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.60.0
 */

namespace DataMachine\Abilities\Content;

defined( 'ABSPATH' ) || exit;

class PendingDiffStore {

	/** Transient TTL in seconds (1 hour). */
	private const TTL = HOUR_IN_SECONDS;

	/** Transient key prefix. */
	private const PREFIX = 'dm_pending_diff_';

	/**
	 * Store a pending diff.
	 *
	 * @param string $diff_id   Unique diff identifier.
	 * @param array  $diff_data The pending edit data.
	 * @return bool Whether the transient was set.
	 */
	public static function store( string $diff_id, array $diff_data ): bool {
		$diff_data['stored_at'] = time();
		$diff_data['diff_id']   = $diff_id;

		return set_transient( self::PREFIX . $diff_id, $diff_data, self::TTL );
	}

	/**
	 * Retrieve a pending diff.
	 *
	 * @param string $diff_id Diff identifier.
	 * @return array|null The diff data, or null if not found / expired.
	 */
	public static function get( string $diff_id ): ?array {
		$data = get_transient( self::PREFIX . $diff_id );

		if ( false === $data || ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Delete a pending diff (after resolution).
	 *
	 * @param string $diff_id Diff identifier.
	 * @return bool Whether the transient was deleted.
	 */
	public static function delete( string $diff_id ): bool {
		return delete_transient( self::PREFIX . $diff_id );
	}

	/**
	 * Generate a unique diff ID.
	 *
	 * @return string A unique diff identifier.
	 */
	public static function generate_id(): string {
		return 'diff_' . wp_generate_uuid4();
	}
}
