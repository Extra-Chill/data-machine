<?php

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Decodes JSON supplied directly by WP-CLI.
 */
final class JsonInput {

	/**
	 * WP-CLI arguments are not WordPress request data and must not be unslashed.
	 *
	 * @return array<mixed>|null Decoded object/array, or null for invalid JSON/scalars.
	 */
	public static function decode_array( string $json ): ?array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
