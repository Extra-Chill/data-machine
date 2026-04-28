<?php
/**
 * Versioned prompt/rubric artifact value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a deployable prompt or rubric artifact.
 */
final class PromptArtifact {

	public const TYPE_PROMPT = 'prompt';
	public const TYPE_RUBRIC = 'rubric';

	private string $artifact_id;
	private string $artifact_type;
	private string $version;
	private string $source_path;
	private string $content;
	private string $content_hash;
	private string $changelog;
	private array $metadata;

	public function __construct( string $artifact_id, string $artifact_type, string $version, string $source_path, string $content, string $changelog = '', array $metadata = array() ) {
		$this->artifact_id   = self::validate_artifact_id( $artifact_id );
		$this->artifact_type = self::validate_artifact_type( $artifact_type );
		$this->version       = self::non_empty_string( $version, 'version' );
		$this->source_path   = self::normalize_source_path( $source_path );
		$this->content       = $content;
		$this->content_hash  = AgentBundleArtifactHasher::hash( $content );
		$this->changelog     = trim( $changelog );
		$this->metadata      = self::normalize_metadata( $metadata );
	}

	/**
	 * Build from a prompt/rubric artifact document.
	 *
	 * @param  array $data Artifact document data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'artifact_id', 'artifact_type', 'version', 'source_path', 'content' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'prompt artifact is missing required field %s.', esc_html( $field ) ) );
			}
		}

		if ( isset( $data['metadata'] ) && ! is_array( $data['metadata'] ) ) {
			throw new BundleValidationException( 'prompt artifact metadata must be an object.' );
		}

		return new self(
			(string) $data['artifact_id'],
			(string) $data['artifact_type'],
			(string) $data['version'],
			(string) $data['source_path'],
			(string) $data['content'],
			(string) ( $data['changelog'] ?? '' ),
			$data['metadata'] ?? array()
		);
	}

	public function to_array(): array {
		return array(
			'artifact_id'   => $this->artifact_id,
			'artifact_type' => $this->artifact_type,
			'version'       => $this->version,
			'source_path'   => $this->source_path,
			'content_hash'  => $this->content_hash,
			'content'       => $this->content,
			'changelog'     => $this->changelog,
			'metadata'      => $this->metadata,
		);
	}

	public function artifact_id(): string {
		return $this->artifact_id;
	}

	public function artifact_type(): string {
		return $this->artifact_type;
	}

	public function version(): string {
		return $this->version;
	}

	public function source_path(): string {
		return $this->source_path;
	}

	public function content(): string {
		return $this->content;
	}

	public function content_hash(): string {
		return $this->content_hash;
	}

	public function version_metadata(): array {
		return array(
			'artifact_id'   => $this->artifact_id,
			'artifact_type' => $this->artifact_type,
			'version'       => $this->version,
			'source_path'   => $this->source_path,
			'content_hash'  => $this->content_hash,
			'changelog'     => $this->changelog,
			'metadata'      => $this->metadata,
		);
	}

	private static function validate_artifact_id( string $artifact_id ): string {
		$artifact_id = trim( $artifact_id );
		if ( '' === $artifact_id || ! preg_match( '/^[a-z0-9][a-z0-9._:\/_-]*$/', $artifact_id ) ) {
			throw new BundleValidationException( 'prompt artifact_id must be a stable lowercase identifier.' );
		}

		return $artifact_id;
	}

	private static function validate_artifact_type( string $artifact_type ): string {
		$artifact_type = self::non_empty_string( $artifact_type, 'artifact_type' );
		if ( ! in_array( $artifact_type, array( self::TYPE_PROMPT, self::TYPE_RUBRIC ), true ) ) {
			throw new BundleValidationException( 'prompt artifact_type must be prompt or rubric.' );
		}

		return $artifact_type;
	}

	private static function non_empty_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new BundleValidationException( sprintf( 'prompt artifact %s must be a non-empty string.', esc_html( $field ) ) );
		}

		return $value;
	}

	private static function normalize_source_path( string $path ): string {
		$path = str_replace( '\\', '/', self::non_empty_string( $path, 'source_path' ) );
		$path = ltrim( $path, '/' );
		if ( str_contains( $path, '..' ) ) {
			throw new BundleValidationException( 'prompt artifact source_path must be bundle-local.' );
		}

		return $path;
	}

	private static function normalize_metadata( array $metadata ): array {
		foreach ( $metadata as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new BundleValidationException( 'prompt artifact metadata keys must be strings.' );
			}
		}

		ksort( $metadata, SORT_STRING );
		return $metadata;
	}
}
