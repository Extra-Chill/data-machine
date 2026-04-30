<?php
/**
 * Migrate legacy ai-http-client provider keys into Connectors settings.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate the old shared ai-http-client provider-key option to Connectors.
 *
 * `chubes_ai_provider_api_keys` previously repaired serialized values at runtime.
 * The wp-ai-client path stores per-provider API keys in WordPress Connectors
 * settings instead, so normalize once and copy keys into the connector-shaped
 * option names.
 *
 * @since next
 * @return void
 */
function datamachine_migrate_ai_provider_keys_to_connectors(): void {
	if ( get_option( 'datamachine_ai_provider_keys_migrated', false ) ) {
		return;
	}

	$legacy = get_site_option( 'chubes_ai_http_shared_api_keys', array() );
	if ( is_string( $legacy ) && '' !== $legacy ) {
		$normalized = maybe_unserialize( $legacy );
		if ( is_array( $normalized ) ) {
			$legacy = $normalized;
		}
	}

	if ( is_array( $legacy ) ) {
		foreach ( $legacy as $provider => $api_key ) {
			$provider = sanitize_key( (string) $provider );
			$api_key  = is_scalar( $api_key ) ? (string) $api_key : '';

			if ( '' === $provider || '' === $api_key ) {
				continue;
			}

			$setting_name = 'connectors_ai_' . str_replace( '-', '_', $provider ) . '_api_key';
			if ( '' === (string) get_option( $setting_name, '' ) ) {
				update_option( $setting_name, $api_key, true );
			}
		}
	}

	update_option( 'datamachine_ai_provider_keys_migrated', true, true );
}
