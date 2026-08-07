<?php
/**
 * GitHub bundle source resolver.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes GitHub bundle URLs into archive/raw download URLs.
 */
final class GitHubBundleSourceResolver implements BundleSourceResolverInterface {

	/**
	 * Normalize GitHub URL shapes into fetch URLs.
	 *
	 * @param string $source  Original source URL.
	 * @param array  $context Resolution context.
	 * @return string|null
	 */
	public function normalize( string $source, array $context = array() ): ?string {
		$url = trim( $source );

		if ( ! preg_match( '#^https?://github\.com/#i', $url ) && ! preg_match( '#^https?://raw\.githubusercontent\.com/#i', $url ) ) {
			return null;
		}

		if ( preg_match( '#^https?://raw\.githubusercontent\.com/#i', $url ) ) {
			return $url;
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/archive/refs/heads/(.+)\.zip$#i', $url, $m ) ) {
			return $this->route_archive( $m[1], $m[2], $m[3], $url, $context );
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/archive/([0-9a-f]{7,40})\.zip$#i', $url, $m ) ) {
			return $this->route_archive( $m[1], $m[2], $m[3], $url, $context );
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/blob/(.+)$#i', $url, $m ) ) {
			return "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}";
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/raw/(.+)$#i', $url, $m ) ) {
			return "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}";
		}

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/tree/([^/]+)/?$#i', $url, $m ) ) {
			return $this->route_archive( $m[1], $m[2], $m[3], $url, $context );
		}

		return $url;
	}

	public function accepts_fetch_url( string $fetch_url ): bool {
		return (bool) preg_match(
			'#^https?://api\.github\.com/repos/[^/]+/[^/]+/(zipball|tarball)(/|$)#i',
			$fetch_url
		);
	}

	public function expected_extension( string $fetch_url ): string {
		return $this->accepts_fetch_url( $fetch_url ) ? 'zip' : '';
	}

	public function revision_from_etag( string $fetch_url, string $etag ): ?string {
		unset( $fetch_url );
		return BundleSource::parse_sha_from_etag( $etag );
	}

	private function route_archive( string $owner, string $repo, string $ref, string $original, array $context ): string {
		$token = BundleSourceAuth::token_for( 'https://api.github.com/', $context );
		if ( null !== $token && '' !== $token ) {
			return sprintf(
				'https://api.github.com/repos/%s/%s/zipball/%s',
				$owner,
				$repo,
				$ref
			);
		}

		if ( preg_match( '#/archive/refs/heads/.+\.zip$#i', $original )
			|| preg_match( '#/archive/[0-9a-f]{7,40}\.zip$#i', $original )
		) {
			return $original;
		}

		return sprintf(
			'https://github.com/%s/%s/archive/refs/heads/%s.zip',
			$owner,
			$repo,
			$ref
		);
	}
}
