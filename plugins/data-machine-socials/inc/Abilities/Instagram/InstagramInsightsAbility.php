<?php
/**
 * Instagram media insights ability.
 *
 * @package DataMachineSocials
 * @subpackage Abilities\Instagram
 */

namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAuth;

defined( 'ABSPATH' ) || exit;

class InstagramInsightsAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;
	const FEED_METRICS  = array( 'views', 'reach', 'saved', 'shares', 'total_interactions' );
	const REEL_METRICS  = array( 'views', 'reach', 'saved', 'shares', 'total_interactions', 'ig_reels_avg_watch_time', 'ig_reels_video_view_total_time' );

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/instagram-insights',
				array(
					'label'               => __( 'Instagram Media Insights', 'data-machine-socials' ),
					'description'         => __( 'Read insights for an Instagram media item.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'media_id' => array( 'type' => 'string', 'description' => __( 'Instagram media ID.', 'data-machine-socials' ) ),
						),
						'required'   => array( 'media_id' ),
					),
					'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'data' => array( 'type' => 'object' ), 'error' => array( 'type' => 'string' ) ) ),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return \DataMachineSocials\PublishAuthorization::can_edit();
	}

	public function execute( array $input ): array|\WP_Error {
		if ( empty( $input['media_id'] ) ) {
			return new \WP_Error( 'missing_param', 'media_id is required', array( 'status' => 400 ) );
		}

		$provider = $this->getAuthProvider();
		if ( ! $provider ) {
			return new \WP_Error( 'missing_auth', 'Instagram auth provider not available', array( 'status' => 401 ) );
		}
		$token = $provider->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Instagram access token unavailable. Re-authenticate the Instagram account.', array( 'status' => 401 ) );
		}

		$media_id = rawurlencode( (string) $input['media_id'] );
		$media    = $this->graphGet( self::GRAPH_API_URL . '/' . $media_id . '?' . http_build_query( array( 'fields' => 'media_type,media_product_type', 'access_token' => $token ) ), $token );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$media_type = strtoupper( (string) ( $media['media_type'] ?? '' ) );
		$is_reel    = 'REELS' === strtoupper( (string) ( $media['media_product_type'] ?? '' ) ) || 'VIDEO' === $media_type;
		$metrics    = $is_reel ? self::REEL_METRICS : self::FEED_METRICS;
		$insights   = $this->graphGet( self::GRAPH_API_URL . '/' . $media_id . '/insights?' . http_build_query( array( 'metric' => implode( ',', $metrics ), 'access_token' => $token ) ), $token );
		if ( is_wp_error( $insights ) ) {
			return $insights;
		}

		return array( 'success' => true, 'data' => array( 'media_id' => (string) $input['media_id'], 'media_type' => $media_type, 'metrics' => $insights['data'] ?? array() ) );
	}

	private function getAuthProvider(): ?InstagramAuth {
		$provider = ( new \DataMachine\Abilities\AuthAbilities() )->getProvider( 'instagram' );
		return $provider instanceof InstagramAuth ? $provider : null;
	}

	private function graphGet( string $url, string $token ): array|\WP_Error {
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Insights' ) );
		$data   = json_decode( (string) ( $result['data'] ?? '' ), true );
		$error  = $data['error'] ?? array();
		if ( 10 === (int) ( $error['code'] ?? 0 ) ) {
			return new \WP_Error( 'missing_scope', 'Instagram insights require instagram_manage_insights. Re-authenticate the Instagram account to grant this scope.', array( 'status' => 403 ) );
		}
		if ( empty( $result['success'] ) || 200 !== (int) ( $result['status_code'] ?? 0 ) || isset( $error['message'] ) ) {
			return new \WP_Error( 'api_error', (string) ( $error['message'] ?? $result['error'] ?? 'Instagram insights request failed' ), array( 'status' => 500 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
