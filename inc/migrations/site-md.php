<?php
/**
 * Data Machine — SITE.md and NETWORK.md section registration.
 *
 * SITE.md and NETWORK.md are composable memory files. Their content is
 * assembled from sections registered against the SectionRegistry, the same
 * extension API plugins use to contribute to AGENTS.md. Regeneration is
 * handled generically by ComposableFileGenerator + ComposableFileInvalidation;
 * this file owns the *content* of the core sections only.
 *
 * Migration history:
 * - 0.36.1 — initial SiteContext class injecting JSON into prompts.
 * - 0.48.0 — replaced with monolithic SITE.md/NETWORK.md generators and a
 *            single whole-string filter per file.
 * - x.y.z  — files made composable; monolithic generators broken into
 *            per-section callbacks. Legacy whole-string filters preserved
 *            via `datamachine_composable_content` shim with a deprecation
 *            notice.
 *
 * @package DataMachine
 * @since   0.60.0
 */

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\SectionRegistry;

/**
 * Register all core SITE.md and NETWORK.md sections.
 *
 * Fires on `datamachine_sections` (once per request, lazily). Each section
 * is a small callback returning markdown. Sections are joined with double
 * newlines by SectionRegistry::generate().
 *
 * Priority spacing leaves room for plugin-contributed sections to slot in
 * between core sections (e.g. priority 35 to land between post-types at 30
 * and taxonomies at 40).
 *
 * @since x.y.z
 * @return void
 */
function datamachine_register_core_sections(): void {
	// SITE.md sections — assembled into shared/SITE.md.
	SectionRegistry::register( 'SITE.md', 'header', 0, 'datamachine_site_section_header' );
	SectionRegistry::register( 'SITE.md', 'identity', 10, 'datamachine_site_section_identity' );
	SectionRegistry::register( 'SITE.md', 'structure', 20, 'datamachine_site_section_structure' );
	SectionRegistry::register( 'SITE.md', 'post-types', 30, 'datamachine_site_section_post_types' );
	SectionRegistry::register( 'SITE.md', 'taxonomies', 40, 'datamachine_site_section_taxonomies' );
	SectionRegistry::register( 'SITE.md', 'user-roles', 50, 'datamachine_site_section_user_roles' );
	SectionRegistry::register( 'SITE.md', 'plugins', 60, 'datamachine_site_section_plugins' );
	SectionRegistry::register( 'SITE.md', 'mu-plugins', 65, 'datamachine_site_section_mu_plugins' );
	SectionRegistry::register( 'SITE.md', 'dropins', 70, 'datamachine_site_section_dropins' );
	SectionRegistry::register( 'SITE.md', 'rest-api', 80, 'datamachine_site_section_rest_api' );

	// NETWORK.md sections — only meaningful on multisite (callbacks self-guard).
	SectionRegistry::register( 'NETWORK.md', 'header', 0, 'datamachine_network_section_header' );
	SectionRegistry::register( 'NETWORK.md', 'identity', 10, 'datamachine_network_section_identity' );
	SectionRegistry::register( 'NETWORK.md', 'sites', 20, 'datamachine_network_section_sites' );
	SectionRegistry::register( 'NETWORK.md', 'plugins', 30, 'datamachine_network_section_plugins' );
	SectionRegistry::register( 'NETWORK.md', 'shared-resources', 40, 'datamachine_network_section_shared_resources' );
}
add_action( 'datamachine_sections', 'datamachine_register_core_sections' );

/**
 * Register the WordPress hooks that should trigger composable file
 * regeneration when SITE.md / NETWORK.md content can change.
 *
 * Plugin (de)activation is wired by ComposableFileInvalidation directly
 * because it changes the SectionRegistry itself; everything below changes
 * the data each section reads.
 *
 * @since x.y.z
 *
 * @param string[] $hooks Existing invalidation hooks.
 * @return string[]
 */
