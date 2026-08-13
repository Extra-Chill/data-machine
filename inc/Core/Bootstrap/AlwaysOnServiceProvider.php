<?php
/**
 * Always-on WordPress host service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Registers host integrations required even when the full runtime is gated off.
 */
final class AlwaysOnServiceProvider {

	/**
	 * Register Action Scheduler policies and lightweight WordPress hooks.
	 */
	public static function register_scheduler(): void {
		$plugin_root = dirname( __DIR__, 3 );

		if ( ! class_exists( 'ActionScheduler' ) ) {
			require_once $plugin_root . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
		}

		\DataMachine\Core\ActionScheduler\LogPersistencePolicy::register();
		\DataMachine\Core\Database\CanonicalPersistencePolicy::register();
		\DataMachine\Engine\Tasks\RecurringScheduler::registerGenerationFence();

		add_action(
			'action_scheduler_init',
			array( \DataMachine\Core\ActionScheduler\GroupRegistrar::class, 'ensureDataMachineGroup' ),
			0
		);

		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			add_action( 'wp_loaded', 'datamachine_skip_action_scheduler_migration_during_install', 0 );
		}
	}

	/**
	 * Register lightweight WordPress host hooks.
	 */
	public static function register_wordpress_hooks(): void {
		add_filter( 'upload_mimes', 'datamachine_allow_json_upload' );
		add_action( 'update_option_datamachine_settings', array( \DataMachine\Core\PluginSettings::class, 'clearCache' ) );
	}

	/**
	 * Prevent Action Scheduler migration during WordPress test installation.
	 */
	public static function skip_action_scheduler_migration_during_install(): void {
		if ( ! class_exists( '\Action_Scheduler\Migration\Controller' ) ) {
			return;
		}

		remove_action( 'wp_loaded', array( \Action_Scheduler\Migration\Controller::instance(), 'schedule_migration' ) );
	}

	/**
	 * Permit JSON uploads used by Data Machine import surfaces.
	 *
	 * @param array<string, string> $mimes Allowed MIME types.
	 * @return array<string, string>
	 */
	public static function allow_json_upload( array $mimes ): array {
		$mimes['json'] = 'application/json';
		return $mimes;
	}
}
