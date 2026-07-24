<?php
/**
 * Centralized HTTP client for Data Machine
 *
 * Provides standardized HTTP request handling with consistent headers,
 * error handling, and logging across the entire ecosystem.
 *
 * @package DataMachine\Core
 * @since 0.5.0
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HttpClient {

	private const VALID_METHODS = array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' );

	private const SUCCESS_CODES = array(
		'GET'    => array( 200, 202 ),
		'POST'   => array( 200, 201, 202 ),
		'PUT'    => array( 200, 201, 204 ),
		'PATCH'  => array( 200, 204 ),
		'DELETE' => array( 200, 202, 204 ),
	);

	private const ERROR_KEYS = array( 'message', 'error', 'error_description', 'detail' );

	private const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Perform HTTP request with standardized handling
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
	 * @param string $url     Request URL
	 * @param array  $options Request options:
	 *                        - headers: array - Additional headers to merge
	 *                        - body: string|array - Request body (for POST/PUT/PATCH)
	 *                        - timeout: int - Request timeout (default 120)
	 *                        - proxy_url: string - Optional per-request proxy URL (http, https, socks4, socks5, socks5h)
	 *                        - auth: array - Optional standard auth config: {type: basic, username, password} or {type: bearer, token}
	 *                        - auth_ref: string - Optional provider:account credential reference resolved through registered auth providers
	 *                        - browser_mode: bool - Use browser-like headers (default false)
	 *                        - context: string - Context for logging (default 'HTTP Request')
	 * @return array Response array.
	 */
	public static function request( string $method, string $url, array $options = array() ): array {
		$method       = strtoupper( $method );
		$context      = $options['context'] ?? 'HTTP Request';
		$proxy_filter = null;

		if ( ! in_array( $method, self::VALID_METHODS, true ) ) {
			do_action(
				'datamachine_log',
				'error',
				'HTTP Request: Invalid method',
				array(
					'method'  => $method,
					'context' => $context,
				)
			);
			return array(
				'success' => false,
				'error'   => 'Invalid HTTP method',
			);
		}

		$options = self::resolveAuthRefOptions( $options, $context );
		if ( is_wp_error( $options ) ) {
			return array(
				'success' => false,
				'error'   => $options->get_error_message(),
			);
		}

		$args = self::buildRequestArgs( $method, $options );

		if ( isset( $options['proxy_url'] ) && is_string( $options['proxy_url'] ) && '' !== $options['proxy_url'] ) {
			$proxy_filter = self::createProxyCurlFilter( $options['proxy_url'] );
			add_action( 'http_api_curl', $proxy_filter, 10, 1 );
		}

		try {
			$response = ( 'GET' === $method )
				? wp_remote_get( $url, $args )
				: wp_remote_request( $url, $args );
		} finally {
			if ( null !== $proxy_filter ) {
				remove_action( 'http_api_curl', $proxy_filter, 10 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return self::handleWpError( $response, $method, $url, $context, $args );
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$success_codes = self::SUCCESS_CODES[ $method ];

		if ( ! in_array( $status_code, $success_codes, true ) ) {
			return self::handleHttpError( $status_code, $body, $method, $url, $context );
		}

		return array(
			'success'     => true,
			'data'        => $body,
			'status_code' => $status_code,
			'headers'     => wp_remote_retrieve_headers( $response ),
			'response'    => $response,
		);
	}

	/**
	 * Perform HTTP GET request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	public static function get( string $url, array $options = array() ): array {
		return self::request( 'GET', $url, $options );
	}

	/**
	 * Perform HTTP POST request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	public static function post( string $url, array $options = array() ): array {
		return self::request( 'POST', $url, $options );
	}

	/**
	 * Perform HTTP PUT request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	public static function put( string $url, array $options = array() ): array {
		return self::request( 'PUT', $url, $options );
	}

	/**
	 * Perform HTTP PATCH request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	public static function patch( string $url, array $options = array() ): array {
		return self::request( 'PATCH', $url, $options );
	}

	/**
	 * Perform HTTP DELETE request
	 *
	 * @param string $url     Request URL
	 * @param array  $options Request options
	 * @return array Response array
	 */
	public static function delete( string $url, array $options = array() ): array {
		return self::request( 'DELETE', $url, $options );
	}

	/**
	 * Build request arguments from options
	 */
	private static function buildRequestArgs( string $method, array $options ): array {
		$browser_mode = $options['browser_mode'] ?? false;
		$timeout      = $options['timeout'] ?? 120;

		$default_user_agent = sprintf(
			'DataMachine/%s (+%s)',
			defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : '1.0',
			home_url()
		);

		$default_headers = $browser_mode
			? array(
				'User-Agent'                => self::BROWSER_USER_AGENT,
				'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
				'Accept-Language'           => 'en-US,en;q=0.9',
				'Cache-Control'             => 'no-cache',
				'Pragma'                    => 'no-cache',
				'Upgrade-Insecure-Requests' => '1',
				'Sec-Fetch-Dest'            => 'document',
				'Sec-Fetch-Mode'            => 'navigate',
				'Sec-Fetch-Site'            => 'none',
				'Sec-Fetch-User'            => '?1',
				'Connection'                => 'keep-alive',
			)
			: array(
				'User-Agent' => $default_user_agent,
			);

		$headers = array_merge( $default_headers, $options['headers'] ?? array() );
		$headers = self::applyAuthentication( $headers, $options['auth'] ?? null );

		$args = array(
			'timeout' => $timeout,
			'headers' => $headers,
		);

		if ( 'GET' !== $method ) {
			$args['method'] = $method;
		}

		if ( isset( $options['body'] ) ) {
			$args['body'] = $options['body'];
		}

		return $args;
	}

	/**
	 * Apply standard request authentication when the caller has not provided an Authorization header.
	 */
	private static function applyAuthentication( array $headers, mixed $auth ): array {
		if ( ! is_array( $auth ) || self::hasHeader( $headers, 'Authorization' ) ) {
			return $headers;
		}

		$type = strtolower( (string) ( $auth['type'] ?? '' ) );
		if ( 'basic' === $type ) {
			$username = (string) ( $auth['username'] ?? '' );
			$password = (string) ( $auth['password'] ?? '' );
			if ( '' !== $username || '' !== $password ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic auth requires RFC 7617 base64 encoding.
			}
		}

		if ( 'bearer' === $type ) {
			$token = (string) ( $auth['token'] ?? '' );
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}

		return $headers;
	}

	/**
	 * Resolve a portable auth_ref into concrete request options for this install.
	 */
	private static function resolveAuthRefOptions( array $options, string $context ): array|\WP_Error {
		$ref_string = $options['auth_ref'] ?? null;
		if ( ! is_string( $ref_string ) || '' === trim( $ref_string ) ) {
			return $options;
		}

		try {
			$ref = \DataMachine\Engine\Bundle\AuthRef::from_string( $ref_string );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'http_auth_ref_invalid', $e->getMessage() );
		}

		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$provider  = is_array( $providers ) ? ( $providers[ $ref->provider() ] ?? null ) : null;
		if ( ! $provider instanceof \DataMachine\Core\OAuth\BaseAuthProvider ) {
			return new \WP_Error(
				'http_auth_ref_unresolved',
				sprintf(
					/* translators: 1: auth ref, 2: provider slug. */
					__( 'Auth ref "%1$s" cannot be resolved because provider "%2$s" is not registered on this install.', 'data-machine' ),
					$ref->ref(),
					$ref->provider()
				)
			);
		}

		$resolved = $provider->resolve_auth_ref(
			$ref->account(),
			'http',
			array(
				'context' => $context,
				'runtime' => true,
			)
		);
		if ( is_wp_error( $resolved ) ) {
			$error_code = $resolved->get_error_code();
			return new \WP_Error(
				'' !== $error_code ? $error_code : 'http_auth_ref_unresolved',
				sprintf(
					/* translators: %s: auth ref. */
					__( 'Auth ref "%s" could not be resolved on this install.', 'data-machine' ),
					$ref->ref()
				)
			);
		}

		unset( $options['auth_ref'] );
		return array_replace( $resolved, $options );
	}

	/**
	 * Determine whether a header exists case-insensitively.
	 */
	private static function hasHeader( array $headers, string $needle ): bool {
		foreach ( array_keys( $headers ) as $name ) {
			if ( strtolower( (string) $name ) === strtolower( $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a request-scoped cURL proxy configurator for WordPress HTTP requests.
	 */
	private static function createProxyCurlFilter( string $proxy_url ): callable {
		return static function ( $handle ) use ( $proxy_url ): void {
			if ( function_exists( 'curl_setopt' ) && defined( 'CURLOPT_PROXY' ) ) {
				curl_setopt( $handle, CURLOPT_PROXY, $proxy_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes the cURL handle only through this hook.
			}

			$scheme = strtolower( (string) wp_parse_url( $proxy_url, PHP_URL_SCHEME ) );
			$type   = self::curlProxyTypeForScheme( $scheme );
			if ( function_exists( 'curl_setopt' ) && null !== $type && defined( 'CURLOPT_PROXYTYPE' ) ) {
				curl_setopt( $handle, CURLOPT_PROXYTYPE, $type ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes the cURL handle only through this hook.
			}
		};
	}

	/**
	 * Map common proxy URL schemes to cURL proxy type constants.
	 */
	private static function curlProxyTypeForScheme( string $scheme ): ?int {
		return match ( $scheme ) {
			'socks4' => defined( 'CURLPROXY_SOCKS4' ) ? CURLPROXY_SOCKS4 : null,
			'socks5' => defined( 'CURLPROXY_SOCKS5' ) ? CURLPROXY_SOCKS5 : null,
			'socks5h' => defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ? CURLPROXY_SOCKS5_HOSTNAME : ( defined( 'CURLPROXY_SOCKS5' ) ? CURLPROXY_SOCKS5 : null ),
			'http' => defined( 'CURLPROXY_HTTP' ) ? CURLPROXY_HTTP : null,
			'https' => defined( 'CURLPROXY_HTTPS' ) ? CURLPROXY_HTTPS : ( defined( 'CURLPROXY_HTTP' ) ? CURLPROXY_HTTP : null ),
			default => null,
		};
	}

	/**
	 * Handle WP_Error response
	 */
	private static function handleWpError( \WP_Error $response, string $method, string $url, string $context, array $args = array() ): array {
		$error_message = sprintf(
			'Failed to connect to %1$s: %2$s',
			$context,
			$response->get_error_message()
		);

		do_action(
			'datamachine_log',
			'error',
			'HTTP Request: Connection failed',
			array(
				'context'    => $context,
				'url'        => $url,
				'method'     => $method,
				'error'      => $response->get_error_message(),
				'error_code' => $response->get_error_code(),
				'args'       => self::redactRequestArgsForLog( $args ),
			)
		);

		return array(
			'success' => false,
			'error'   => $error_message,
		);
	}

	/**
	 * Redact sensitive HTTP request data before log emission.
	 */
	private static function redactRequestArgsForLog( array $args ): array {
		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $name => $value ) {
				if ( in_array( strtolower( (string) $name ), array( 'authorization', 'proxy-authorization', 'cookie', 'set-cookie' ), true ) ) {
					$args['headers'][ $name ] = '[redacted]';
				}
			}
		}

		return $args;
	}

	/**
	 * Handle non-success HTTP status code
	 */
	private static function handleHttpError( int $status_code, string $body, string $method, string $url, string $context ): array {
		$error_message = sprintf(
			'%1$s %2$s returned HTTP %3$d',
			$context,
			$method,
			$status_code
		);

		$error_details = self::extractErrorDetails( $body );

		if ( $error_details ) {
			$error_message .= ': ' . $error_details;
		}

		// A received-but-non-2xx response is expected external attrition when probing
		// third-party sites (403 bot-blocks, 404 moved pages, 5xx origin-down) and is
		// not a Data-Machine-side fault. Log it at `warning`; true transport failures
		// (no response at all) are handled separately in handleWpError() and stay `error`.
		do_action(
			'datamachine_log',
			'warning',
			'HTTP Request: Error response',
			array(
				'context'      => $context,
				'url'          => $url,
				'method'       => $method,
				'status_code'  => $status_code,
				'body_preview' => substr( $body, 0, 200 ),
			)
		);

		return array(
			'success' => false,
			'error'   => $error_message,
		);
	}

	/**
	 * Extract error details from response body
	 */
	private static function extractErrorDetails( string $body ): ?string {
		if ( empty( $body ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			foreach ( self::ERROR_KEYS as $key ) {
				if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					return $decoded[ $key ];
				}
			}
		}

		$first_line = strtok( $body, "\n" );
		return strlen( $first_line ) > 100 ? substr( $first_line, 0, 97 ) . '...' : $first_line;
	}
}