function datamachine_register_core_invalidation_hooks( array $hooks ): array {
	// SITE.md invalidation — site identity, structure, menus.
	$hooks[] = 'switch_theme';
	$hooks[] = 'update_option_blogname';
	$hooks[] = 'update_option_blogdescription';
	$hooks[] = 'update_option_home';
	$hooks[] = 'update_option_siteurl';
	$hooks[] = 'update_option_permalink_structure';
	$hooks[] = 'update_option_page_on_front';
	$hooks[] = 'update_option_page_for_posts';
	$hooks[] = 'update_option_show_on_front';
	$hooks[] = 'wp_update_nav_menu';
	$hooks[] = 'wp_delete_nav_menu';
	$hooks[] = 'wp_update_nav_menu_item';

	// NETWORK.md invalidation — site lifecycle (multisite only; harmless on single-site).
	$hooks[] = 'wp_initialize_site';
	$hooks[] = 'wp_delete_site';
	$hooks[] = 'wp_uninitialize_site';

	return $hooks;
}
add_filter( 'datamachine_composable_invalidation_hooks', 'datamachine_register_core_invalidation_hooks' );

/**
 * Soft-deprecation shim for the legacy whole-string filters.
 *
 * `datamachine_site_scaffold_content` and `datamachine_network_scaffold_content`
 * are superseded by `SectionRegistry::register()`. To minimize breakage, we
 * still fire them on the assembled output of SITE.md / NETWORK.md so existing
 * consumers keep working, but emit a `_doing_it_wrong` notice when a callback
 * is attached so consumers know to migrate.
 *
 * Will be removed in a future major version.
 *
 * @since x.y.z
 *
 * @param string $content  Assembled file content.
 * @param string $filename Composable filename.
 * @return string
 */
function datamachine_legacy_scaffold_filter_shim( string $content, string $filename ): string {
	$legacy_filter = '';
	$replacement   = '';

	if ( 'SITE.md' === $filename ) {
		$legacy_filter = 'datamachine_site_scaffold_content';
		$replacement   = "SectionRegistry::register( 'SITE.md', '<your-slug>', <priority>, <callback> )";
	} elseif ( 'NETWORK.md' === $filename ) {
		$legacy_filter = 'datamachine_network_scaffold_content';
		$replacement   = "SectionRegistry::register( 'NETWORK.md', '<your-slug>', <priority>, <callback> )";
	}

	if ( '' === $legacy_filter ) {
		return $content;
	}

	if ( has_filter( $legacy_filter ) ) {
		_doing_it_wrong(
			esc_html( $legacy_filter ),
			sprintf(
				/* translators: 1: legacy filter name, 2: replacement code snippet */
				esc_html__( 'The %1$s filter is deprecated. Register a SectionRegistry section instead: %2$s', 'data-machine' ),
				esc_html( $legacy_filter ),
				esc_html( $replacement )
			),
			'x.y.z'
		);

		/** This filter is documented in inc/migrations/site-md.php (legacy). */
		$content = (string) apply_filters( $legacy_filter, $content );
	}

	return $content;
}
add_filter( 'datamachine_composable_content', 'datamachine_legacy_scaffold_filter_shim', 10, 2 );

// -----------------------------------------------------------------------------
// SITE.md sections.
// -----------------------------------------------------------------------------

