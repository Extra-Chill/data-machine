<?php
/**
 * Bluesky Publish Ability
 *
 * Primitive ability for publishing content to Bluesky via AT Protocol.
 *
 * @package DataMachineSocials\Abilities\Bluesky
 * @since 0.1.0
 */

namespace DataMachineSocials\Abilities\Bluesky;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Bluesky Publish Ability
 */
class BlueskyPublishAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Register Bluesky publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/bluesky-publish',
				array(
					'label'               => __( 'Publish to Bluesky', 'data-machine-socials' ),
					'description'         => __( 'Post content to Bluesky with optional media', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'content' ),
						'properties' => array(
							'content'    => array(
								'type'        => 'string',
								'description' => 'Post content text',
							),
							'title'      => array(
								'type'        => 'string',
								'description' => 'Post title (optional)',
							),
							'image_url'  => array(
								'type'        => 'string',
								'description' => 'URL to image for the post',
								'format'      => 'uri',
							),
							'source_url' => array(
								'type'        => 'string',
								'description' => 'Source URL to include',
								'format'      => 'uri',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'post_id'  => array( 'type' => 'string' ),
							'post_url' => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Get account ability
			wp_register_ability(
				'datamachine/bluesky-account',
				array(
					'label'               => __( 'Bluesky Account Info', 'data-machine-socials' ),
					'description'         => __( 'Get authenticated Bluesky account details', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'handle'  => array( 'type' => 'string' ),
							'did'     => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'get_account' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute Bluesky publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array {
		$content    = $input['content'] ?? '';
		$title      = $input['title'] ?? '';
		$image_url  = $input['image_url'] ?? '';
		$source_url = $input['source_url'] ?? '';

		if ( empty( $content ) ) {
			return array(
				'success' => false,
				'error'   => 'Content is required',
			);
		}

		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'bluesky' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => 'Bluesky not authenticated',
			);
		}

		// Use the provider's session method which handles auth internally.
		$session = $provider->get_session();
		if ( is_wp_error( $session ) ) {
			return array(
				'success' => false,
				'error'   => $session->get_error_message(),
			);
		}

		$handle       = $session['handle'];
		$did          = $session['did'];
		$access_token = $session['accessJwt'];

		// Upload image if provided
		$image_blob = null;
		if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$image_blob = self::upload_image( $access_token, $image_url );
		}

		// Build post text — do NOT append raw URLs, use embeds/facets instead.
		$post_text = $title ? $title . "\n\n" . $content : $content;

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $post_text,
			'createdAt' => gmdate( 'c' ),
		);

		// Detect URLs in text and create facets for clickable links.
		$facets = self::extract_url_facets( $post_text );
		if ( ! empty( $facets ) ) {
			$record['facets'] = $facets;
		}

		// Embed: explicit image OR link card with OG metadata for source URL.
		if ( $image_blob ) {
			$record['embed'] = array(
				'$type'  => 'app.bsky.embed.images',
				'images' => array(
					array(
						'alt'   => $title ? $title : 'Image',
						'image' => $image_blob,
					),
				),
			);
		} elseif ( ! empty( $source_url ) && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
			// Fetch OG tags from the source URL for a rich link card.
			$og = self::fetch_og_tags( $source_url );

			$card = array(
				'uri'         => $source_url,
				'title'       => $og['title'] ? $og['title'] : ( $title ? $title : $source_url ),
				'description' => $og['description'] ? $og['description'] : mb_substr( $content, 0, 300 ),
			);

			// Upload OG image as thumbnail blob if available.
			if ( ! empty( $og['image'] ) ) {
				$thumb_blob = self::upload_image( $access_token, $og['image'] );
				if ( $thumb_blob ) {
					$card['thumb'] = $thumb_blob;
				}
			}

			$record['embed'] = array(
				'$type'    => 'app.bsky.embed.external',
				'external' => $card,
			);
		}

		// Create post
		$response = wp_remote_post(
			'https://bsky.social/xrpc/com.atproto.repo.createRecord',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'repo'       => $did,
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['uri'] ) ) {
			$parts    = explode( '/', $data['uri'] );
			$post_id  = end( $parts );
			$post_url = "https://bsky.app/profile/{$handle}/post/{$post_id}";

			return array(
				'success'  => true,
				'post_id'  => $post_id,
				'post_url' => $post_url,
			);
		}

		$error_msg = 'Bluesky API error';
		if ( isset( $data['message'] ) ) {
			$error_msg = $data['message'];
		}

		return array(
			'success' => false,
			'error'   => $error_msg,
		);
	}

	/**
	 * Get Bluesky account details.
	 *
	 * @param array $input Ability input.
	 * @return array Account details or error.
	 */
	public static function get_account( array $input ): array {
		$input;
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'bluesky' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => 'Bluesky not authenticated',
			);
		}

		$details = $provider->get_account_details();

		return array(
			'success' => true,
			'handle'  => $details['handle'] ?? '',
			'did'     => '',
		);
	}

	/**
	 * Upload image to Bluesky.
	 *
	 * @param string $access_token Access token.
	 * @param string $image_url Image URL.
	 * @return array|null Blob reference or null.
	 */
	private static function upload_image( string $access_token, string $image_url ): ?array {
		// Download image
		$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$image_data   = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Upload blob
		$response = wp_remote_post(
			'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => $content_type ? $content_type : 'image/jpeg',
				),
				'body'    => $image_data,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 && isset( $data['blob'] ) ) {
			return $data['blob'];
		}

		return null;
	}

	/**
	 * Extract URL facets from post text for Bluesky rich text.
	 *
	 * Bluesky requires explicit facet annotations for URLs to be clickable.
	 * Byte offsets are used (not character offsets) per AT Protocol spec.
	 *
	 * @param string $text Post text to scan for URLs.
	 * @return array Array of facet objects, empty if no URLs found.
	 */
	private static function extract_url_facets( string $text ): array {
		$facets = array();

		// Match URLs in the text.
		$pattern = '/(https?:\/\/[^\s\)\]\}]+)/i';
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $facets;
		}

		foreach ( $matches[0] as $match ) {
			$url        = $match[0];
			$char_start = $match[1];

			// Convert character offset to byte offset (AT Protocol uses UTF-8 bytes).
			$byte_start = strlen( substr( $text, 0, $char_start ) );
			$byte_end   = $byte_start + strlen( $url );

			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte_start,
					'byteEnd'   => $byte_end,
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		return $facets;
	}

	/**
	 * Fetch Open Graph tags from a URL.
	 *
	 * Retrieves og:title, og:description, and og:image from the target page
	 * so link card embeds render with proper metadata and thumbnails.
	 *
	 * @param string $url URL to fetch OG tags from.
	 * @return array Associative array with 'title', 'description', 'image' keys (empty strings if not found).
	 */
	private static function fetch_og_tags( string $url ): array {
		$defaults = array(
			'title'       => '',
			'description' => '',
			'image'       => '',
		);

		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'user-agent' => 'DataMachineSocials/1.0 (link card preview)',
		) );

		if ( is_wp_error( $response ) ) {
			return $defaults;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return $defaults;
		}

		// Parse OG meta tags.
		$og = $defaults;

		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$og['title'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$og['description'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$og['image'] = $m[1];
		}

		// Fallback: try content before property (some sites reverse the attribute order).
		if ( empty( $og['title'] ) && preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m ) ) {
			$og['title'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		if ( empty( $og['description'] ) && preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/i', $html, $m ) ) {
			$og['description'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		if ( empty( $og['image'] ) && preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
			$og['image'] = $m[1];
		}

		return $og;
	}
}
