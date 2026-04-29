<?php
/**
 * Data Machine — Migrate legacy settings model keys to `mode_models`.
 *
 * The execution-mode model setting was renamed from earlier
 * `context_models` / `agent_models` shapes to `mode_models`, but existing
 * installs can still carry the old keys in datamachine_settings and
 * datamachine_network_settings. New readers only consult `mode_models`, so
 * those installs fall through to default_model and pipeline jobs run on the
 * wrong model.
 *
 * @package DataMachine
 * @since 0.99.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate site and network model settings to the canonical `mode_models` key.
 *
 * Idempotent: gated on `datamachine_settings_mode_models_migrated`.
 *
 * @since 0.99.0
 * @return void
 */
function datamachine_migrate_settings_mode_models(): void {
	if ( get_option( 'datamachine_settings_mode_models_migrated', false ) ) {
		return;
	}

	$site_settings = get_option( 'datamachine_settings', array() );
	if ( is_array( $site_settings ) ) {
		update_option( 'datamachine_settings', datamachine_migrate_settings_mode_models_array( $site_settings ), true );
	}

	$network_settings = get_site_option( 'datamachine_network_settings', array() );
	if ( is_array( $network_settings ) ) {
		update_site_option( 'datamachine_network_settings', datamachine_migrate_settings_mode_models_array( $network_settings ) );
	}

	update_option( 'datamachine_settings_mode_models_migrated', true, true );
}

/**
 * Normalize a settings array to canonical mode_models storage.
 *
 * @since 0.99.0
 * @param array $settings Raw settings array.
 * @return array Settings array with legacy model keys removed.
 */
function datamachine_migrate_settings_mode_models_array( array $settings ): array {
	$legacy = array();

	if ( isset( $settings['context_models'] ) && is_array( $settings['context_models'] ) ) {
		$legacy = $settings['context_models'];
	} elseif ( isset( $settings['agent_models'] ) && is_array( $settings['agent_models'] ) ) {
		$legacy = $settings['agent_models'];
	}

	if ( empty( $settings['mode_models'] ) && ! empty( $legacy ) ) {
		$settings['mode_models'] = datamachine_sanitize_settings_mode_models( $legacy );
	}

	unset( $settings['context_models'], $settings['agent_models'] );

	return $settings;
}

/**
 * Sanitize persisted mode model entries without filtering extension modes.
 *
 * @since 0.99.0
 * @param array $mode_models Raw mode model map.
 * @return array Sanitized mode model map.
 */
function datamachine_sanitize_settings_mode_models( array $mode_models ): array {
	$sanitized = array();

	foreach ( $mode_models as $mode => $config ) {
		if ( ! is_array( $config ) ) {
			continue;
		}

		$mode = sanitize_key( (string) $mode );
		if ( '' === $mode ) {
			continue;
		}

		$sanitized[ $mode ] = array(
			'provider' => sanitize_text_field( $config['provider'] ?? '' ),
			'model'    => sanitize_text_field( $config['model'] ?? '' ),
		);
	}

	return $sanitized;
}