/**
 * Heading for SITE.md.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_header(): string {
	return '# SITE';
}

/**
 * Identity block: name, description, URL, theme, language, timezone, etc.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_identity(): string {
	$site_name        = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_description = (string) get_bloginfo( 'description' );
	$site_url         = home_url();
	$language         = get_locale();
	$timezone         = wp_timezone_string();
	$theme_name       = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';
	$permalink        = (string) get_option( 'permalink_structure', '' );

	$lines   = array();
	$lines[] = '## Identity';
	$lines[] = '- **name:** ' . $site_name;
	if ( '' !== $site_description ) {
		$lines[] = '- **description:** ' . $site_description;
	}
	$lines[] = '- **url:** ' . $site_url;
	$lines[] = '- **theme:** ' . $theme_name;
	$lines[] = '- **language:** ' . $language;
	$lines[] = '- **timezone:** ' . $timezone;
	if ( '' !== $permalink ) {
		$lines[] = '- **permalinks:** ' . $permalink;
	}
	$lines[] = '- **multisite:** ' . ( is_multisite() ? 'true' : 'false' );

	return implode( "\n", $lines );
}

/**
 * Site Structure: front/blog/privacy pages, homepage display, menus.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_structure(): string {
	$lines   = array();
	$lines[] = '## Site Structure';

	$front_page_id = (int) get_option( 'page_on_front', 0 );
	if ( $front_page_id > 0 ) {
		$lines[] = sprintf( '- **Front page:** %s (ID %d)', get_the_title( $front_page_id ), $front_page_id );
	}

	$blog_page_id = (int) get_option( 'page_for_posts', 0 );
	if ( $blog_page_id > 0 ) {
		$lines[] = sprintf( '- **Blog page:** %s (ID %d)', get_the_title( $blog_page_id ), $blog_page_id );
	}

	$privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
	if ( $privacy_page_id > 0 ) {
		$lines[] = sprintf( '- **Privacy page:** %s (ID %d)', get_the_title( $privacy_page_id ), $privacy_page_id );
	}

	$show_on_front = get_option( 'show_on_front', 'posts' );
	$lines[]       = '- **Homepage displays:** ' . ( 'page' === $show_on_front ? 'static page' : 'latest posts' );

	// Menus subsection — only emitted when at least one menu location is registered.
	$registered_menus = get_registered_nav_menus();
	$menu_locations   = get_nav_menu_locations();

	if ( ! empty( $registered_menus ) ) {
		$lines[] = '';
		$lines[] = '### Menus';
		foreach ( $registered_menus as $location => $description ) {
			$assigned = 'unassigned';
			if ( ! empty( $menu_locations[ $location ] ) ) {
				$menu_obj = wp_get_nav_menu_object( $menu_locations[ $location ] );
				$assigned = $menu_obj ? $menu_obj->name : 'unassigned';
			}
			$lines[] = sprintf( '- **%s** (%s): %s', $description, $location, $assigned );
		}
	}

	return implode( "\n", $lines );
}

/**
 * Post type table.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_post_types(): string {
	// The bundled phpstan stub for get_post_types() only declares one param,
	// but the WP signature is (array $args, string $output, string $operator)
	// and we need 'objects' to access WP_Post_Type properties below.
	// @phpstan-ignore-next-line arguments.count
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	/** @var WP_Post_Type[] $post_types */

	$lines   = array();
	$lines[] = '## Post Types';
	$lines[] = '| Label | Slug | Published | Type |';
	$lines[] = '|-------|------|-----------|------|';

	foreach ( $post_types as $pt ) {
		$count     = wp_count_posts( $pt->name );
		$published = isset( $count->publish ) ? (int) $count->publish : 0;
		$hier      = $pt->hierarchical ? 'hierarchical' : 'flat';
		$lines[]   = sprintf( '| %s | %s | %d | %s |', $pt->label, $pt->name, $published, $hier );
	}

	return implode( "\n", $lines );
}

/**
 * Taxonomy table.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_taxonomies(): string {
	/** @var WP_Taxonomy[] $taxonomies */
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

	$lines   = array();
	$lines[] = '## Taxonomies';
	$lines[] = '| Label | Slug | Terms | Type | Post Types |';
	$lines[] = '|-------|------|-------|------|------------|';

	foreach ( $taxonomies as $tax ) {
		$term_count_raw = wp_count_terms( array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
		) );
		// wp_count_terms returns int|numeric-string|WP_Error. Narrow to int
		// before formatting so phpstan stays happy and output is consistent.
		if ( is_wp_error( $term_count_raw ) ) {
			$term_count = 0;
		} elseif ( is_numeric( $term_count_raw ) ) {
			$term_count = (int) $term_count_raw;
		} else {
			$term_count = 0;
		}
		$hier = $tax->hierarchical ? 'hierarchical' : 'flat';
		// WP_Taxonomy::$object_type is array<string>, not nullable — use it directly.
		$associated = implode( ', ', $tax->object_type );
		$lines[]    = sprintf( '| %s | %s | %d | %s | %s |', $tax->label, $tax->name, $term_count, $hier, $associated );
	}

	return implode( "\n", $lines );
}

