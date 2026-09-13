<?php
/**
 * Generic artifact manifest helpers.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class ArtifactManifest {

	public const SCHEMA_VERSION = 1;

	/**
	 * Build portable manifest metadata for stored artifact content.
	 *
	 * @param array<string,mixed> $args Manifest arguments.
	 * @return array<string,mixed>
	 */
	public static function create( array $args ): array {
		$content = isset( $args['content'] ) && is_string( $args['content'] ) ? $args['content'] : '';
		$ref     = self::text( $args['artifact_ref'] ?? '' );
		$type    = self::key( $args['artifact_type'] ?? ( $args['type'] ?? '' ) );

		if ( '' === $ref || '' === $type ) {
			return array();
		}

		$manifest = array(
			'artifact_ref'    => $ref,
			'artifact_type'   => $type,
			'type'            => $type,
			'schema_version'  => self::SCHEMA_VERSION,
			'sha256'          => hash( 'sha256', $content ),
			'bytes'           => strlen( $content ),
			'relative_path'   => self::text( $args['relative_path'] ?? '' ),
			'export_url'      => self::url( $args['export_url'] ?? '' ),
			'signed_url'      => self::url( $args['signed_url'] ?? '' ),
			'retention_scope' => self::key( $args['retention_scope'] ?? '' ),
			'payload_sha256'  => self::text( $args['payload_sha256'] ?? '' ),
			'written_at'      => self::text( $args['written_at'] ?? gmdate( 'c' ) ),
			'local_debug'     => is_array( $args['local_debug'] ?? null ) ? self::filter_present( $args['local_debug'] ) : null,
		);

		return self::filter_present( $manifest );
	}

	/**
	 * Resolve an artifact ref from a manifest list.
	 *
	 * @param string              $artifact_ref Portable artifact ref.
	 * @param array<string,mixed> $manifests    Manifest list keyed by artifact key.
	 * @return array{success:bool,artifact?:array<string,mixed>,error?:string}
	 */
	public static function resolve( string $artifact_ref, array $manifests ): array {
		$artifact_ref = self::text( $artifact_ref );
		if ( '' === $artifact_ref ) {
			return array(
				'success' => false,
				'error'   => 'artifact_ref must be a non-empty string.',
			);
		}

		foreach ( $manifests as $manifest ) {
			if ( is_array( $manifest ) && (string) ( $manifest['artifact_ref'] ?? '' ) === $artifact_ref ) {
				return array(
					'success'  => true,
					'artifact' => self::public_metadata( $manifest ),
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'Artifact ref %s was not found.', $artifact_ref ),
		);
	}

	/**
	 * Hydrate and verify artifact content from a caller-provided content resolver.
	 *
	 * @param string   $artifact_ref Portable artifact ref.
	 * @param array    $manifests    Manifest list keyed by artifact key.
	 * @param callable $resolver     Receives public manifest metadata and returns content string or null.
	 * @return array{success:bool,artifact?:array<string,mixed>,content?:string,bytes?:int,sha256?:string,verified?:bool,error?:string}
	 */
	public static function hydrate( string $artifact_ref, array $manifests, callable $resolver ): array {
		$resolved = self::resolve( $artifact_ref, $manifests );
		if ( empty( $resolved['success'] ) || ! is_array( $resolved['artifact'] ?? null ) ) {
			return array(
				'success' => false,
				'error'   => (string) ( $resolved['error'] ?? 'Artifact metadata was not found.' ),
			);
		}

		$artifact = $resolved['artifact'];
		$content  = $resolver( $artifact );
		if ( ! is_string( $content ) ) {
			return array(
				'success' => false,
				'error'   => 'Artifact content is unavailable from configured artifact storage.',
			);
		}

		$bytes  = strlen( $content );
		$sha256 = hash( 'sha256', $content );
		$verify = self::verify( $artifact, $bytes, $sha256 );
		if ( empty( $verify['success'] ) ) {
			return array(
				'success'  => false,
				'artifact' => $artifact,
				'bytes'    => $bytes,
				'sha256'   => $sha256,
				'error'    => (string) ( $verify['error'] ?? 'Artifact content failed integrity verification.' ),
			);
		}

		return array(
			'success'  => true,
			'artifact' => $artifact,
			'content'  => $content,
			'bytes'    => $bytes,
			'sha256'   => $sha256,
			'verified' => true,
		);
	}

	/**
	 * Verify artifact bytes and sha256 against manifest metadata.
	 *
	 * @param array<string,mixed> $artifact Artifact metadata.
	 * @param int                 $bytes    Observed byte count.
	 * @param string              $sha256   Observed sha256.
	 * @return array{success:bool,error?:string}
	 */
	public static function verify( array $artifact, int $bytes, string $sha256 ): array {
		$expected_bytes = isset( $artifact['bytes'] ) ? (int) $artifact['bytes'] : null;
		if ( null !== $expected_bytes && $expected_bytes !== $bytes ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Artifact byte count mismatch: expected %d, got %d.', $expected_bytes, $bytes ),
			);
		}

		$expected_sha256 = strtolower( trim( (string) ( $artifact['sha256'] ?? '' ) ) );
		if ( '' !== $expected_sha256 && ! hash_equals( $expected_sha256, $sha256 ) ) {
			return array(
				'success' => false,
				'error'   => 'Artifact sha256 mismatch.',
			);
		}

		return array( 'success' => true );
	}

	/**
	 * @param array<string,mixed> $artifact Artifact metadata.
	 * @return array<string,mixed>
	 */
	public static function public_metadata( array $artifact ): array {
		unset( $artifact['local_debug'] );
		return $artifact;
	}

	/**
	 * @param array<string,mixed> $value Raw metadata.
	 * @return array<string,mixed>
	 */
	private static function filter_present( array $value ): array {
		if ( class_exists( DataPath::class ) ) {
			return DataPath::filterPresent( $value );
		}

		return array_filter(
			$value,
			static fn( $item ): bool => null !== $item && '' !== $item && array() !== $item
		);
	}

	private static function key( mixed $value ): string {
		return sanitize_key( (string) $value );
	}

	private static function text( mixed $value ): string {
		return sanitize_text_field( (string) $value );
	}

	private static function url( mixed $value ): string {
		$value = trim( (string) $value );
		return '' === $value ? '' : esc_url_raw( $value );
	}
}
