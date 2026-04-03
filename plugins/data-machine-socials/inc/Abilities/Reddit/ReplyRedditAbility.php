<?php
/**
 * Reply Reddit Ability
 *
 * Abilities API primitive for replying to Reddit posts and comments.
 * Uses the Reddit OAuth API POST /api/comment endpoint.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Reddit
 * @since      0.7.0
 */

namespace DataMachineSocials\Abilities\Reddit;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Abilities\Traits\HasCheckPermission;

defined( 'ABSPATH' ) || exit;

class ReplyRedditAbility {
	use HasCheckPermission;


	private static bool $registered = false;

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

	/**
	 * Reddit's max comment length.
	 */
	private const MAX_COMMENT_LENGTH = 10000;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/reply-reddit',
				array(
					'label'               => __( 'Reply to Reddit Post or Comment', 'data-machine-socials' ),
					'description'         => __( 'Post a reply to a Reddit post or comment', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'thing_id', 'text', 'access_token' ),
						'properties' => array(
							'thing_id'     => array(
								'type'        => 'string',
								'description' => __( 'Reddit fullname of the post (t3_xxx) or comment (t1_xxx) to reply to.', 'data-machine-socials' ),
							),
							'text'         => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_COMMENT_LENGTH,
								'description' => __( 'Reply text in markdown format.', 'data-machine-socials' ),
							),
							'access_token' => array(
								'type'        => 'string',
								'description' => __( 'Reddit OAuth access token.', 'data-machine-socials' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute Reddit reply.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with reply data or error.
	 */
	public function execute( array $input ): array|\WP_Error {
		$thing_id     = sanitize_text_field( $input['thing_id'] ?? '' );
		$text         = trim( $input['text'] ?? '' );
		$access_token = $input['access_token'] ?? '';

		if ( empty( $thing_id ) ) {
			return new \WP_Error( 'missing_param', 'thing_id is required (e.g. t3_abc123 for post, t1_abc123 for comment)', array( 'status' => 400 ) );
		}

		// Validate thing_id format: must be t1_ (comment) or t3_ (post).
		if ( ! preg_match( '/^t[13]_[a-z0-9]+$/i', $thing_id ) ) {
			return new \WP_Error( 'missing_param', 'Invalid thing_id format. Must be t3_xxx (post) or t1_xxx (comment).', array( 'status' => 400 ) );
		}

		if ( empty( $text ) ) {
			return new \WP_Error( 'missing_param', 'Reply text is required.', array( 'status' => 400 ) );
		}

		if ( mb_strlen( $text ) > self::MAX_COMMENT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_COMMENT_LENGTH );
		}

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_param', 'access_token is required.', array( 'status' => 400 ) );
		}

		return $this->postComment( $access_token, $thing_id, $text );
	}

	/**
	 * Post a comment via Reddit API.
	 *
	 * @param string $access_token OAuth access token.
	 * @param string $thing_id     Fullname of parent (t3_ or t1_).
	 * @param string $text         Comment text (markdown).
	 * @return array Result.
	 */
	private function postComment( string $access_token, string $thing_id, string $text ): array|\WP_Error {
		$url = self::API_BASE . '/api/comment';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by Data Machine)',
				),
				'body'    => array(
					'api_type' => 'json',
					'thing_id' => $thing_id,
					'text'     => $text,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		// Reddit returns errors in json.errors array.
		$errors = $body['json']['errors'] ?? array();
		if ( ! empty( $errors ) ) {
			$error_messages = array_map(
				function ( $err ) {
					return is_array( $err ) ? implode( ': ', $err ) : (string) $err;
				},
				$errors
			);
			return new \WP_Error( 'api_error', implode( '; ', $error_messages ), array( 'status' => 500 ) );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error( 'api_error', "Reddit API returned HTTP {$status_code}", array( 'status' => 500 ) );
		}

		// Extract the new comment data.
		$comment_data = $body['json']['data']['things'][0]['data'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'comment_id'  => $comment_data['id'] ?? '',
				'comment_url' => ! empty( $comment_data['permalink'] )
					? 'https://www.reddit.com' . $comment_data['permalink']
					: '',
				'parent_id'   => $thing_id,
				'text'        => $text,
				'author'      => $comment_data['author'] ?? '',
			),
		);
	}
}
