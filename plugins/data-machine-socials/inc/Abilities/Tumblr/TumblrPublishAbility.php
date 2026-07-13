<?php
/**
 * Tumblr Publish Ability
 *
 * Primitive ability for publishing a Neue Post Format (NPF) text post to a
 * Tumblr blog via the v2 API.
 *
 * @package DataMachineSocials\Abilities\Tumblr
 * @since 0.17.0
 */

namespace DataMachineSocials\Abilities\Tumblr;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Tumblr Publish Ability
 */
class TumblrPublishAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.tumblr.com/v2';

	public function __construct() {
		$this->registerAbility( $this->registerCallback() );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tumblr-publish',
				array(
					'label'               => __( 'Publish to Tumblr', 'data-machine-socials' ),
					'description'         => __( 'Create a Tumblr post (Neue Post Format) with a title, body, tags, and source attribution', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'body', 'blog_identifier' ),
						'properties' => array(
							'title'           => array(
								'type'        => 'string',
								'description' => 'Optional post title (rendered as a heading)',
							),
							'body'            => array(
								'type'        => 'string',
								'description' => 'The main text body of the post',
							),
							'tags'            => array(
								'type'        => 'string',
								'description' => 'Comma-separated tags',
							),
							'state'           => array(
								'type'        => 'string',
								'enum'        => array( 'published', 'queue', 'draft' ),
								'description' => 'Post state. Defaults to published.',
							),
							'source_url'      => array(
								'type'        => 'string',
								'format'      => 'uri',
								'description' => 'Source attribution URL (e.g. link back to the origin post)',
							),
							'blog_identifier' => array(
								'type'        => 'string',
								'description' => 'Target Tumblr blog: blog name (e.g. extrachill) or hostname (e.g. extrachill.tumblr.com)',
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
					'permission_callback' => fn() => PermissionHelper::can( 'use_tools' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Execute Tumblr publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with post details or error.
	 */
	public static function execute_publish( array $input ): array|\WP_Error {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'tumblr' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'Tumblr not authenticated', array( 'status' => 401 ) );
		}

		$token = $provider->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Tumblr access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		$body            = trim( (string) ( $input['body'] ?? '' ) );
		$title           = trim( (string) ( $input['title'] ?? '' ) );
		$tags            = trim( (string) ( $input['tags'] ?? '' ) );
		$source_url      = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
		$blog_identifier = sanitize_text_field( (string) ( $input['blog_identifier'] ?? '' ) );

		if ( '' === $body ) {
			return new \WP_Error( 'api_error', 'Missing required field: body', array( 'status' => 500 ) );
		}
		if ( '' === $blog_identifier ) {
			return new \WP_Error( 'api_error', 'Missing required field: blog_identifier', array( 'status' => 500 ) );
		}

		$content_blocks = array();
		if ( '' !== $title ) {
			$content_blocks[] = array(
				'type'    => 'text',
				'subtype' => 'heading1',
				'text'    => $title,
			);
		}
		$content_blocks[] = array(
			'type' => 'text',
			'text' => $body,
		);

		$post_data = array(
			'content' => $content_blocks,
			'state'   => in_array( $input['state'] ?? '', array( 'published', 'queue', 'draft' ), true )
				? $input['state']
				: 'published',
		);
		if ( '' !== $tags ) {
			$post_data['tags'] = $tags;
		}
		if ( '' !== $source_url ) {
			$post_data['source_url'] = $source_url;
		}

		$url = self::API_URL . '/blog/' . rawurlencode( $blog_identifier ) . '/posts';

		$result = HttpClient::post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $post_data ),
			'timeout' => 30,
			'context' => 'Tumblr Post Creation',
		) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to create Tumblr post', array( 'status' => 500 ) );
		}

		$decoded = json_decode( $result['data'], true );
		$id      = is_array( $decoded ) ? ( $decoded['response']['id'] ?? '' ) : '';

		if ( '' === $id ) {
			return new \WP_Error( 'api_error', 'Invalid response from Tumblr API', array( 'status' => 500 ) );
		}

		$post_id  = (string) $id;
		$post_url = 'https://' . \DataMachineSocials\Handlers\Tumblr\Tumblr::hostname_for( $blog_identifier ) . '/post/' . $post_id;

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'post_url' => $post_url,
		);
	}
}
