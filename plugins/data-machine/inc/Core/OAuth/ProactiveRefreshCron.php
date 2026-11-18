<?php
/**
 * Proactive OAuth refresh cron listener bootstrap.
 *
 * @package DataMachine\Core\OAuth
 */

namespace DataMachine\Core\OAuth;

use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Makes sure proactive refresh events always have a listener when cron runs.
 *
 * BaseOAuth2Provider registers its `datamachine_refresh_token_{slug}` handler
 * in its constructor, and providers are only built lazily when something
 * applies the `datamachine_auth_providers` filter. A cron run (wp-cron.php or
 * `wp cron event run`) that reaches a due refresh event without having built
 * the providers fires the hook with no callback. WordPress still treats the
 * event as executed and drops it, which silently ends the refresh chain until
 * the token hard-expires.
 *
 * In cron contexts with a refresh event scheduled, build the registered
 * providers once so each one attaches its handler before events run.
 */
final class ProactiveRefreshCron {

	/**
	 * Cron hook prefix shared with BaseOAuth2Provider::get_cron_hook_name().
	 */
	public const HOOK_PREFIX = 'datamachine_refresh_token_';

	/**
	 * Register the bootstrap hook.
	 */
	public static function register(): void {
		// Providers register their filters on plugins_loaded; cron events run
		// after wp_loaded in both wp-cron.php and WP-CLI.
		add_action( 'wp_loaded', array( self::class, 'ensure_listeners' ), 0 );
	}

	/**
	 * Build auth providers when a refresh event may run in this request.
	 */
	public static function ensure_listeners(): void {
		if ( ! self::is_cron_context() || ! self::has_scheduled_refresh() ) {
			return;
		}

		( new AuthAbilities() )->getAllProviders();
	}

	/**
	 * Whether this request can execute cron events.
	 *
	 * @return bool
	 */
	public static function is_cron_context(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Whether any proactive refresh event is scheduled on the current site.
	 *
	 * @param array|null $crons Cron array to inspect. Defaults to the site's cron array.
	 * @return bool
	 */
	public static function has_scheduled_refresh( ?array $crons = null ): bool {
		if ( null === $crons ) {
			$crons = _get_cron_array();
		}

		foreach ( (array) $crons as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}

			foreach ( array_keys( $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, self::HOOK_PREFIX ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
