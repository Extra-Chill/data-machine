<?php
/**
 * Submit Reddit Ability
 *
 * Abilities API primitive for submitting new posts to Reddit subreddits.
 * Supports self (text) posts and link posts.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Reddit
 * @since      0.7.0
 */

namespace DataMachineSocials\Abilities\Reddit;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class SubmitRedditAbility {

	private static bool $registered = false;

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

	/**
	 * Reddit title max length.
	 */
	private const MAX_TITLE_LENGTH = 300;

	/**
	 * Reddit self-text max length.
	 */
	private const MAX_TEXT_LENGTH = 40000;

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
				'datamachine/submit-reddit',
				array(
					'label'               => __( 'Submit Reddit Post', 'data-machine-socials' ),
					'description'         => __( 'Submit a new text or link post to a Reddit subreddit', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'subreddit', 'title', 'access_token' ),
						'properties' => array(
							'subreddit'    => array(
								'type'        => 'string',
								'description' => __( 'Subreddit name to post to (without "r/").', 'data-machine-socials' ),
							),
							'title'        => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_TITLE_LENGTH,
								'description' => __( 'Post title.', 'data-machine-socials' ),
							),
							'text'         => array(
								'type'        => 'string',
								'default'     => '',
								'maxLength'   => self::MAX_TEXT_LENGTH,
								'description' => __( 'Self-post body text in markdown. Omit for link posts.', 'data-machine-socials' ),
							),
							'url'          => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'URL for link posts. Omit for self (text) posts.', 'data-machine-socials' ),
							),
							'flair_id'     => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Flair template ID (if required by subreddit).', 'data-machine-socials' ),
							),
							'flair_text'   => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Flair text (if editable).', 'data-machine-socials' ),
							),
							'nsfw'         => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Mark as NSFW.', 'data-machine-socials' ),
							),
							'spoiler'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Mark as spoiler.', 'data-machine-socials' ),
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

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Execute Reddit submit.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with post data or error.
	 */
	public function execute( array $input ): array|\WP_Error {
		$subreddit    = sanitize_text_field( $input['subreddit'] ?? '' );
		$title        = trim( sanitize_text_field( $input['title'] ?? '' ) );
		$text         = trim( $input['text'] ?? '' );
		$url          = esc_url_raw( $input['url'] ?? '' );
		$flair_id     = sanitize_text_field( $input['flair_id'] ?? '' );
		$flair_text   = sanitize_text_field( $input['flair_text'] ?? '' );
		$nsfw         = ! empty( $input['nsfw'] );
		$spoiler      = ! empty( $input['spoiler'] );
		$access_token = $input['access_token'] ?? '';

		if ( empty( $subreddit ) ) {
			return new \WP_Error( 'missing_param', 'subreddit is required.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $subreddit ) ) {
			return new \WP_Error( 'missing_param', 'Invalid subreddit name format.', array( 'status' => 400 ) );
		}

		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_param', 'title is required.', array( 'status' => 400 ) );
		}

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_param', 'access_token is required.', array( 'status' => 400 ) );
		}

		// Determine post kind: 'self' for text, 'link' for URL.
		$kind = ! empty( $url ) ? 'link' : 'self';

		if ( 'self' === $kind && mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
		}

		return $this->submitPost( $access_token, $subreddit, $title, $kind, $text, $url, $flair_id, $flair_text, $nsfw, $spoiler );
	}

	/**
	 * Submit a post via Reddit API.
	 *
	 * @param string $access_token OAuth access token.
	 * @param string $subreddit    Subreddit name.
	 * @param string $title        Post title.
	 * @param string $kind         Post type: 'self' or 'link'.
	 * @param string $text         Self-post body.
	 * @param string $url          Link URL.
	 * @param string $flair_id     Flair template ID.
	 * @param string $flair_text   Flair text.
	 * @param bool   $nsfw         NSFW flag.
	 * @param bool   $spoiler      Spoiler flag.
	 * @return array Result.
	 */
	private function submitPost(
		string $access_token,
		string $subreddit,
		string $title,
		string $kind,
		string $text,
		string $url,
		string $flair_id,
		string $flair_text,
		bool $nsfw,
		bool $spoiler
	): array|\WP_Error {
		$api_url = self::API_BASE . '/api/submit';

		$body = array(
			'api_type' => 'json',
			'sr'       => $subreddit,
			'title'    => $title,
			'kind'     => $kind,
		);

		if ( 'self' === $kind && ! empty( $text ) ) {
			$body['text'] = $text;
		} elseif ( 'link' === $kind ) {
			$body['url'] = $url;
		}

		if ( ! empty( $flair_id ) ) {
			$body['flair_id'] = $flair_id;
		}
		if ( ! empty( $flair_text ) ) {
			$body['flair_text'] = $flair_text;
		}
		if ( $nsfw ) {
			$body['nsfw'] = true;
		}
		if ( $spoiler ) {
			$body['spoiler'] = true;
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by Data Machine)',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$result      = json_decode( wp_remote_retrieve_body( $response ), true );

		// Reddit returns errors in json.errors array.
		$errors = $result['json']['errors'] ?? array();
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

		$post_data = $result['json']['data'] ?? array();

		return array(
			'success' => true,
			'data'    => array(
				'post_id'   => $post_data['id'] ?? '',
				'post_name' => $post_data['name'] ?? '',
				'post_url'  => $post_data['url'] ?? '',
				'subreddit' => $subreddit,
				'title'     => $title,
				'kind'      => $kind,
			),
		);
	}
}
