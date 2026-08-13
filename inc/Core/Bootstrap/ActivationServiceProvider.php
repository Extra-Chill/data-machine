<?php
/**
 * Plugin lifecycle service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Owns activation, deactivation, and multisite setup behavior.
 */
final class ActivationServiceProvider {

	/**
	 * Register lifecycle hooks in their established order.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function register_defaults_hook( string $plugin_file ): void {
		register_activation_hook( $plugin_file, 'datamachine_activate_plugin_defaults' );
	}

	/**
	 * Register the main lifecycle and multisite hooks.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function register_lifecycle_hooks( string $plugin_file ): void {
		register_activation_hook( $plugin_file, 'datamachine_activate_plugin' );
		register_deactivation_hook( $plugin_file, 'datamachine_deactivate_plugin' );
	}

	/**
	 * Register multisite creation setup at its historical late bootstrap phase.
	 */
	public static function register_new_site_hook(): void {
		// @phpstan-ignore-next-line WordPress stubs in CI omit the optional priority argument.
		add_action( 'wp_initialize_site', 'datamachine_on_new_site', 200 );
	}

	/**
	 * Initialize default settings across the activation scope.
	 *
	 * @param bool $network_wide Whether activation is network-wide.
	 */
	public static function activate_defaults( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'activate_defaults_for_site' ) );
			return;
		}

		self::activate_defaults_for_site();
	}

	/**
	 * Set default settings for one site.
	 */
	public static function activate_defaults_for_site(): void {
		add_option(
			'datamachine_settings',
			array(
				'disabled_tools'              => array(),
				'enabled_pages'               => array(
					'pipelines' => true,
					'jobs'      => true,
					'logs'      => true,
					'settings'  => true,
				),
				'site_context_enabled'        => true,
				'cleanup_job_data_on_failure' => true,
			)
		);
	}

	/**
	 * Activate database and filesystem setup across the activation scope.
	 *
	 * @param bool $network_wide Whether activation is network-wide.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::create_network_agent_tables();

		if ( is_multisite() && $network_wide ) {
			self::for_each_site( array( self::class, 'activate_for_site' ) );

			if ( function_exists( 'datamachine_migrate_chat_sessions_to_network' ) ) {
				datamachine_migrate_chat_sessions_to_network();
			}
			return;
		}

		self::activate_for_site();
	}

	/**
	 * Remove capabilities and scheduled maintenance on deactivation.
	 */
	public static function deactivate(): void {
		self::remove_capabilities();

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( 'datamachine_cleanup_stale_claims', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_failed_jobs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_completed_jobs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_logs', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_processed_items', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_as_actions', array(), 'datamachine-maintenance' );
		as_unschedule_all_actions( 'datamachine_cleanup_old_files', array(), 'datamachine-files' );
		as_unschedule_all_actions( 'datamachine_cleanup_chat_sessions', array(), 'datamachine-chat' );
	}

	/**
	 * Run setup for one site.
	 */
	public static function activate_for_site(): void {
		self::register_capabilities();
		self::ensure_all_tables();

		if ( ! datamachine_ensure_default_memory_files() ) {
			set_transient( 'datamachine_needs_scaffold', 1, HOUR_IN_SECONDS );
		}

		\DataMachine\Engine\AI\ComposableFileGenerator::regenerate_all();
		datamachine_mark_flow_schedule_reconciliation();
		update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
	}

	/**
	 * Create network-scoped agent tables.
	 */
	public static function create_network_agent_tables(): void {
		\DataMachine\Core\Database\Agents\Agents::create_table();
		\DataMachine\Core\Database\Agents\Agents::ensure_identity_scope_schema();
		\DataMachine\Core\Database\Agents\Agents::ensure_site_scope_column();
		\DataMachine\Core\Database\Agents\AgentAccess::create_table();
		\DataMachine\Core\Database\Agents\AgentTokens::create_table();
	}

	/**
	 * Create or update every Data Machine database table.
	 */
	public static function ensure_all_tables(): void {
		\DataMachine\Core\Database\Logs\LogRepository::create_table();
		self::create_network_agent_tables();

		$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
		$db_pipelines->create_table();
		$db_pipelines->migrate_columns();

		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$db_flows->create_table();
		$db_flows->migrate_columns();

		$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		$db_jobs->create_table();

		$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		$db_processed_items->create_table();
		\DataMachine\Core\Database\BatchItems\BatchItems::create_table();

		$db_tracked_items = new \DataMachine\Core\Database\TrackedItems\TrackedItems();
		$db_tracked_items->create_table();

		$db_identity_index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
		$db_identity_index->create_table();
		\DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations::create_table();
		\DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts::create_table();
		\DataMachine\Core\Database\RunMetadata\RunMetadata::create_table();

		\DataMachine\Core\Database\Chat\Chat::create_table();
		\DataMachine\Core\Database\Chat\Chat::ensure_owner_columns();
		\DataMachine\Core\Database\Chat\Chat::ensure_mode_column();
		\DataMachine\Core\Database\Chat\Chat::ensure_workspace_columns();
		\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();
		\DataMachine\Core\Database\Chat\Chat::ensure_last_read_at_column();
		\DataMachine\Core\Database\Chat\Chat::ensure_transcript_lock_columns();
		\DataMachine\Engine\AI\Actions\PendingActionStore::create_table();
	}

	/**
	 * Register Data Machine capabilities on roles.
	 */
	public static function register_capabilities(): void {
		$role_capabilities = array(
			'administrator' => array( 'datamachine_manage_agents', 'datamachine_manage_flows', 'datamachine_manage_settings', 'datamachine_view_analytics', 'datamachine_chat', 'datamachine_use_tools', 'datamachine_view_logs', 'datamachine_create_own_agent' ),
			'editor'        => array( 'datamachine_chat', 'datamachine_use_tools', 'datamachine_view_logs', 'datamachine_create_own_agent' ),
			'author'        => array( 'datamachine_chat', 'datamachine_use_tools', 'datamachine_create_own_agent' ),
			'contributor'   => array( 'datamachine_chat', 'datamachine_create_own_agent' ),
			'subscriber'    => array( 'datamachine_chat', 'datamachine_create_own_agent' ),
		);

		foreach ( $role_capabilities as $role_name => $capabilities ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Remove Data Machine capabilities from roles.
	 */
	public static function remove_capabilities(): void {
		$capabilities = array( 'datamachine_manage_agents', 'datamachine_manage_flows', 'datamachine_manage_settings', 'datamachine_view_analytics', 'datamachine_chat', 'datamachine_use_tools', 'datamachine_view_logs', 'datamachine_create_own_agent' );

		foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Run a callback for every site on the network.
	 *
	 * @param callable $callback Site callback.
	 */
	public static function for_each_site( callable $callback ): void {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$callback();
			restore_current_blog();
		}
	}

	/**
	 * Initialize a newly-created site when Data Machine is network-active.
	 *
	 * @param \WP_Site $new_site New site object.
	 */
	public static function on_new_site( \WP_Site $new_site ): void {
		if ( ! is_plugin_active_for_network( plugin_basename( dirname( __DIR__, 3 ) . '/data-machine.php' ) ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::activate_defaults_for_site();
		self::activate_for_site();
		restore_current_blog();
	}
}
