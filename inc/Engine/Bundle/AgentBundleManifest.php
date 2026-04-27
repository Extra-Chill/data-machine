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
	private array $agent;
	private array $included;

	public function __construct( string $exported_at, string $exported_by, array $agent, array $included ) {
		$this->exported_at = $exported_at;
		$this->exported_by = $exported_by;
		$this->agent       = self::validate_agent( $agent );
		$this->included    = self::validate_included( $included );
	}

	/**
	 * Build from decoded manifest.json data.
	 *
	 * @param array $data Manifest data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		BundleSchema::assert_supported_version( $data, 'manifest.json' );

		foreach ( array( 'exported_at', 'exported_by', 'agent', 'included' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( "manifest.json is missing required field {$field}." );
			}
		}

		if ( ! is_array( $data['agent'] ) || ! is_array( $data['included'] ) ) {
			throw new BundleValidationException( 'manifest.json agent and included fields must be objects.' );
		}

		return new self( (string) $data['exported_at'], (string) $data['exported_by'], $data['agent'], $data['included'] );
	}

	/**
	 * Convert to manifest.json data.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'schema_version' => BundleSchema::VERSION,
			'exported_at'    => $this->exported_at,
			'exported_by'    => $this->exported_by,
			'agent'          => $this->agent,
			'included'       => $this->included,
		);
	}

	public function agent_slug(): string {
		return (string) $this->agent['slug'];
	}

	private static function validate_agent( array $agent ): array {
		foreach ( array( 'slug', 'label', 'description', 'agent_config' ) as $field ) {
			if ( ! array_key_exists( $field, $agent ) ) {
				throw new BundleValidationException( "manifest.json agent is missing required field {$field}." );
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
				throw new BundleValidationException( "manifest.json included is missing required field {$field}." );
			}
		}

		foreach ( array( 'memory', 'pipelines', 'flows' ) as $field ) {
			if ( ! is_array( $included[ $field ] ) || ! array_is_list( $included[ $field ] ) ) {
				throw new BundleValidationException( "manifest.json included.{$field} must be a list." );
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
}
