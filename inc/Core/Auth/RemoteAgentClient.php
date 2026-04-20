<?php
/**
 * Remote Agent Client
 *
 * Outbound HTTP client for cross-site agent communication. Looks up
 * bearer tokens stored by {@see AgentAuthCallback} and attaches them
 * to wp_remote_request() calls targeting another Data Machine site.
 *
 * This is the complement to {@see AgentAuthMiddleware} (which validates
 * inbound bearer tokens) — it consumes stored external tokens so this
 * site's agents can act on behalf of agents on other DM sites.
 *
 * Usage:
 *   $result = RemoteAgentClient::request(
 *       'chubes.net',
 *       'chubes-bot',
 *       'POST',
 *       '/wp-json/datamachine/v1/chat',
 *       array( 'body' => array( 'message' => 'hello' ) )
 *   );
 *
 *   if ( $result['success'] ) {
 *       // $result['body'] is the decoded JSON response
 *   }
 *
 * @package DataMachine\Core\Auth
 * @since 0.71.0
 */

namespace DataMachine\Core\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteAgentClient {

	/**
	 * Default request timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Allowed HTTP methods.
	 */
	const ALLOWED_METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Send an authenticated request to a remote Data Machine site.
	 *
	 * Looks up the stored bearer token for ($remote_site, $agent_slug),
	 * attaches it as an Authorization header, and returns a structured
	 * response suitable for ability output or CLI display.
	 *
	 * The response shape is stable:
	 *   array{
	 *     success: bool,
	 *     status_code: int,
	 *     body: mixed,            // decoded JSON, or raw string if not JSON
	 *     raw_body: string,
	 *     headers: array<string,string>,
	 *     url: string,
	 *     error?: string,
	 *   }
	 *
	 * @param string $remote_site  Remote site domain (e.g., "chubes.net"). Scheme optional.
	 * @param string $agent_slug   Agent slug on the remote site.
	 * @param string $method       HTTP method: GET, POST, PUT, PATCH, DELETE.
	 * @param string $path         Path starting with "/" (e.g., "/wp-json/wp/v2/users/me")
	 *                             or a full URL. If a full URL, its host must match $remote_site.
	 * @param array  $args         Optional args:
	 *                             - body: array|string   Request body (arrays are JSON-encoded)
	 *                             - headers: array       Additional headers (merged)
	 *                             - timeout: int         Timeout in seconds (default 30)
	 *                             - query: array         Query params to append to the URL
	 * @return array Structured response (see shape above).
	 */
	public static function request(
		string $remote_site,
		string $agent_slug,
		string $method,
		string $path,
		array $args = array()
	): array {
		$remote_site = self::normalize_site( $remote_site );
		$method      = strtoupper( trim( $method ) );

		if ( '' === $remote_site ) {
			return self::error_response( '', 'remote_site is required.' );
		}

		if ( '' === $agent_slug ) {
			return self::error_response( '', 'agent_slug is required.' );
		}

		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			return self::error_response(
				'',
				sprintf(
					'Unsupported HTTP method "%s". Allowed: %s.',
					$method,
					implode( ', ', self::ALLOWED_METHODS )
				)
			);
		}

		$token = AgentAuthCallback::get_token( $remote_site, $agent_slug );

		if ( null === $token ) {
			return self::error_response(
				'',
				sprintf(
					'No stored token for "%s/%s". Run `wp datamachine external connect %s %s` to initiate the authorize flow, or `wp datamachine external add` to register a token manually.',
					$remote_site,
					$agent_slug,
					$remote_site,
					$agent_slug
				)
			);
		}

		$url = self::build_url( $remote_site, $path, $args['query'] ?? array() );

		if ( '' === $url ) {
			return self::error_response( '', 'Invalid path or URL.' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		);

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			// Caller-provided headers win except for Authorization, which
			// we always control to guarantee the stored token is used.
			$caller_headers = $args['headers'];
			unset( $caller_headers['Authorization'], $caller_headers['authorization'] );
			$headers = array_merge( $headers, $caller_headers );
		}

		$body_raw = null;
		if ( isset( $args['body'] ) && null !== $args['body'] ) {
			if ( is_array( $args['body'] ) || is_object( $args['body'] ) ) {
				$body_raw = wp_json_encode( $args['body'] );
				if ( ! isset( $headers['Content-Type'] ) ) {
					$headers['Content-Type'] = 'application/json';
				}
			} else {
				$body_raw = (string) $args['body'];
			}
		}

		$timeout = isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : self::DEFAULT_TIMEOUT;

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body_raw,
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();

			do_action(
				'datamachine_log',
				'warning',
				'RemoteAgentClient request failed (transport error)',
				array(
					'remote_site' => $remote_site,
					'agent_slug'  => $agent_slug,
					'method'      => $method,
					'url'         => $url,
					'error'       => $err,
				)
			);

