<?php
/**
 * Template interface for GD-based image generation.
 *
 * All image templates — quote cards, event roundups, promo cards —
 * implement this contract. Templates are stateless: receive data, return file paths.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.32.0
 */

namespace DataMachine\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface TemplateInterface {

	/**
	 * Unique template identifier.
	 *
	 * Used for registration and pipeline configuration.
	 * Example: 'quote_card', 'event_roundup', 'promo_card'.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable template name.
	 *
	 * Shown in the pipeline builder UI.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Template description.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Required data fields for this template.
	 *
	 * Returns an array of field definitions that the template expects.
	 * Each field has a key, label, type, and required flag.
	 *
	 * Example:
	 * [
	 *     'quote_text' => ['label' => 'Quote Text', 'type' => 'string', 'required' => true],
	 *     'attribution' => ['label' => 'Attribution', 'type' => 'string', 'required' => true],
	 * ]
	 *
	 * @return array<string, array{label: string, type: string, required: bool}>
	 */
	public function get_fields(): array;

	/**
	 * Default platform preset for this template.
	 *
	 * Returns the PlatformPresets key that this template
	 * targets by default (e.g. 'instagram_feed_portrait').
	 *
	 * @return string
	 */
	public function get_default_preset(): string;

	/**
	 * Render the template to one or more image files.
	 *
	 * @param array      $data     Structured data matching get_fields().
	 * @param GDRenderer $renderer Shared GD renderer with utilities.
	 * @param array      $options  Optional overrides (preset, width, height, output_format).
	 * @return string[] Array of generated image file paths.
	 */
	public function render( array $data, GDRenderer $renderer, array $options = array() ): array;
}
