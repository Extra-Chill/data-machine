<?php
/**
 * Agent bundle flow file value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of flows/<slug>.json schema_version 1.
 */
final class AgentBundleFlowFile {
	use AgentBundleSlugTrait;

	private string $slug;
	private string $name;
	private string $pipeline_slug;
	private string $schedule;
	private array $max_items;
	private array $steps;

	public function __construct( string $slug, string $name, string $pipeline_slug, string $schedule, array $max_items, array $steps ) {
		$this->slug          = PortableSlug::normalize( $slug, 'flow' );
		$this->name          = $name;
		$this->pipeline_slug = PortableSlug::normalize( $pipeline_slug, 'pipeline' );
		$this->schedule      = $schedule;
		$this->max_items     = $max_items;
		$this->steps         = self::validate_steps( $steps );
	}

	public static function from_array( array $data ): self {
		BundleSchema::assert_supported_version( $data, 'flow file' );
		foreach ( array( 'slug', 'name', 'pipeline_slug', 'schedule', 'max_items', 'steps' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( "flow file is missing required field {$field}." );
			}
		}

		if ( ! is_array( $data['max_items'] ) || ! is_array( $data['steps'] ) || ! array_is_list( $data['steps'] ) ) {
			throw new BundleValidationException( 'flow file max_items must be an object and steps must be a list.' );
		}

		return new self( (string) $data['slug'], (string) $data['name'], (string) $data['pipeline_slug'], (string) $data['schedule'], $data['max_items'], $data['steps'] );
	}

	public function to_array(): array {
		return array(
			'schema_version' => BundleSchema::VERSION,
			'slug'           => $this->slug,
			'name'           => $this->name,
			'pipeline_slug'  => $this->pipeline_slug,
			'schedule'       => $this->schedule,
			'max_items'      => $this->max_items,
			'steps'          => $this->steps,
		);
	}

	private static function validate_steps( array $steps ): array {
		$normalized = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				throw new BundleValidationException( 'flow file steps must contain objects.' );
			}
			foreach ( array( 'step_position', 'handler_configs' ) as $field ) {
				if ( ! array_key_exists( $field, $step ) ) {
					throw new BundleValidationException( "flow file step is missing required field {$field}." );
				}
			}
			if ( ! is_array( $step['handler_configs'] ) ) {
				throw new BundleValidationException( 'flow file handler_configs must be an object.' );
			}

			$normalized_step = array(
				'step_position'   => (int) $step['step_position'],
				'handler_configs' => $step['handler_configs'],
			);

			if ( array_key_exists( 'handler_slug', $step ) ) {
				$normalized_step['handler_slug'] = (string) $step['handler_slug'];
			}
			if ( array_key_exists( 'handler_slugs', $step ) ) {
				if ( ! is_array( $step['handler_slugs'] ) || ! array_is_list( $step['handler_slugs'] ) ) {
					throw new BundleValidationException( 'flow file handler_slugs must be a list.' );
				}
				$normalized_step['handler_slugs'] = array_values( array_map( 'strval', $step['handler_slugs'] ) );
			}

			$normalized[] = $normalized_step;
		}

		usort( $normalized, fn( $a, $b ) => $a['step_position'] <=> $b['step_position'] );

		return $normalized;
	}
}
