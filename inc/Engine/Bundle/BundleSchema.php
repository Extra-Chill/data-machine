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

	public const AUTH_REFS_DIR = 'auth-refs';

	public const SEED_QUEUES_DIR = 'seed-queues';

	public const EXTENSIONS_DIR = 'extensions';

	public const CORE_ARTIFACT_TYPES = array(
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

	public const ARTIFACT_TYPES = self::CORE_ARTIFACT_TYPES;

	/**
	 * Return all artifact types known to the bundle runtime.
	 *
	 * Plugins register their own artifact types here while keeping semantics in
	 * the owning plugin. Data Machine only hashes, diffs, and routes envelopes.
	 *
	 * @return string[]
	 */
	public static function artifact_types(): array {
		$types = self::CORE_ARTIFACT_TYPES;

		/**
		 * Register plugin-owned agent bundle artifact types.
		 *
		 * @param string[] $types Known artifact type slugs.
		 */
		$types = self::apply_filter( 'datamachine_agent_bundle_artifact_types', $types );
		if ( ! is_array( $types ) ) {
			$types = self::CORE_ARTIFACT_TYPES;
		}

		$normalized = array();
		foreach ( $types as $type ) {
			$type = self::sanitize_key( (string) $type );
			if ( '' !== $type ) {
				$normalized[] = $type;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	private static function apply_filter( string $hook, array $value ): mixed {
		if ( ! \function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return call_user_func_array( 'apply_filters', array( $hook, $value ) );
	}

	private static function sanitize_key( string $key ): string {
		if ( \function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $key );
		}

		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );
		return strtolower( is_string( $sanitized ) ? $sanitized : '' );
	}

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
			throw new BundleValidationException( sprintf( '%s is not valid JSON.', esc_html( $label ) ) );
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
		$version           = (int) ( $data['schema_version'] ?? 0 );
		$supported_version = self::VERSION;
		if ( self::VERSION !== $version ) {
			throw new BundleValidationException(
				sprintf( '%s uses unsupported schema_version %s; this Data Machine build supports schema_version %s.', esc_html( $label ), esc_html( (string) $version ), esc_html( (string) $supported_version ) )
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
