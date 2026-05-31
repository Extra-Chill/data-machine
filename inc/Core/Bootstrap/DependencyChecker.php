<?php
/**
 * Central dependency and capability checks.
 *
 * @package DataMachine\Core\Bootstrap
 * @since   0.138.0
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Provides named bootstrap checks for host/runtime capabilities.
 */
class DependencyChecker {

	public const CHECK_AGENTS_API_ACCESS_STORE = 'agents_api_access_store';
	public const CHECK_ACTION_SCHEDULER        = 'action_scheduler';
	public const CHECK_FILESYSTEM_WRITES       = 'filesystem_writes';
	public const CHECK_IMAP                    = 'imap';
	public const CHECK_PENDING_ACTION_OBSERVER = 'pending_action_observer';
	public const CHECK_WORDPRESS_ABILITIES     = 'wordpress_abilities';
	public const CHECK_ZIP_ARCHIVE             = 'zip_archive';

	/**
	 * Run a named dependency/capability check.
	 *
	 * @param string $check Check name.
	 * @return bool True when the named dependency/capability is available.
	 */
	public static function has( string $check ): bool {
		return match ( $check ) {
			self::CHECK_AGENTS_API_ACCESS_STORE => self::has_agents_api_access_store_contracts(),
			self::CHECK_ACTION_SCHEDULER        => self::has_action_scheduler(),
			self::CHECK_FILESYSTEM_WRITES       => self::has_filesystem_writes(),
			self::CHECK_IMAP                    => self::has_imap(),
			self::CHECK_PENDING_ACTION_OBSERVER => self::has_pending_action_observer_contract(),
			self::CHECK_WORDPRESS_ABILITIES     => self::has_wordpress_abilities(),
			self::CHECK_ZIP_ARCHIVE             => self::has_zip_archive(),
			default                             => false,
		};
	}

	/**
	 * Determine whether the Agents API access-store contracts are available.
	 *
	 * @return bool True when the adapter can safely register.
	 */
	public static function has_agents_api_access_store_contracts(): bool {
		return interface_exists( 'WP_Agent_Access_Store' ) && interface_exists( 'WP_Agent_Principal_Access_Store' );
	}

	/**
	 * Determine whether Action Scheduler is available.
	 *
	 * @return bool True when Action Scheduler is loaded.
	 */
	public static function has_action_scheduler(): bool {
		return class_exists( 'ActionScheduler' ) || function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Determine whether Data Machine can write to its plugin directory by default.
	 *
	 * @param string|null $path Optional path to check.
	 * @return bool True when the path is writable.
	 */
	public static function has_filesystem_writes( ?string $path = null ): bool {
		$path ??= defined( 'DATAMACHINE_PATH' ) ? DATAMACHINE_PATH : dirname( __DIR__, 3 );

		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $path );
		}

		return false;
	}

	/**
	 * Determine whether IMAP support is available.
	 *
	 * @return bool True when the PHP IMAP extension functions are available.
	 */
	public static function has_imap(): bool {
		return function_exists( 'imap_open' );
	}

	/**
	 * Determine whether the Agents API pending-action observer contract is available.
	 *
	 * @return bool True when observer implementations can safely register.
	 */
	public static function has_pending_action_observer_contract(): bool {
		return interface_exists( '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer' );
	}

	/**
	 * Determine whether the WordPress Abilities API is available.
	 *
	 * @return bool True when WordPress abilities can be registered/resolved.
	 */
	public static function has_wordpress_abilities(): bool {
		return class_exists( 'WP_Ability' ) && class_exists( 'WP_Abilities_Registry' );
	}

	/**
	 * Determine whether ZipArchive support is available.
	 *
	 * @return bool True when the PHP Zip extension is available.
	 */
	public static function has_zip_archive(): bool {
		return class_exists( 'ZipArchive' );
	}
}
