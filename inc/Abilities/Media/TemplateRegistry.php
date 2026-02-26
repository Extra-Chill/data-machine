<?php
/**
 * Template registry for GD-based image generation.
 *
 * Downstream plugins register templates via the `datamachine/image_generation/templates` filter.
 * The registry resolves template classes, instantiates them, and provides lookup.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.32.0
 */

namespace DataMachine\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplateRegistry {

	/**
	 * Cached template instances.
	 *
	 * @var array<string, TemplateInterface>|null
	 */
	private static ?array $templates = null;

	/**
	 * Get all registered templates.
	 *
	 * @return array<string, TemplateInterface>
	 */
	public static function all(): array {
		if ( null === self::$templates ) {
			self::$templates = self::resolve();
		}

		return self::$templates;
	}

	/**
	 * Get a template by ID.
	 *
	 * @param string $template_id Template identifier.
	 * @return TemplateInterface|null
	 */
	public static function get( string $template_id ): ?TemplateInterface {
		$templates = self::all();
		return $templates[ $template_id ] ?? null;
	}

	/**
	 * Check if a template exists.
	 *
	 * @param string $template_id Template identifier.
	 * @return bool
	 */
	public static function has( string $template_id ): bool {
		return null !== self::get( $template_id );
	}

	/**
	 * Get template metadata for UI/API consumption.
	 *
	 * @return array<string, array{id: string, name: string, description: string, fields: array, default_preset: string}>
	 */
	public static function get_template_definitions(): array {
		$definitions = array();

		foreach ( self::all() as $id => $template ) {
			$definitions[ $id ] = array(
				'id'             => $template->get_id(),
				'name'           => $template->get_name(),
				'description'    => $template->get_description(),
				'fields'         => $template->get_fields(),
				'default_preset' => $template->get_default_preset(),
			);
		}

		return $definitions;
	}

	/**
	 * Clear cached templates.
	 *
	 * Call when plugins register new templates dynamically.
	 */
	public static function clear_cache(): void {
		self::$templates = null;
	}

	/**
	 * Resolve template classes from the filter.
	 *
	 * The filter provides an array of template_id => class_name.
	 * Each class is instantiated and validated against TemplateInterface.
	 *
	 * @return array<string, TemplateInterface>
	 */
	private static function resolve(): array {
		/**
		 * Filter the registered image generation templates.
		 *
		 * Downstream plugins add their template classes here.
		 *
		 * @param array<string, string> $template_classes Map of template_id => fully-qualified class name.
		 */
		$template_classes = apply_filters( 'datamachine/image_generation/templates', array() );

		$resolved = array();

		foreach ( $template_classes as $id => $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				do_action(
					'datamachine_log',
					'warning',
					sprintf( 'Image template class not found: %s (id: %s)', $class_name, $id ),
					array(
						'template_id' => $id,
						'class'       => $class_name,
					)
				);
				continue;
			}

			$instance = new $class_name();

			if ( ! $instance instanceof TemplateInterface ) {
				do_action(
					'datamachine_log',
					'warning',
					sprintf( 'Image template class does not implement TemplateInterface: %s', $class_name ),
					array(
						'template_id' => $id,
						'class'       => $class_name,
					)
				);
				continue;
			}

			$resolved[ $instance->get_id() ] = $instance;
		}

		return $resolved;
	}
}
