<?php
/**
 * Tumblr Engage Ability
 *
 * Abilities API primitive for engagement actions on Tumblr: liking a post and
 * following a blog. Tumblr does not expose a native comments API, so the
 * supported engagement surface is like + follow.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Tumblr
 * @since      0.17.0
 */

namespace DataMachineSocials\Abilities\Tumblr;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Tumblr\TumblrAuth;
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class TumblrEngageAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const API_URL = 'https://api.tumblr.com/v2';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/tumblr-engage',
				array(
					'label'               => __( 'Engage on Tumblr', 'data-machine-socials' ),
					'description'         => __( 'Like a Tumblr post (requires post id + reblog key) or follow a blog (by URL). Tumblr has no comments API.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'      => array(
								'type'        => 'string',
								'enum'        => array( 'like', 'unlike', 'follow', 'unfollow' ),
								'description' => __( 'Engagement action', 'data-machine-socials' ),
							),
							'post_id'     => array(
								'type'        => 'string',
								'description' => __( 'Post ID (required for like/unlike)', 'data-machine-socials' ),
							),
							'reblog_key'  => array(
								'type'        => 'string',
								'description' => __( 'Reblog key for the post (required for like/unlike)', 'data-machine-socials' ),
							),
							'blog_url'    => array(
								'type'        => 'string',
								'format'      => 'uri',
								'description' => __( 'URL of the blog to follow/unfollow (required for follow/unfollow)', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Tumblr auth provider not available', array( 'status' => 401 ) );
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Tumblr access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		$action = $input['action'] ?? '';

		switch ( $action ) {
			case 'like':
			case 'unlike':
				if ( empty( $input['post_id'] ) || empty( $input['reblog_key'] ) ) {
					return new \WP_Error( 'missing_param', 'post_id and reblog_key are required for like/unlike', array( 'status' => 400 ) );
				}
				return $this->likeAction( $token, 'like' === $action ? 'like' : 'unlike', $input['post_id'], $input['reblog_key'] );

			case 'follow':
			case 'unfollow':
				if ( empty( $input['blog_url'] ) ) {
					return new \WP_Error( 'missing_param', 'blog_url is required for follow/unfollow', array( 'status' => 400 ) );
				}
				return $this->followAction( $token, 'follow' === $action ? 'follow' : 'unfollow', $input['blog_url'] );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use like, unlike, follow, or unfollow.", array( 'status' => 500 ) );
		}
	}

	private function likeAction( string $token, string $verb, string $post_id, string $reblog_key ): array|\WP_Error {
		$url = self::API_URL . '/user/' . $verb;

		$result = HttpClient::post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'id'         => $post_id,
					'reblog_key' => $reblog_key,
				),
				'timeout' => 30,
				'context' => 'Tumblr ' . ucfirst( $verb ),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'post_id' => $post_id,
					'action'  => $verb,
				),
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? "Failed to {$verb} Tumblr post", array( 'status' => 500 ) );
	}

	private function followAction( string $token, string $verb, string $blog_url ): array|\WP_Error {
		$url = self::API_URL . '/user/' . $verb;

		$result = HttpClient::post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'url' => esc_url_raw( $blog_url ),
				),
				'timeout' => 30,
				'context' => 'Tumblr ' . ucfirst( $verb ),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'blog_url' => $blog_url,
					'action'   => $verb,
				),
			);
		}

		return new \WP_Error( 'api_error', $result['error'] ?? "Failed to {$verb} Tumblr blog", array( 'status' => 500 ) );
	}

	private function getAuthProvider(): ?TumblrAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'tumblr' );

		if ( $provider instanceof TumblrAuth ) {
			return $provider;
		}

		return null;
	}
}
