<?php
/**
 * Data Machine — chat-sessions network-wide migration.
 *
 * Chat sessions moved from per-site storage (`$wpdb->prefix`) to network-wide
 * storage (`$wpdb->base_prefix`) so a user's chat history follows them across
 * every subsite, consistent with the network-scoped agent identity tables.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Union legacy per-site chat session tables into the single network table.
 *
 * Runs once via the version-gated deferred migration runtime. The underlying
 * copy is idempotent (INSERT IGNORE on the session_id primary key), so this is
 * safe to call more than once and on single-site installs (where it no-ops).
 *
 * Guarded by a network option so the (multisite-wide) union runs a single time
 * rather than re-scanning every subsite on every per-site deferred-migration
 * pass.
 */
function datamachine_migrate_chat_sessions_to_network(): void {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return;
	}

	// One network-wide flag — the union spans all subsites, so it should not
	// re-run per-site. Stored via site options so it lives at the network level.
	if ( function_exists( 'get_site_option' ) && get_site_option( 'datamachine_chat_sessions_network_migrated' ) ) {
		return;
	}

	\DataMachine\Core\Database\Chat\Chat::migrate_per_site_tables_to_network();

	if ( function_exists( 'update_site_option' ) ) {
		update_site_option( 'datamachine_chat_sessions_network_migrated', 1 );
	}
}
