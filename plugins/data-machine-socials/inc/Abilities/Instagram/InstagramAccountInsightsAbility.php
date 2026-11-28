<?php
/**
 * Instagram account-level insights ability.
 *
 * Reads the connected professional account's follower snapshot and its
 * account-level totals (reach, views, engagement) over a window of up to 30
 * days. Per-media insights stay in InstagramInsightsAbility.
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

class InstagramAccountInsightsAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	const GRAPH_API_URL = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION;

	/** Account metrics that support `metric_type=total_value` without a breakdown. */
	const METRICS = array( 'reach', 'views', 'accounts_engaged', 'total_interactions', 'profile_links_taps' );

	/** The Graph API refuses total_value windows longer than 30 days. */
	const MAX_DAYS = 30;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/instagram-account-insights',
				array(
					'label'               => __( 'Instagram Account Insights', 'data-machine-socials' ),
					'description'         => __( 'Read follower counts and account-level reach, views, and engagement totals for the connected Instagram account.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'days' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => self::MAX_DAYS,
								'default'     => 28,
								'description' => __( 'Window length in days, ending now (1-30).', 'data-machine-socials' ),
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
		return \DataMachineSocials\PublishAuthorization::can_edit();
	}

	public function execute( array $input ): array|\WP_Error {
		$days = (int) ( $input['days'] ?? 28 );
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return new \WP_Error( 'invalid_param', sprintf( 'days must be between 1 and %d.', self::MAX_DAYS ), array( 'status' => 400 ) );
		}

		$credentials = $this->resolveCredentials( 'instagram', 'Instagram', false, InstagramAuth::class );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$token   = $credentials['token'];
		$account = (array) $credentials['provider']->get_account_details();
		$user_id = (string) ( $account['user_id'] ?? '' );
		if ( '' === $user_id ) {
			return new \WP_Error( 'missing_auth', 'Instagram account ID unavailable. Re-authenticate the Instagram account.', array( 'status' => 401 ) );
		}

		$profile = $this->graphGet(
			self::GRAPH_API_URL . '/' . rawurlencode( $user_id ) . '?' . http_build_query(
				array(
					'fields'       => 'username,followers_count,follows_count,media_count',
					'access_token' => $token,
				)
			)
		);
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$until    = time();
		$since    = $until - $days * DAY_IN_SECONDS;
		$insights = $this->graphGet(
			self::GRAPH_API_URL . '/' . rawurlencode( $user_id ) . '/insights?' . http_build_query(
				array(
					'metric'       => implode( ',', self::METRICS ),
					'period'       => 'day',
					'metric_type'  => 'total_value',
					'since'        => $since,
					'until'        => $until,
					'access_token' => $token,
				)
			)
		);
		if ( is_wp_error( $insights ) ) {
			return $insights;
		}

		$totals = array_fill_keys( self::METRICS, null );
		foreach ( (array) ( $insights['data'] ?? array() ) as $metric ) {
			$name = (string) ( $metric['name'] ?? '' );
			if ( array_key_exists( $name, $totals ) && isset( $metric['total_value']['value'] ) ) {
				$totals[ $name ] = (int) $metric['total_value']['value'];
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'username'        => (string) ( $profile['username'] ?? '' ),
				'followers_count' => isset( $profile['followers_count'] ) ? (int) $profile['followers_count'] : null,
				'follows_count'   => isset( $profile['follows_count'] ) ? (int) $profile['follows_count'] : null,
				'media_count'     => isset( $profile['media_count'] ) ? (int) $profile['media_count'] : null,
				'window'          => array(
					'days'  => $days,
					'since' => gmdate( 'Y-m-d H:i:s', $since ),
					'until' => gmdate( 'Y-m-d H:i:s', $until ),
				),
				// Null means Instagram returned no value for that metric, never zero.
				'totals'          => $totals,
			),
		);
	}

	private function graphGet( string $url ): array|\WP_Error {
		$result = HttpClient::get( $url, array( 'context' => 'Instagram Account Insights' ) );
		$data   = json_decode( (string) ( $result['data'] ?? '' ), true );
		$error  = $data['error'] ?? array();
		if ( 10 === (int) ( $error['code'] ?? 0 ) ) {
			return new \WP_Error( 'missing_scope', 'Instagram insights require instagram_manage_insights. Re-authenticate the Instagram account to grant this scope.', array( 'status' => 403 ) );
		}
		if ( empty( $result['success'] ) || 200 !== (int) ( $result['status_code'] ?? 0 ) || isset( $error['message'] ) ) {
			return new \WP_Error( 'api_error', (string) ( $error['message'] ?? $result['error'] ?? 'Instagram account insights request failed' ), array( 'status' => 500 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
