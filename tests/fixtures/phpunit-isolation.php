<?php
/**
 * Shared setup for PHPUnit files executed in independent WordPress processes.
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'datamachine_test_prepare_site' ) ) {

	/**
	 * Re-establish the plugin contract required by an isolated integration file.
	 */
	function datamachine_test_prepare_site(): void {
		static $activated = false;

		if ( class_exists( '\DataMachine\Core\FilesRepository\DirectoryManager' ) ) {
			\DataMachine\Core\FilesRepository\DirectoryManager::reset_ensure_flag();
		}
		delete_option( 'datamachine_settings' );
		if ( ! $activated ) {
			delete_option( 'datamachine_db_version' );
			datamachine_activate_for_site();
			$activated = true;
		}
		datamachine_test_clear_runtime_rows();
		datamachine_test_reset_scheduler();
		if ( function_exists( 'datamachine_register_scaffold_generators' ) ) {
			datamachine_register_scaffold_generators();
		}
		datamachine_register_capabilities();
	}
}

if ( ! function_exists( 'datamachine_test_clear_runtime_rows' ) ) {

	/**
	 * Remove only plugin-owned rows; WordPress factory data remains intact.
	 */
	function datamachine_test_clear_runtime_rows(): void {
		global $wpdb;
		$agent_access_table = $wpdb->base_prefix . \DataMachine\Core\Database\Agents\AgentAccess::TABLE_NAME;

		$tables = array(
			$wpdb->base_prefix . \DataMachine\Core\Database\Agents\Agents::TABLE_NAME,
			$agent_access_table,
			$wpdb->base_prefix . \DataMachine\Core\Database\Agents\AgentTokens::TABLE_NAME,
			$wpdb->prefix . 'datamachine_pipelines',
			$wpdb->prefix . 'datamachine_flows',
			$wpdb->prefix . 'datamachine_jobs',
			$wpdb->prefix . 'datamachine_processed_items',
			$wpdb->prefix . 'datamachine_batch_items',
			$wpdb->prefix . 'datamachine_tracked_items',
			$wpdb->prefix . 'datamachine_post_identity_index',
			$wpdb->prefix . 'datamachine_post_identity_reservations',
			$wpdb->prefix . 'datamachine_pending_actions',
		);

		foreach ( $tables as $table ) {
			if ( ! \DataMachine\Core\Database\BaseRepository::database_table_exists( $table, $wpdb ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}
}

if ( ! function_exists( 'datamachine_test_reset_scheduler' ) ) {

	/**
	 * Remove plugin maintenance actions left by another test method.
	 */
	function datamachine_test_reset_scheduler(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$hooks = array(
			\DataMachine\Api\Flows\FlowScheduling::FLOW_HOOK,
			\DataMachine\Core\DirectJobEnqueuer::HOOK,
			\DataMachine\Abilities\Engine\PipelineBatchScheduler::BATCH_HOOK,
			\DataMachine\Engine\Tasks\TaskScheduler::BATCH_HOOK,
			'datamachine_task_retry',
			\DataMachine\Engine\AI\AIConcurrencyBackpressure::RESUME_HOOK,
			'datamachine_cleanup_stale_claims',
			'datamachine_cleanup_failed_jobs',
			'datamachine_cleanup_completed_jobs',
			'datamachine_cleanup_logs',
			'datamachine_cleanup_processed_items',
			'datamachine_cleanup_as_actions',
			'datamachine_cleanup_old_files',
			'datamachine_cleanup_chat_sessions',
		);

		foreach ( $hooks as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}
}

if ( ! function_exists( 'datamachine_test_reset_abilities' ) ) {

	/**
	 * Reset only test-owned ability registrations without weakening API checks.
	 *
	 * @param string[] $slugs Ability slugs owned by the calling fixture.
	 */
	function datamachine_test_reset_abilities( array $slugs ): void {
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$registry = \WP_Abilities_Registry::get_instance();
		foreach ( $slugs as $slug ) {
			if ( $registry->is_registered( $slug ) ) {
				$registry->unregister( $slug );
			}
		}
	}
}

if ( ! function_exists( 'datamachine_test_prepare_uploads' ) ) {

	/**
	 * Establish the upload root used by file-layer integration tests.
	 */
	function datamachine_test_prepare_uploads(): string {
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/datamachine-files' );
		return $uploads['basedir'] . '/datamachine-files';
	}
}
