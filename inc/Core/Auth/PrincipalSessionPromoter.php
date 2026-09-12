<?php
/**
 * Principal session promotion helpers.
 *
 * @package DataMachine\Core\Auth
 */

namespace DataMachine\Core\Auth;

use DataMachine\Abilities\Chat\ChatTranscriptOwner;
use DataMachine\Core\Database\Chat\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Moves anonymous/browser-owned transcripts onto a logged-in user principal.
 */
final class PrincipalSessionPromoter {

	/**
	 * Promote browser-principal chat sessions to a WordPress user owner.
	 */
	public static function promote_browser_to_user( string $browser_principal_id, int $user_id ): int {
		if ( '' === $browser_principal_id || $user_id <= 0 ) {
			return 0;
		}

		$old = ChatTranscriptOwner::resolve_for_request(
			array(
				'transcript_owner' => array(
					'type' => 'browser',
					'key'  => $browser_principal_id,
				),
			),
			0
		);
		if ( is_wp_error( $old ) ) {
			return 0;
		}

		$user = ChatTranscriptOwner::user_owner( $user_id );

		global $wpdb;
		$table = Chat::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional ownership migration for a completed login flow.
		$result = $wpdb->update(
			$table,
			array(
				'user_id'        => $user_id,
				'owner_type'     => $user['owner_type'],
				'owner_key_hash' => $user['owner_key_hash'],
				'owner_label'    => $user['owner_label'],
			),
			array(
				'owner_type'     => $old['owner_type'],
				'owner_key_hash' => $old['owner_key_hash'],
			),
			array( '%d', '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);

		return false === $result ? 0 : (int) $result;
	}
}
