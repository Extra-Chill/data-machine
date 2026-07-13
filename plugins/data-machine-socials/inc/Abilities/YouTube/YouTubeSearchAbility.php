<?php
/**
 * YouTube Search Ability
 *
 * Primitive ability for searching YouTube via the official Data API v3
 * search.list endpoint. Returns videos, channels, or playlists matching a
 * query. Quota cost: 1 unit per call; default project quota is 100
 * search.list calls/day.
 *
 * @package DataMachineSocials
 * @subpackage Abilities\YouTube
 * @since 0.17.0
 *
 * @link https://developers.google.com/youtube/v3/docs/search/list
 */

namespace DataMachineSocials\Abilities\YouTube;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\YouTube\YouTubeAuth;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube Search Ability
 */
class YouTubeSearchAbility extends AbstractSocialAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	protected static bool $registered = false;

	const API_BASE = 'https://www.googleapis.com/youtube/v3';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	/**
	 * Build the YouTube search ability registration callback.
	 *
	 * @return callable
	 */
	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/youtube-search',
				array(
					'label'               => __( 'Search YouTube', 'data-machine-socials' ),
					'description'         => __( 'Search YouTube for videos, channels, or playlists matching a query (Data API search.list).', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'Search query string',
							),
							'type' => array(
								'type'        => 'string',
								'enum'        => array( 'video', 'channel', 'playlist', 'any' ),
								'default'     => 'video',
								'description' => 'Resource type to search for',
							),
							'limit'     => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => 'Number of results (max 50)',
							),
							'order'     => array(
								'type'        => 'string',
								'enum'        => array( 'relevance', 'date', 'viewCount', 'rating' ),
								'default'     => 'relevance',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'results' => array( 'type' => 'array' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute_search' ),
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Execute a YouTube search.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Search results or error.
	 */
	public function execute_search( array $input ): array|\WP_Error {
		$query = trim( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return new \WP_Error( 'missing_param', 'A search query is required', array( 'status' => 400 ) );
		}

		$provider = self::resolve_auth_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$access_token = $provider->get_valid_access_token();
		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_auth', 'YouTube access token unavailable (expired or refresh failed)', array( 'status' => 401 ) );
		}

		$type  = in_array( $input['type'] ?? '', array( 'video', 'channel', 'playlist', 'any' ), true ) ? $input['type'] : 'video';
		$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
		$order = in_array( $input['order'] ?? '', array( 'relevance', 'date', 'viewCount', 'rating' ), true ) ? $input['order'] : 'relevance';

		$params = array(
			'part'       => 'snippet',
			'q'          => $query,
			'type'       => 'any' === $type ? 'video,channel,playlist' : $type,
			'maxResults' => $limit,
			'order'      => $order,
		);

		$url = self::API_BASE . '/search?' . http_build_query( $params );

		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'YouTube Search',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'api_error', 'YouTube search failed: ' . ( $result['error'] ?? 'unknown error' ), array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'api_error', 'YouTube returned an unparseable search response', array( 'status' => 500 ) );
		}

		if ( ! empty( $data['error']['message'] ) ) {
			return new \WP_Error( 'api_error', $data['error']['message'], array( 'status' => 500 ) );
		}

		$items = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			$id        = $item['id'] ?? array();
			$snippet   = $item['snippet'] ?? array();
			$resource  = '';
			$video_id  = '';
			if ( ! empty( $id['videoId'] ) ) {
				$video_id = (string) $id['videoId'];
				$resource = 'video';
			} elseif ( ! empty( $id['channelId'] ) ) {
				$video_id = (string) $id['channelId'];
				$resource = 'channel';
			} elseif ( ! empty( $id['playlistId'] ) ) {
				$video_id = (string) $id['playlistId'];
				$resource = 'playlist';
			} elseif ( isset( $item['kind'] ) ) {
				$resource = str_replace( 'youtube#', '', (string) $item['kind'] );
			}

			$items[] = array(
				'id'          => $video_id,
				'kind'        => $resource,
				'title'       => (string) ( $snippet['title'] ?? '' ),
				'description' => (string) ( $snippet['description'] ?? '' ),
				'channel'     => (string) ( $snippet['channelTitle'] ?? '' ),
				'publishedAt' => (string) ( $snippet['publishedAt'] ?? '' ),
				'url'         => $video_id ? self::resourceUrl( $resource, $video_id ) : '',
			);
		}

		return array(
			'success' => true,
			'results' => $items,
		);
	}

	/**
	 * Build a canonical YouTube URL for a search result resource.
	 *
	 * @param string $kind Resource kind.
	 * @param string $id   Resource ID.
	 * @return string URL.
	 */
	private static function resourceUrl( string $kind, string $id ): string {
		return match ( $kind ) {
			'channel'  => 'https://www.youtube.com/channel/' . $id,
			'playlist' => 'https://www.youtube.com/playlist?list=' . $id,
			default    => 'https://www.youtube.com/watch?v=' . $id,
		};
	}

	/**
	 * Resolve and authenticate the YouTube provider.
	 *
	 * @return YouTubeAuth|\WP_Error
	 */
	private static function resolve_auth_provider() {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'youtube' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'YouTube not authenticated', array( 'status' => 401 ) );
		}

		return $provider;
	}
}
