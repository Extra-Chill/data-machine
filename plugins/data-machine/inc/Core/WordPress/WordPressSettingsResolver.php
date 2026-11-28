<?php
/**
 * WordPress Settings Resolver
 *
 * Centralized utility for resolving WordPress post settings with system defaults override.
 * Provides single source of truth for WordPress settings resolution across all handlers.
 *
 * @package DataMachine\Core\WordPress
 * @since 0.2.7
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Core\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressSettingsResolver {

	/**
	 * Get effective post status from handler config with system defaults override.
	 *
	 * System-wide defaults always take precedence over handler-specific configuration.
	 *
	 * @param array  $handler_config Handler configuration
	 * @param string $default Default status if not configured (default: 'draft')
	 * @return string Post status (publish, draft, pending, etc.)
	 */
	public static function getPostStatus( array $handler_config, string $default_value = 'draft' ): string {
		$wp_settings         = PluginSettings::get( 'wordpress_settings', array() );
		$default_post_status = $wp_settings['default_post_status'] ?? '';

		if ( ! empty( $default_post_status ) ) {
			return $default_post_status;
		}
		return $handler_config['post_status'] ?? $default_value;
	}

	/**
	 * Get effective post author from handler config with system defaults override.
	 *
	 * Resolution order:
	 * 1. System-wide default_author_id from wordpress_settings
	 * 2. Handler-specific post_author from config
	 * 3. Current logged-in user (interactive contexts only)
	 * 4. First administrator user (headless/cron fallback)
	 *
	 * @param array $handler_config Handler configuration
	 * @return int Post author ID
	 */
	public static function getPostAuthor( array $handler_config ): int {
		$wp_settings       = PluginSettings::get( 'wordpress_settings', array() );
		$default_author_id = $wp_settings['default_author_id'] ?? 0;

		if ( ! empty( $default_author_id ) ) {
			return (int) $default_author_id;
		}

		$author = $handler_config['post_author'] ?? 0;
		if ( $author > 0 ) {
			return (int) $author;
		}

		$current_user = get_current_user_id();
		if ( $current_user > 0 ) {
			return $current_user;
		}

		return self::getFirstAdministratorId();
	}

	/**
	 * Get the first administrator user ID as a last-resort fallback.
	 *
	 * @return int Administrator user ID, or 0 if none found.
	 */
	private static function getFirstAdministratorId(): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}
}
