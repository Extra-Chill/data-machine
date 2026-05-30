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
use DataMachineSocials\Abilities\AbstractSocialAbility;

defined( 'ABSPATH' ) || exit;

class VoteRedditAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/**
	 * Reddit OAuth API base URL.
	 */
	private const API_BASE = 'https://oauth.reddit.com';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/vote-reddit',
				array(
					'label'               => __( 'Vote on Reddit Post or Comment', 'data-machine-socials' ),
					'description'         => __( 'Upvote, downvote, or unvote a Reddit post or comment', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
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
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Execute Reddit vote.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public function execute( array $input ): array|\WP_Error {
		$thing_id     = sanitize_text_field( $input['thing_id'] ?? '' );
		$direction    = (int) ( $input['direction'] ?? 0 );
		$access_token = $input['access_token'] ?? '';

		if ( empty( $thing_id ) ) {
			return new \WP_Error( 'missing_param', 'thing_id is required.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^t[13]_[a-z0-9]+$/i', $thing_id ) ) {
			return new \WP_Error( 'missing_param', 'Invalid thing_id format. Must be t3_xxx (post) or t1_xxx (comment).', array( 'status' => 400 ) );
		}

		if ( ! in_array( $direction, array( 1, 0, -1 ), true ) ) {
			return new \WP_Error( 'missing_param', 'direction must be 1 (upvote), 0 (unvote), or -1 (downvote).', array( 'status' => 400 ) );
		}

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'missing_param', 'access_token is required.', array( 'status' => 400 ) );
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
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'api_error', $body['message'] ?? "Reddit API returned HTTP {$status_code}", array( 'status' => 500 ) );
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
