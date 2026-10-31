<?php
/**
 * WP-CLI YouTube Command
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.17.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manage YouTube integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials youtube status
 *     wp datamachine-socials youtube account
 *     wp datamachine-socials youtube search "Charleston live music"
 *     wp datamachine-socials youtube upload "Show Recap" --file=/tmp/recap.mp4
 */
class YouTubeCommand {

	/**
	 * Show YouTube authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials youtube status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'youtube' );

		WP_CLI::log( 'YouTube Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		if ( method_exists( $provider, 'get_channel_id' ) ) {
			$channel_id = $provider->get_channel_id();
			if ( $channel_id ) {
				WP_CLI::log( 'Channel ID:    ' . $channel_id );
			}
		}

		$details = $provider->get_account_details();
		if ( $details && ! empty( $details['token_expires_at'] ) ) {
			$expires_at = intval( $details['token_expires_at'] );
			$remaining  = $expires_at - time();

			if ( $remaining > 0 ) {
				$minutes = round( $remaining / 60 );
				WP_CLI::log( "Token expires: in {$minutes} minutes" );
			} else {
				WP_CLI::log( 'Token expires: EXPIRED (will auto-refresh)' );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Note: unverified API projects (created after 2020-07-28) upload' );
		WP_CLI::log( 'videos as private only until passing YouTube compliance audit.' );
	}

	/**
	 * Show the authenticated YouTube channel details.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials youtube account
	 */
	public function account( $args, $assoc_args ) {
		$args;
		$ability = wp_get_ability( 'datamachine/youtube-account' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/youtube-account ability not registered.' );
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Unknown error' ) );
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( 'YouTube Channel' );
		WP_CLI::log( '' );
		WP_CLI::log( 'Channel ID:  ' . ( $result['channel_id'] ?? '' ) );
		WP_CLI::log( 'Title:       ' . ( $result['title'] ?? '' ) );
		WP_CLI::log( 'Custom URL:  ' . ( $result['custom_url'] ?? '' ) );
		WP_CLI::log( 'Subscribers: ' . ( $result['subscriber_count'] ?? '' ) );
		WP_CLI::log( 'Views:       ' . ( $result['view_count'] ?? '' ) );
		WP_CLI::log( 'Videos:      ' . ( $result['video_count'] ?? '' ) );
	}

	/**
	 * Search YouTube.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The search query.
	 *
	 * [--type=<type>]
	 * : Resource type.
	 * ---
	 * default: video
	 * options:
	 *   - video
	 *   - channel
	 *   - playlist
	 *   - any
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of results (max 50).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials youtube search "Grateful Dead live"
	 *     wp datamachine-socials youtube search "Charleston music" --type=channel
	 */
	public function search( $args, $assoc_args ) {
		$query   = $args[0] ?? '';
		$ability = wp_get_ability( 'datamachine/youtube-search' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/youtube-search ability not registered.' );
		}

		if ( empty( $query ) ) {
			WP_CLI::error( 'A search query is required.' );
		}

		$result = $ability->execute(
			array(
				'query' => $query,
				'type'  => $assoc_args['type'] ?? 'video',
				'limit' => absint( $assoc_args['limit'] ?? 10 ),
				'order' => $assoc_args['order'] ?? 'relevance',
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Unknown error' ) );
		}

		$items  = $result['results'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::warning( 'No results found.' );
			return;
		}

		WP_CLI::success( 'Found ' . count( $items ) . ' results' );
		WP_CLI::log( '' );

		foreach ( $items as $item ) {
			$title = mb_substr( $item['title'] ?? '(no title)', 0, 70 );
			WP_CLI::log( sprintf( '  %-8s  %s', $item['kind'] ?? '', $title ) );
			if ( ! empty( $item['channel'] ) ) {
				WP_CLI::log( sprintf( '           %s', $item['channel'] ) );
			}
			if ( ! empty( $item['url'] ) ) {
				WP_CLI::log( sprintf( '           %s', $item['url'] ) );
			}
			WP_CLI::log( '' );
		}
	}

	/**
	 * Upload a video to YouTube.
	 *
	 * Defaults to private visibility. Unverified API projects can only upload
	 * private videos until passing YouTube's compliance audit.
	 *
	 * ## OPTIONS
	 *
	 * <title>
	 * : Video title (max 100 characters).
	 *
	 * [--file=<path>]
	 * : Local path to the video file to upload.
	 *
	 * [--url=<url>]
	 * : Public URL of a video to download and upload.
	 *
	 * [--description=<description>]
	 * : Video description.
	 *
	 * [--category-id=<id>]
	 * : YouTube video category ID (e.g. 10 = Music).
	 *
	 * [--privacy=<status>]
	 * : Privacy status.
	 * ---
	 * default: private
	 * options:
	 *   - private
	 *   - unlisted
	 *   - public
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials youtube upload "Show Recap" --file=/tmp/recap.mp4
	 *     wp datamachine-socials youtube upload "Teaser" --url=https://example.com/clip.mp4 --privacy=unlisted
	 */
	public function upload( $args, $assoc_args ) {
		$title = $args[0] ?? '';

		if ( empty( $title ) ) {
			WP_CLI::error( 'A video title is required.' );
		}

		$this->get_upload_ability();

		$input = array(
			'title'          => $title,
			'privacy_status' => $assoc_args['privacy'] ?? 'private',
		);

		if ( ! empty( $assoc_args['file'] ) ) {
			$input['video_file_path'] = $assoc_args['file'];
		} elseif ( ! empty( $assoc_args['url'] ) ) {
			$input['video_url'] = $assoc_args['url'];
		} else {
			WP_CLI::error( 'Either --file=<path> or --url=<url> is required.' );
		}

		if ( ! empty( $assoc_args['description'] ) ) {
			$input['description'] = $assoc_args['description'];
		}

		if ( ! empty( $assoc_args['category-id'] ) ) {
			$input['category_id'] = $assoc_args['category-id'];
		}

		WP_CLI::log( 'Uploading to YouTube...' );

		$result = \DataMachineSocials\Abilities\YouTube\YouTubeUploadAbility::execute_upload( $input );

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Upload failed' ) );
		}

		WP_CLI::success( 'Uploaded to YouTube!' );
		WP_CLI::log( 'Video ID: ' . ( $result['video_id'] ?? '' ) );
		WP_CLI::log( 'URL:      ' . ( $result['url'] ?? '' ) );
		WP_CLI::log( 'Privacy:  ' . ( $result['privacy_status'] ?? 'private' ) );
	}

	private function get_upload_ability() {
		$ability = wp_get_ability( 'datamachine/youtube-upload' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/youtube-upload ability not registered.' );
		}

		return $ability;
	}
}
