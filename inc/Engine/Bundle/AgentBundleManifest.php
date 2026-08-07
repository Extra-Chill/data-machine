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
	private array $run_artifacts;
	private array $capabilities;

	public function __construct( string $exported_at, string $exported_by, string $bundle_slug, string $bundle_version, string $source_ref, string $source_revision, array $agent, array $included, array $run_artifacts = array(), array $capabilities = array() ) {
		$this->exported_at     = $exported_at;
		$this->exported_by     = $exported_by;
		$this->bundle_slug     = PortableSlug::normalize( $bundle_slug, 'bundle' );
		$this->bundle_version  = self::validate_version_string( $bundle_version, 'bundle_version' );
		$this->source_ref      = self::validate_optional_string( $source_ref, 'source_ref' );
		$this->source_revision = self::validate_optional_string( $source_revision, 'source_revision' );
		$this->agent           = self::validate_agent( $agent );
		$this->included        = self::validate_included( $included );
		$this->run_artifacts   = BundleSchema::normalize_run_artifact_egress_policy( $run_artifacts );
		$this->capabilities    = self::validate_string_list( $capabilities, 'capabilities' );
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
			$data['included'],
			is_array( $data['run_artifacts'] ?? null ) ? $data['run_artifacts'] : array(),
			is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : array()
		);
	}

	/**
	 * Convert to manifest.json data.
	 *
	 * @return array
	 */
	public function to_array(): array {
		$data = array(
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

		if ( ! empty( $this->run_artifacts ) ) {
			$data['run_artifacts'] = $this->run_artifacts;
		}
		if ( ! empty( $this->capabilities ) ) {
			$data['capabilities'] = $this->capabilities;
		}

		return $data;
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

	public function run_artifacts(): array {
		return $this->run_artifacts;
	}

	public function capabilities(): array {
		return $this->capabilities;
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

		$validated = array(
			'slug'         => $agent['slug'],
			'label'        => $agent['label'],
			'description'  => $agent['description'],
			'agent_config' => $agent['agent_config'],
		);

		// Carry the agent's site scope through the round-trip. `null` means
		// network-wide; a positive integer means a specific blog. Anything else
		// (legacy `'site'`, empty string, absent key) is "unspecified" and is
		// omitted so the importer never re-pins the agent to the installing blog.
		if ( array_key_exists( 'site_scope', $agent ) ) {
			$scope = BundleSchema::normalize_agent_site_scope( $agent['site_scope'] );
			if ( BundleSchema::SITE_SCOPE_UNSPECIFIED !== $scope ) {
				$validated['site_scope'] = $scope;
			}
		}

		return $validated;
	}

	private static function validate_included( array $included ): array {
		foreach ( array( 'memory', 'pipelines', 'flows', 'handler_auth' ) as $field ) {
			if ( ! array_key_exists( $field, $included ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json included is missing required field %s.', esc_html( $field ) ) );
			}
		}

		foreach ( array( 'prompts', 'rubrics', 'tool_policies', 'auth_refs', 'seed_queues', 'extensions' ) as $field ) {
			$included[ $field ] = $included[ $field ] ?? array();
		}

		foreach ( array( 'memory', 'pipelines', 'flows', 'prompts', 'rubrics', 'tool_policies', 'auth_refs', 'seed_queues', 'extensions' ) as $field ) {
			if ( ! is_array( $included[ $field ] ) || ! array_is_list( $included[ $field ] ) ) {
				throw new BundleValidationException( sprintf( 'manifest.json included.%s must be a list.', esc_html( $field ) ) );
			}
			$included[ $field ] = array_map( 'strval', $included[ $field ] );
			sort( $included[ $field ], SORT_STRING );
		}

		$handler_auth = (string) $included['handler_auth'];
		if ( ! in_array( $handler_auth, array( 'refs', 'full', 'omit' ), true ) ) {
			throw new BundleValidationException( 'manifest.json included.handler_auth must be one of refs, full, or omit.' );
		}
		$included['handler_auth'] = $handler_auth;

		return array(
			'memory'        => $included['memory'],
			'pipelines'     => $included['pipelines'],
			'flows'         => $included['flows'],
			'prompts'       => $included['prompts'],
			'rubrics'       => $included['rubrics'],
			'tool_policies' => $included['tool_policies'],
			'auth_refs'     => $included['auth_refs'],
			'seed_queues'   => $included['seed_queues'],
			'extensions'    => $included['extensions'],
			'handler_auth'  => $included['handler_auth'],
		);
	}

	private static function validate_string_list( array $values, string $field ): array {
		if ( ! array_is_list( $values ) ) {
			throw new BundleValidationException( sprintf( 'manifest.json %s must be a list.', esc_html( $field ) ) );
		}

		$normalized = array();
		foreach ( $values as $value ) {
			$value = trim( strtolower( (string) $value ) );
			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
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
