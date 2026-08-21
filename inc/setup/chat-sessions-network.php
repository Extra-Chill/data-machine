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
 * The completion marker is evidence, not a one-shot guard. Every schema setup
 * pass re-runs the idempotent copy and anti-join verification so rows missed by
 * an interrupted or older deployment are recovered later.
 */
function datamachine_converge_chat_sessions_to_network(): bool {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return true;
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
	if ( $marker !== get_site_option( 'datamachine_chat_sessions_network_migrated' ) ) {
		return false;
	}

	return true;
}
