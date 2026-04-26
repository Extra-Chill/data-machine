<?php
/**
 * Data Machine — Collapse user_message into prompt_queue + queue_mode (#1291).
 *
 * Pre-#1291 AIStep had two physically-separate storage slots feeding
 * the same per-flow user-role message:
 *
 *   - flow_step_config[step_id].user_message   (single string)
 *   - flow_step_config[step_id].prompt_queue   (array of {prompt, added_at})
 *
 * paired with a `queue_enabled` boolean that picked between two access
 * patterns (drain-on-pop when true, peek-without-pop when false).
 *
 * Post-#1291:
 *
 *   - prompt_queue       — array<{prompt, added_at}>      (AI consumer)
 *   - config_patch_queue — array<{patch, added_at}>       (Fetch consumer, untouched here)
 *   - queue_mode         — "drain" | "loop" | "static"    (replaces queue_enabled)
 *
 * The `user_message` field is deleted from AI steps. The
 * `queue_enabled` boolean is deleted from every queueable step.
 *
 * Migration shape mirrors split-queue-payload.php (#1294) and
 * ai-enabled-tools.php (#1216): one-shot, idempotent, gated on a
 * single option, no runtime fallback shim. Per the no-shim rule.
 *
 * Boolean → mode resolution:
 *   queue_enabled === true  → queue_mode = "drain"   (matches today's pop-per-tick)
 *   queue_enabled === false → queue_mode = "static"  (matches today's peek-without-pop;
 *                                                     the multi-entry-queue case is
 *                                                     named explicitly as the manual
 *                                                     stockpile / iterative-dev pattern)
 *
 * AI-step-only handling for `user_message`:
 *   1. prompt_queue empty + user_message non-empty
 *        → seed queue as [{prompt: user_message, added_at: now()}], queue_mode=static
 *   2. prompt_queue non-empty + user_message non-empty
 *        → keep queue as-is, queue_mode=static, drop user_message
 *          (matches existing AIStep precedence: queue head wins; the fallback was
 *          shadowed at runtime), log dropped value at info level for traceability
 *   3. user_message empty
 *        → just unset the dead key; nothing to seed
 *
 * `loop` mode is net-new on both consumers — no flow gets it from
 * migration; opt-in via `flow queue mode loop`.
 *
 * Fetch steps have no `user_message` to migrate (the
 * `config_patch_queue` slot is the only fetch-side storage post-#1294).
 * The migration just resolves `queue_enabled` → `queue_mode` and drops
 * the dead key.
 *
 * @package DataMachine
 * @since 0.85.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replace `user_message` + `queue_enabled` with the unified `queue_mode`
 * enum on every flow step.
 *
 * Idempotent: gated on `datamachine_user_message_collapsed`.
 *
 * @since 0.85.0
 */
function datamachine_migrate_user_message_queue_mode(): void {
	$already_done = get_option( 'datamachine_user_message_collapsed', false );
	if ( $already_done ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'datamachine_flows';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	// phpcs:enable WordPress.DB.PreparedSQL
	if ( ! $table_exists ) {
		update_option( 'datamachine_user_message_collapsed', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $rows ) ) {
		update_option( 'datamachine_user_message_collapsed', true, true );
		return;
	}

	$migrated_flows         = 0;
	$user_messages_seeded   = 0;
	$user_messages_dropped  = 0;
	$queue_modes_resolved   = 0;

	foreach ( $rows as $row ) {
		$flow_config = json_decode( $row['flow_config'], true );
		if ( ! is_array( $flow_config ) ) {
			continue;
		}

		$changed = false;
		foreach ( $flow_config as $step_id => &$step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_type = $step['step_type'] ?? '';

			$has_queue_enabled = array_key_exists( 'queue_enabled', $step );
			$has_user_message  = array_key_exists( 'user_message', $step );

			// Skip steps that touch neither key — nothing to migrate.
			if ( ! $has_queue_enabled && ! $has_user_message ) {
				continue;
			}

			// Resolve queue_mode from queue_enabled (or default to static
			// when only user_message was set without queue toggling).
			$queue_enabled = $has_queue_enabled ? (bool) $step['queue_enabled'] : false;
			$queue_mode    = $queue_enabled ? 'drain' : 'static';

			$step['queue_mode'] = $queue_mode;
			++$queue_modes_resolved;

			// AI-step-only: collapse user_message into prompt_queue.
			if ( 'ai' === $step_type && $has_user_message ) {
				$user_message = is_string( $step['user_message'] ) ? trim( $step['user_message'] ) : '';
				$queue        = isset( $step['prompt_queue'] ) && is_array( $step['prompt_queue'] )
					? $step['prompt_queue']
					: array();

				if ( '' !== $user_message ) {
					if ( empty( $queue ) ) {
						// Seed 1-entry static queue with the legacy user_message.
						$step['prompt_queue'] = array(
							array(
								'prompt'   => $user_message,
								'added_at' => gmdate( 'c' ),
							),
						);
						// Force static so the seeded entry doesn't drain on
						// the next tick — preserves "runs every tick" semantics.
						$step['queue_mode'] = 'static';
						++$user_messages_seeded;
					} else {
						// Both populated: queue head was already winning at
						// runtime (AIStep precedence). Drop user_message and
						// log it for traceability.
						do_action(
							'datamachine_log',
							'info',
							'user_message → prompt_queue migration: dropped user_message that was already shadowed by non-empty prompt_queue head',
							array(
								'flow_id'        => $row['flow_id'],
								'step_id'        => $step_id,
								'queue_depth'    => count( $queue ),
								'dropped_value'  => mb_substr( $user_message, 0, 200 ),
							)
						);
						// Force static so the kept queue head doesn't suddenly
						// start draining if queue_enabled was true previously
						// — preserves observable behaviour ("first entry wins
						// every tick" was the de-facto state once user_message
						// was dropped on the floor).
						$step['queue_mode'] = 'static';
						++$user_messages_dropped;
					}
				}
			}

			// Always strip the dead keys (no runtime fallback shim).
			unset( $step['user_message'] );
			unset( $step['queue_enabled'] );

			$changed = true;
		}
		unset( $step );

		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'flow_config' => wp_json_encode( $flow_config ) ),
				array( 'flow_id' => $row['flow_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			++$migrated_flows;
		}
	}

	update_option( 'datamachine_user_message_collapsed', true, true );

	if ( $migrated_flows > 0 || $queue_modes_resolved > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'user_message → queue_mode collapse migration complete',
			array(
				'flows_updated'         => $migrated_flows,
				'queue_modes_resolved'  => $queue_modes_resolved,
				'user_messages_seeded'  => $user_messages_seeded,
				'user_messages_dropped' => $user_messages_dropped,
			)
		);
	}
}
