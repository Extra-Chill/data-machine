<?php
/**
 * Read-only GitHub REST client.
 *
 * @package DataMachineGitHub
 */

namespace DataMachineGitHub\Api;

use DataMachine\Core\HttpClient;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GitHubClient {

	public function __construct( private readonly string $token = '' ) {}

	public static function encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	public function get_default_branch( string $repo ): string|WP_Error {
		return $this->get_string( $this->get( $repo ), 'default_branch' );
	}

	public function get_latest_commit_sha( string $repo, string $branch ): string|WP_Error {
		return $this->get_string( $this->get_commit( $repo, $branch ), 'sha' );
	}

	/** Return both SHAs so the caller can fetch the pinned tree without another request. */
	public function get_commit( string $repo, string $branch ): array|WP_Error {
		$result = $this->get( $repo, '/commits/' . rawurlencode( $branch ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( array( $result['sha'] ?? null, $result['commit']['tree']['sha'] ?? null ) as $sha ) {
			if ( ! is_string( $sha ) || '' === $sha ) {
				return $this->invalid_response();
			}
		}
		return $result;
	}

	public function get_recursive_tree( string $repo, string $sha ): array|WP_Error {
		$result = $this->get( $repo, '/git/trees/' . rawurlencode( $sha ) . '?recursive=1' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['truncated'] ) ) {
			return new WP_Error( 'github_tree_truncated', __( 'GitHub truncated the recursive tree. File changes cannot be determined from an incomplete tree.', 'data-machine-github' ) );
		}
		if ( ! isset( $result['tree'] ) || ! is_array( $result['tree'] ) || ! array_is_list( $result['tree'] ) ) {
			return $this->invalid_response();
		}
		foreach ( $result['tree'] as $entry ) {
			if ( ! is_string( $entry['path'] ?? null ) || '' === $entry['path'] || ! is_string( $entry['type'] ?? null ) ) {
				return $this->invalid_response();
			}
			if ( 'blob' === $entry['type'] && ( ! is_string( $entry['sha'] ?? null ) || '' === $entry['sha'] || ! is_int( $entry['size'] ?? null ) || $entry['size'] < 0 ) ) {
				return $this->invalid_response();
			}
		}
		return $result['tree'];
	}

	public function get_blob( string $repo, string $sha, int $max_bytes = 1048576 ): string|WP_Error {
		// Allow room for base64, JSON escaping, and response metadata.
		$result  = $this->get( $repo, '/git/blobs/' . rawurlencode( $sha ), 3 * $max_bytes + 4096 );
		$content = $this->decode_contents( $result );
		if ( ! is_wp_error( $content ) && strlen( $content ) > $max_bytes ) {
			return new WP_Error( 'github_blob_too_large', __( 'GitHub returned a blob larger than the configured file size limit.', 'data-machine-github' ) );
		}
		return $content;
	}

	public function get_file_contents( string $repo, string $path, string $ref = '' ): string|WP_Error {
		$query = '' === $ref ? '' : '?ref=' . rawurlencode( $ref );
		return $this->decode_contents( $this->get( $repo, '/contents/' . self::encode_path( $path ) . $query ) );
	}

	private function decode_contents( array|WP_Error $result ): string|WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'base64' !== ( $result['encoding'] ?? null ) || ! is_string( $result['content'] ?? null ) ) {
			return $this->invalid_response();
		}
		$content = base64_decode( $result['content'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- GitHub returns base64-encoded content.
		return false === $content ? $this->invalid_response() : $content;
	}

	private function get_string( array|WP_Error $result, string $key ): string|WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_string( $result[ $key ] ?? null ) && '' !== $result[ $key ] ? $result[ $key ] : $this->invalid_response();
	}

	private function get( string $repo, string $endpoint = '', int $response_limit = 0 ): array|WP_Error {
		if ( ! preg_match( '#^[a-zA-Z0-9-]+/(?!\.{1,2}$)[a-zA-Z0-9_.-]+$#D', $repo ) ) {
			return new WP_Error( 'github_invalid_repo', __( 'GitHub repo must be in owner/name format.', 'data-machine-github' ) );
		}

		$result = HttpClient::get(
			'https://api.github.com/repos/' . self::encode_path( $repo ) . $endpoint,
			array(
				'headers'                   => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				'auth'                      => array( 'type' => 'bearer', 'token' => $this->token ),
				'timeout'                   => 30,
				'context'                   => 'GitHub API',
				'limit_response_size'       => $response_limit,
				'log_response_body_preview' => false,
			)
		);

		$status = (int) ( $result['status_code'] ?? 0 );
		if ( empty( $result['success'] ) || $status < 200 || $status >= 300 ) {
			$message = $status > 0
				/* translators: %d: HTTP status code. */
				? sprintf( __( 'GitHub API returned HTTP %d. Check repository access, branch or file references, and API rate limits.', 'data-machine-github' ), $status )
				: __( 'Could not connect to the GitHub API.', 'data-machine-github' );
			return new WP_Error( 'github_api_error', $message, array( 'status' => $status ) );
		}

		$data = json_decode( $result['data'], true );
		return is_array( $data ) ? $data : $this->invalid_response();
	}

	private function invalid_response(): WP_Error {
		return new WP_Error( 'github_invalid_response', __( 'GitHub API returned an invalid or unsupported response.', 'data-machine-github' ) );
	}
}
