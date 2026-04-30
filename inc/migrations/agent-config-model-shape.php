<?php
/**
 * Data Machine — Migrate stale `agent_config.model.default.*` shape to
 * `agent_config.default_provider` / `agent_config.default_model`.
 *
 * `inc/Engine/Agents/datamachine-register-agents.php` previously persisted newly
 * registered agents with `agent_config = { model: { default: { provider,
 * model } } }`. The reader (`PluginSettings::resolveModelForAgentMode()`)
 * has read `agent_config.mode_models[mode]` plus top-level
 * `agent_config.default_provider` / `agent_config.default_model` since
 * the contexts → modes rename in #1138, and never spoke the
 * `model.default.*` dialect at all.
 *
 * Effect on existing installs: every agent created through
 * `datamachine_register_agent()` (including DM's own default
 * administrator agent and every plugin-registered agent — roadie,
 * events-bot, game-master, etc.) carries a config shape the resolver
 * cannot read. `SendMessageAbility` then trips its
 * `provider_required` / `model_required` guards on the first chat send
 * with no explicit provider/model, surfacing as the user-facing
 * "AI provider is required" error.
 *
 * The writer is fixed in the same change. This migration cleans up the
 * persisted rows so existing installs recover without manual SQL.
 *
 * Idempotent: gated on `datamachine_agent_config_model_shape_migrated`.
 *
 * @package DataMachine
 * @since 0.88.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migrate stale `agent_config.model.default.{provider,model}` rows to
 * top-level `default_provider` / `default_model` keys.
 *
 * - Preserves every other key in `agent_config` (tool_policy,
 *   directive_policy, memory_policy, daily_memory, allowed_redirect_uris, …).
 * - Drops the legacy `model` key after migration.
 * - Skips empty provider/model values so rows fall through to the site
 *   and network defaults instead of being pinned to empty strings.
 * - Network-scoped: agents live on `base_prefix`, so we only need the
 *   one network-scoped table regardless of subsite count.
 *
 * @since 0.88.0
 * @return void
 */
function datamachine_migrate_agent_config_model_shape(): void {
	if ( get_option( 'datamachine_agent_config_model_shape_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$agents_table = $wpdb->base_prefix . 'datamachine_agents';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agents_table ) );
	if ( ! $table_exists ) {
		update_option( 'datamachine_agent_config_model_shape_migrated', true, true );
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results( "SELECT agent_id, agent_config FROM {$agents_table}", ARRAY_A );

	$migrated = 0;

	if ( ! empty( $rows ) ) {
		foreach ( $rows as $row ) {
			$config = json_decode( $row['agent_config'] ?? '', true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( ! isset( $config['model'] ) || ! is_array( $config['model'] ) ) {
				continue;
			}

			$legacy   = isset( $config['model']['default'] ) && is_array( $config['model']['default'] )
				? $config['model']['default']
				: array();
			$provider = isset( $legacy['provider'] ) ? trim( (string) $legacy['provider'] ) : '';
			$model    = isset( $legacy['model'] ) ? trim( (string) $legacy['model'] ) : '';

			unset( $config['model'] );

			if ( '' !== $provider && ! isset( $config['default_provider'] ) ) {
				$config['default_provider'] = $provider;
			}
			if ( '' !== $model && ! isset( $config['default_model'] ) ) {
				$config['default_model'] = $model;
			}

			// Empty array → empty object on the JSON side, so the column
			// stays `{}` rather than `[]` and matches every other code
			// path that writes agent_config.
			$encoded = empty( $config )
				? '{}'
				: wp_json_encode( $config );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$agents_table,
				array( 'agent_config' => $encoded ),
				array( 'agent_id' => (int) $row['agent_id'] ),
				array( '%s' ),
				array( '%d' )
			);

			++$migrated;
		}
	}

	update_option( 'datamachine_agent_config_model_shape_migrated', true, true );

	if ( $migrated > 0 ) {
		do_action(
			'datamachine_log',
			'info',
			'Migrated stale agent_config.model.default.* shape to default_provider/default_model.',
			array(
				'agents_updated' => $migrated,
			)
		);
	}
}
