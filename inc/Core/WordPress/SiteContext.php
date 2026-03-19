<?php
/**
 * Cached WordPress site metadata — DEPRECATED.
 *
 * Site context is now provided by SITE.md (auto-regenerated on disk via
 * datamachine_regenerate_site_md()). This class remains for backward
 * compatibility with code that references the class name or its static
 * methods. It will be removed in a future major version.
 *
 * @package DataMachine\Core\WordPress
 * @deprecated 0.48.0 Site context is now provided by SITE.md auto-regeneration.
 */

namespace DataMachine\Core\WordPress;

defined( 'ABSPATH' ) || exit;

class SiteContext {

	const CACHE_KEY = 'datamachine_site_context_data';

	/**
	 * Get site context data.
	 *
	 * @deprecated 0.48.0 Site context is now in SITE.md. This method remains for backward compat.
	 * @return array Site metadata (empty — context is now file-based).
	 */
	public static function get_context(): array {
		return array();
	}

	/**
	 * Clear site context cache.
	 *
	 * @deprecated 0.48.0 SITE.md regeneration handles freshness. Cleans up legacy transient.
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Register automatic cache invalidation hooks.
	 *
	 * @deprecated 0.48.0 Use datamachine_register_site_md_invalidation() instead.
	 */
	public static function register_cache_invalidation(): void {
		// No-op. SITE.md invalidation hooks are registered in bootstrap.php.
		// This method remains to avoid fatal errors from code calling it directly.
	}
}
