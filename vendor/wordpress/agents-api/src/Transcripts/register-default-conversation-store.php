<?php
/**
 * Opt-in registration for the default CPT conversation store.
 *
 * The substrate ships the store class dormant. A consumer turns it on with a
 * single filter:
 *
 *     add_filter( 'agents_api_enable_default_conversation_store', '__return_true' );
 *
 * When enabled, this registers the `agents_api_session` post type and provides
 * the store as a *fallback* on `wp_agent_conversation_store` (priority 5), so a
 * host store registered at the default priority always wins. When not enabled,
 * nothing is registered and a vanilla install is unchanged — no post type, no
 * store — preserving the contracts-only default.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Database\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the built-in default conversation store is enabled.
 *
 * @return bool
 */
function agents_api_default_conversation_store_enabled(): bool {
	if ( ! function_exists( 'apply_filters' ) ) {
		return false;
	}
	return (bool) apply_filters( 'agents_api_enable_default_conversation_store', false );
}

if ( function_exists( 'add_action' ) ) {
	add_action(
		'init',
		static function (): void {
			if ( ! agents_api_default_conversation_store_enabled() ) {
				return;
			}
			WP_Agent_Cpt_Conversation_Store::register_post_type();
		},
		5
	);
}

if ( function_exists( 'add_filter' ) ) {
	add_filter(
		'wp_agent_conversation_store',
		static function ( $store ) {
			if ( $store instanceof WP_Agent_Conversation_Store ) {
				return $store;
			}
			if ( ! agents_api_default_conversation_store_enabled() ) {
				return $store;
			}
			return new WP_Agent_Cpt_Conversation_Store();
		},
		5
	);
}
