<?php
/**
 * Chat identity links.
 *
 * Stores a platform-scoped external chat actor ID on a WordPress user so chat
 * bridges can resolve "who is speaking" without keeping their own mapping file.
 * Links are scoped by platform only (never by server/guild/workspace) and
 * platform names are plain data: any non-empty slug is accepted.
 *
 * Storage: one user meta row per platform, `datamachine_chat_identity_{platform}`,
 * whose value is the external actor ID.
 *
 * @package DataMachine\Core\Agents
 */

namespace DataMachine\Core\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link, unlink, look up and list chat identities stored on WordPress users.
 */
class ChatIdentityLinks {

	/**
	 * User meta key prefix. The normalized platform slug is appended.
	 */
	const META_PREFIX = 'datamachine_chat_identity_';

	/**
	 * Maximum accepted actor ID length.
	 */
	const MAX_ACTOR_ID_LENGTH = 191;

	/**
	 * Normalize a platform name to its stored slug.
	 *
	 * @param string $platform Raw platform name.
	 * @return string Normalized slug, '' when invalid.
	 */
	public static function normalize_platform( string $platform ): string {
		return sanitize_key( trim( $platform ) );
	}

	/**
	 * Normalize an external actor ID.
	 *
	 * @param string $actor_id Raw actor ID.
	 * @return string Normalized actor ID, '' when invalid.
	 */
	public static function normalize_actor_id( string $actor_id ): string {
		$actor_id = trim( $actor_id );
		if ( strlen( $actor_id ) > self::MAX_ACTOR_ID_LENGTH ) {
			return '';
		}

		return $actor_id;
	}

	/**
	 * User meta key for a platform.
	 *
	 * @param string $platform Normalized platform slug.
	 * @return string Meta key.
	 */
	public static function meta_key( string $platform ): string {
		return self::META_PREFIX . $platform;
	}

	/**
	 * Link an external actor to a WordPress user on a platform.
	 *
	 * Replaces any existing link the user holds for that platform. Fails when the
	 * actor is already linked to a different user.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $platform Platform name.
	 * @param string $actor_id External actor ID on that platform.
	 * @return array|\WP_Error Link row on success.
	 */
	public static function link( int $user_id, string $platform, string $actor_id ): array|\WP_Error {
		$platform = self::normalize_platform( $platform );
		$actor_id = self::normalize_actor_id( $actor_id );

		if ( '' === $platform ) {
			return new \WP_Error( 'chat_identity_invalid_platform', 'Platform must not be empty.', array( 'status' => 400 ) );
		}
		if ( '' === $actor_id ) {
			return new \WP_Error( 'chat_identity_invalid_actor', 'Actor ID must be non-empty and at most ' . self::MAX_ACTOR_ID_LENGTH . ' characters.', array( 'status' => 400 ) );
		}
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'chat_identity_user_not_found', 'WordPress user not found.', array( 'status' => 404 ) );
		}

		$holders = self::find_user_ids( $platform, $actor_id );
		foreach ( $holders as $holder ) {
			if ( $holder !== $user_id ) {
				return new \WP_Error( 'chat_identity_conflict', 'That actor is already linked to another user on this platform.', array( 'status' => 409 ) );
			}
		}

		update_user_meta( $user_id, self::meta_key( $platform ), $actor_id );

		return array(
			'user_id'  => $user_id,
			'platform' => $platform,
			'actor_id' => $actor_id,
		);
	}

	/**
	 * Remove a user's link for a platform.
	 *
	 * @param int    $user_id  WordPress user ID.
	 * @param string $platform Platform name.
	 * @return bool True when a link existed and was removed.
	 */
	public static function unlink( int $user_id, string $platform ): bool {
		$platform = self::normalize_platform( $platform );
		if ( '' === $platform || $user_id <= 0 ) {
			return false;
		}

		$key = self::meta_key( $platform );
		if ( '' === (string) get_user_meta( $user_id, $key, true ) ) {
			return false;
		}

		return delete_user_meta( $user_id, $key );
	}

	/**
	 * Find the WordPress user ID linked to an external actor.
	 *
	 * Fails closed: unknown actors and ambiguous (multiple-holder) links return 0.
	 *
	 * @param string $platform Platform name.
	 * @param string $actor_id External actor ID.
	 * @return int User ID, 0 when not linked.
	 */
	public static function resolve_user_id( string $platform, string $actor_id ): int {
		$platform = self::normalize_platform( $platform );
		$actor_id = self::normalize_actor_id( $actor_id );
		if ( '' === $platform || '' === $actor_id ) {
			return 0;
		}

		$holders = self::find_user_ids( $platform, $actor_id );

		return 1 === count( $holders ) ? $holders[0] : 0;
	}

	/**
	 * List links, optionally filtered by user and/or platform.
	 *
	 * @param int    $user_id  Optional user ID filter (0 = any).
	 * @param string $platform Optional platform filter ('' = any).
	 * @return array<int, array{user_id: int, platform: string, actor_id: string}>
	 */
	public static function list_links( int $user_id = 0, string $platform = '' ): array {
		global $wpdb;

		$platform = self::normalize_platform( $platform );
		$like     = $wpdb->esc_like( self::META_PREFIX ) . ( '' !== $platform ? $wpdb->esc_like( $platform ) : '%' );

		$sql    = "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s";
		$params = array( $like );
		if ( $user_id > 0 ) {
			$sql     .= ' AND user_id = %d';
			$params[] = $user_id;
		}
		$sql .= ' ORDER BY user_id ASC, meta_key ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is trusted; values are prepared.

		$links = array();
		foreach ( (array) $rows as $row ) {
			$row_platform = substr( (string) $row['meta_key'], strlen( self::META_PREFIX ) );
			if ( '' === $row_platform || ( '' !== $platform && $row_platform !== $platform ) ) {
				continue;
			}
			$links[] = array(
				'user_id'  => (int) $row['user_id'],
				'platform' => $row_platform,
				'actor_id' => (string) $row['meta_value'],
			);
		}

		return $links;
	}

	/**
	 * Find all user IDs holding an actor on a platform.
	 *
	 * @param string $platform Normalized platform slug.
	 * @param string $actor_id Normalized actor ID.
	 * @return int[] User IDs.
	 */
	private static function find_user_ids( string $platform, string $actor_id ): array {
		$ids = get_users(
			array(
				'meta_key'   => self::meta_key( $platform ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact-match identity lookup.
				'meta_value' => $actor_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 2,
				'blog_id'    => 0,
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}
}
