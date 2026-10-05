<?php
/**
 * Bundle source resolver — accept local paths or remote URLs.
 *
 * Hydrates remote agent bundle URLs (HTTPS to .zip/.json or GitHub
 * blob/tree/archive/raw URLs) into a local filesystem path that
 * AgentBundler::from_directory / from_zip / from_json can consume.
 *
 * Local paths pass through unchanged.
 *
 * Cleanup contract: callers MUST invoke {@see self::cleanup()} once they
 * are done with the resolved path (after AgentBundler::import() returns,
 * success OR failure) to delete any temp file that was downloaded.
 *
 * @package DataMachine\Engine\Bundle
 * @since   0.10.3
 */

namespace DataMachine\Engine\Bundle;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve agent bundle source strings into local filesystem paths.
 */
final class BundleSource {

	/**
	 * Last revision (git SHA) parsed from a successful remote resolve.
	 *
	 * Reset at the start of every {@see self::resolve()} call. Best-effort
	 * GitHub-only capture from response ETag — null when the source was
	 * local, the response had no ETag, or the ETag did not parse as a
	 * 40-hex git SHA. Callers that care should snapshot this immediately
	 * after a successful resolve.
	 *
	 * @var string|null
	 */
	private static ?string $last_resolved_revision = null;

	/**
	 * Resolve a bundle source string into a local path.
	 *
	 * Local paths (directories, .zip, .json) that exist on disk are
	 * returned unchanged. HTTP/HTTPS URLs are downloaded to a temporary
	 * file under {@see sys_get_temp_dir()} and that path is returned.
	 *
	 * GitHub repository URLs (blob/tree/archive/raw) are normalized to
	 * a downloadable archive or raw URL before fetching.
	 *
	 * Callers MUST invoke {@see self::cleanup()} after they are done
	 * with the resolved path to delete temp files.
	 *
	 * @param string $source  Bundle source — local path or remote URL.
	 * @param array  $context Optional resolution context (e.g. CLI-supplied token).
	 *                        Recognized keys:
	 *                        - 'cli_token' (string): explicit token supplied by the
	 *                          caller; short-circuits the env/constant/option/filter
	 *                          chain inside {@see BundleSourceAuth::token_for()}.
	 * @return string|WP_Error Absolute local filesystem path on success.
	 */
	public static function resolve( string $source, array $context = array() ) {
		self::$last_resolved_revision = null;

		$source = trim( $source );

		if ( '' === $source ) {
			return new WP_Error(
				'datamachine_bundle_source_invalid',
				'Bundle source is empty.'
			);
		}

		if ( ! self::is_remote( $source ) ) {
			if ( ! file_exists( $source ) ) {
				return new WP_Error(
					'datamachine_bundle_source_invalid',
					sprintf( 'Bundle path not found: %s', $source )
				);
			}

			return $source;
		}

		$fetch_url = self::normalize_remote_url( $source, $context );

		if ( ! preg_match( '#\.(zip|json)(\?.*)?$#i', $fetch_url ) && ! self::resolver_accepts_fetch_url( $fetch_url ) ) {
			return new WP_Error(
				'datamachine_bundle_source_invalid',
				sprintf(
					'Remote bundle URL must point to a .zip or .json (after GitHub normalization). Got: %s',
					$fetch_url
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$args = self::default_download_args( $source, $fetch_url, $context );

		/**
		 * Filter HTTP request args used to download a remote bundle.
		 *
		 * Consumers can inject Authorization headers, custom user-agents,
		 * or any other args supported by wp_safe_remote_get(). The args
		 * include 'stream' => true and 'filename' so the body is streamed
		 * to disk instead of buffered in memory; consumers should leave
		 * those keys alone.
		 *
		 * The token resolution chain in BundleSourceAuth::token_for()
		 * uses this filter via BundleSourceAuth::inject_github_auth() to
		 * auto-attach an Authorization: Bearer header for github.com,
		 * raw.githubusercontent.com, and configured GHE hosts when a
		 * token is available.
		 *
		 * @param array  $args      HTTP args. Includes 'timeout', 'headers',
		 *                          'redirection', 'user-agent', 'stream',
		 *                          'filename', and a private 'datamachine_bundle_source'
		 *                          key carrying the original $source and any
		 *                          $context such as 'cli_token'.
		 * @param string $source    Original (un-normalized) source string.
		 * @param string $fetch_url Normalized URL the request will hit.
		 */
		$args = (array) apply_filters(
			'datamachine_bundle_source_download_args',
			$args,
			$source,
			$fetch_url
		);

		$result = self::fetch_to_tempfile( $fetch_url, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$temp_file = (string) $result['path'];
		if ( ! empty( $result['sha'] ) ) {
			self::$last_resolved_revision = (string) $result['sha'];
		}

		// fetch_to_tempfile() streams to a tempfile with no extension. The
		// downstream parser branches on extension (.zip vs .json), so
		// rename the temp file to preserve the extension from the URL.
		// api.github.com/repos/<o>/<r>/zipball/<ref> URLs have no .zip
		// suffix but always produce a zip archive.
		$expected_ext = preg_match( '#\.(zip|json)(\?.*)?$#i', $fetch_url, $m ) ? strtolower( $m[1] ) : '';
		if ( '' === $expected_ext ) {
			$expected_ext = self::resolver_expected_extension( $fetch_url );
		}
		if ( '' !== $expected_ext ) {
			$with_ext = $temp_file . '.' . $expected_ext;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @rename( $temp_file, $with_ext ) ) {
				$temp_file = $with_ext;
			}
		}

		return $temp_file;
	}

	/**
	 * Return the source revision (git SHA) captured from the most recent
	 * successful remote resolve, or null when none was available.
	 *
	 * GitHub archive responses ship the commit SHA via the ETag header
	 * (`W/"<sha>:<format>"` or `"<sha>"`). When present and parseable,
	 * the SHA is captured here so callers can stamp it onto the bundle
	 * as `source_revision`. Local resolves and resolves that did not
	 * yield an ETag return null.
	 *
	 * @return string|null 40-hex git SHA on success; null otherwise.
	 */
	public static function last_resolved_revision(): ?string {
		return self::$last_resolved_revision;
	}

	/**
	 * Default HTTP args for a remote bundle fetch.
	 *
	 * @param string $source    Original source string.
	 * @param string $fetch_url Normalized URL.
	 * @param array  $context   Optional resolution context (e.g. CLI token).
	 * @return array
	 */
	private static function default_download_args( string $source, string $fetch_url, array $context = array() ): array {
		unset( $fetch_url );

		$timeout    = (int) apply_filters( 'datamachine_bundle_source_download_timeout', 60, $source );
		$user_agent = 'DataMachine/' . ( defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'dev' );

		return array(
			'timeout'                   => $timeout,
			'redirection'               => 5,
			'user-agent'                => $user_agent,
			'headers'                   => array(),
			// Internal handle for filter callbacks that need to know
			// who is calling and which one-off context (e.g. cli_token)
			// applies. Stripped before the request is dispatched so it
			// never reaches the wire.
			'datamachine_bundle_source' => array(
				'source'  => $source,
				'context' => $context,
			),
		);
	}

	/**
	 * Stream a remote URL to a tempfile and capture the response ETag.
	 *
	 * Uses wp_safe_remote_get() to enforce the SSL/host policy WP applies
	 * to user-supplied URLs. The body is streamed directly to disk via
	 * WP_Http's 'stream' + 'filename' args so multi-MB bundles never sit
	 * in PHP memory.
	 *
	 * Cross-host redirects strip the Authorization header before they
	 * fly. WordPress's bundled Requests library forwards all original
	 * headers on redirect, which would re-send a GitHub PAT to the S3
	 * signed URL that `api.github.com/zipball` returns and trigger an
	 * HTTP 400 from S3 ("unrecognized auth scheme"). We follow redirects
	 * manually with `redirection => 0` so we can drop the Authorization
	 * header on every cross-host hop.
	 *
	 * @param string $fetch_url Normalized URL.
	 * @param array  $args      Request args (already passed through the
	 *                          datamachine_bundle_source_download_args filter).
	 * @return array{path:string,etag:?string,sha:?string}|WP_Error
	 */
	private static function fetch_to_tempfile( string $fetch_url, array $args ) {
		$temp_path = wp_tempnam( $fetch_url );
		if ( '' === $temp_path ) {
			return new WP_Error(
				'datamachine_bundle_source_tempfile_failed',
				sprintf( 'Failed to allocate a temporary file for bundle download: %s', $fetch_url )
			);
		}

		// Strip the internal handle before the request flies.
		unset( $args['datamachine_bundle_source'] );

		// Manage redirects ourselves so we can strip the Authorization
		// header on cross-host hops. Cap matches WP's default of 5.
		$max_redirects       = isset( $args['redirection'] ) ? max( 0, (int) $args['redirection'] ) : 5;
		$args['redirection'] = 0;
		$args['stream']      = true;
		$args['filename']    = $temp_path;

		$current_url = $fetch_url;
		$response    = null;

		for ( $hop = 0; $hop <= $max_redirects; $hop++ ) {
			$response = wp_safe_remote_get( $current_url, $args );

			if ( is_wp_error( $response ) ) {
				if ( file_exists( $temp_path ) ) {
					wp_delete_file( $temp_path );
				}

				return new WP_Error(
					'datamachine_bundle_source_download_failed',
					sprintf(
						'Failed to download bundle from %s: %s',
						$current_url,
						$response->get_error_message()
					)
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 300 && $code < 400 ) {
				$location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
				if ( '' === $location ) {
					break;
				}

				$next_url = self::resolve_redirect_url( $current_url, $location );
				if ( '' === $next_url ) {
					break;
				}

				// Cross-host hop → strip Authorization. Same-host hops
				// keep it (mirrors browser/Requests behavior).
				if ( ! self::same_host( $current_url, $next_url ) ) {
					$args = self::strip_authorization( $args );
				}

				// Streaming target needs to be reset because the previous
				// hop may have written response data (a 302 normally has
				// an empty body, but defensively truncate).
				if ( file_exists( $temp_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged
					@file_put_contents( $temp_path, '' );
				}

				$current_url = $next_url;
				continue;
			}

			break;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			if ( file_exists( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}

			if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
				return new WP_Error(
					'datamachine_bundle_source_auth_required',
					sprintf(
						'Authentication required (HTTP %d) for %s. Configure a token via DATAMACHINE_GITHUB_TOKEN, the datamachine_bundle_source_download_args filter, or the --token / --token-env CLI flags.',
						$code,
						$current_url
					)
				);
			}

			if ( $code >= 300 && $code < 400 ) {
				return new WP_Error(
					'datamachine_bundle_source_too_many_redirects',
					sprintf( 'Too many redirects fetching %s', $fetch_url )
				);
			}

			return new WP_Error(
				'datamachine_bundle_source_http_error',
				sprintf( 'HTTP %d fetching %s', $code, $current_url )
			);
		}

		$etag = (string) wp_remote_retrieve_header( $response, 'etag' );
		$sha  = self::revision_from_etag( $current_url, $etag );

		return array(
			'path' => $temp_path,
			'etag' => '' !== $etag ? $etag : null,
			'sha'  => $sha,
		);
	}

	/**
	 * Resolve a redirect Location header against its source URL.
	 *
	 * Handles absolute and protocol-relative URLs. Returns '' when the
	 * Location can't be resolved.
	 *
	 * @param string $base     URL the response came from.
	 * @param string $location Location header value.
	 * @return string Absolute URL or '' on failure.
	 */
	private static function resolve_redirect_url( string $base, string $location ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		// Protocol-relative — //host/path.
		if ( 0 === strpos( $location, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			if ( ! is_string( $scheme ) || '' === $scheme ) {
				$scheme = 'https';
			}
			return $scheme . ':' . $location;
		}

		// Absolute path — /path → reuse scheme + host from base.
		if ( '' !== $location && '/' === $location[0] ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			$host   = wp_parse_url( $base, PHP_URL_HOST );
			if ( ! is_string( $scheme ) || '' === $scheme || ! is_string( $host ) || '' === $host ) {
				return '';
			}
			return $scheme . '://' . $host . $location;
		}

		return '';
	}

	/**
	 * Are these two URLs on the same host (case-insensitive)?
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private static function same_host( string $a, string $b ): bool {
		$ha = wp_parse_url( $a, PHP_URL_HOST );
		$hb = wp_parse_url( $b, PHP_URL_HOST );
		if ( ! is_string( $ha ) || ! is_string( $hb ) || '' === $ha || '' === $hb ) {
			return false;
		}
		return 0 === strcasecmp( $ha, $hb );
	}

	/**
	 * Drop Authorization headers (case-insensitive) from request args.
	 *
	 * Used before following a cross-host redirect so we never re-send a
	 * GitHub PAT to the signed S3 URL that `api.github.com/zipball`
	 * redirects to.
	 *
	 * @param array $args HTTP args.
	 * @return array
	 */
	private static function strip_authorization( array $args ): array {
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			return $args;
		}
		foreach ( $args['headers'] as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
				unset( $args['headers'][ $name ] );
			}
		}
		return $args;
	}

	/**
	 * Parse a git SHA out of a response ETag.
	 *
	 * GitHub archive endpoints serve ETags in a couple of shapes:
	 *
	 *   - `W/"<40-hex>:<format>"` — weak validator for archive endpoints.
	 *   - `"<40-hex>"` — strong validator on some raw / blob endpoints.
	 *
	 * Anything else returns null. The SHA is best-effort: callers must
	 * tolerate a null result rather than fail the install.
	 *
	 * @param string $etag Raw ETag header value.
	 * @return string|null Lower-case 40-hex SHA on success; null otherwise.
	 */
	public static function parse_sha_from_etag( string $etag ): ?string {
		$etag = trim( $etag );
		if ( '' === $etag ) {
			return null;
		}

		// Strip leading W/ weakness marker.
		if ( 0 === strncasecmp( $etag, 'W/', 2 ) ) {
			$etag = substr( $etag, 2 );
		}

		// Strip surrounding double quotes.
		$etag = trim( $etag, '"' );

		// Match either a bare 40-hex SHA or a SHA followed by a colon
		// and an arbitrary format suffix (e.g. ':zipball', ':tarball').
		if ( preg_match( '/^([0-9a-f]{40})(?::.*)?$/i', $etag, $m ) ) {
			return strtolower( $m[1] );
		}

		return null;
	}

	/**
	 * Is this source a remote URL?
	 *
	 * @param string $source Source string.
	 * @return bool
	 */
	public static function is_remote( string $source ): bool {
		return (bool) preg_match( '#^https?://#i', trim( $source ) );
	}

	/**
	 * Normalize a GitHub URL into a downloadable archive or raw URL.
	 *
	 * Cases handled:
	 * - archive/refs/heads/<branch>.zip → API zipball when token configured, else unchanged
	 * - archive/<sha>.zip → API zipball when token configured, else unchanged
	 * - blob/<ref>/<path>.{zip,json} → raw.githubusercontent.com/.../<ref>/<path>
	 * - raw/<ref>/<path> → already raw, pass through
	 * - tree/<branch> → API zipball when token configured, else archive/refs/heads/<branch>.zip
	 *
	 * Bare repo URLs (https://github.com/<org>/<repo>) and any other
	 * shape are returned unchanged. The caller validates the final
	 * extension and rejects unsupported targets.
	 *
	 * The web-host archive endpoint (`github.com/<o>/<r>/archive/...`)
	 * does not accept Personal Access Token authentication — it returns
	 * HTTP 404 even with a valid PAT. The API endpoint
	 * (`api.github.com/repos/<o>/<r>/zipball/<ref>`) accepts both
	 * fine-grained and classic PATs and returns a 302 to a signed S3 URL
	 * containing the archive. To preserve current public-install behavior
	 * (no API rate-limit penalty for unauthenticated users), API routing
	 * only happens when {@see BundleSourceAuth::token_for()} resolves a
	 * token for `api.github.com` from the supplied context — either a
	 * CLI-supplied token or one of the env/constant/option/filter slots.
	 *
	 * GHE follow-up: GHE has the same `api.<host>/repos/.../zipball/<ref>`
	 * pattern, but the current `datamachine_bundle_source_ghe_hosts`
	 * filter shape doesn't carry an API host. GHE archive auth remains
	 * unfixed in this pass and is tracked separately. See PR for #1840.
	 *
	 * @param string $url     URL to normalize.
	 * @param array  $context Optional resolution context — recognized keys
	 *                        match {@see BundleSource::resolve()}. Used to
	 *                        decide whether a token is available before
	 *                        rewriting to the API endpoint.
	 * @return string Normalized URL.
	 */
	public static function normalize_github_url( string $url, array $context = array() ): string {
		return ( new GitHubBundleSourceResolver() )->normalize( $url, $context ) ?? trim( $url );
	}

	/**
	 * Normalize a remote URL through registered source resolvers.
	 *
	 * @param string $url     URL to normalize.
	 * @param array  $context Resolution context.
	 * @return string
	 */
	public static function normalize_remote_url( string $url, array $context = array() ): string {
		$url = trim( $url );
		foreach ( BundleSourceResolverRegistry::source_resolvers() as $resolver ) {
			$normalized = $resolver->normalize( $url, $context );
			if ( is_string( $normalized ) && '' !== $normalized ) {
				return $normalized;
			}
		}

		return $url;
	}

	private static function resolver_accepts_fetch_url( string $url ): bool {
		foreach ( BundleSourceResolverRegistry::source_resolvers() as $resolver ) {
			if ( $resolver->accepts_fetch_url( $url ) ) {
				return true;
			}
		}
		return false;
	}

	private static function resolver_expected_extension( string $url ): string {
		foreach ( BundleSourceResolverRegistry::source_resolvers() as $resolver ) {
			$extension = $resolver->expected_extension( $url );
			if ( '' !== $extension ) {
				return $extension;
			}
		}
		return '';
	}

	private static function revision_from_etag( string $url, string $etag ): ?string {
		foreach ( BundleSourceResolverRegistry::source_resolvers() as $resolver ) {
			$revision = $resolver->revision_from_etag( $url, $etag );
			if ( null !== $revision && '' !== $revision ) {
				return $revision;
			}
		}
		return null;
	}

	/**
	 * Delete the resolved temp file when it differs from the original
	 * source string.
	 *
	 * Safe to call unconditionally: if the resolved path equals the
	 * original local source, this is a no-op. Long-running PHP
	 * processes (wp-cron, action scheduler) need this to avoid leaking
	 * downloaded archives across runs.
	 *
	 * @param string $resolved_path   Path returned by {@see self::resolve()}.
	 * @param string $original_source Original source string passed to resolve().
	 */
	public static function cleanup( string $resolved_path, string $original_source ): void {
		if ( '' === $resolved_path ) {
			return;
		}

		if ( ! self::is_remote( $original_source ) ) {
			return;
		}

		if ( $resolved_path === $original_source ) {
			return;
		}

		if ( ! file_exists( $resolved_path ) ) {
			return;
		}

		// Only delete files inside the system temp dir to avoid
		// accidentally removing user-supplied local paths if the caller
		// passed something unexpected. realpath() both ends because
		// some platforms (macOS) symlink /tmp to /private/tmp.
		$temp_dir_real = realpath( sys_get_temp_dir() );
		$resolved_real = realpath( $resolved_path );
		if ( false === $temp_dir_real || false === $resolved_real ) {
			return;
		}
		$temp_dir_real = rtrim( $temp_dir_real, '/\\' );
		if ( 0 !== strpos( $resolved_real, $temp_dir_real ) ) {
			return;
		}

		wp_delete_file( $resolved_path );
	}
}
