<?php
/**
 * Installed agent bundle artifact tracking value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record shape for a bundle-installed runtime artifact.
 */
final class AgentBundleInstalledArtifact {

	private string $bundle_slug;
	private string $bundle_version;
	private string $artifact_type;
	private string $artifact_id;
	private string $source_path;
	private ?string $installed_hash;
	private ?string $current_hash;
	private string $status;
	private string $installed_at;
	private string $updated_at;

	public function __construct( string $bundle_slug, string $bundle_version, string $artifact_type, string $artifact_id, string $source_path, ?string $installed_hash, ?string $current_hash, string $installed_at, string $updated_at ) {
		$this->bundle_slug    = PortableSlug::normalize( $bundle_slug, 'bundle' );
		$this->bundle_version = self::non_empty_string( $bundle_version, 'bundle_version' );
		$this->artifact_type  = self::validate_artifact_type( $artifact_type );
		$this->artifact_id    = self::non_empty_string( $artifact_id, 'artifact_id' );
		$this->source_path    = self::normalize_source_path( $source_path );
		$this->installed_hash = self::optional_string( $installed_hash );
		$this->current_hash   = self::optional_string( $current_hash );
		$this->status         = AgentBundleArtifactStatus::classify( $this->installed_hash, $this->current_hash );
		$this->installed_at   = self::non_empty_string( $installed_at, 'installed_at' );
		$this->updated_at     = self::non_empty_string( $updated_at, 'updated_at' );
	}

	/**
	 * Build from a stored artifact row shape.
	 *
	 * @param array $data Artifact row data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'bundle_slug', 'bundle_version', 'artifact_type', 'artifact_id', 'source_path', 'installed_hash', 'installed_at', 'updated_at' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'installed bundle artifact is missing required field %s.', esc_html( $field ) ) );
			}
		}

		return new self(
			(string) $data['bundle_slug'],
			(string) $data['bundle_version'],
			(string) $data['artifact_type'],
			(string) $data['artifact_id'],
			(string) $data['source_path'],
			isset( $data['installed_hash'] ) ? (string) $data['installed_hash'] : null,
			isset( $data['current_hash'] ) ? (string) $data['current_hash'] : null,
			(string) $data['installed_at'],
			(string) $data['updated_at']
		);
	}

	/**
	 * Build an installed record from the artifact's install-time payload.
	 *
	 * @param AgentBundleManifest $manifest Manifest that owns the artifact.
	 * @param string              $artifact_type Artifact type.
	 * @param string              $artifact_id Stable artifact identifier.
	 * @param string              $source_path Bundle-local source path/key.
	 * @param mixed               $artifact_payload Artifact payload to hash.
	 * @param string              $timestamp Install/update timestamp.
	 * @return self
	 */
	public static function from_installed_payload( AgentBundleManifest $manifest, string $artifact_type, string $artifact_id, string $source_path, mixed $artifact_payload, string $timestamp ): self {
		$hash = AgentBundleArtifactHasher::hash( $artifact_payload );
		return new self( $manifest->bundle_slug(), $manifest->bundle_version(), $artifact_type, $artifact_id, $source_path, $hash, $hash, $timestamp, $timestamp );
	}

	/**
	 * Return a copy with refreshed current artifact hash/status.
	 *
	 * @param mixed|null $current_payload Current artifact payload, or null when missing.
	 * @param string     $updated_at Updated timestamp.
	 * @return self
	 */
	public function with_current_payload( mixed $current_payload, string $updated_at ): self {
		$current_hash = null === $current_payload ? null : AgentBundleArtifactHasher::hash( $current_payload );

		return new self(
			$this->bundle_slug,
			$this->bundle_version,
			$this->artifact_type,
			$this->artifact_id,
			$this->source_path,
			$this->installed_hash,
			$current_hash,
			$this->installed_at,
			$updated_at
		);
	}

	public function to_array(): array {
		return array(
			'bundle_slug'    => $this->bundle_slug,
			'bundle_version' => $this->bundle_version,
			'artifact_type'  => $this->artifact_type,
			'artifact_id'    => $this->artifact_id,
			'source_path'    => $this->source_path,
			'installed_hash' => $this->installed_hash,
			'current_hash'   => $this->current_hash,
			'status'         => $this->status,
			'installed_at'   => $this->installed_at,
			'updated_at'     => $this->updated_at,
		);
	}

	private static function validate_artifact_type( string $type ): string {
		$type = self::non_empty_string( $type, 'artifact_type' );
		if ( ! in_array( $type, BundleSchema::ARTIFACT_TYPES, true ) ) {
			throw new BundleValidationException( sprintf( 'installed bundle artifact_type must be one of: %s.', esc_html( implode( ', ', BundleSchema::ARTIFACT_TYPES ) ) ) );
		}

		return $type;
	}

	private static function non_empty_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new BundleValidationException( sprintf( 'installed bundle artifact %s must be a non-empty string.', esc_html( $field ) ) );
		}

		return $value;
	}

	private static function optional_string( ?string $value ): ?string {
		$value = null === $value ? '' : trim( $value );
		return '' === $value ? null : $value;
	}

	private static function normalize_source_path( string $path ): string {
		$path = str_replace( '\\', '/', self::non_empty_string( $path, 'source_path' ) );
		$path = ltrim( $path, '/' );
		if ( str_contains( $path, '..' ) ) {
			throw new BundleValidationException( 'installed bundle artifact source_path must be bundle-local.' );
		}

		return $path;
	}
}
