<?php
/**
 * Agent template source/version metadata value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks which template/bundle installed a local agent and owned artifacts.
 */
final class AgentTemplateMetadata {

	private string $template_slug;
	private string $template_version;
	private string $bundle_slug;
	private string $bundle_version;
	private string $source_ref;
	private string $source_revision;
	private array $installed_hashes;

	public function __construct( string $template_slug, string $template_version, string $bundle_slug, string $bundle_version, string $source_ref = '', string $source_revision = '', array $installed_hashes = array() ) {
		$this->template_slug    = PortableSlug::normalize( $template_slug, 'template' );
		$this->template_version = self::non_empty_string( $template_version, 'template_version' );
		$this->bundle_slug      = PortableSlug::normalize( $bundle_slug, 'bundle' );
		$this->bundle_version   = self::non_empty_string( $bundle_version, 'bundle_version' );
		$this->source_ref       = self::optional_string( $source_ref );
		$this->source_revision  = self::optional_string( $source_revision );
		$this->installed_hashes = self::normalize_hashes( $installed_hashes );
	}

	public static function from_array( array $data ): self {
		foreach ( array( 'template_slug', 'template_version', 'bundle_slug', 'bundle_version' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'agent template metadata is missing required field %s.', esc_html( $field ) ) );
			}
		}

		if ( isset( $data['installed_hashes'] ) && ! is_array( $data['installed_hashes'] ) ) {
			throw new BundleValidationException( 'agent template metadata installed_hashes must be an object.' );
		}

		return new self(
			(string) $data['template_slug'],
			(string) $data['template_version'],
			(string) $data['bundle_slug'],
			(string) $data['bundle_version'],
			(string) ( $data['source_ref'] ?? '' ),
			(string) ( $data['source_revision'] ?? '' ),
			$data['installed_hashes'] ?? array()
		);
	}

	public static function from_manifest( AgentBundleManifest $manifest, string $template_slug = '', string $template_version = '', array $installed_hashes = array() ): self {
		$manifest_data = $manifest->to_array();
		$agent_slug    = (string) ( $manifest_data['agent']['slug'] ?? 'template' );

		return new self(
			'' !== trim( $template_slug ) ? $template_slug : $agent_slug,
			'' !== trim( $template_version ) ? $template_version : $manifest->bundle_version(),
			$manifest->bundle_slug(),
			$manifest->bundle_version(),
			$manifest->source_ref(),
			$manifest->source_revision(),
			$installed_hashes
		);
	}

	public function to_array(): array {
		return array(
			'template_slug'    => $this->template_slug,
			'template_version' => $this->template_version,
			'bundle_slug'      => $this->bundle_slug,
			'bundle_version'   => $this->bundle_version,
			'source_ref'       => $this->source_ref,
			'source_revision'  => $this->source_revision,
			'installed_hashes' => $this->installed_hashes,
		);
	}

	public function version_metadata(): array {
		return $this->to_array();
	}

	private static function non_empty_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new BundleValidationException( sprintf( 'agent template metadata %s must be a non-empty string.', esc_html( $field ) ) );
		}

		return $value;
	}

	private static function optional_string( string $value ): string {
		$value = trim( $value );
		if ( strlen( $value ) > 191 ) {
			throw new BundleValidationException( 'agent template metadata source fields must be 191 characters or fewer.' );
		}

		return $value;
	}

	private static function normalize_hashes( array $hashes ): array {
		$normalized = array();
		foreach ( $hashes as $artifact_id => $hash ) {
			$artifact_id = trim( (string) $artifact_id );
			$hash        = trim( (string) $hash );
			if ( '' === $artifact_id || '' === $hash ) {
				throw new BundleValidationException( 'agent template installed_hashes must map non-empty artifact IDs to hashes.' );
			}
			$normalized[ $artifact_id ] = $hash;
		}

		ksort( $normalized, SORT_STRING );
		return $normalized;
	}
}
