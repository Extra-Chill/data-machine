<?php
/**
 * Canonical multisite chat-session convergence.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preserve per-site chat history in the canonical network table.
 *
 * A verified completion marker prevents every per-site schema pass from
 * reconverging the same immutable legacy tables into the network target.
 */
function datamachine_converge_chat_sessions_to_network(): bool {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return true;
	}
	if ( function_exists( 'get_site_option' ) ) {
		$marker = get_site_option( 'datamachine_chat_sessions_network_migrated' );
		if ( is_array( $marker ) && true === ( $marker['verified'] ?? false ) ) {
			return true;
		}
	}

	$result = \DataMachine\Core\Database\Chat\Chat::migrate_per_site_tables_to_network();
	if ( empty( $result['success'] ) || ! empty( $result['missing'] ) ) {
		if ( function_exists( 'delete_site_option' ) ) {
			delete_site_option( 'datamachine_chat_sessions_network_migrated' );
		}
		do_action( 'datamachine_log', 'error', 'Chat session network convergence failed', $result );
		return false;
	}

	if ( ! function_exists( 'update_site_option' ) || ! function_exists( 'get_site_option' ) ) {
		return false;
	}

	$marker = array(
		'verified'    => true,
		'rows_copied' => (int) ( $result['copied'] ?? 0 ),
	);
	update_site_option( 'datamachine_chat_sessions_network_migrated', $marker );
	if ( get_site_option( 'datamachine_chat_sessions_network_migrated' ) !== $marker ) {
		return false;
	}

	return true;
}
