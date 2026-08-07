<?php
/**
 * IndexNow CLI Command
 *
 * Submit URLs to IndexNow, manage API keys, and check integration status.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.36.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\SEO\IndexNowAbilities;
use DataMachine\Core\PluginSettings;

class IndexNowCommand extends BaseCommand {

	/**
	 * Submit one or more URLs to IndexNow for instant search engine indexing.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : One or more URLs to submit.
	 *
	 * [--post-id=<id>]
	 * : Submit the permalink for a WordPress post ID.
	 *
	 * [--post-type=<type>]
	 * : Submit all published posts of this type.
	 *
	 * [--limit=<number>]
	 * : Limit the number of URLs when using --post-type. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     # Submit a single URL
	 *     wp datamachine indexnow submit https://example.com/my-post/
	 *
	 *     # Submit multiple URLs
	 *     wp datamachine indexnow submit https://example.com/post-1/ https://example.com/post-2/
	 *
	 *     # Submit by post ID
	 *     wp datamachine indexnow submit --post-id=42
	 *
	 *     # Submit all published events
	 *     wp datamachine indexnow submit --post-type=event --limit=50
	 *
	 * @subcommand submit
	 */
	public function submit( array $args, array $assoc_args ): void {
		$urls = array();

		$post_id   = $assoc_args['post-id'] ?? null;
		$post_type = $assoc_args['post-type'] ?? null;
		$limit     = intval( $assoc_args['limit'] ?? 100 );

		if ( $post_id ) {
			$url = get_permalink( intval( $post_id ) );
			if ( ! $url ) {
				WP_CLI::error( "Could not get permalink for post ID {$post_id}" );
			}
			$urls[] = $url;
		} elseif ( $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => min( $limit, IndexNowAbilities::MAX_BATCH_SIZE ),
					'fields'         => 'ids',
				)
			);

			if ( empty( $posts ) ) {
				WP_CLI::error( "No published posts found for post type '{$post_type}'" );
			}

			foreach ( $posts as $pid ) {
				$url = get_permalink( $pid );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		} else {
			$urls = $args;
		}

		if ( empty( $urls ) ) {
			WP_CLI::error( 'No URLs provided. Pass URLs as arguments, or use --post-id or --post-type.' );
		}

		$count = count( $urls );
		WP_CLI::log( "Submitting {$count} URL(s) to IndexNow..." );

		$result = IndexNowAbilities::submit_urls( $urls );

		if ( $result['success'] ) {
			WP_CLI::success( $result['message'] . " ({$count} URL" . ( $count > 1 ? 's' : '' ) . ')' );
		} else {
			$error_msg = $result['error'] ?? 'Unknown error';
			$code      = $result['status_code'] ?? '';
			if ( $code ) {
				$error_msg .= " (HTTP {$code})";
			}
			WP_CLI::error( $error_msg );
		}
	}

	/**
	 * Show IndexNow integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine indexnow status
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = IndexNowAbilities::get_status();

		WP_CLI::log( '' );
		WP_CLI::log( 'IndexNow Integration Status' );
		WP_CLI::log( str_repeat( '─', 40 ) );
		WP_CLI::log( 'Enabled:       ' . ( $status['enabled'] ? WP_CLI::colorize( '%GYes%n' ) : WP_CLI::colorize( '%RNo%n' ) ) );
		WP_CLI::log( 'API Key:       ' . ( $status['has_key'] ? $status['key_preview'] : WP_CLI::colorize( '%RNot set%n' ) ) );

		if ( $status['has_key'] ) {
			WP_CLI::log( 'Key File URL:  ' . $status['key_file_url'] );
		}

		WP_CLI::log( 'Endpoint:      ' . $status['endpoint'] );
		WP_CLI::log( '' );

		if ( ! $status['has_key'] ) {
			WP_CLI::log( WP_CLI::colorize( '%YRun `wp datamachine indexnow key generate` to create an API key.%n' ) );
		} elseif ( ! $status['enabled'] ) {
			WP_CLI::log( WP_CLI::colorize( '%YRun `wp datamachine settings set indexnow_enabled true` to enable auto-ping on publish.%n' ) );
		}
	}

	/**
	 * Manage the IndexNow API key.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: generate, verify, show
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a new API key
	 *     wp datamachine indexnow key generate
	 *
	 *     # Verify the key file is accessible
	 *     wp datamachine indexnow key verify
	 *
	 *     # Show the current key
	 *     wp datamachine indexnow key show
	 *
	 * @subcommand key
	 */
	public function key( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		switch ( $action ) {
			case 'generate':
				$key = IndexNowAbilities::generate_key();
				WP_CLI::success( 'Generated new IndexNow API key: ' . substr( $key, 0, 8 ) . '...' );
				WP_CLI::log( 'Key file URL: ' . home_url( '/' . $key . '.txt' ) );

				// Flush rewrite rules so the key file route is active.
				flush_rewrite_rules();
				WP_CLI::log( 'Rewrite rules flushed.' );
				break;

			case 'verify':
				WP_CLI::log( 'Verifying key file accessibility...' );
				$result = IndexNowAbilities::verify_key_file();

				if ( $result['success'] ) {
					WP_CLI::success( $result['message'] );
					WP_CLI::log( 'URL: ' . $result['url'] );
				} else {
					WP_CLI::error( $result['error'] . ( isset( $result['url'] ) ? "\nURL: " . $result['url'] : '' ) );
				}
				break;

			case 'show':
				$key = IndexNowAbilities::get_api_key();
				if ( empty( $key ) ) {
					WP_CLI::error( 'No IndexNow API key configured. Run: wp datamachine indexnow key generate' );
				}
				WP_CLI::log( $key );
				break;

			default:
				WP_CLI::error( 'Unknown action: ' . $action . '. Valid actions: generate, verify, show' );
		}
	}

	/**
	 * Enable IndexNow auto-ping on publish.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine indexnow enable
	 *
	 * @subcommand enable
	 */
	public function enable( array $args, array $assoc_args ): void {
		$settings                     = get_option( 'datamachine_settings', array() );
		$settings['indexnow_enabled'] = true;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

		// Ensure API key exists.
		$key = IndexNowAbilities::get_or_generate_key();

		WP_CLI::success( 'IndexNow auto-ping enabled. URLs will be submitted when posts are published.' );
		WP_CLI::log( 'API key: ' . substr( $key, 0, 8 ) . '...' );
	}

	/**
	 * Disable IndexNow auto-ping on publish.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine indexnow disable
	 *
	 * @subcommand disable
	 */
	public function disable( array $args, array $assoc_args ): void {
		$settings                     = get_option( 'datamachine_settings', array() );
		$settings['indexnow_enabled'] = false;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();

		WP_CLI::success( 'IndexNow auto-ping disabled.' );
	}
}
