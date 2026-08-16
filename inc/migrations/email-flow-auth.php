<?php
/**
 * Persist authorization markers for legacy agent-owned email flows.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mark existing agent-owned email fetch handlers that historically used the
 * site default mailbox without an auth ref.
 *
 * New flows never pass through this migration. The signed marker binds legacy
 * compatibility to the exact persisted flow, step, and agent principal.
 */
function datamachine_migrate_legacy_email_flow_auth(): void {
	if ( get_option( 'datamachine_legacy_email_flow_auth_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table name; one-time migration.
	$flows = $wpdb->get_results( "SELECT flow_id, agent_id, flow_config FROM {$table} WHERE agent_id > 0", ARRAY_A );
	if ( ! is_array( $flows ) ) {
		return;
	}

	foreach ( $flows as $flow ) {
		$flow_id = absint( $flow['flow_id'] ?? 0 );
		$agent_id = absint( $flow['agent_id'] ?? 0 );
		$config   = json_decode( (string) ( $flow['flow_config'] ?? '' ), true );
		if ( $flow_id <= 0 || $agent_id <= 0 || ! is_array( $config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $config as $flow_step_id => &$step ) {
			if ( ! is_array( $step ) || ! in_array( 'email', (array) ( $step['handler_slugs'] ?? array() ), true ) ) {
				continue;
			}
			$email_config = $step['handler_configs']['email'] ?? null;
			if ( ! is_array( $email_config ) || ! empty( $email_config['auth_ref'] ) ) {
				continue;
			}

			$step['handler_configs']['email']['_legacy_default_auth'] = \DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth::legacy_default_marker(
				$flow_id,
				(string) $flow_step_id,
				$agent_id
			);
			$changed = true;
		}
		unset( $step );

		if ( ! $changed ) {
			continue;
		}

		$updated = $wpdb->update(
			$table,
			array( 'flow_config' => wp_json_encode( $config ) ),
			array( 'flow_id' => $flow_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return;
		}
	}

	update_option( 'datamachine_legacy_email_flow_auth_migrated', 1, true );
}
