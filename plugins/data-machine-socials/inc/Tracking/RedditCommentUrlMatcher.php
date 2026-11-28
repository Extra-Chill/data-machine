<?php
/**
 * Canonical URL matching for bounded Reddit comment monitoring.
 *
 * @package DataMachineSocials
 * @subpackage Tracking
 */

namespace DataMachineSocials\Tracking;

defined( 'ABSPATH' ) || exit;

class RedditCommentUrlMatcher {

	/**
	 * Extract canonical HTTP(S) URLs that match configured domains.
	 *
	 * @param string   $body               Reddit comment body.
	 * @param string[] $domains            Canonical monitored domains.
	 * @param bool     $include_subdomains Whether child hosts match.
	 * @return array<int,array{domain:string,url:string,host:string}>
	 */
	public static function match( string $body, array $domains, bool $include_subdomains ): array {
		$domains = array_values( array_filter( array_unique( array_map( array( self::class, 'normalizeDomain' ), $domains ) ) ) );
		usort( $domains, static fn( string $left, string $right ): int => strlen( $right ) <=> strlen( $left ) );
		if ( '' === trim( $body ) || empty( $domains ) ) {
			return array();
		}

		$pattern = '~(?<![a-z0-9@._-])(?:https?://)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]{1,5})?(?:/[^\s<>"\'\]\)]*)?~iu';
		preg_match_all( $pattern, html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $found );

		$matches = array();
		foreach ( $found[0] ?? array() as $candidate ) {
			$canonical = self::canonicalizeUrl( (string) $candidate );
			if ( null === $canonical ) {
				continue;
			}

			foreach ( $domains as $domain ) {
				if ( $canonical['host'] !== $domain && ( ! $include_subdomains || ! str_ends_with( $canonical['host'], '.' . $domain ) ) ) {
					continue;
				}

				$key             = $canonical['url'] . '|' . $canonical['host'];
				$matches[ $key ] = array(
					'domain' => $domain,
					'url'    => $canonical['url'],
					'host'   => $canonical['host'],
				);
				break;
			}
		}

		return array_values( $matches );
	}

	/** Normalize a configured domain or URL to its registrable input host. */
	public static function normalizeDomain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		if ( '' === $domain ) {
			return '';
		}

		$host = wp_parse_url( str_contains( $domain, '://' ) ? $domain : 'https://' . $domain, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = self::normalizeHost( $host );
		return preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ? $host : '';
	}

	/**
	 * Canonicalize one URL-like token.
	 *
	 * @return array{url:string,host:string}|null
	 */
	private static function canonicalizeUrl( string $candidate ): ?array {
		$candidate = rtrim( trim( $candidate ), '.,;:!?}>' );
		$url       = preg_match( '~^https?://~i', $candidate ) ? $candidate : 'https://' . $candidate;
		$parts     = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = self::normalizeHost( (string) $parts['host'] );
		if ( '' === $host ) {
			return null;
		}

		$port  = isset( $parts['port'] ) && ! ( 80 === $parts['port'] && 'http' === $scheme ) && ! ( 443 === $parts['port'] && 'https' === $scheme )
			? ':' . (int) $parts['port']
			: '';
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return array(
			'url'  => $scheme . '://' . $host . $port . $path . $query,
			'host' => $host,
		);
	}

	/** Normalize case, IDN form, trailing dot, and conventional www alias. */
	private static function normalizeHost( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = false !== $ascii ? strtolower( $ascii ) : $host;
		}
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
