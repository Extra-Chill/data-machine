<?php
/**
 * Web Fetch Abilities
 *
 * Read-only retrieval of a public web page as readable text. Exposed as the
 * datamachine/web-fetch ability (REST/MCP) and shared by the web_fetch chat
 * and pipeline tool, so both paths use one implementation and one set of
 * safety rules:
 *
 * - http(s) on the default ports only, no embedded credentials.
 * - Hosts must resolve to public addresses: loopback, private, link-local
 *   (including 169.254.169.254), reserved and unique-local ranges are
 *   rejected before any request, and again for every redirect hop.
 * - Response bodies are capped (2 MB) and returned as extracted text
 *   (50,000 characters max), never raw bytes.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class WebFetchAbilities {

	public const MAX_RESPONSE_BYTES = 2097152;
	public const MAX_CONTENT_CHARS  = 50000;
	public const MAX_REDIRECTS      = 5;
	public const TIMEOUT_SECONDS    = 20;

	private static bool $registered = false;

	/**
	 * Host resolver override for tests: callable( string $host ): string[].
	 *
	 * @var callable|null
	 */
	public static $resolver = null;

	/**
	 * HTTP GET override for tests: callable( string $url, array $options ): array.
	 *
	 * @var callable|null
	 */
	public static $http_get = null;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		AbilityRegistration::on_abilities_api_init( array( $this, 'register' ) );
		self::$registered = true;
	}

	/**
	 * Register datamachine/web-fetch.
	 */
	public function register(): void {
		wp_register_ability(
			'datamachine/web-fetch',
			array(
				'label'               => __( 'Web Fetch', 'data-machine' ),
				'description'         => __( 'Fetch a public web page (http/https) and return its title and readable text (up to 50,000 characters). Use it to read an article, show listing, episode page or profile you have a link for. Internal and private network addresses are refused.', 'data-machine' ),
				'category'            => 'datamachine-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'url' ),
					'properties'           => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'Full http:// or https:// URL of a public web page.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'               => array( 'type' => 'string' ),
						'final_url'         => array( 'type' => 'string' ),
						'title'             => array( 'type' => 'string' ),
						'content'           => array( 'type' => 'string' ),
						'content_length'    => array( 'type' => 'integer' ),
						'content_truncated' => array( 'type' => 'boolean' ),
						'fetch_timestamp'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => fn() => PermissionHelper::can( 'web_fetch' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Ability execute callback.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		return self::fetch( (string) ( $input['url'] ?? '' ) );
	}

	/**
	 * Fetch a public URL and extract readable content.
	 *
	 * @param string $url URL to fetch.
	 * @return array|\WP_Error
	 */
	public static function fetch( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'web_fetch_url_required', 'A url is required.', array( 'status' => 400 ) );
		}

		$current = $url;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$valid = self::validate_public_url( $current );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$result = self::http_get( $current );
			$status = (int) ( $result['status_code'] ?? 0 );

			if ( $status >= 300 && $status < 400 ) {
				$location = self::header_value( $result['headers'] ?? array(), 'location' );
				if ( '' === $location ) {
					return new \WP_Error( 'web_fetch_failed', sprintf( 'Redirect from %s had no Location header.', $current ), array( 'status' => 502 ) );
				}
				$current = self::resolve_location( $current, $location );
				continue;
			}

			if ( empty( $result['success'] ) ) {
				return new \WP_Error( 'web_fetch_failed', sprintf( 'Failed to fetch %s: %s', $current, (string) ( $result['error'] ?? 'unknown error' ) ), array( 'status' => 502 ) );
			}

			$body = (string) ( $result['data'] ?? '' );
			if ( '' === $body ) {
				return new \WP_Error( 'web_fetch_empty', sprintf( 'No content received from %s.', $current ), array( 'status' => 502 ) );
			}

			$extracted = self::extract_readable_content( $body );
			$content   = $extracted['content'];
			$truncated = false;
			if ( strlen( $content ) > self::MAX_CONTENT_CHARS ) {
				$content   = substr( $content, 0, self::MAX_CONTENT_CHARS ) . '... [Content truncated]';
				$truncated = true;
			}

			return array(
				'url'               => $url,
				'final_url'         => $current,
				'title'             => $extracted['title'],
				'content'           => $content,
				'content_length'    => strlen( $content ),
				'content_truncated' => $truncated,
				'fetch_timestamp'   => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		return new \WP_Error( 'web_fetch_too_many_redirects', sprintf( 'Stopped after %d redirects.', self::MAX_REDIRECTS ), array( 'status' => 502 ) );
	}

	/**
	 * Validate that a URL points at a public http(s) host.
	 *
	 * @param string $url URL.
	 * @return true|\WP_Error
	 */
	public static function validate_public_url( string $url ) {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new \WP_Error( 'web_fetch_invalid_url', 'Only http:// and https:// URLs can be fetched.', array( 'status' => 400 ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'web_fetch_invalid_url', 'URLs with embedded credentials are not allowed.', array( 'status' => 400 ) );
		}
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			return new \WP_Error( 'web_fetch_blocked_host', 'Only the default http and https ports are allowed.', array( 'status' => 403 ) );
		}
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return new \WP_Error( 'web_fetch_blocked_host', sprintf( '%s is an internal host.', $host ), array( 'status' => 403 ) );
		}

		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve_host( $host );
		if ( empty( $ips ) ) {
			return new \WP_Error( 'web_fetch_unresolvable', sprintf( '%s could not be resolved.', $host ), array( 'status' => 400 ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'web_fetch_blocked_host', sprintf( '%s resolves to a non-public address.', $host ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Whether an IP address is publicly routable.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$lower = strtolower( $ip );
		/* Ranges PHP's flags miss: CGNAT, link-local and IPv4-mapped IPv6. */
		if ( preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip ) || str_starts_with( $ip, '169.254.' ) || str_starts_with( $ip, '0.' ) ) {
			return false;
		}
		if ( str_starts_with( $lower, 'fe80:' ) || str_starts_with( $lower, 'fc' ) || str_starts_with( $lower, 'fd' ) || str_starts_with( $lower, '::ffff:' ) || '::1' === $lower || '::' === $lower ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract a page title and readable text from HTML.
	 *
	 * @param string $html_content HTML.
	 * @return array{title:string,content:string}
	 */
	public static function extract_readable_content( string $html_content ): array {
		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html_content, $title_matches ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $title_matches[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		$content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html_content );
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );

		foreach ( array( 'nav', 'header', 'footer', 'aside', 'menu' ) as $element ) {
			$content = preg_replace( "/<{$element}[^>]*>.*?<\/{$element}>/is", '', $content );
		}

		foreach ( array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br' ) as $element ) {
			$content = preg_replace( "/<\/{$element}>/i", "</{$element}>\n", $content );
		}

		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( '/\n\s*\n/', "\n\n", $content );
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = trim( $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return array(
			'title'   => $title,
			'content' => $content,
		);
	}

	/**
	 * Resolve a host to IPv4 and IPv6 addresses.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function resolve_host( string $host ): array {
		if ( is_callable( self::$resolver ) ) {
			return (array) call_user_func( self::$resolver, $host );
		}

		$ips = (array) gethostbynamel( $host );
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are reported as unresolvable below.
			foreach ( (array) $records as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values( array_filter( $ips ) );
	}

	/**
	 * Perform one GET without following redirects.
	 *
	 * @param string $url URL.
	 * @return array HttpClient-style result.
	 */
	private static function http_get( string $url ): array {
		$options = array(
			'timeout'             => self::TIMEOUT_SECONDS,
			'browser_mode'        => true,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'context'             => 'Web Fetch',
		);

		if ( is_callable( self::$http_get ) ) {
			return (array) call_user_func( self::$http_get, $url, $options );
		}

		/* Redirects are followed by fetch() so every hop is validated. */
		$no_redirects = static function ( $args ) {
			$args['redirection'] = 0;
			return $args;
		};
		add_filter( 'http_request_args', $no_redirects, 999 );
		try {
			return HttpClient::get( $url, $options );
		} finally {
			remove_filter( 'http_request_args', $no_redirects, 999 );
		}
	}

	/**
	 * Read a header value from HttpClient headers (array or WP headers object).
	 *
	 * @param mixed  $headers Headers.
	 * @param string $name    Lowercase header name.
	 * @return string
	 */
	private static function header_value( $headers, string $name ): string {
		if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
			$value = $headers->offsetGet( $name );
		} else {
			$value = null;
			foreach ( (array) $headers as $key => $header_value ) {
				if ( strtolower( (string) $key ) === $name ) {
					$value = $header_value;
					break;
				}
			}
		}
		if ( is_array( $value ) ) {
			$value = end( $value );
		}

		return trim( (string) $value );
	}

	/**
	 * Resolve a Location header against the current URL.
	 *
	 * @param string $base     Current URL.
	 * @param string $location Location header value.
	 * @return string Absolute URL.
	 */
	private static function resolve_location( string $base, string $location ): string {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			return $location;
		}

		$parts  = wp_parse_url( $base );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $location, '//' ) ) {
			return $parts['scheme'] . ':' . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';

		return $origin . $path . $location;
	}
}
