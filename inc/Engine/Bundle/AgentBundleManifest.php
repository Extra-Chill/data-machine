<?php
/**
 * Agent bundle manifest value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of manifest.json schema_version 1.
 */
final class AgentBundleManifest {

	private string $exported_at;
	private string $exported_by;
	private string $bundle_slug;
	private string $bundle_version;
	private string $source_ref;
	private string $source_revision;
	private array $agent;
	private array $included;

	public function __construct( string $exported_at, string $exported_by, string $bundle_slug, string $bundle_version, string $source_ref, string $source_revision, array $agent, array $included ) {
		$this->exported_at     = $exported_at;
		$this->exported_by     = $exported_by;
		$this->bundle_slug     = PortableSlug::normalize( $bundle_slug, 'bundle' );
		$this->bundle_version  = self::validate_version_string( $bundle_version, 'bundle_version' );
		$this->source_ref      = self::validate_optional_string( $source_ref, 'source_ref' );
		$this->source_revision = self::validate_optional_string( $source_revision, 'source_revision' );
		$this->agent           = self::validate_agent( $agent );
		$this->included        = self::validate_included( $included );
	}

	/**
	 * Build from decoded manifest.json data.
	 *
	 * @param array $data Manifest data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		BundleSchema::assert_supported_version( $data, 'manifest.json' );

		foreach ( array( 'exported_at', 'exported_by', 'bundle_slug', 'bundle_version', 'agent', 'included' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json is missing required field %s.', esc_html( $field ) ) );
			}
		}

		if ( ! is_array( $data['agent'] ) || ! is_array( $data['included'] ) ) {
			throw new BundleValidationException( 'manifest.json agent and included fields must be objects.' );
		}

		return new self(
			(string) $data['exported_at'],
			(string) $data['exported_by'],
			(string) $data['bundle_slug'],
			(string) $data['bundle_version'],
			(string) ( $data['source_ref'] ?? '' ),
			(string) ( $data['source_revision'] ?? '' ),
			$data['agent'],
			$data['included']
		);
	}

	/**
	 * Convert to manifest.json data.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'schema_version'  => BundleSchema::VERSION,
			'bundle_slug'     => $this->bundle_slug,
			'bundle_version'  => $this->bundle_version,
			'source_ref'      => $this->source_ref,
			'source_revision' => $this->source_revision,
			'exported_at'     => $this->exported_at,
			'exported_by'     => $this->exported_by,
			'agent'           => $this->agent,
			'included'        => $this->included,
		);
	}

	public function agent_slug(): string {
		return (string) $this->agent['slug'];
	}

	public function bundle_slug(): string {
		return $this->bundle_slug;
	}

	public function bundle_version(): string {
		return $this->bundle_version;
	}

	public function source_ref(): string {
		return $this->source_ref;
	}

	public function source_revision(): string {
		return $this->source_revision;
	}

	public function version_metadata(): array {
		return array(
			'bundle_slug'     => $this->bundle_slug,
			'bundle_version'  => $this->bundle_version,
			'source_ref'      => $this->source_ref,
			'source_revision' => $this->source_revision,
		);
	}

	private static function validate_agent( array $agent ): array {
		foreach ( array( 'slug', 'label', 'description', 'agent_config' ) as $field ) {
			if ( ! array_key_exists( $field, $agent ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json agent is missing required field %s.', esc_html( $field ) ) );
			}
		}

		$agent['slug']        = PortableSlug::normalize( (string) $agent['slug'], 'agent' );
		$agent['label']       = (string) $agent['label'];
		$agent['description'] = (string) $agent['description'];
		if ( ! is_array( $agent['agent_config'] ) ) {
			throw new BundleValidationException( 'manifest.json agent.agent_config must be an object.' );
		}

		return array(
			'slug'         => $agent['slug'],
			'label'        => $agent['label'],
			'description'  => $agent['description'],
			'agent_config' => $agent['agent_config'],
		);
	}

	private static function validate_included( array $included ): array {
		foreach ( array( 'memory', 'pipelines', 'flows', 'handler_auth' ) as $field ) {
			if ( ! array_key_exists( $field, $included ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json included is missing required field %s.', esc_html( $field ) ) );
			}
		}

		foreach ( array( 'memory', 'pipelines', 'flows' ) as $field ) {
			if ( ! is_array( $included[ $field ] ) || ! array_is_list( $included[ $field ] ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json included.%s must be a list.', esc_html( $field ) ) );
			}
			$included[ $field ] = array_values( array_map( 'strval', $included[ $field ] ) );
			sort( $included[ $field ], SORT_STRING );
		}

		$handler_auth = (string) $included['handler_auth'];
		if ( ! in_array( $handler_auth, array( 'refs', 'full', 'omit' ), true ) ) {
			throw new BundleValidationException( 'manifest.json included.handler_auth must be one of refs, full, or omit.' );
		}
		$included['handler_auth'] = $handler_auth;

		return array(
			'memory'       => $included['memory'],
			'pipelines'    => $included['pipelines'],
			'flows'        => $included['flows'],
			'handler_auth' => $included['handler_auth'],
		);
	}

	private static function validate_version_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new BundleValidationException( sprintf( 'manifest.json %s must be a non-empty string.', esc_html( $field ) ) );
		}

		if ( strlen( $value ) > 191 ) {
			throw new BundleValidationException( sprintf( 'manifest.json %s must be 191 characters or fewer.', esc_html( $field ) ) );
		}

		return $value;
	}

	private static function validate_optional_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( strlen( $value ) > 191 ) {
			throw new BundleValidationException( sprintf( 'manifest.json %s must be 191 characters or fewer.', esc_html( $field ) ) );
		}

		return $value;
	}
}
