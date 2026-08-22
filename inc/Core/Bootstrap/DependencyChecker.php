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

	public const CHECK_ACTION_SCHEDULER    = 'action_scheduler';
	public const CHECK_FILESYSTEM_WRITES   = 'filesystem_writes';
	public const CHECK_IMAP                = 'imap';
	public const CHECK_WORDPRESS_ABILITIES = 'wordpress_abilities';
	public const CHECK_ZIP_ARCHIVE         = 'zip_archive';

	/** @return array<string,string> */
	private static function checks(): array {
		return array(
			self::CHECK_ACTION_SCHEDULER    => 'Action Scheduler is available.',
			self::CHECK_FILESYSTEM_WRITES   => 'The Data Machine directory is writable.',
			self::CHECK_IMAP                => 'The PHP IMAP extension is available.',
			self::CHECK_WORDPRESS_ABILITIES => 'The WordPress Abilities API is available.',
			self::CHECK_ZIP_ARCHIVE         => 'The PHP Zip extension is available.',
		);
	}

	/**
	 * Run a named dependency/capability check.
	 *
	 * @param string $check Check name.
	 * @return bool True when the named dependency/capability is available.
	 */
	public static function has( string $check ): bool {
		return match ( $check ) {
			self::CHECK_ACTION_SCHEDULER    => self::has_action_scheduler(),
			self::CHECK_FILESYSTEM_WRITES   => self::has_filesystem_writes(),
			self::CHECK_IMAP                => self::has_imap(),
			self::CHECK_WORDPRESS_ABILITIES => self::has_wordpress_abilities(),
			self::CHECK_ZIP_ARCHIVE         => self::has_zip_archive(),
			default                         => false,
		};
	}

	/**
	 * Return a structured result for one named check.
	 *
	 * @return array{available:bool,code:string,message:string}
	 */
	public static function report( string $check ): array {
		$checks = self::checks();
		if ( ! isset( $checks[ $check ] ) ) {
			return array(
				'available' => false,
				'code'      => 'unknown_capability',
				'message'   => 'Unknown dependency or capability check.',
			);
		}

		$available = self::has( $check );

		return array(
			'available' => $available,
			'code'      => $available ? 'available' : 'unavailable',
			'message'   => $available ? $checks[ $check ] : self::unavailable_message( $check ),
		);
	}

	/** @return array<string,array{available:bool,code:string,message:string}> */
	public static function report_all(): array {
		$report = array();
		foreach ( array_keys( self::checks() ) as $check ) {
			$report[ $check ] = self::report( $check );
		}

		return $report;
	}

	private static function unavailable_message( string $check ): string {
		return match ( $check ) {
			self::CHECK_ACTION_SCHEDULER    => 'Action Scheduler is unavailable.',
			self::CHECK_FILESYSTEM_WRITES   => 'The Data Machine directory is not writable.',
			self::CHECK_IMAP                => 'The PHP IMAP extension is unavailable.',
			self::CHECK_WORDPRESS_ABILITIES => 'The WordPress Abilities API is unavailable.',
			self::CHECK_ZIP_ARCHIVE         => 'The PHP Zip extension is unavailable.',
			default                         => 'The dependency or capability is unavailable.',
		};
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
