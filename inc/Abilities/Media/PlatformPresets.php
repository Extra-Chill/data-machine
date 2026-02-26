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
	 * @var array<string, array{width: int, height: int, label: string}>
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
	);

	/**
	 * Get a preset by name.
	 *
	 * @param string $name Preset name.
	 * @return array{width: int, height: int, label: string}|null Preset data or null if not found.
	 */
	public static function get( string $name ): ?array {
		$presets = self::all();
		return $presets[ $name ] ?? null;
	}

	/**
	 * Get all presets.
	 *
	 * @return array<string, array{width: int, height: int, label: string}>
	 */
	public static function all(): array {
		/**
		 * Filter the available platform dimension presets.
		 *
		 * @param array $presets Default presets.
		 */
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
