<?php
/**
 * Scheduling Documentation Builder
 *
 * Provides JSON-formatted scheduling interval options for chat tools.
 * Ensures consistent, machine-readable interval documentation across all
 * tools that accept scheduling configuration.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.9.5
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchedulingDocumentation {

	/**
	 * Cached intervals JSON string.
	 *
	 * @var string|null
	 */
	private static ?string $cached_json = null;

	/**
	 * Clear cached documentation.
	 */
	public static function clearCache(): void {
		self::$cached_json = null;
	}

	/**
	 * Get scheduling intervals as a JSON array.
	 *
	 * Returns a formatted JSON string suitable for inclusion in tool descriptions.
	 * The JSON format is more parseable by LLMs than pipe-delimited strings.
	 *
	 * @return string JSON array of valid scheduling intervals
	 */
	public static function getIntervalsJson(): string {
		if ( null !== self::$cached_json ) {
			return self::$cached_json;
		}

		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );

		$options = array(
			array(
				'value' => 'manual',
				'label' => 'Manual only',
			),
			array(
				'value' => 'one_time',
				'label' => 'One-time (requires timestamp)',
			),
		);

		foreach ( $intervals as $key => $config ) {
			$options[] = array(
				'value' => $key,
				'label' => $config['label'] ?? $key,
			);
		}

		self::$cached_json = wp_json_encode( $options, JSON_PRETTY_PRINT );

		return self::$cached_json;
	}
}
