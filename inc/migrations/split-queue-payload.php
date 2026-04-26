<?php
/**
 * Data Machine — Split queue payload migration (#1292).
 *
 * Pre-#1292, both AIStep and FetchStep consumed from the same
 * `prompt_queue` storage slot, but with different payload semantics:
 * AI expected plain string prompts; Fetch expected JSON-encoded
 * config-patch objects in the `prompt` field. The schema lied at
 * write time, validation was implicit via the consumer step type,
 * and tooling could not tell the two payload shapes apart.
 *
 * After #1292, the slot is split:
 *
 *   - prompt_queue        — array<{prompt:string, added_at}>      (AI only)
 *   - config_patch_queue  — array<{patch:array, added_at}>        (Fetch only)
 *
 * This migration walks every flow_config and, for each fetch step,
 * decodes the JSON-encoded `prompt` field of every prompt_queue entry
 * back into an object and moves it to a new `config_patch_queue`
 * entry under the `patch` field. The fetch step's `prompt_queue` is
 * then unset (not just emptied — fetch steps no longer have a
 * prompt_queue at all under the post-split shape).
 *
 * Idempotent. Gated on the `datamachine_queue_payload_split_migrated`
 * option. Misshaped entries (a `prompt` string that does not
 * JSON-decode to an object) are logged at level=warning with the
 * flow_id, step_id and a short preview, then skipped — they would
 * have silently no-op'd at runtime under the pre-split code anyway,
 * so dropping them on the floor is the same observable behaviour.
 *
 * AI-step prompt_queue entries are left untouched. Other step types
 * (publish/upsert/system_task/etc.) are also untouched — they have no
 * queueable consumer and any prompt_queue rows on them are stale.
 *
 * @package DataMachine
 * @since 0.84.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Move fetch-step queue entries from `prompt_queue` (with JSON-encoded
 * `prompt` strings) to a new `config_patch_queue` slot (with decoded
 * `patch` arrays).
 *
 * Idempotent: gated on `datamachine_queue_payload_split_migrated`.
 *
 * @since 0.84.0
 */
function datamachine_migrate_split_queue_payload(): void {
	$already_done = get_option( 'datamachine_queue_payload_split_migrated', false );
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
		update_option( 'datamachine_queue_payload_split_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
	$rows = $wpdb->get_results( "SELECT flow_id, flow_config FROM {$table}", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL

	if ( empty( $rows ) ) {
		update_option( 'datamachine_queue_payload_split_migrated', true, true );
		return;
	}

	$migrated_flows   = 0;
	$migrated_entries = 0;
	$skipped_entries  = 0;

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

			if ( 'fetch' !== ( $step['step_type'] ?? '' ) ) {
				continue;
			}

			$legacy_queue = $step['prompt_queue'] ?? null;
			if ( ! is_array( $legacy_queue ) || empty( $legacy_queue ) ) {
				// Nothing to migrate; ensure the prompt_queue field is
				// gone so fetch steps have a uniform shape post-split.
				if ( array_key_exists( 'prompt_queue', $step ) ) {
					unset( $step['prompt_queue'] );
					$changed = true;
				}
				continue;
			}

			$existing_patch_queue = $step['config_patch_queue'] ?? array();
			if ( ! is_array( $existing_patch_queue ) ) {
				$existing_patch_queue = array();
			}

			$migrated_for_step = array();
			foreach ( $legacy_queue as $idx => $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['prompt'] ) ) {
					++$skipped_entries;
					do_action(
						'datamachine_log',
						'warning',
						'Split queue payload migration: skipped malformed prompt_queue entry on fetch step',
						array(
							'flow_id' => $row['flow_id'],
							'step_id' => $step_id,
							'index'   => $idx,
							'preview' => is_string( $entry ) ? substr( $entry, 0, 80 ) : gettype( $entry ),
						)
					);
					continue;
				}

				$decoded = json_decode( (string) $entry['prompt'], true );
				if ( ! is_array( $decoded ) || empty( $decoded ) ) {
					++$skipped_entries;
					do_action(
						'datamachine_log',
						'warning',
						'Split queue payload migration: prompt_queue entry on fetch step is not a JSON object — skipped',
						array(
							'flow_id' => $row['flow_id'],
							'step_id' => $step_id,
							'index'   => $idx,
							'preview' => substr( (string) $entry['prompt'], 0, 80 ),
						)
					);
					continue;
				}

				$migrated_for_step[] = array(
					'patch'    => $decoded,
					'added_at' => $entry['added_at'] ?? gmdate( 'c' ),
				);
				++$migrated_entries;
			}

			$step['config_patch_queue'] = array_merge( $existing_patch_queue, $migrated_for_step );
			unset( $step['prompt_queue'] );
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

	update_option( 'datamachine_queue_payload_split_migrated', true, true );

	if ( $migrated_flows > 0 || $skipped_entries > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Split queue payload migration complete',
			array(
				'flows_updated'    => $migrated_flows,
				'entries_migrated' => $migrated_entries,
				'entries_skipped'  => $skipped_entries,
			)
		);
	}
}
