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
	private mixed $installed_payload;
	private string $installed_at;
	private string $updated_at;
	private object $package_artifact;

	public function __construct(
		string $bundle_slug,
		string $bundle_version,
		string $artifact_type,
		string $artifact_id,
		string $source_path,
		?string $installed_hash,
		?string $current_hash,
		string $installed_at,
		string $updated_at,
		mixed $installed_payload = null
	) {
		$this->bundle_slug       = PortableSlug::normalize( $bundle_slug, 'bundle' );
		$this->bundle_version    = self::non_empty_string( $bundle_version, 'bundle_version' );
		$this->artifact_type     = self::validate_artifact_type( $artifact_type );
		$this->artifact_id       = self::non_empty_string( $artifact_id, 'artifact_id' );
		$this->source_path       = self::normalize_source_path( $source_path );
		$this->installed_hash    = self::optional_string( $installed_hash );
		$this->current_hash      = self::optional_string( $current_hash );
		$this->installed_payload = $installed_payload;
		$this->installed_at      = self::non_empty_string( $installed_at, 'installed_at' );
		$this->updated_at        = self::non_empty_string( $updated_at, 'updated_at' );
		$this->package_artifact  = self::build_package_artifact(
			$this->bundle_slug,
			$this->bundle_version,
			$this->artifact_type,
			$this->artifact_id,
			$this->source_path,
			$this->installed_hash,
			$this->current_hash,
			$this->installed_at,
			$this->updated_at,
			$this->installed_payload
		);
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
			(string) $data['updated_at'],
			array_key_exists( 'installed_payload', $data ) ? $data['installed_payload'] : null
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
		return new self(
			$manifest->bundle_slug(),
			$manifest->bundle_version(),
			$artifact_type,
			$artifact_id,
			$source_path,
			$hash,
			$hash,
			$timestamp,
			$timestamp,
			$artifact_payload
		);
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
			$updated_at,
			$this->installed_payload
		);
	}

	/**
	 * Return the persisted install-time payload snapshot, when available.
	 *
	 * Returns null when this row predates the snapshot field or was reconstructed
	 * from a hash-only source (e.g. orphaned runtime artifacts). Callers that
	 * need 3-way merge fidelity (e.g. AgentBundleArtifactRebase) should treat
	 * null as "no base info" and fall back to a more conservative policy.
	 */
	public function installed_payload(): mixed {
		return $this->installed_payload;
	}

	public function to_array(): array {
		$row = self::from_package_artifact_array( call_user_func( array( $this->package_artifact, 'to_array' ) ) );
		if ( null !== $this->installed_payload ) {
			$row['installed_payload'] = $this->installed_payload;
		}
		return $row;
	}

	/**
	 * Returns the underlying Agents API package artifact snapshot.
	 */
	public function package_artifact(): object {
		return $this->package_artifact;
	}

	private static function validate_artifact_type( string $type ): string {
		$type       = AgentBundleArtifactDefinitions::bundle_artifact_type( self::non_empty_string( $type, 'artifact_type' ) );
		$normalized = preg_replace( '/[^a-z0-9_\.\/-]+/', '', $type );
		$normalized = preg_replace( '#/+#', '/', is_string( $normalized ) ? $normalized : '' );
		$normalized = trim( is_string( $normalized ) ? $normalized : '', '/' );

		if ( '' === $normalized || $normalized !== $type ) {
			throw new BundleValidationException( 'installed bundle artifact_type must be a normalized artifact type.' );
		}
		if ( ! in_array( $normalized, BundleSchema::artifact_types(), true ) ) {
			throw new BundleValidationException( 'installed bundle artifact_type must be one of the registered bundle artifact types.' );
		}

		return $normalized;
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
		return BundlePath::normalize_relative( $path, 'source_path', 'installed bundle artifact' );
	}

	private static function build_package_artifact( string $bundle_slug, string $bundle_version, string $artifact_type, string $artifact_id, string $source_path, ?string $installed_hash, ?string $current_hash, string $installed_at, string $updated_at, mixed $installed_payload ): object {
		$artifact_class = 'WP_Agent_Package_Installed_Artifact';

		return new $artifact_class(
			array(
				'package_slug'      => $bundle_slug,
				'package_version'   => $bundle_version,
				'artifact_type'     => self::package_artifact_type( $artifact_type ),
				'artifact_id'       => $artifact_id,
				'source'            => $source_path,
				'installed_hash'    => $installed_hash,
				'current_hash'      => $current_hash,
				'installed_payload' => $installed_payload,
				'installed_at'      => $installed_at,
				'updated_at'        => $updated_at,
			)
		);
	}

	/** @param array<string,mixed> $package_row */
	private static function from_package_artifact_array( array $package_row ): array {
		$row = array(
			'bundle_slug'    => (string) $package_row['package_slug'],
			'bundle_version' => (string) $package_row['package_version'],
			'artifact_type'  => self::bundle_artifact_type( (string) $package_row['artifact_type'] ),
			'artifact_id'    => (string) $package_row['artifact_id'],
			'source_path'    => (string) $package_row['source'],
			'installed_hash' => $package_row['installed_hash'] ?? null,
			'current_hash'   => $package_row['current_hash'] ?? null,
			'status'         => (string) $package_row['status'],
			'installed_at'   => (string) $package_row['installed_at'],
			'updated_at'     => (string) $package_row['updated_at'],
		);

		if ( array_key_exists( 'installed_payload', $package_row ) ) {
			$row['installed_payload'] = $package_row['installed_payload'];
		}

		return $row;
	}

	private static function package_artifact_type( string $type ): string {
		return AgentBundleArtifactDefinitions::package_artifact_type( $type );
	}

	private static function bundle_artifact_type( string $type ): string {
		return AgentBundleArtifactDefinitions::bundle_artifact_type( $type );
	}
}
