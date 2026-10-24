<?php
/**
 * Platform dimension presets for image generation.
 *
 * Named presets for common social media platform dimensions.
 * Extensible via the `datamachine/image_generation/platform_presets` filter.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.32.0
 */

namespace DataMachine\Abilities\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlatformPresets {

	/**
	 * Default platform dimension presets.
	 *
	 * @var array<string, array{width: int, height: int, label: string, dpi?: int}>
	 */
	private const PRESETS = array(
		'instagram_feed_portrait' => array(
			'width'  => 1080,
			'height' => 1350,
			'label'  => 'Instagram Feed (Portrait)',
		),
		'instagram_feed_square'   => array(
			'width'  => 1080,
			'height' => 1080,
			'label'  => 'Instagram Feed (Square)',
		),
		'instagram_story'         => array(
			'width'  => 1080,
			'height' => 1920,
			'label'  => 'Instagram Story',
		),
		'twitter_card'            => array(
			'width'  => 1200,
			'height' => 675,
			'label'  => 'Twitter/X Card',
		),
		'facebook_share'          => array(
			'width'  => 1200,
			'height' => 630,
			'label'  => 'Facebook Share',
		),
		'pinterest_pin'           => array(
			'width'  => 1000,
			'height' => 1500,
			'label'  => 'Pinterest Pin',
		),
		'open_graph'              => array(
			'width'  => 1200,
			'height' => 630,
			'label'  => 'Open Graph',
		),
		'landscape_16_9'          => array(
			'width'  => 1920,
			'height' => 1080,
			'label'  => 'Landscape 16:9 (featured / Open Graph)',
		),
		'print_tabloid'           => array(
			'width'  => 1650,
			'height' => 2550,
			'label'  => 'Print Tabloid (11x17 @ 150dpi)',
			'dpi'    => 150,
		),
		'print_tabloid_300'       => array(
			'width'  => 3300,
			'height' => 5100,
			'label'  => 'Print Tabloid (11x17 @ 300dpi)',
			'dpi'    => 300,
		),
		'print_pocket_flyer'      => array(
			'width'  => 1275,
			'height' => 1650,
			'label'  => 'Print Pocket Flyer (4.25x5.5 @ 300dpi, 8-up on 11x17)',
			'dpi'    => 300,
		),
	);

	/**
	 * Get a preset by name.
	 *
	 * @param string $name Preset name.
	 * @return array{width: int, height: int, label: string, dpi?: int}|null Preset data or null if not found.
	 */
	public static function get( string $name ): ?array {
		$presets = self::all();
		return $presets[ $name ] ?? null;
	}

	/**
	 * Get all presets.
	 *
	 * @return array<string, array{width: int, height: int, label: string, dpi?: int}>
	 */
	public static function all(): array {
		/**
		 * Filter the available platform dimension presets.
		 *
		 * @param array $presets Default presets.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Intentional slash-separated hook namespace.
		return apply_filters( 'datamachine/image_generation/platform_presets', self::PRESETS );
	}

	/**
	 * Get dimensions for a preset.
	 *
	 * @param string $name Preset name.
	 * @return array{width: int, height: int}|null Width and height or null if not found.
	 */
	public static function dimensions( string $name ): ?array {
		$preset = self::get( $name );
		if ( ! $preset ) {
			return null;
		}

		return array(
			'width'  => $preset['width'],
			'height' => $preset['height'],
		);
	}

	/**
	 * Get all preset names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::all() );
	}
}
