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
	 * @param string $source Bundle source — local path or remote URL.
	 * @return string|WP_Error Absolute local filesystem path on success.
	 */
	public static function resolve( string $source ) {
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

		$fetch_url = self::normalize_github_url( $source );

		if ( ! preg_match( '#\.(zip|json)(\?.*)?$#i', $fetch_url ) ) {
			return new WP_Error(
				'datamachine_bundle_source_invalid',
				sprintf(
					'Remote bundle URL must point to a .zip or .json (after GitHub normalization). Got: %s',
					$fetch_url
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$timeout   = (int) apply_filters( 'datamachine_bundle_source_download_timeout', 60, $source );
		$temp_file = download_url( $fetch_url, $timeout );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'datamachine_bundle_source_download_failed',
				sprintf(
					'Failed to download bundle from %s: %s',
					$fetch_url,
					$temp_file->get_error_message()
				)
			);
		}

		// download_url() returns a temp file with no extension. The
		// downstream parser branches on extension (.zip vs .json), so
		// rename the temp file to preserve the extension from the URL.
		$expected_ext = preg_match( '#\.(zip|json)(\?.*)?$#i', $fetch_url, $m ) ? strtolower( $m[1] ) : '';
		if ( '' !== $expected_ext ) {
			$with_ext = $temp_file . '.' . $expected_ext;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $temp_file, $with_ext ) ) {
				$temp_file = $with_ext;
			}
		}

		return $temp_file;
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
	 * - archive/refs/heads/<branch>.zip → unchanged
	 * - archive/<sha>.zip → unchanged
	 * - blob/<ref>/<path>.{zip,json} → raw.githubusercontent.com/.../<ref>/<path>
	 * - raw/<ref>/<path> → already raw, pass through
	 * - tree/<branch> → archive/refs/heads/<branch>.zip
	 *
	 * Bare repo URLs (https://github.com/<org>/<repo>) and any other
	 * shape are returned unchanged. The caller validates the final
	 * extension and rejects unsupported targets.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	public static function normalize_github_url( string $url ): string {
		$url = trim( $url );

		if ( ! preg_match( '#^https?://github\.com/#i', $url ) && ! preg_match( '#^https?://raw\.githubusercontent\.com/#i', $url ) ) {
			return $url;
		}

		// Already a raw.githubusercontent.com URL — pass through.
		if ( preg_match( '#^https?://raw\.githubusercontent\.com/#i', $url ) ) {
			return $url;
		}

		// Already an archive URL — pass through.
		if ( preg_match( '#^https?://github\.com/[^/]+/[^/]+/archive/.+\.zip$#i', $url ) ) {
			return $url;
		}

		// blob/<ref>/<path> → raw URL.
		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/blob/(.+)$#i', $url, $m ) ) {
			return "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}";
		}

		// raw/<ref>/<path> → raw URL.
		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/raw/(.+)$#i', $url, $m ) ) {
			return "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/{$m[3]}";
		}

		// tree/<branch> → archive zip for that branch.
		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/tree/([^/]+)/?$#i', $url, $m ) ) {
			return "https://github.com/{$m[1]}/{$m[2]}/archive/refs/heads/{$m[3]}.zip";
		}

		return $url;
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
