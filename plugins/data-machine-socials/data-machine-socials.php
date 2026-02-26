<?php
/**
 * Plugin Name: Data Machine Socials
 * Plugin URI: https://github.com/Extra-Chill/data-machine-socials
 * Description: Social media extension for Data Machine. Adds support for Instagram, Twitter, Facebook, Bluesky, Threads, Pinterest, and Reddit.
 * Version: 0.1.0
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

// Check if Data Machine core is active
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

define( 'DATAMACHINE_SOCIALS_VERSION', '0.2.0' );
define( 'DATAMACHINE_SOCIALS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_SOCIALS_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load and instantiate social media handlers and abilities.
 */
function datamachine_socials_load_handlers() {
	// Load Abilities (they self-register)
	// Pinterest
	new \DataMachineSocials\Abilities\Pinterest\PinterestBoardsAbility();
	new \DataMachineSocials\Abilities\Pinterest\PinterestPublishAbility();

	// Twitter
	new \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility();

	// Facebook
	new \DataMachineSocials\Abilities\Facebook\FacebookPublishAbility();

	// Bluesky
	new \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility();

	// Threads
	new \DataMachineSocials\Abilities\Threads\ThreadsPublishAbility();

	// Instagram
	new \DataMachineSocials\Abilities\Instagram\InstagramPublishAbility();

	// Reddit (Fetch)
	new \DataMachineSocials\Abilities\Reddit\FetchRedditAbility();

	// Social Handlers
	new \DataMachineSocials\Handlers\Twitter\Twitter();
	new \DataMachineSocials\Handlers\Facebook\Facebook();
	new \DataMachineSocials\Handlers\Threads\Threads();
	new \DataMachineSocials\Handlers\Bluesky\Bluesky();
	new \DataMachineSocials\Handlers\Pinterest\Pinterest();
	new \DataMachineSocials\Handlers\Instagram\Instagram();

	// Reddit (Fetch)
	new \DataMachineSocials\Handlers\Reddit\Reddit();
}

// Hook into plugins_loaded to ensure Data Machine core is loaded first
add_action( 'plugins_loaded', 'datamachine_socials_load_handlers', 20 );

// Register REST API
require_once DATAMACHINE_SOCIALS_PATH . 'inc/RestApi.php';
add_action( 'plugins_loaded', array( 'DataMachineSocials\RestApi', 'register' ), 25 );

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
	$post_id = get_the_ID();
	$featured_image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );

	wp_localize_script(
		'data-machine-socials-editor',
		'dmsData',
		array(
			'postId'      => $post_id,
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'featuredImage' => $featured_image ? array(
				'id'     => get_post_thumbnail_id( $post_id ),
				'url'    => $featured_image[0],
				'width'  => $featured_image[1],
				'height' => $featured_image[2],
			) : null,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'datamachine_socials_enqueue_assets' );

/**
 * Register WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DATAMACHINE_SOCIALS_PATH . 'inc/Cli/Commands/PinterestCommand.php';
	require_once DATAMACHINE_SOCIALS_PATH . 'inc/Cli/Commands/RedditCommand.php';
	WP_CLI::add_command( 'datamachine-socials pinterest', \DataMachineSocials\Cli\Commands\PinterestCommand::class );
	WP_CLI::add_command( 'datamachine-socials reddit', \DataMachineSocials\Cli\Commands\RedditCommand::class );
}

/**
 * Register chat tools.
 *
 * Chat tools extend BaseTool from core and self-register via filters.
 * Only load when Data Machine core's AI engine is available.
 */
function datamachine_socials_load_chat_tools() {
	if ( ! class_exists( 'DataMachine\Engine\AI\Tools\BaseTool' ) ) {
		return;
	}

	new \DataMachineSocials\Chat\Tools\FetchReddit();
}
add_action( 'plugins_loaded', 'datamachine_socials_load_chat_tools', 25 );