/**
 * User roles list. Returns empty string when no custom roles exist
 * (matches legacy behavior — the section was conditional on custom roles).
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_user_roles(): string {
	$wp_roles      = wp_roles();
	$role_names    = $wp_roles->get_names();
	$default_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	$custom_roles  = array_diff( array_keys( $role_names ), $default_roles );

	if ( empty( $custom_roles ) ) {
		return '';
	}

	$lines   = array();
	$lines[] = '## User Roles';

	foreach ( $role_names as $slug => $name ) {
		$is_custom = in_array( $slug, $custom_roles, true ) ? ' (custom)' : '';
		$lines[]   = sprintf( '- %s (`%s`)%s', translate_user_role( $name ), $slug, $is_custom );
	}

	return implode( "\n", $lines );
}

/**
 * Active plugins (excluding Data Machine itself), with descriptions.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_plugins(): string {
	$active_plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$entries = array();
	foreach ( $active_plugins as $plugin_file ) {
		$plugin_file = (string) $plugin_file;
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data = get_plugin_data( $plugin_path, false, false );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
			$plugin_desc = ! empty( $plugin_data['Description'] ) ? $plugin_data['Description'] : '';
		} else {
			$dir         = dirname( $plugin_file );
			$plugin_name = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
			$plugin_desc = '';
		}

		// Skip Data Machine's own plugin entry — it's always active and adds noise.
		if ( 'data-machine' === strtolower( (string) $plugin_name ) || 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$entries[] = array(
			'name' => $plugin_name,
			'desc' => $plugin_desc,
		);
	}

	$lines   = array();
	$lines[] = '## Active Plugins';

	if ( empty( $entries ) ) {
		$lines[] = '- (none)';
		return implode( "\n", $lines );
	}

	foreach ( $entries as $entry ) {
		$desc_suffix = '';
		if ( ! empty( $entry['desc'] ) ) {
			$desc = wp_strip_all_tags( $entry['desc'] );
			if ( strlen( $desc ) > 120 ) {
				$desc = substr( $desc, 0, 117 ) . '...';
			}
			$desc_suffix = ' — ' . $desc;
		}
		$lines[] = '- **' . $entry['name'] . '**' . $desc_suffix;
	}

	return implode( "\n", $lines );
}

/**
 * Must-Use plugins. Returns empty string when none are present.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_mu_plugins(): string {
	if ( ! function_exists( 'get_mu_plugins' ) ) {
		return '';
	}

	$mu_plugins = get_mu_plugins();
	if ( empty( $mu_plugins ) ) {
		return '';
	}

	$lines   = array();
	$lines[] = '## Must-Use Plugins';

	$emitted = false;
	foreach ( $mu_plugins as $mu_file => $mu_data ) {
		$mu_name = ! empty( $mu_data['Name'] ) ? $mu_data['Name'] : basename( $mu_file, '.php' );

		if ( 'data-machine' === strtolower( $mu_name ) ) {
			continue;
		}

		$mu_desc_suffix = '';
		if ( ! empty( $mu_data['Description'] ) ) {
			$mu_desc = wp_strip_all_tags( $mu_data['Description'] );
			if ( strlen( $mu_desc ) > 120 ) {
				$mu_desc = substr( $mu_desc, 0, 117 ) . '...';
			}
			$mu_desc_suffix = ' — ' . $mu_desc;
		}
		$lines[] = '- **' . $mu_name . '**' . $mu_desc_suffix;
		$emitted = true;
	}

	if ( ! $emitted ) {
		return '';
	}

	return implode( "\n", $lines );
}

/**
 * Drop-ins (advanced-cache.php, object-cache.php, etc.).
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_dropins(): string {
	if ( ! function_exists( 'get_dropins' ) ) {
		return '';
	}

	$dropins = get_dropins();
	if ( empty( $dropins ) ) {
		return '';
	}

	$lines   = array();
	$lines[] = '## Drop-ins';

	foreach ( $dropins as $dropin_file => $dropin_data ) {
		$dropin_name = ! empty( $dropin_data['Name'] ) ? $dropin_data['Name'] : $dropin_file;

		$dropin_desc_suffix = '';
		if ( ! empty( $dropin_data['Description'] ) ) {
			$dropin_desc = wp_strip_all_tags( $dropin_data['Description'] );
			if ( strlen( $dropin_desc ) > 120 ) {
				$dropin_desc = substr( $dropin_desc, 0, 117 ) . '...';
			}
			$dropin_desc_suffix = ' — ' . $dropin_desc;
		}
		$lines[] = '- **' . $dropin_name . '** (`' . $dropin_file . '`)' . $dropin_desc_suffix;
	}

	return implode( "\n", $lines );
}

/**
 * Custom REST API namespaces. Excludes core WP namespaces.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_site_section_rest_api(): string {
	if ( ! function_exists( 'rest_get_server' ) || ! did_action( 'rest_api_init' ) ) {
		return '';
	}

	$builtin_prefixes = array( 'wp/', 'oembed/', 'wp-site-health/' );
	$rest_namespaces  = array();

	foreach ( rest_get_server()->get_namespaces() as $namespace ) {
		$is_builtin = false;
		foreach ( $builtin_prefixes as $prefix ) {
			if ( 0 === strpos( $namespace . '/', $prefix ) || 'wp' === $namespace ) {
				$is_builtin = true;
				break;
			}
		}
		if ( ! $is_builtin ) {
			$rest_namespaces[] = $namespace;
		}
	}

	if ( empty( $rest_namespaces ) ) {
		return '';
	}

	$lines   = array();
	$lines[] = '## REST API';
	$lines[] = '- **Custom namespaces:** ' . implode( ', ', $rest_namespaces );

	return implode( "\n", $lines );
}

// -----------------------------------------------------------------------------
// NETWORK.md sections (multisite only — every callback short-circuits otherwise).
// -----------------------------------------------------------------------------

/**
 * Heading for NETWORK.md. Empty on single-site installs so the entire
 * file collapses to nothing and ComposableFileGenerator skips the write.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_network_section_header(): string {
	if ( ! is_multisite() ) {
		return '';
	}
	return '# Network';
}

/**
 * Network identity: name, primary site, count.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_network_section_identity(): string {
	if ( ! is_multisite() ) {
		return '';
	}

	$network      = get_network();
	$network_name = $network ? $network->site_name : 'WordPress Network';
	$main_site_id = get_main_site_id();
	$main_site    = get_site( $main_site_id );
	$main_url     = $main_site ? $main_site->domain . $main_site->path : home_url();
	$site_count   = get_blog_count();

	$lines   = array();
	$lines[] = '## Identity';
	$lines[] = '- **network_name:** ' . $network_name;
	$lines[] = '- **primary_site:** ' . $main_url;
	$lines[] = '- **sites_count:** ' . $site_count;

	return implode( "\n", $lines );
}

/**
 * Sites table.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_network_section_sites(): string {
	if ( ! is_multisite() ) {
		return '';
	}

	$sites        = get_sites( array( 'number' => 100 ) );
	$main_site_id = get_main_site_id();

	$lines   = array();
	$lines[] = '## Sites';
	$lines[] = '| Site | URL | Theme |';
	$lines[] = '|------|-----|-------|';

	foreach ( $sites as $site ) {
		$blog_id = (int) $site->blog_id;

		switch_to_blog( $blog_id );
		$name  = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Site ' . $blog_id;
		$url   = home_url();
		$theme = wp_get_theme()->get( 'Name' ) ? wp_get_theme()->get( 'Name' ) : 'Unknown';
		restore_current_blog();

		$is_main = ( $blog_id === $main_site_id ) ? ' (main)' : '';
		$lines[] = sprintf( '| %s%s | %s | %s |', $name, $is_main, $url, $theme );
	}

	return implode( "\n", $lines );
}

/**
 * Network-activated plugins (excluding Data Machine itself).
 *
 * @since x.y.z
 * @return string
 */
