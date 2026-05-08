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

	/**
	 * Top-level bundle entries that Data Machine owns by name.
	 *
	 * Anything matching one of these names — file or directory — at the bundle
	 * root is treated as canonical bundle content (manifest + first-class
	 * artifact directories). Anything else at the root is opaque to the
	 * Bundle layer and round-trips through the `extras` transport for
	 * consuming plugins to claim via the
	 * `datamachine_bundle_install_succeeded` action and the
	 * `datamachine_bundle_export_extras` filter.
	 *
	 * @var string[]
	 */
	public const RESERVED_ROOT_ENTRIES = array(
		self::MANIFEST_FILE,
		self::MEMORY_DIR,
		self::PIPELINES_DIR,
		self::FLOWS_DIR,
		self::PROMPTS_DIR,
		self::RUBRICS_DIR,
		self::TOOL_POLICIES_DIR,
		self::AUTH_REFS_DIR,
		self::SEED_QUEUES_DIR,
		self::EXTENSIONS_DIR,
		// Legacy export paths produced by AgentBundler::to_directory():
		'agent',
		'USER.md',
	);

	/**
	 * Subset of RESERVED_ROOT_ENTRIES that are top-level directories owned by
	 * Data Machine. Used by the extras read/write paths to skip directories
	 * that already round-trip through dedicated adapters.
	 *
	 * @var string[]
	 */
	public const RESERVED_TREES = array(
		self::MEMORY_DIR,
		self::PIPELINES_DIR,
		self::FLOWS_DIR,
		self::PROMPTS_DIR,
		self::RUBRICS_DIR,
		self::TOOL_POLICIES_DIR,
		self::AUTH_REFS_DIR,
		self::SEED_QUEUES_DIR,
		self::EXTENSIONS_DIR,
		'agent',
	);

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
			$type = self::sanitize_artifact_type( (string) $type );
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

	private static function sanitize_artifact_type( string $type ): string {
		$type = strtolower( trim( str_replace( '\\', '/', $type ) ) );
		$type = preg_replace( '/[^a-z0-9_\.\/-]+/', '', $type );
		$type = preg_replace( '#/+#', '/', is_string( $type ) ? $type : '' );
		$type = trim( is_string( $type ) ? $type : '', '/' );

		return $type;
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
	 * Validate and normalize a bundle extras payload.
	 *
	 * Extras are arbitrary top-level directories carried by the bundle for
	 * consumer plugins. This validates the shape only — Data Machine has no
	 * opinion on the semantics of any particular extra.
	 *
	 * Rules:
	 * - Must be an associative array (object).
	 * - Top-level keys are slug-like directory names: ASCII alphanumerics,
	 *   dashes, and underscores; no slashes; not in {@see RESERVED_TREES} and
	 *   not equal to the manifest filename.
	 * - Each value is a map of file path => string contents.
	 * - File paths must start with `<key>/`, must not contain `..`, and must
	 *   not be absolute.
	 *
	 * @param mixed $extras Candidate extras payload.
	 * @return array<string,array<string,string>> Normalized extras map. Empty
	 *                                            array when input is empty.
	 * @throws BundleValidationException On any rule violation.
	 */
	public static function validate_extras( mixed $extras ): array {
		if ( null === $extras || ( is_array( $extras ) && array() === $extras ) ) {
			return array();
		}

		if ( ! is_array( $extras ) || array_is_list( $extras ) ) {
			throw new BundleValidationException( 'Bundle extras must be an associative object keyed by directory name.' );
		}

		$normalized = array();
		foreach ( $extras as $key => $files ) {
			$key = (string) $key;
			if ( '' === $key ) {
				throw new BundleValidationException( 'Bundle extras keys must be non-empty directory names.' );
			}
			if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]*$/', $key ) ) {
				throw new BundleValidationException( sprintf( 'Bundle extras key "%s" must be a slug-like directory name (ASCII alphanumerics, dashes, underscores).', esc_html( $key ) ) );
			}
			if ( in_array( $key, self::RESERVED_TREES, true ) || self::MANIFEST_FILE === $key ) {
				throw new BundleValidationException( sprintf( 'Bundle extras key "%s" collides with a reserved bundle entry.', esc_html( $key ) ) );
			}

			if ( ! is_array( $files ) ) {
				throw new BundleValidationException( sprintf( 'Bundle extras["%s"] must be an object mapping path => contents.', esc_html( $key ) ) );
			}
			if ( array() === $files ) {
				// Skip empty directories rather than emit empty payloads.
				continue;
			}
			if ( array_is_list( $files ) ) {
				throw new BundleValidationException( sprintf( 'Bundle extras["%s"] must be an associative object, not a list.', esc_html( $key ) ) );
			}

			$file_map      = array();
			$prefix        = $key . '/';
			$prefix_length = strlen( $prefix );
			foreach ( $files as $relative_path => $contents ) {
				$relative_path = (string) $relative_path;
				if ( '' === $relative_path ) {
					throw new BundleValidationException( sprintf( 'Bundle extras["%s"] contains an empty file path.', esc_html( $key ) ) );
				}
				$relative_path = str_replace( '\\', '/', $relative_path );
				if ( str_starts_with( $relative_path, '/' ) ) {
					throw new BundleValidationException( sprintf( 'Bundle extras["%s"] file path "%s" must be relative.', esc_html( $key ), esc_html( $relative_path ) ) );
				}
				if ( ! str_starts_with( $relative_path, $prefix ) ) {
					throw new BundleValidationException( sprintf( 'Bundle extras["%s"] file path "%s" must start with "%s".', esc_html( $key ), esc_html( $relative_path ), esc_html( $prefix ) ) );
				}
				$tail = substr( $relative_path, $prefix_length );
				if ( '' === $tail ) {
					throw new BundleValidationException( sprintf( 'Bundle extras["%s"] file path "%s" must include a file name beneath the directory.', esc_html( $key ), esc_html( $relative_path ) ) );
				}
				foreach ( explode( '/', $relative_path ) as $segment ) {
					if ( '..' === $segment || '.' === $segment ) {
						throw new BundleValidationException( sprintf( 'Bundle extras["%s"] file path "%s" must not contain ".." or "." segments.', esc_html( $key ), esc_html( $relative_path ) ) );
					}
				}
				if ( ! is_string( $contents ) ) {
					throw new BundleValidationException( sprintf( 'Bundle extras["%s"] file "%s" must be a string.', esc_html( $key ), esc_html( $relative_path ) ) );
				}
				$file_map[ $relative_path ] = $contents;
			}

			if ( array() === $file_map ) {
				continue;
			}

			ksort( $file_map, SORT_STRING );
			$normalized[ $key ] = $file_map;
		}

		ksort( $normalized, SORT_STRING );
		return $normalized;
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
