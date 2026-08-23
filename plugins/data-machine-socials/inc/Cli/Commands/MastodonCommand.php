<?php
/**
 * WP-CLI Mastodon Command.
 *
 * @package DataMachineSocials
 * @subpackage Cli\Commands
 * @since 0.17.0
 */

namespace DataMachineSocials\Cli\Commands;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Mastodon\MastodonPublishAbility;
use DataMachineSocials\Handlers\Mastodon\MastodonAuth;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the instance-agnostic Mastodon integration.
 */
class MastodonCommand {

	/**
	 * List statuses from the authenticated account.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of statuses to return. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 */
	public function posts( $args, $assoc_args ) {
		$result = $this->read( array(
			'action' => 'list',
			'limit'  => absint( $assoc_args['limit'] ?? 20 ),
		) );
		$this->output_collection( $result, 'statuses', $assoc_args );
	}

	/**
	 * Show an individual status.
	 *
	 * ## OPTIONS
	 *
	 * <status_id>
	 * : Status ID.
	 */
	public function post( $args, $assoc_args ) {
		$assoc_args;
		$this->output_json( $this->read( array(
			'action'    => 'get',
			'status_id' => $args[0] ?? '',
		) ) );
	}

	/**
	 * Show a status thread context.
	 *
	 * ## OPTIONS
	 *
	 * <status_id>
	 * : Status ID.
	 */
	public function context( $args, $assoc_args ) {
		$assoc_args;
		$this->output_json( $this->read( array(
			'action'    => 'context',
			'status_id' => $args[0] ?? '',
		) ) );
	}

	/**
	 * Show the authenticated account profile.
	 */
	public function profile( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$this->output_json( $this->read( array( 'action' => 'profile' ) ) );
	}

	/**
	 * Read a home or public timeline.
	 *
	 * ## OPTIONS
	 *
	 * [--timeline=<timeline>]
	 * : home or public. Default: home.
	 *
	 * [--limit=<limit>]
	 * : Number of statuses to return. Default: 20.
	 */
	public function timeline( $args, $assoc_args ) {
		$args;
		$result = $this->read( array(
			'action'   => 'timeline',
			'timeline' => $assoc_args['timeline'] ?? 'home',
			'limit'    => absint( $assoc_args['limit'] ?? 20 ),
		) );
		$this->output_collection( $result, 'statuses', $assoc_args );
	}

	/**
	 * Read a hashtag timeline.
	 *
	 * ## OPTIONS
	 *
	 * <tag>
	 * : Hashtag, with or without #.
	 */
	public function hashtag( $args, $assoc_args ) {
		$result = $this->read( array(
			'action' => 'hashtag',
			'tag'    => $args[0] ?? '',
			'limit'  => absint( $assoc_args['limit'] ?? 20 ),
		) );
		$this->output_collection( $result, 'statuses', $assoc_args );
	}

	/**
	 * Search accounts, statuses, or hashtags.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search query.
	 *
	 * [--type=<type>]
	 * : accounts, statuses, or hashtags.
	 */
	public function search( $args, $assoc_args ) {
		$this->output_json( $this->read( array(
			'action'      => 'search',
			'query'       => $args[0] ?? '',
			'search_type' => $assoc_args['type'] ?? '',
			'limit'       => absint( $assoc_args['limit'] ?? 20 ),
		) ) );
	}

