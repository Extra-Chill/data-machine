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

	private const OPTIONAL_STEP_FIELDS = array(
		'step_type',
		'handler_slug',
		'handler_slugs',
		'handler_config',
		'enabled_tools',
		'disabled_tools',
		'prompt_queue',
		'config_patch_queue',
		'queue_mode',
		'enabled',
	);

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
		if ( in_array( $field, array( 'step_type', 'handler_slug' ), true ) ) {
			return (string) $value;
		}

		if ( 'enabled' === $field ) {
			return (bool) $value;
		}

		if ( 'handler_config' === $field ) {
			if ( ! is_array( $value ) ) {
				throw new BundleValidationException( 'flow file handler_config must be an object.' );
			}
			return $value;
		}

		if ( in_array( $field, array( 'handler_slugs', 'enabled_tools', 'disabled_tools' ), true ) ) {
			return self::normalize_string_list( $field, $value );
		}

		if ( 'prompt_queue' === $field ) {
			return self::normalize_prompt_queue( $value );
		}

		if ( 'config_patch_queue' === $field ) {
			return self::normalize_config_patch_queue( $value );
		}

		if ( 'queue_mode' === $field ) {
			if ( ! in_array( $value, array( 'drain', 'loop', 'static' ), true ) ) {
				throw new BundleValidationException( 'flow file queue_mode must be one of drain, loop, static.' );
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Normalize portable string-list fields.
	 */
	private static function normalize_string_list( string $field, $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new BundleValidationException( "flow file {$field} must be a list of strings." );
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new BundleValidationException( "flow file {$field} must be a list of strings." );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize AI prompt queue seed entries.
	 */
	private static function normalize_prompt_queue( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new BundleValidationException( 'flow file prompt_queue must be a list of objects.' );
		}

		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new BundleValidationException( 'flow file prompt_queue must be a list of objects.' );
			}
			if ( ! array_key_exists( 'prompt', $entry ) || ! is_string( $entry['prompt'] ) ) {
				throw new BundleValidationException( 'flow file prompt_queue entries must include a string prompt.' );
			}
			if ( array_key_exists( 'added_at', $entry ) && ! is_string( $entry['added_at'] ) ) {
				throw new BundleValidationException( 'flow file prompt_queue added_at must be a string when present.' );
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}

	/**
	 * Normalize fetch config-patch queue seed entries.
	 */
	private static function normalize_config_patch_queue( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new BundleValidationException( 'flow file config_patch_queue must be a list of objects.' );
		}

		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new BundleValidationException( 'flow file config_patch_queue must be a list of objects.' );
			}
			if ( ! array_key_exists( 'patch', $entry ) || ! is_array( $entry['patch'] ) ) {
				throw new BundleValidationException( 'flow file config_patch_queue entries must include an object patch.' );
			}
			if ( array_key_exists( 'added_at', $entry ) && ! is_string( $entry['added_at'] ) ) {
				throw new BundleValidationException( 'flow file config_patch_queue added_at must be a string when present.' );
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}
}
