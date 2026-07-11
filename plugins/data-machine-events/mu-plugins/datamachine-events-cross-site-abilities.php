<?php
/**
 * Data Machine Events — Cross-Site Abilities (mu-plugin loader)
 *
 * Network: true
 *
 * Why this file exists
 * --------------------
 * `data-machine-events` is a per-site plugin (active only on the events blog).
 * Some of its abilities are designed to be CROSS-SITE CALLABLE: their execute
 * callbacks internally `switch_to_blog()` to the events blog, so once the
 * ability is REGISTERED it runs correctly from any blog in the network. The
 * prime example is `data-machine-events/events-by-term`, consumed by the
 * artist-platform Shows section on blogs where this plugin's PHP never loads.
 *
 * The problem: the WP Abilities registry is a per-request singleton. On a
 * request for a blog where this plugin is NOT active, the plugin's PHP never
 * loads, so the ability is never registered. A consumer that calls
 * `wp_get_ability( 'data-machine-events/events-by-term' )` on that blog hits a
 * missing registration, which fires `_doing_it_wrong` (see issue #422) AND
 * silently returns null so the consumer renders nothing.
 *
 * The fix: register ONLY the cross-site-callable abilities on every blog — not
 * the whole plugin (post types, blocks, admin, etc. must stay scoped to the
 * events blog). mu-plugins are network-wide, so this loader runs on every blog
 * request and instantiates just the ability classes that carry the cross-site
 * contract. Each class registers itself on `wp_abilities_api_init` and is
 * guarded by its own static `$registered` flag, so on the events blog (where
 * the plugin is also active) the double load is a harmless no-op.
 *
 * Deployment
 * ----------
 * Ship this file into `wp-content/mu-plugins/` (a symlink to this file is the
 * usual convention, e.g.
 *   ln -s .../data-machine-events/mu-plugins/datamachine-events-cross-site-abilities.php \
 *          .../wp-content/mu-plugins/datamachine-events-cross-site-abilities.php
 * ). It only loads the ability class files from the plugin directory; it does
 * not activate the plugin itself, so event post types / blocks / admin screens
 * remain unavailable off the events blog by design.
 *
 * Layer purity
 * ------------
 * This loader is generic and consumer-agnostic. It references no consumer
 * plugin, taxonomy, or blog identity — it only loads the owning plugin's own
 * ability classes and lets them register under their own namespace.
 *
 * @package DataMachineEvents
 * @since 0.48.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The Abilities API ships in WordPress 6.9+. Without it there is nothing to
// register; bail silently so this mu-plugin is a no-op on older installs.
if ( ! function_exists( 'wp_register_ability' ) ) {
	return;
}

/**
 * Resolve the data-machine-events plugin directory.
 *
 * On the events blog (where the plugin is active) its path constant exists.
 * Everywhere else fall back to the standard plugins folder location. The
 * folder name matches the plugin slug / repo name.
 */
$events_plugin_dir = '';
if ( defined( 'DATA_MACHINE_EVENTS_PLUGIN_DIR' ) ) {
	$events_plugin_dir = DATA_MACHINE_EVENTS_PLUGIN_DIR;
} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
	$events_plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . 'data-machine-events/';
}

if ( '' === $events_plugin_dir ) {
	return;
}

$events_plugin_dir = trailingslashit( $events_plugin_dir );

// Bail if the plugin is not actually present on disk (nothing to load).
$ability_file = $events_plugin_dir . 'inc/Abilities/EventsByTermAbilities.php';
if ( ! is_readable( $ability_file ) ) {
	return;
}

// Minimal class dependencies for the cross-site ability(ies) below. These are
// loaded explicitly rather than via the plugin's Composer autoloader, which is
// only bootstrapped when the plugin is active. Each file guards against
// redeclaration via require_once, so loading them here AND in the active plugin
// on the events blog is safe.
require_once $events_plugin_dir . 'inc/Core/EventDatesTable.php';
require_once $events_plugin_dir . 'inc/Abilities/AbilityCategories.php';
require_once $ability_file;

// Ensure the ability category exists before the ability registers against it.
// Idempotent: safe alongside the plugin's own ensure_registered() call.
\DataMachineEvents\Abilities\AbilityCategories::ensure_registered();

// Instantiate the cross-site-callable ability. The constructor hooks
// `wp_abilities_api_init` (or registers immediately if it already fired) and
// guards against duplicate registration via a static flag, so this is a no-op
// on the events blog where the plugin has already registered it.
new \DataMachineEvents\Abilities\EventsByTermAbilities();