	/**
	 * Publish a Mastodon status.
	 *
	 * ## OPTIONS
	 *
	 * <content>
	 * : Status text.
	 *
	 * [--image=<url>]
	 * : Public image URL to attach.
	 *
	 * [--source-url=<url>]
	 * : Source URL to append.
	 *
	 * [--visibility=<visibility>]
	 * : public, unlisted, or private.
	 */
	public function publish( $args, $assoc_args ) {
		$input = array( 'content' => $args[0] ?? '' );
		foreach ( array(
			'image'      => 'image_url',
			'source-url' => 'source_url',
			'visibility' => 'visibility',
		) as $flag => $key ) {
			if ( ! empty( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}

		$result = MastodonPublishAbility::execute_publish( $input );
		$this->assert_success( $result );
		WP_CLI::success( 'Published to Mastodon.' );
		WP_CLI::log( 'Post ID: ' . $result['post_id'] );
		WP_CLI::log( 'URL: ' . $result['post_url'] );
	}

	/**
	 * Delete one of your Mastodon statuses.
	 *
	 * ## OPTIONS
	 *
	 * <status_id>
	 * : Status ID.
	 */
	public function delete( $args, $assoc_args ) {
		$assoc_args;
		$result = $this->ability( 'datamachine/mastodon-delete' )->execute( array( 'status_id' => $args[0] ?? '' ) );
		$this->assert_success( $result );
		WP_CLI::success( 'Status deleted.' );
	}

	/**
	 * Favourite a Mastodon status.
	 *
	 * ## OPTIONS
	 *
	 * <status_id>
	 * : Status ID.
	 */
	public function favourite( $args, $assoc_args ) {
		$assoc_args;
		$this->engage( 'favourite', $args[0] ?? '' );
	}

	/**
	 * Boost (reblog) a Mastodon status.
	 *
	 * ## OPTIONS
	 *
	 * <status_id>
	 * : Status ID.
	 */
	public function boost( $args, $assoc_args ) {
		$assoc_args;
		$this->engage( 'reblog', $args[0] ?? '' );
	}

	/**
	 * Show Mastodon authentication status.
	 */
	public function status( $args, $assoc_args ) {
		$args;
		$assoc_args;
		$provider = ( new AuthAbilities() )->getProvider( 'mastodon' );
		if ( ! $provider ) {
			WP_CLI::log( 'Mastodon provider: not registered' );
			return;
		}
		WP_CLI::log( 'Authenticated: ' . ( $provider->is_authenticated() ? 'Yes' : 'No' ) );
		WP_CLI::log( 'Instance: ' . ( $provider->get_instance() ?? '' ) );
		$details = $provider->get_account_details();
		if ( $details ) {
			WP_CLI::log( 'Account: ' . ( $details['acct'] ?? '' ) );
		}
	}

	/**
	 * Register a Mastodon OAuth application on an instance.
	 *
	 * ## OPTIONS
	 *
	 * <instance>
	 * : Instance URL.
	 */
	public function register_app( $args, $assoc_args ) {
		$assoc_args;
		$result = MastodonAuth::register_app( $args[0] ?? '' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		WP_CLI::log( 'Use the returned client_id with the OAuth authorize URL, then save the resulting access token in Data Machine auth settings.' );
	}

	private function read( array $input ) {
		return $this->ability( 'datamachine/mastodon-read' )->execute( $input );
	}

	private function engage( string $action, string $status_id ): void {
		$result = $this->ability( 'datamachine/mastodon-update' )->execute( array(
			'action'    => $action,
			'status_id' => $status_id,
		) );
		$this->assert_success( $result );
		WP_CLI::success( ucfirst( $action ) . ' completed.' );
	}

	private function ability( string $slug ) {
		$ability = wp_get_ability( $slug );
		if ( ! $ability ) {
			WP_CLI::error( $slug . ' ability not registered.' );
		}
		return $ability;
	}

	private function assert_success( $result ): void {
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			WP_CLI::error( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Mastodon request failed.' ) );
		}
	}

	private function output_json( $result ): void {
		$this->assert_success( $result );
		WP_CLI::log( wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	private function output_collection( $result, string $key, array $assoc_args ): void {
		$this->assert_success( $result );
		$data = $result['data'];
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		foreach ( $data[ $key ] ?? array() as $status ) {
			$text = wp_strip_all_tags( $status['content'] ?? '' );
			WP_CLI::log( sprintf( '%s  %d favourites  %d boosts  %s', $status['id'] ?? '', $status['favourites_count'] ?? 0, $status['reblogs_count'] ?? 0, mb_substr( $text, 0, 100 ) ) );
		}
	}
}
