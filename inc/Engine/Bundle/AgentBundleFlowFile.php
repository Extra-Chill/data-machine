<?php
/**
 * Agent bundle flow file value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Engine\PortableFlowStepFields;

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
	private array $run_artifacts;

	private const OPTIONAL_STEP_FIELDS = array(
		'step_type',
		'handler_slugs',
		'flow_step_settings',
		'enabled_tools',
		'disabled_tools',
		'prompt_queue',
		'config_patch_queue',
		'queue_mode',
		'completion_assertions',
		'tool_runtime_rules',
		'enabled',
	);

	public function __construct( string $slug, string $name, string $pipeline_slug, string $schedule, array $max_items, array $steps, array $run_artifacts = array() ) {
		$this->slug          = PortableSlug::normalize( $slug, 'flow' );
		$this->name          = $name;
		$this->pipeline_slug = PortableSlug::normalize( $pipeline_slug, 'pipeline' );
		$this->schedule      = $schedule;
		$this->max_items     = $max_items;
		$this->steps         = self::validate_steps( $steps );
		$this->run_artifacts = BundleSchema::normalize_run_artifact_egress_policy( $run_artifacts );
	}

	public static function from_array( array $data ): self {
		BundleSchema::assert_supported_version( $data, 'flow file' );
		foreach ( array( 'slug', 'name', 'pipeline_slug', 'schedule', 'max_items', 'steps' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'flow file is missing required field %s.', esc_html( $field ) ) );
			}
		}

		if ( ! is_array( $data['max_items'] ) || ! is_array( $data['steps'] ) || ! array_is_list( $data['steps'] ) ) {
			throw new BundleValidationException( 'flow file max_items must be an object and steps must be a list.' );
		}

		return new self(
			(string) $data['slug'],
			(string) $data['name'],
			(string) $data['pipeline_slug'],
			(string) $data['schedule'],
			$data['max_items'],
			$data['steps'],
			is_array( $data['run_artifacts'] ?? null ) ? $data['run_artifacts'] : array()
		);
	}

	public function to_array(): array {
		$data = array(
			'schema_version' => BundleSchema::VERSION,
			'slug'           => $this->slug,
			'name'           => $this->name,
			'pipeline_slug'  => $this->pipeline_slug,
			'schedule'       => $this->schedule,
			'max_items'      => $this->max_items,
			'steps'          => $this->steps,
		);

		if ( ! empty( $this->run_artifacts ) ) {
			$data['run_artifacts'] = $this->run_artifacts;
		}

		return $data;
	}

	public function run_artifacts(): array {
		return $this->run_artifacts;
	}

	private static function validate_steps( array $steps ): array {
		$normalized = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				throw new BundleValidationException( 'flow file steps must contain objects.' );
			}
			foreach ( array( 'handler', 'handler_slug', 'handler_config' ) as $legacy_field ) {
				if ( array_key_exists( $legacy_field, $step ) ) {
					throw new BundleValidationException( sprintf( 'flow file step uses unsupported legacy field %s; use handler_slugs and handler_configs.', esc_html( $legacy_field ) ) );
				}
			}
			foreach ( array( 'step_position', 'handler_configs' ) as $field ) {
				if ( ! array_key_exists( $field, $step ) ) {
					throw new BundleValidationException( sprintf( 'flow file step is missing required field %s.', esc_html( $field ) ) );
				}
			}
			if ( ! is_array( $step['handler_configs'] ) ) {
				throw new BundleValidationException( 'flow file handler_configs must be an object.' );
			}

			$normalized_step = array(
				'step_position'   => (int) $step['step_position'],
				'handler_configs' => $step['handler_configs'],
			);

			foreach ( self::OPTIONAL_STEP_FIELDS as $field ) {
				if ( array_key_exists( $field, $step ) ) {
					$normalized_step[ $field ] = self::normalize_optional_step_field( $field, $step[ $field ] );
				}
			}

			$normalized[] = $normalized_step;
		}

		usort( $normalized, fn( $a, $b ) => $a['step_position'] <=> $b['step_position'] );

		return $normalized;
	}

	private static function normalize_optional_step_field( string $field, $value ) {
		try {
			return PortableFlowStepFields::normalize_field( $field, $value, 'flow file' );
		} catch ( \InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly.
			throw new BundleValidationException( $e->getMessage() );
		}
	}
}
