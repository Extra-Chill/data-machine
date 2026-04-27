<?php
/**
 * Data Machine — Webhook auth v2 migration (#1333).
 *
 * Pre-#1333 webhook auth still allowed legacy v1 scheduling config rows at
 * runtime: `webhook_auth_mode = hmac_sha256`, `webhook_signature_*`, and a
 * singular `webhook_secret`. Runtime webhook consumers normalized those rows
 * repeatedly before they could trust the config shape.
 *
 * This migration normalizes persisted flow scheduling configs once. After it
 * runs, webhook runtime code reads only the canonical v2 shape:
 *
 *   - webhook_auth_mode = bearer|hmac
 *   - webhook_auth      = verifier template config
 *   - webhook_secrets   = array of secret roster entries
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize persisted webhook auth scheduling configs to the canonical v2 shape.
 *
 * Idempotent: gated on `datamachine_webhook_auth_v2_migrated`.
 *
 * @return void
 */
function datamachine_migrate_webhook_auth_v2(): void {
	if ( get_option( 'datamachine_webhook_auth_v2_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_webhook_auth_v2_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL -- Table/column names are internal constants from this migration.
	$rows = $wpdb->get_results( "SELECT flow_id, scheduling_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	$updated = 0;
	foreach ( $rows as $row ) {
		$scheduling_config = json_decode( $row['scheduling_config'] ?? '', true );
		if ( ! is_array( $scheduling_config ) ) {
			continue;
		}

		$normalized = datamachine_normalize_webhook_auth_v2_config( $scheduling_config );
		if ( ! $normalized['changed'] ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'scheduling_config' => wp_json_encode( $normalized['config'] ) ),
			array( 'flow_id' => $row['flow_id'] ),
			array( '%s' ),
			array( '%d' )
		);
		++$updated;
	}

	update_option( 'datamachine_webhook_auth_v2_migrated', true, true );

	if ( $updated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated webhook auth scheduling configs to canonical v2 shape',
			array( 'flows_updated' => $updated )
		);
	}
}

/**
 * Normalize one scheduling config.
 *
 * @param array $scheduling_config Scheduling config.
 * @return array{config:array,changed:bool}
 */
function datamachine_normalize_webhook_auth_v2_config( array $scheduling_config ): array {
	$legacy_mode       = $scheduling_config['webhook_auth_mode'] ?? null;
	$has_legacy_fields = isset( $scheduling_config['webhook_signature_header'] )
		|| isset( $scheduling_config['webhook_signature_format'] )
		|| isset( $scheduling_config['webhook_secret'] );

	if ( 'hmac_sha256' !== $legacy_mode && ! $has_legacy_fields ) {
		return array(
			'config'  => $scheduling_config,
			'changed' => false,
		);
	}

	if ( 'hmac_sha256' !== $legacy_mode ) {
		unset(
			$scheduling_config['webhook_signature_header'],
			$scheduling_config['webhook_signature_format'],
			$scheduling_config['webhook_secret']
		);

		return array(
			'config'  => $scheduling_config,
			'changed' => true,
		);
	}

	$scheduling_config['webhook_auth_mode'] = 'hmac';

	if ( empty( $scheduling_config['webhook_auth'] ) ) {
		$scheduling_config['webhook_auth'] = datamachine_webhook_auth_v1_template(
			(string) ( $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256' ),
			(string) ( $scheduling_config['webhook_signature_format'] ?? 'sha256=hex' )
		);
	}

	if ( empty( $scheduling_config['webhook_secrets'] ) && ! empty( $scheduling_config['webhook_secret'] ) ) {
		$scheduling_config['webhook_secrets'] = array(
			array(
				'id'    => 'current',
				'value' => (string) $scheduling_config['webhook_secret'],
			),
		);
	}

	unset(
		$scheduling_config['webhook_signature_header'],
		$scheduling_config['webhook_signature_format'],
		$scheduling_config['webhook_secret']
	);

	return array(
		'config'  => $scheduling_config,
		'changed' => true,
	);
}

/**
 * Build the canonical v2 verifier template equivalent for legacy v1 fields.
 *
 * @param string $header Signature header name.
 * @param string $format Legacy signature format enum.
 * @return array
 */
function datamachine_webhook_auth_v1_template( string $header, string $format ): array {
	$signature_source = array(
		'header'   => $header,
		'extract'  => array( 'kind' => 'raw' ),
		'encoding' => 'hex',
	);

	switch ( $format ) {
		case 'sha256=hex':
			$signature_source['extract']  = array(
				'kind' => 'prefix',
				'key'  => 'sha256=',
			);
			$signature_source['encoding'] = 'hex';
			break;
		case 'base64':
			$signature_source['encoding'] = 'base64';
			break;
		case 'hex':
		default:
			$signature_source['encoding'] = 'hex';
			break;
	}

	return array(
		'mode'             => 'hmac',
		'algo'             => 'sha256',
		'signed_template'  => '{body}',
		'signature_source' => $signature_source,
		'max_body_bytes'   => \DataMachine\Api\WebhookVerifier::DEFAULT_MAX_BODY_BYTES,
	);
}
