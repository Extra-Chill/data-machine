<?php
/**
 * Agent bundle pipeline file value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of pipelines/<slug>.json schema_version 1.
 */
final class AgentBundlePipelineFile {

	private string $slug;
	private string $name;
	private array $steps;

	public function __construct( string $slug, string $name, array $steps ) {
		$this->slug  = PortableSlug::normalize( $slug, 'pipeline' );
		$this->name  = $name;
		$this->steps = self::validate_steps( $steps );
	}

	public static function from_array( array $data ): self {
		BundleSchema::assert_supported_version( $data, 'pipeline file' );
		foreach ( array( 'slug', 'name', 'steps' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( "pipeline file is missing required field {$field}." );
			}
		}

		if ( ! is_array( $data['steps'] ) || ! array_is_list( $data['steps'] ) ) {
			throw new BundleValidationException( 'pipeline file steps must be a list.' );
		}

		return new self( (string) $data['slug'], (string) $data['name'], $data['steps'] );
	}

	public function to_array(): array {
		return array(
			'schema_version' => BundleSchema::VERSION,
			'slug'           => $this->slug,
			'name'           => $this->name,
			'steps'          => $this->steps,
		);
	}

	public function slug(): string {
		return $this->slug;
	}

	private static function validate_steps( array $steps ): array {
		$normalized = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				throw new BundleValidationException( 'pipeline file steps must contain objects.' );
			}
			foreach ( array( 'step_position', 'step_type', 'step_config' ) as $field ) {
				if ( ! array_key_exists( $field, $step ) ) {
					throw new BundleValidationException( "pipeline file step is missing required field {$field}." );
				}
			}
			if ( ! is_array( $step['step_config'] ) ) {
				throw new BundleValidationException( 'pipeline file step_config must be an object.' );
			}

			$normalized[] = array(
				'step_position' => (int) $step['step_position'],
				'step_type'     => (string) $step['step_type'],
				'step_config'   => $step['step_config'],
			);
		}

		usort( $normalized, fn( $a, $b ) => $a['step_position'] <=> $b['step_position'] );

		return $normalized;
	}
}