			return self::error_response( $url, $err );
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body      = (string) wp_remote_retrieve_body( $response );
		$response_hdrs = wp_remote_retrieve_headers( $response );

		// Decode JSON if the response looks like JSON; otherwise return raw string.
		$decoded = null;
		if ( '' !== $raw_body ) {
			$decoded = json_decode( $raw_body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$decoded = null;
			}
		}

		$success = $status_code >= 200 && $status_code < 300;

		$headers_array = array();
		if ( is_object( $response_hdrs ) && method_exists( $response_hdrs, 'getAll' ) ) {
			$headers_array = (array) $response_hdrs->getAll();
		} elseif ( is_array( $response_hdrs ) ) {
			$headers_array = $response_hdrs;
		}

		do_action(
			'datamachine_log',
			$success ? 'debug' : 'warning',
			'RemoteAgentClient request completed',
			array(
				'remote_site' => $remote_site,
				'agent_slug'  => $agent_slug,
				'method'      => $method,
				'url'         => $url,
				'status_code' => $status_code,
			)
		);

		$result = array(
			'success'     => $success,
			'status_code' => $status_code,
			'body'        => null !== $decoded ? $decoded : $raw_body,
			'raw_body'    => $raw_body,
			'headers'     => $headers_array,
			'url'         => $url,
		);

		if ( ! $success ) {
			// Surface the remote error message if the body is a WP_Error-style payload.
			$msg = null;
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
					$msg = $decoded['message'];
				} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
					$msg = $decoded['error'];
				}
			}
			$result['error'] = $msg ?? sprintf( 'HTTP %d', $status_code );
		}

		return $result;
	}

	/**
	 * Build the authorize URL for a remote Data Machine site.
	 *
	 * The caller opens this URL in a browser, the human approves on the
	 * remote site, and the remote site redirects to the receiving site's
	 * /agent/auth/callback endpoint with a bearer token.
	 *
	 * @param string $remote_site      Remote site domain.
	 * @param string $remote_agent     Agent slug on the remote site.
	 * @param string $redirect_uri     Callback URL on the receiving site.
	 *                                 Defaults to this site's /agent/auth/callback.
	 * @param string $label            Optional token label.
	 * @return string The authorize URL.
	 */
	public static function build_authorize_url(
		string $remote_site,
		string $remote_agent,
		string $redirect_uri = '',
		string $label = ''
	): string {
		$remote_site = self::normalize_site( $remote_site );

		if ( '' === $redirect_uri ) {
			$redirect_uri = rest_url( 'datamachine/v1/agent/auth/callback' );
		}

		$query = array(
			'agent_slug'   => $remote_agent,
			'redirect_uri' => $redirect_uri,
		);

		if ( '' !== $label ) {
			$query['label'] = $label;
		}

		return add_query_arg(
			$query,
			sprintf( 'https://%s/wp-json/datamachine/v1/agent/authorize', $remote_site )
		);
	}

	/**
	 * Normalize a remote site string to just the host.
	 *
	 * Strips scheme, trailing slashes, and whitespace.
	 *
	 * @param string $site Raw input.
	 * @return string Normalized host, or empty string on failure.
	 */
	public static function normalize_site( string $site ): string {
		$site = trim( $site );
		$site = preg_replace( '#^https?://#i', '', $site );
		$site = rtrim( (string) $site, '/' );

		if ( '' === $site || false !== strpos( $site, ' ' ) ) {
			return '';
		}

		return $site;
	}

	/**
	 * Build a full URL from a site + path (or validated full URL).
	 *
	 * @param string $remote_site Normalized host.
	 * @param string $path        Path starting with "/" or a full URL.
	 * @param array  $query       Optional query params to append.
	 * @return string Full URL, or empty string if invalid.
	 */
	private static function build_url( string $remote_site, string $path, array $query = array() ): string {
		$path = trim( $path );

		if ( '' === $path ) {
			return '';
		}

		// Full URL — validate host matches.
		if ( preg_match( '#^https?://#i', $path ) ) {
			$parsed = wp_parse_url( $path );
			$host   = $parsed['host'] ?? '';
			if ( strcasecmp( $host, $remote_site ) !== 0 ) {
				return '';
			}
			$url = $path;
		} else {
			if ( '/' !== $path[0] ) {
				$path = '/' . $path;
			}
			$url = sprintf( 'https://%s%s', $remote_site, $path );
		}

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $url;
	}

	/**
	 * Build an error response envelope matching the success response shape.
	 *
	 * @param string $url   Target URL (may be empty if URL building failed).
	 * @param string $error Error message.
	 * @return array Error response.
	 */
	private static function error_response( string $url, string $error ): array {
		return array(
			'success'     => false,
			'status_code' => 0,
			'body'        => null,
			'raw_body'    => '',
			'headers'     => array(),
			'url'         => $url,
			'error'       => $error,
		);
	}
}
