<?php
/**
 * Memory section artifact metadata.
 *
 * @package DataMachine\Engine\AI\Memory
 */

namespace DataMachine\Engine\AI\Memory;

use DataMachine\Engine\Bundle\AgentBundleArtifactHasher;
use DataMachine\Engine\Bundle\AgentBundleArtifactStatus;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PortableSlug;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable section-level memory artifact row.
 */
final class MemorySectionArtifact {

	public const OWNER_BUNDLE     = 'bundle';
	public const OWNER_USER       = 'user';
	public const OWNER_RUNTIME    = 'runtime';
	public const OWNER_COMPACTION = 'compaction';

	private const OWNERS = array(
		self::OWNER_BUNDLE,
		self::OWNER_USER,
		self::OWNER_RUNTIME,
		self::OWNER_COMPACTION,
	);

	private int $agent_id;
	private string $bundle_slug;
	private string $bundle_version;
	private string $section_id;
	private string $section_heading;
	private string $section_type;
	private string $owner;
	private string $source_path;
	private ?string $installed_hash;
	private ?string $current_hash;
	private string $local_status;
	private string $installed_at;
	private string $updated_at;

	public function __construct( array $data ) {
		$this->agent_id        = absint( $data['agent_id'] ?? 0 );
		$this->bundle_slug     = self::optional_slug( $data['bundle_slug'] ?? '' );
		$this->bundle_version  = self::optional_string( $data['bundle_version'] ?? '' );
		$this->section_heading = self::non_empty_string( $data['section_heading'] ?? ( $data['section'] ?? '' ), 'section_heading' );
		$this->section_id      = self::section_id( $data['section_id'] ?? $this->section_heading );
		$this->section_type    = self::section_type( $data['section_type'] ?? 'operating_note' );
		$this->owner           = self::owner( $data['owner'] ?? self::OWNER_RUNTIME );
		$this->source_path     = self::source_path( $data['source_path'] ?? '' );
		$this->installed_hash  = self::optional_hash( $data['installed_hash'] ?? null );
		$this->current_hash    = self::optional_hash( $data['current_hash'] ?? null );
		$this->local_status    = AgentBundleArtifactStatus::classify( $this->installed_hash, $this->current_hash );
		$this->installed_at    = self::optional_string( $data['installed_at'] ?? '' );
		$this->updated_at      = self::optional_string( $data['updated_at'] ?? '' );

		if ( self::OWNER_BUNDLE === $this->owner && ( '' === $this->bundle_slug || '' === $this->bundle_version || null === $this->installed_hash ) ) {
			throw new BundleValidationException( 'bundle-owned memory section artifacts require bundle_slug, bundle_version, and installed_hash.' );
		}
	}

	public static function from_bundle_section( AgentBundleManifest $manifest, int $agent_id, string $section_heading, string $section_type, string $source_path, string $content, string $timestamp ): self {
		$hash = AgentBundleArtifactHasher::hash( $content );

		return new self(
			array(
				'agent_id'        => $agent_id,
				'bundle_slug'     => $manifest->bundle_slug(),
				'bundle_version'  => $manifest->bundle_version(),
				'section_heading' => $section_heading,
				'section_type'    => $section_type,
				'owner'           => self::OWNER_BUNDLE,
				'source_path'     => $source_path,
				'installed_hash'  => $hash,
				'current_hash'    => $hash,
				'installed_at'    => $timestamp,
				'updated_at'      => $timestamp,
			)
		);
	}

	public static function from_array( array $data ): self {
		return new self( $data );
	}

	public function with_current_content( ?string $content, string $updated_at ): self {
		$data                 = $this->to_array();
		$data['current_hash'] = null === $content ? null : AgentBundleArtifactHasher::hash( $content );
		$data['updated_at']   = $updated_at;
		return new self( $data );
	}

	public function is_bundle_owned(): bool {
		return self::OWNER_BUNDLE === $this->owner;
	}

	public function can_auto_update_from_bundle(): bool {
		return $this->is_bundle_owned() && AgentBundleArtifactStatus::CLEAN === $this->local_status;
	}

	public function should_stage_bundle_update(): bool {
		return $this->is_bundle_owned() && AgentBundleArtifactStatus::MODIFIED === $this->local_status;
	}

	public function local_status(): string {
		return $this->local_status;
	}

	public function to_array(): array {
		return array(
			'agent_id'        => $this->agent_id,
			'bundle_slug'     => $this->bundle_slug,
			'bundle_version'  => $this->bundle_version,
			'section_id'      => $this->section_id,
			'section_heading' => $this->section_heading,
			'section_type'    => $this->section_type,
			'owner'           => $this->owner,
			'source_path'     => $this->source_path,
			'installed_hash'  => $this->installed_hash,
			'current_hash'    => $this->current_hash,
			'local_status'    => $this->local_status,
			'installed_at'    => $this->installed_at,
			'updated_at'      => $this->updated_at,
		);
	}

	private static function section_id( string $value ): string {
		$value = strtolower( self::non_empty_string( $value, 'section_id' ) );
		$value = preg_replace( '/[^a-z0-9._\/-]+/', '-', $value ) ?? '';
		$value = trim( $value, '-/' );

		return self::non_empty_string( $value, 'section_id' );
	}

	private static function section_type( string $value ): string {
		$value = sanitize_key( self::non_empty_string( $value, 'section_type' ) );
		return self::non_empty_string( $value, 'section_type' );
	}

	private static function owner( string $owner ): string {
		$owner = sanitize_key( self::non_empty_string( $owner, 'owner' ) );
		if ( ! in_array( $owner, self::OWNERS, true ) ) {
			throw new BundleValidationException( sprintf( 'memory section owner must be one of: %s.', implode( ', ', self::OWNERS ) ) );
		}

		return $owner;
	}

	private static function optional_slug( string $value ): string {
		$value = trim( $value );
		return '' === $value ? '' : PortableSlug::normalize( $value, 'bundle' );
	}

	private static function optional_string( string $value ): string {
		return trim( $value );
	}

	private static function optional_hash( ?string $value ): ?string {
		$value = null === $value ? '' : trim( $value );
		return '' === $value ? null : $value;
	}

	private static function source_path( string $path ): string {
		$path = str_replace( "\\", '/', trim( $path ) );
		$path = ltrim( $path, '/' );
		if ( str_contains( $path, '..' ) ) {
			throw new BundleValidationException( 'memory section source_path must be bundle-local.' );
		}

		return $path;
	}

	private static function non_empty_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new BundleValidationException( sprintf( 'memory section %s must be a non-empty string.', esc_html( $field ) ) );
		}

		return $value;
	}
}