function datamachine_network_section_plugins(): string {
	if ( ! is_multisite() ) {
		return '';
	}

	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
	$plugin_names    = array();

	foreach ( array_keys( (array) $network_plugins ) as $plugin_file ) {
		// active_sitewide_plugins is keyed by plugin file path (strings), but
		// array_keys() returns array<int, int|string>. Cast for strict-typing tools.
		$plugin_file = (string) $plugin_file;

		if ( 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data    = get_plugin_data( $plugin_path, false, false );
			$plugin_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir            = dirname( $plugin_file );
			$plugin_names[] = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}
	}

	$lines   = array();
	$lines[] = '## Network Plugins';

	if ( empty( $plugin_names ) ) {
		$lines[] = '- (none)';
	} else {
		foreach ( $plugin_names as $name ) {
			$lines[] = '- ' . $name;
		}
	}

	return implode( "\n", $lines );
}

/**
 * Shared resources block. Currently a static descriptor of how users and
 * media are scoped on the network.
 *
 * @since x.y.z
 * @return string
 */
function datamachine_network_section_shared_resources(): string {
	if ( ! is_multisite() ) {
		return '';
	}

	$lines   = array();
	$lines[] = '## Shared Resources';
	$lines[] = '- **Users:** network-wide (see USER.md)';
	$lines[] = '- **Media:** per-site uploads';

	return implode( "\n", $lines );
}

