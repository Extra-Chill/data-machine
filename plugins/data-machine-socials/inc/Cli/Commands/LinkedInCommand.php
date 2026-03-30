<?php
/**
 * WP-CLI LinkedIn Command
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 * @since      0.5.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manage LinkedIn integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     wp datamachine-socials linkedin posts
 *     wp datamachine-socials linkedin post urn:li:share:12345
 *     wp datamachine-socials linkedin publish "Hello from the CLI!"
 *     wp datamachine-socials linkedin delete urn:li:share:12345
 *     wp datamachine-socials linkedin status
 */
class LinkedInCommand {

	/**
	 * List recent posts from your LinkedIn profile.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of posts to return (max 100).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--start=<start>]
	 * : Pagination start index.
	 * ---
	 * default: 0
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
	 */
	public function posts( $args, $assoc_args ) {
		$ability = $this->get_read_ability();

		$result = $ability->execute( array(
			'action' => 'list',
			'limit'  => absint( $assoc_args['limit'] ?? 10 ),
			'start'  => absint( $assoc_args['start'] ?? 0 ),
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$data   = $result['data'];
		$posts  = $data['posts'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} posts" );
		WP_CLI::log( '' );

		foreach ( $posts as $post ) {
			$text = mb_substr( $post['commentary'] ?? '', 0, 70 );
			if ( mb_strlen( $post['commentary'] ?? '' ) > 70 ) {
				$text .= '...';
			}
			$date = isset( $post['createdAt'] )
				? wp_date( 'Y-m-d', intval( $post['createdAt'] / 1000 ) )
				: '';
			$state = $post['lifecycleState'] ?? '';

			WP_CLI::log( sprintf( '  %s  %s  [%s]  %s', $post['id'], $date, $state, $text ) );
		}

		if ( $data['has_next'] ) {
			$next_start = ( $data['start'] ?? 0 ) + count( $posts );
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --start={$next_start}" );
		}
	}

	/**
	 * Get details for a specific LinkedIn post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post URN (e.g., urn:li:share:12345).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function post( $args, $assoc_args ) {
		$post_id = $args[0];
		$ability = $this->get_read_ability();

		$result = $ability->execute( array(
			'action'  => 'get',
			'post_id' => $post_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Post {$post_id}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'ID:         ' . ( $data['id'] ?? '' ) );
		WP_CLI::log( 'Author:     ' . ( $data['author'] ?? '' ) );
		WP_CLI::log( 'Visibility: ' . ( $data['visibility'] ?? '' ) );
		WP_CLI::log( 'State:      ' . ( $data['lifecycleState'] ?? '' ) );

		if ( ! empty( $data['createdAt'] ) ) {
			WP_CLI::log( 'Created:    ' . wp_date( 'Y-m-d H:i:s', intval( $data['createdAt'] / 1000 ) ) );
		}
		if ( ! empty( $data['lastModifiedAt'] ) ) {
			WP_CLI::log( 'Modified:   ' . wp_date( 'Y-m-d H:i:s', intval( $data['lastModifiedAt'] / 1000 ) ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Commentary:' );
		WP_CLI::log( $data['commentary'] ?? '' );
	}

	/**
	 * Publish a post to LinkedIn.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : Post text (up to 3000 characters).
	 *
	 * [--image=<path>]
	 * : Path to a local image file to attach.
	 *
	 * [--visibility=<visibility>]
	 * : Post visibility.
	 * ---
	 * default: PUBLIC
	 * options:
	 *   - PUBLIC
	 *   - CONNECTIONS
	 * ---
	 *
	 * [--article-url=<url>]
	 * : URL for article-type post.
	 *
	 * [--article-title=<title>]
	 * : Title for article-type post.
	 *
	 * ## EXAMPLES
	 *
	 *     # Simple text post
	 *     wp datamachine-socials linkedin publish "Hello from the CLI!"
	 *
	 *     # Post with image
	 *     wp datamachine-socials linkedin publish "Check this out" --image=/tmp/photo.jpg
	 *
	 *     # Article post
	 *     wp datamachine-socials linkedin publish "Great read" --article-url=https://extrachill.com/article --article-title="Article Title"
	 *
	 *     # Connections-only post
	 *     wp datamachine-socials linkedin publish "For my network only" --visibility=CONNECTIONS
	 */
	public function publish( $args, $assoc_args ) {
		$content = $args[0] ?? '';

		if ( empty( $content ) ) {
			WP_CLI::error( 'Post content is required.' );
		}

		if ( mb_strlen( $content ) > 3000 ) {
			WP_CLI::error( 'Post exceeds 3000 characters (' . mb_strlen( $content ) . ' chars).' );
		}

		$input = array(
			'content'    => $content,
			'visibility' => $assoc_args['visibility'] ?? 'PUBLIC',
		);

		if ( ! empty( $assoc_args['image'] ) ) {
			$image_path = $assoc_args['image'];
			if ( ! file_exists( $image_path ) ) {
				WP_CLI::error( "Image file not found: {$image_path}" );
			}
			$input['image_path'] = $image_path;
			WP_CLI::log( 'Publishing LinkedIn post with image...' );
		} elseif ( ! empty( $assoc_args['article-url'] ) ) {
			$input['article_url'] = $assoc_args['article-url'];
			if ( ! empty( $assoc_args['article-title'] ) ) {
				$input['article_title'] = $assoc_args['article-title'];
			}
			WP_CLI::log( 'Publishing LinkedIn article post...' );
		} else {
			WP_CLI::log( 'Publishing LinkedIn post...' );
		}

		$result = \DataMachineSocials\Abilities\LinkedIn\LinkedInPublishAbility::execute_publish( $input );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'Published to LinkedIn!' );
		WP_CLI::log( 'Post ID: ' . ( $result['post_id'] ?? '' ) );
		WP_CLI::log( 'URL:     ' . ( $result['post_url'] ?? '' ) );
	}

	/**
	 * Update a LinkedIn post's commentary.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post URN to update.
	 *
	 * <commentary>
	 * : New commentary text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials linkedin update "urn:li:share:12345" "Updated text"
	 */
	public function update( $args, $assoc_args ) {
		$assoc_args;
		$post_id    = $args[0] ?? '';
		$commentary = $args[1] ?? '';

		if ( empty( $post_id ) || empty( $commentary ) ) {
			WP_CLI::error( 'Both post_id and commentary are required.' );
		}

		$ability = $this->get_update_ability();

		$result = $ability->execute( array(
			'post_id'    => $post_id,
			'commentary' => $commentary,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'LinkedIn post updated successfully!' );
		WP_CLI::log( 'Post ID: ' . $result['post_id'] );
	}

	/**
	 * Delete a LinkedIn post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post URN to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials linkedin delete "urn:li:share:12345"
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$post_id = $args[0];
		$ability = $this->get_delete_ability();

		$result = $ability->execute( array(
			'post_id' => $post_id,
		) );

		if ( is_wp_error( $result ) || ! $result['success'] ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : $result['error'] );
		}

		WP_CLI::success( 'LinkedIn post deleted successfully!' );
		WP_CLI::log( 'Post ID: ' . $result['post_id'] );
	}

	/**
	 * Show LinkedIn authentication status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials linkedin status
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		WP_CLI::log( 'LinkedIn Integration Status' );
		WP_CLI::log( '---' );

		if ( ! $provider ) {
			WP_CLI::log( 'Provider:      Not found' );
			return;
		}

		$authenticated = $provider->is_authenticated();
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes' : 'No' ) );

		$details = $provider->get_account_details();
		if ( $details ) {
			$username = $provider->get_username();
			if ( $username ) {
				WP_CLI::log( 'Name:          ' . $username );
			}
			if ( ! empty( $details['email'] ) ) {
				WP_CLI::log( 'Email:         ' . $details['email'] );
			}
			if ( ! empty( $details['person_id'] ) ) {
				WP_CLI::log( 'Person ID:     ' . $details['person_id'] );
			}
			if ( ! empty( $details['token_expires_at'] ) ) {
				$expires = wp_date( 'Y-m-d H:i:s', intval( $details['token_expires_at'] ) );
				$days    = max( 0, intval( ( intval( $details['token_expires_at'] ) - time() ) / DAY_IN_SECONDS ) );
				WP_CLI::log( "Token expires: {$expires} ({$days} days)" );
			}
			if ( ! empty( $details['last_refreshed_at'] ) ) {
				WP_CLI::log( 'Last refresh:  ' . wp_date( 'Y-m-d H:i:s', intval( $details['last_refreshed_at'] ) ) );
			}
		}

		WP_CLI::log( 'Auth type:     OAuth 2.0 (60-day access tokens with refresh)' );
	}

	private function get_read_ability() {
		$ability = wp_get_ability( 'datamachine/linkedin-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/linkedin-read ability not registered.' );
		}

		return $ability;
	}

	private function get_update_ability() {
		$ability = wp_get_ability( 'datamachine/linkedin-update' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/linkedin-update ability not registered.' );
		}

		return $ability;
	}

	private function get_delete_ability() {
		$ability = wp_get_ability( 'datamachine/linkedin-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/linkedin-delete ability not registered.' );
		}

		return $ability;
	}

	private function get_publish_ability() {
		$ability = wp_get_ability( 'datamachine/linkedin-publish' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/linkedin-publish ability not registered.' );
		}

		return $ability;
	}
}
