<?php
/**
 * Generic input normalization helpers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Return a string only for values that can safely be represented as text.
 *
 * @param mixed $value Value to normalize.
 */
function agents_api_scalar_to_string( $value ): string {
	if ( is_scalar( $value ) || $value instanceof \Stringable ) {
		return (string) $value;
	}

	return '';
}

/**
 * Coerce a value to int only when it is numeric, defaulting to 0.
 *
 * @param mixed $value Value to normalize.
 */
function agents_api_numeric_to_int( $value ): int {
	return is_numeric( $value ) ? (int) $value : 0;
}

/**
 * Keep only string-keyed entries for canonical associative arrays.
 *
 * @param array<mixed> $value Input array.
 * @return array<string,mixed>
 */
function agents_api_string_keyed_array( array $value ): array {
	$result = array();
	foreach ( $value as $key => $item ) {
		if ( is_string( $key ) ) {
			$result[ $key ] = $item;
		}
	}

	return $result;
}