// -----------------------------------------------------------------------------
// Backwards-compat shims for callers that still build SITE.md / NETWORK.md
// content directly (activation, layered-architecture migration, network-scope
// migration). These delegate to ComposableFileGenerator and remain available
// so external callers don't break across the migration.
// -----------------------------------------------------------------------------

/**
 * Build SITE.md content via the SectionRegistry.
 *
 * Preserved for backwards compatibility with any external callers that
 * imported the legacy function. Internal callers should use
 * `\DataMachine\Engine\AI\SectionRegistry::generate( 'SITE.md' )` directly.
 *
 * @since 0.36.1
 * @since x.y.z Delegates to SectionRegistry. The whole-string filter
 *              `datamachine_site_scaffold_content` is now applied via the
 *              `datamachine_composable_content` shim and emits a deprecation
 *              notice when used.
 * @return string
 */
function datamachine_get_site_scaffold_content(): string {
	$content = SectionRegistry::generate( 'SITE.md' );
	return '' === $content ? '' : $content . "\n";
}

/**
 * Build NETWORK.md content via the SectionRegistry.
 *
 * Preserved for backwards compatibility. Returns an empty string on
 * single-site installs (every NETWORK.md section short-circuits there).
 *
 * @since 0.48.0
 * @since x.y.z Delegates to SectionRegistry.
 * @return string
 */
function datamachine_get_network_scaffold_content(): string {
	if ( ! is_multisite() ) {
		return '';
	}
	$content = SectionRegistry::generate( 'NETWORK.md' );
	return '' === $content ? '' : $content . "\n";
}

/**
 * Regenerate SITE.md on disk.
 *
 * @since 0.48.0
 * @since x.y.z Delegates to ComposableFileGenerator.
 * @return void
 */
function datamachine_regenerate_site_md(): void {
	if ( ! \DataMachine\Core\PluginSettings::get( 'site_context_enabled', true ) ) {
		return;
	}
	\DataMachine\Engine\AI\ComposableFileGenerator::regenerate( 'SITE.md' );
}

/**
 * Regenerate NETWORK.md on disk.
 *
 * @since 0.49.1
 * @since x.y.z Delegates to ComposableFileGenerator.
 * @return void
 */
function datamachine_regenerate_network_md(): void {
	if ( ! is_multisite() ) {
		return;
	}
	if ( ! \DataMachine\Core\PluginSettings::get( 'site_context_enabled', true ) ) {
		return;
	}
	\DataMachine\Engine\AI\ComposableFileGenerator::regenerate( 'NETWORK.md' );
}
