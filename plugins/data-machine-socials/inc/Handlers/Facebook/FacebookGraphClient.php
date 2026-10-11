<?php
/**
 * Facebook Graph Client
 *
 * One small transport for Meta Graph API calls: builds the versioned URL,
 * attaches the access token, JSON-encodes structured parameters the way
 * Graph expects, and normalizes Graph's error envelope into a WP_Error.
 *
 * Abilities hold the business logic; this holds nothing but the wire.
 *
 * @package    DataMachineSocials
 * @subpackage Handlers\Facebook
 */

namespace DataMachineSocials\Handlers\Facebook;

use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

final class FacebookGraphClient {

	/** @var string */
	private string $token;

	/** @var string */
	private string $context;

	/**
	 * @param string $token   Graph access token (user or page).
	 * @param string $context Log context label.
	 */
	public function __construct( string $token, string $context = 'Facebook Graph' ) {
		$this->token   = $token;
		$this->context = $context;
	}

	/**
	 * Client authorized for paid-promotion calls.
	 *
	 * Resolves the Facebook provider, its user token, and a live
	 * ads_management grant, returning an actionable error otherwise.
	 *
	 * @return self|\WP_Error
	 */
	public static function for_ads() {
		$auth = self::auth();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}
		$token = (string) $auth->get_valid_user_access_token();
		if ( '' === $token ) {
			return new \WP_Error( 'missing_auth', 'Facebook is not connected.', array( 'status' => 401 ) );
		}
		if ( ! $auth->has_ads_permission() ) {
			return new \WP_Error( 'ads_permission_missing', 'The Facebook connection has no ads permission. Run `wp datamachine-socials facebook ads-connect` and approve ads access.', array( 'status' => 403 ) );
		}
		return new self( $token, 'Facebook Ads' );
	}

	/**
	 * The registered Facebook auth provider, if any.
	 */
	public static function auth(): ?FacebookAuth {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return null;
		}
		$provider = ( new \DataMachine\Abilities\AuthAbilities() )->getProvider( 'facebook' );
		return $provider instanceof FacebookAuth ? $provider : null;
	}

	/** @return array|\WP_Error */
	public function get( string $path, array $params = array() ) {
		return $this->request( 'GET', $path, $params );
	}

	/** @return array|\WP_Error */
	public function post( string $path, array $params = array() ) {
		return $this->request( 'POST', $path, $params );
	}

	/** @return array|\WP_Error */
	public function delete( string $path ) {
		return $this->request( 'DELETE', $path, array() );
	}

	/**
	 * Call the Graph API.
	 *
	 * @param string $method GET|POST|DELETE.
	 * @param string $path   Path beginning with "/".
	 * @param array  $params Parameters; arrays and bools are JSON-encoded.
	 * @return array|\WP_Error Decoded response body.
	 */
	public function request( string $method, string $path, array $params ) {
		$params['access_token'] = $this->token;
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) || is_bool( $value ) ) {
				$params[ $key ] = wp_json_encode( $value );
			}
		}

		$url = FacebookAuth::GRAPH_API_URL . $path;
		if ( 'POST' === $method ) {
			$result = HttpClient::post(
				$url,
				array(
					'context' => $this->context,
					'timeout' => 60,
					'body'    => $params,
				)
			);
		} else {
			$result = HttpClient::request(
				$method,
				add_query_arg( array_map( 'rawurlencode', $params ), $url ),
				array(
					'context' => $this->context,
					'timeout' => 30,
				)
			);
		}

		$body = json_decode( (string) ( $result['data'] ?? '' ), true );
		if ( ! empty( $result['success'] ) && is_array( $body ) && ! isset( $body['error'] ) ) {
			return $body;
		}

		return new \WP_Error( 'facebook_graph_error', self::error_message( $body, $result ), array( 'status' => (int) ( $result['status_code'] ?? 502 ) ) );
	}

	/**
	 * Most useful message from a Graph error envelope.
	 *
	 * @param mixed $body   Decoded body.
	 * @param array $result HttpClient result.
	 */
	public static function error_message( $body, array $result ): string {
		if ( is_array( $body ) && is_array( $body['error'] ?? null ) ) {
			foreach ( array( 'error_user_msg', 'message' ) as $key ) {
				if ( ! empty( $body['error'][ $key ] ) ) {
					return (string) $body['error'][ $key ];
				}
			}
		}
		return (string) ( $result['error'] ?? 'Graph request failed' );
	}
}
