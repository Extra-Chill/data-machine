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
	$table      = $wpdb->prefix . 'datamachine_flows';
	$repository = new \DataMachine\Core\Database\Flows\Flows();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table name; one-time migration.
	$flows = $wpdb->get_results( "SELECT flow_id, agent_id FROM {$table} WHERE agent_id > 0", ARRAY_A );
	if ( ! is_array( $flows ) ) {
		return;
	}

	foreach ( $flows as $flow ) {
		$flow_id = absint( $flow['flow_id'] ?? 0 );
		$agent_id = absint( $flow['agent_id'] ?? 0 );
		if ( $flow_id <= 0 || $agent_id <= 0 ) {
			continue;
		}

		$handled = false;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$expected_json = $repository->get_flow_config_json( $flow_id );
			$config        = null === $expected_json ? null : json_decode( $expected_json, true );
			if ( ! is_array( $config ) ) {
				$handled = true;
				break;
			}

			$changed = datamachine_mark_legacy_email_flow_auth_config( $config, $flow_id, $agent_id );
			if ( ! $changed ) {
				$handled = true;
				break;
			}
			if ( $repository->compare_and_swap_flow_config( $flow_id, $expected_json, $config ) ) {
				$handled = true;
				break;
			}
		}
		if ( ! $handled ) {
			return;
		}
	}

	update_option( 'datamachine_legacy_email_flow_auth_migrated', 1, true );
}

/**
 * Add exact legacy markers to one decoded flow config.
 *
 * @param array<string,mixed> $config Flow config, mutated by reference.
 */
function datamachine_mark_legacy_email_flow_auth_config( array &$config, int $flow_id, int $agent_id ): bool {
	$changed = false;
	foreach ( $config as $flow_step_id => &$step ) {
		if ( ! is_array( $step ) || ! in_array( 'email', (array) ( $step['handler_slugs'] ?? array() ), true ) ) {
			continue;
		}
		$email_config = $step['handler_configs']['email'] ?? null;
		if ( ! is_array( $email_config ) || ! empty( $email_config['auth_ref'] ) || ! empty( $email_config['_legacy_default_auth'] ) ) {
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

	return $changed;
}
