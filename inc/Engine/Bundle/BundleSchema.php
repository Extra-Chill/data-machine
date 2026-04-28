<?php
/**
 * Agent bundle schema constants and deterministic JSON helpers.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Shared schema constants for portable agent bundles.
 */
final class BundleSchema {

	public const VERSION = 1;

	public const MANIFEST_FILE = 'manifest.json';

	public const MEMORY_DIR = 'memory';

	public const PIPELINES_DIR = 'pipelines';

	public const FLOWS_DIR = 'flows';

	public const PROMPTS_DIR = 'prompts';

	public const RUBRICS_DIR = 'rubrics';

	public const TOOL_POLICIES_DIR = 'tool-policies';

	public const SEED_QUEUES_DIR = 'seed-queues';

	public const ARTIFACT_TYPES = array(
		'agent',
		'memory',
		'pipeline',
		'flow',
		'prompt',
		'rubric',
		'tool_policy',
		'auth_ref',
		'seed_queue',
		'schedule',
	);

	/**
	 * Encode bundle JSON in a stable, review-friendly shape.
	 *
	 * @param array $data JSON-serializable data.
	 * @return string JSON document ending with a newline.
	 */
	public static function encode_json( array $data ): string {
		$encoded = wp_json_encode( self::sort_recursive( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new BundleValidationException( 'Unable to encode bundle JSON.' );
		}

		return $encoded . "\n";
	}

	/**
	 * Decode a bundle JSON document into an array.
	 *
	 * @param string $json JSON document.
	 * @param string $label Human-readable file label for errors.
	 * @return array
	 */
	public static function decode_json( string $json, string $label ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new BundleValidationException( sprintf( '%s is not valid JSON.', $label ) );
		}

		return $data;
	}

	/**
	 * Validate supported schema_version.
	 *
	 * @param array  $data Document data.
	 * @param string $label Human-readable document label.
	 */
	public static function assert_supported_version( array $data, string $label ): void {
		$version = (int) ( $data['schema_version'] ?? 0 );
		if ( self::VERSION !== $version ) {
			throw new BundleValidationException(
				sprintf( '%s uses unsupported schema_version %d; this Data Machine build supports schema_version %d.', $label, $version, self::VERSION )
			);
		}
	}

	/**
	 * Sort associative arrays recursively while preserving list order.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function sort_recursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::sort_recursive( $child );
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
