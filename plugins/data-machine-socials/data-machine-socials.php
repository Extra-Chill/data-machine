<?php
/**
 * Plugin Name: Data Machine Socials
 * Plugin URI: https://github.com/Extra-Chill/data-machine-socials
 * Description: Social media extension for Data Machine. Adds support for Instagram, TikTok, YouTube, Twitter, Facebook, Bluesky, Mastodon, Threads, Pinterest, LinkedIn, Tumblr, and Reddit.
 * Version: 0.20.2
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-socials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMACHINE_SOCIALS_VERSION', '0.20.2' );
define( 'DATAMACHINE_SOCIALS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_SOCIALS_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Register the Data Machine Socials ability category.
 *
 * Hooked on wp_abilities_api_categories_init so the category is
 * available before any social abilities are registered.
 */
function datamachine_socials_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	/*
	 * wp_abilities_api_categories_init can fire more than once per request on
	 * multisite; guard against re-registering an already-registered category,
	 * which trips a _doing_it_wrong notice in core's categories registry.
	 */
	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'datamachine-socials' ) ) {
		return;
	}

	wp_register_ability_category(
		'datamachine-socials',
		array(
			'label'       => __( 'Socials', 'data-machine-socials' ),
			'description' => __( 'Social media publishing, reading, and engagement across all supported platforms.', 'data-machine-socials' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'datamachine_socials_register_ability_category' );

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Data Machine core must be active — check at plugins_loaded time
 * (not at plugin load time, since load order is alphabetical and
 * data-machine-socials loads before data-machine).
 */
function datamachine_socials_bootstrap() {
	if ( ! class_exists( 'DataMachine\Core\Steps\Publish\Handlers\PublishHandler' ) ) {
		add_action( 'admin_notices', function() {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Data Machine Socials requires Data Machine core plugin to be installed and activated.', 'data-machine-socials' ); ?></p>
			</div>
			<?php
		} );
		return;
	}

	// Load cross-platform abilities before isolated platform providers.
	new \DataMachineSocials\Abilities\SocialCommentsAbility();

	\DataMachineSocials\Bootstrap\PlatformBootstrap::instance()->register();

	// Register task handlers for DM Task System.
	add_filter( 'datamachine_tasks', function ( array $tasks ): array {
		$tasks['social_cross_post'] = \DataMachineSocials\Tasks\SocialCrossPostTask::class;
		return $tasks;
	} );

	\DataMachineSocials\Operations\DelegatedCrossPostAction::register();

	// Register image generation templates
	add_filter( 'datamachine/image_generation/templates', function ( array $templates ): array {
		$templates['quote_card'] = \DataMachineSocials\ImageGeneration\Templates\QuoteCard::class;
		$templates['chart']      = \DataMachineSocials\ImageGeneration\Templates\ChartTemplate::class;
		$templates['diagram']    = \DataMachineSocials\ImageGeneration\Templates\DiagramTemplate::class;
		return $templates;
	} );

	// Register REST API
	require_once DATAMACHINE_SOCIALS_PATH . 'inc/RestApi.php';
	\DataMachineSocials\RestApi::register();
}
add_action( 'plugins_loaded', 'datamachine_socials_bootstrap', 20 );

/**
 * Return explicit bootstrap availability for every social platform.
 *
 * @return array<string, array<string, mixed>>
 */
function datamachine_socials_get_platform_availability() {
	return \DataMachineSocials\Bootstrap\PlatformBootstrap::instance()->availability();
}

/**
 * Register the Data Machine Socials CLI section in the composable AGENTS.md
 * file so external agent runtimes discover intent-based social CLI routing.
 * Runs outside the WP_CLI guard because compose/auto-regeneration may fire in
 * non-CLI WordPress contexts (web/cron).
 */
function datamachine_socials_register_agents_md_section() {
	if ( ! class_exists( '\DataMachine\Engine\AI\SectionRegistry' ) ) {
		return;
	}

	$wp = 'wp --allow-root --path=' . ABSPATH;

	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'data-machine-socials', 60, function () use ( $wp ) {
		return \DataMachineSocials\Cli\AgentsMdSection::render( $wp );
	}, array(
		'label'       => 'Data Machine Socials CLI',
		'description' => 'Cross-platform social media WP-CLI commands.',
		'freshness'   => 'generated',
	) );
}
add_action( 'plugins_loaded', 'datamachine_socials_register_agents_md_section', 22 );

// Temp file cleanup runs independently (doesn't need DM core)
\DataMachineSocials\Cleanup::register();
\DataMachineSocials\Tracking\RedditCommentDomainStore::register();

register_deactivation_hook( __FILE__, array( \DataMachineSocials\Tracking\RedditCommentDomainStore::class, 'deactivate' ) );

/**
 * Enqueue Gutenberg sidebar assets
 */
function datamachine_socials_enqueue_assets( $hook ) {
	// Only load on post edit screens
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$asset_file = DATAMACHINE_SOCIALS_PATH . 'build/index.asset.php';

	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'data-machine-socials-editor',
		DATAMACHINE_SOCIALS_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_enqueue_style(
		'data-machine-socials-editor',
		DATAMACHINE_SOCIALS_URL . 'build/style-index.css',
		array(),
		$asset['version']
	);

	// Pass data to JavaScript
	$post_id        = (int) get_the_ID();
	$thumbnail_id   = (int) get_post_thumbnail_id( $post_id );
	$featured_image = wp_get_attachment_image_src( $thumbnail_id, 'full' );

	wp_localize_script(
		'data-machine-socials-editor',
		'dmsData',
		array(
			'postId'        => $post_id,
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'featuredImage' => $featured_image ? array(
				'id'     => $thumbnail_id,
				'url'    => $featured_image[0],
				'width'  => $featured_image[1],
				'height' => $featured_image[2],
			) : null,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'datamachine_socials_enqueue_assets' );

/*
 * Register WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) ) {
	// Cross-platform commands remain outside platform-local providers.
	foreach ( array( 'datamachine-socials comments', 'datamachine-socials shares' ) as $command ) {
		$command_map = \DataMachineSocials\Cli\CommandRegistry::map();
		$class       = $command_map[ $command ];
		require_once \DataMachineSocials\Cli\CommandRegistry::file_for_class( $class );
		WP_CLI::add_command( $command, $class );
	}
}

/**
 * Register chat tools.
 *
 * Chat tools extend BaseTool from core and self-register via filters.
 * Only load when Data Machine core's AI engine is available.
 */
function datamachine_socials_load_chat_tools() {
	$bootstrap = \DataMachineSocials\Bootstrap\PlatformBootstrap::instance();
	$bootstrap->register_tools();
	$bootstrap->register_cli();
}
add_action( 'plugins_loaded', 'datamachine_socials_load_chat_tools', 25 );
