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
		register_activation_hook( $plugin_file, array( self::class, 'activate_defaults' ) );
	}

	/**
	 * Register the main lifecycle and multisite hooks.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function register_lifecycle_hooks( string $plugin_file ): void {
		register_activation_hook( $plugin_file, array( self::class, 'activate' ) );
		register_deactivation_hook( $plugin_file, array( self::class, 'deactivate' ) );
	}

	/**
	 * Register multisite creation setup at its historical late bootstrap phase.
	 */
	public static function register_new_site_hook(): void {
		// @phpstan-ignore-next-line WordPress stubs in CI omit the optional priority argument.
		add_action( 'wp_initialize_site', array( self::class, 'on_new_site' ), 200 );
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
		self::ensure_action_scheduler_tables();
		self::register_capabilities();
		if ( ! self::ensure_all_tables() ) {
			return;
		}
		if ( ! datamachine_ensure_default_memory_files() ) {
			set_transient( 'datamachine_needs_scaffold', 1, HOUR_IN_SECONDS );
		}

		\DataMachine\Engine\AI\ComposableFileGenerator::regenerate_all();
		datamachine_mark_flow_schedule_reconciliation();
		update_option( 'datamachine_db_version', DATAMACHINE_VERSION, true );
	}

	/**
	 * Initialize Action Scheduler's custom tables for the current site.
	 *
	 * Action Scheduler stores these tables using the current site's prefix, so
	 * network activation must run its schema registration inside each site.
	 */
	public static function ensure_action_scheduler_tables(): void {
		if ( ! class_exists( '\ActionScheduler_StoreSchema' ) || ! class_exists( '\ActionScheduler_LoggerSchema' ) ) {
			return;
		}

		global $wpdb;

		$store_schema  = new \ActionScheduler_StoreSchema();
		$logger_schema = new \ActionScheduler_LoggerSchema();
		$table_names   = array(
			\ActionScheduler_StoreSchema::ACTIONS_TABLE,
			\ActionScheduler_StoreSchema::CLAIMS_TABLE,
			\ActionScheduler_StoreSchema::GROUPS_TABLE,
			\ActionScheduler_LoggerSchema::LOG_TABLE,
		);

		// AS registers the same suffixes on every blog. Remove prior entries so a
		// network activation leaves one registration per table, not one per site.
		$wpdb->tables = array_values( array_diff( $wpdb->tables, $table_names ) );

		$store_schema->init();
		$logger_schema->init();

		try {
			$store_schema->register_tables( ! $store_schema->tables_exist() );
			$logger_schema->register_tables( ! $logger_schema->tables_exist() );
		} finally {
			remove_action( 'action_scheduler_before_schema_update', array( $store_schema, 'update_schema_5_0' ), 10 );
			remove_action( 'action_scheduler_before_schema_update', array( $logger_schema, 'update_schema_3_0' ), 10 );
		}
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
	public static function ensure_all_tables(): bool {
		try {
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
		} catch ( \Throwable $error ) {
			do_action( 'datamachine_log', 'error', 'Data Machine schema convergence threw an exception', array( 'error' => $error->getMessage() ) );
			return false;
		}

		if ( function_exists( 'datamachine_converge_chat_sessions_to_network' ) && ! datamachine_converge_chat_sessions_to_network() ) {
			return false;
		}

		return self::validate_current_schema();
	}

	/**
	 * Validate required current tables, columns, and named indexes after all ensures.
	 */
	public static function validate_current_schema(): bool {
		global $wpdb;

		foreach ( self::current_schema_requirements() as $table_name => $required ) {
			if ( ! \DataMachine\Core\Database\BaseRepository::database_table_exists( $table_name, $wpdb ) ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$column_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ), ARRAY_A );
			$columns     = array_map( static fn( array $row ): string => (string) ( $row['Field'] ?? $row['field'] ?? '' ), is_array( $column_rows ) ? $column_rows : array() );
			if ( ! empty( $wpdb->last_error ) || array_diff( $required['columns'], $columns ) ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$index_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ), ARRAY_A );
			$indexes    = array_unique( array_map( static fn( array $row ): string => (string) ( $row['Key_name'] ?? $row['key_name'] ?? '' ), is_array( $index_rows ) ? $index_rows : array() ) );
			if ( ! empty( $wpdb->last_error ) || array_diff( $required['indexes'], $indexes ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string,array{columns:string[],indexes:string[]}> */
	public static function current_schema_requirements(): array {
		global $wpdb;

		$site    = $wpdb->prefix;
		$network = $wpdb->base_prefix;

		return array(
			$site . 'datamachine_logs'             => array(
				'columns' => array( 'id', 'agent_id', 'user_id', 'level', 'message', 'context', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'idx_agent_time', 'idx_level_time', 'idx_created_at' ),
			),
			$network . 'datamachine_agents'        => array(
				'columns' => array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'instance_key', 'instance_key_hash', 'provisioning_token', 'provisioning_started_at', 'provisioned_at', 'site_scope', 'agent_config', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'agent_identity_scope_hash', 'owner_id', 'site_scope' ),
			),
			$network . 'datamachine_agent_access'  => array(
				'columns' => array( 'id', 'agent_id', 'user_id', 'role', 'granted_at' ),
				'indexes' => array( 'PRIMARY', 'agent_user', 'agent_id', 'user_id', 'role' ),
			),
			$network . 'datamachine_agent_principal_access' => array(
				'columns' => array( 'id', 'agent_id', 'principal_type', 'principal_id', 'role', 'granted_at' ),
				'indexes' => array( 'PRIMARY', 'agent_principal', 'agent_id', 'principal', 'role' ),
			),
			$network . 'datamachine_agent_tokens'  => array(
				'columns' => array( 'token_id', 'agent_id', 'token_hash', 'token_prefix', 'label', 'capabilities', 'last_used_at', 'expires_at', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'token_hash', 'agent_id' ),
			),
			$site . 'datamachine_pipelines'        => array(
				'columns' => array( 'pipeline_id', 'user_id', 'agent_id', 'pipeline_name', 'portable_slug', 'pipeline_config', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'user_id', 'agent_id', 'pipeline_name', 'agent_portable_slug', 'created_at', 'updated_at' ),
			),
			$site . 'datamachine_flows'            => array(
				'columns' => array( 'flow_id', 'pipeline_id', 'user_id', 'agent_id', 'flow_name', 'portable_slug', 'flow_config', 'scheduling_config' ),
				'indexes' => array( 'PRIMARY', 'pipeline_id', 'user_id', 'agent_id', 'pipeline_portable_slug' ),
			),
			$site . 'datamachine_jobs'             => array(
				'columns' => array( 'job_id', 'user_id', 'pipeline_id', 'flow_id', 'source', 'label', 'parent_job_id', 'status', 'engine_data', 'handler_slug', 'idempotency_key', 'request_fingerprint', 'operation_state', 'operation_step_id', 'operation_claimed_at', 'operation_claim_token', 'operation_generation', 'operation_action_id', 'operation_ref_hash', 'operation_effects_begun_at', 'operation_envelope', 'terminal_accounting_state', 'terminal_accounting_owner', 'terminal_accounting_claimed_at', 'terminal_accounting_processed_count', 'created_at', 'completed_at' ),
				'indexes' => array( 'PRIMARY', 'status', 'pipeline_id', 'flow_id', 'source', 'parent_job_id', 'user_id', 'idx_created_at', 'idx_flow_created', 'idx_status_created', 'idx_source_created', 'idx_terminal_accounting', 'idx_idempotency_key', 'idx_operation_ref_hash' ),
			),
			$site . 'datamachine_processed_items'  => array(
				'columns' => array( 'id', 'flow_step_id', 'source_type', 'item_identifier', 'job_id', 'status', 'claim_expires_at', 'claim_token', 'deferral_count', 'last_deferral_job_id', 'deferred_at', 'last_seen_at', 'processed_timestamp' ),
				'indexes' => array( 'PRIMARY', 'flow_source_item', 'flow_step_id', 'source_type', 'job_id', 'status_claim_expires', 'status_deferred_at', 'flow_source_ts' ),
			),
			$site . 'datamachine_batch_items'      => array(
				'columns' => array( 'batch_job_id', 'item_index', 'payload', 'payload_checksum', 'cleanup_context', 'state', 'worklist_token', 'lease_token', 'lease_expires_at', 'child_result_id', 'attempts', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'claimable' ),
			),
			$site . 'datamachine_tracked_items'    => array(
				'columns' => array( 'id', 'namespace', 'item_id', 'item_type', 'state', 'source_ref', 'source_revision', 'source_path', 'source_line', 'output_ref', 'metadata_json', 'first_seen_at', 'last_seen_at', 'last_job_id', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'namespace_item', 'namespace_type_state', 'namespace_state', 'source_ref', 'updated_at' ),
			),
			$site . 'datamachine_post_identity'    => array(
				'columns' => array( 'post_id', 'post_type', 'event_date', 'venue_term_id', 'ticket_url', 'title_hash', 'source_url', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'idx_date_venue', 'idx_date_title', 'idx_ticket_date', 'idx_source_url', 'idx_post_type' ),
			),
			$site . 'datamachine_post_identity_reservations' => array(
				'columns' => array( 'identity_hash', 'post_type_hash', 'meta_key_hash', 'meta_value_hash', 'post_id', 'state', 'attempt_count', 'last_attempt_at', 'last_error_code', 'last_error_message', 'created_at', 'updated_at', 'completed_at' ),
				'indexes' => array( 'PRIMARY', 'post_id' ),
			),
			$site . 'datamachine_bundle_artifacts' => array(
				'columns' => array( 'artifact_record_id', 'agent_id', 'bundle_slug', 'bundle_version', 'artifact_type', 'artifact_id', 'source_path', 'installed_hash', 'current_hash', 'installed_payload', 'local_status', 'installed_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'artifact_identity', 'bundle_slug', 'artifact_type', 'local_status' ),
			),
			$site . 'datamachine_run_metadata'     => array(
				'columns' => array( 'metadata_id', 'job_id', 'metadata_path', 'metadata_value', 'value_type', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'job_path', 'path_value', 'job_id' ),
			),
			$network . 'datamachine_chat_sessions' => array(
				'columns' => array( 'session_id', 'workspace_type', 'workspace_id', 'user_id', 'owner_type', 'owner_key_hash', 'owner_label', 'agent_id', 'title', 'messages', 'metadata', 'provider', 'model', 'provider_response_id', 'mode', 'created_at', 'updated_at', 'last_read_at', 'expires_at', 'transcript_lock_token', 'transcript_lock_expires_at' ),
				'indexes' => array( 'PRIMARY', 'workspace', 'user_id', 'owner', 'agent_id', 'mode', 'user_mode', 'created_at', 'updated_at', 'expires_at', 'transcript_lock_expires_at' ),
			),
			$site . 'datamachine_pending_actions'  => array(
				'columns' => array( 'action_id', 'kind', 'summary', 'preview_data', 'apply_input', 'agent_id', 'agent', 'workspace_type', 'workspace_id', 'created_by', 'creator', 'context', 'metadata', 'status', 'created_at', 'expires_at', 'resolved_at', 'resolved_by', 'resolver', 'resolution_result', 'resolution_error', 'resolution_metadata', 'receipt_nonce', 'receipt_consumed_at', 'receipt_operation', 'receipt_evidence' ),
				'indexes' => array( 'PRIMARY', 'workspace', 'status', 'kind', 'agent_id', 'agent', 'created_by', 'creator', 'resolver', 'expires_at', 'created_at' ),
			),
		);
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
