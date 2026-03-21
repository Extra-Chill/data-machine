<?php
/**
 * Vote Reddit Ability
 *
 * Abilities API primitive for upvoting/downvoting Reddit posts and comments.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Reddit
 * @since      0.7.0
 */

namespace DataMachineSocials\Abilities\Reddit;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class VoteRedditAbility {

	private static bool $registered = false;

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

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
				'datamachine/vote-reddit',
				array(
					'label'               => __( 'Vote on Reddit Post or Comment', 'data-machine-socials' ),
					'description'         => __( 'Upvote, downvote, or unvote a Reddit post or comment', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'thing_id', 'direction', 'access_token' ),
						'properties' => array(
							'thing_id'     => array(
								'type'        => 'string',
								'description' => __( 'Reddit fullname of the post (t3_xxx) or comment (t1_xxx) to vote on.', 'data-machine-socials' ),
							),
							'direction'    => array(
								'type'        => 'integer',
								'enum'        => array( 1, 0, -1 ),
								'description' => __( 'Vote direction: 1 (upvote), 0 (unvote), -1 (downvote).', 'data-machine-socials' ),
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
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute Reddit vote.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array {
		$thing_id     = sanitize_text_field( $input['thing_id'] ?? '' );
		$direction    = (int) ( $input['direction'] ?? 0 );
		$access_token = $input['access_token'] ?? '';

		if ( empty( $thing_id ) ) {
			return array(
				'success' => false,
				'error'   => 'thing_id is required.',
			);
		}

		if ( ! preg_match( '/^t[13]_[a-z0-9]+$/i', $thing_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid thing_id format. Must be t3_xxx (post) or t1_xxx (comment).',
			);
		}

		if ( ! in_array( $direction, array( 1, 0, -1 ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'direction must be 1 (upvote), 0 (unvote), or -1 (downvote).',
			);
		}

		if ( empty( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'access_token is required.',
			);
		}

		$url = self::API_BASE . '/api/vote';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'User-Agent'    => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION . ' (by Data Machine)',
				),
				'body'    => array(
					'id'  => $thing_id,
					'dir' => $direction,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'success' => false,
				'error'   => $body['message'] ?? "Reddit API returned HTTP {$status_code}",
			);
		}

		$direction_labels = array(
			1  => 'upvoted',
			0  => 'unvoted',
			-1 => 'downvoted',
		);

		return array(
			'success' => true,
			'data'    => array(
				'thing_id'  => $thing_id,
				'direction' => $direction,
				'action'    => $direction_labels[ $direction ] ?? 'voted',
			),
		);
	}
}
