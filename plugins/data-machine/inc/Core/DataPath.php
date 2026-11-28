<?php
/**
 * Generic array dot-path helpers.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves dot paths and shared value-presence semantics.
 */
final class DataPath {

	private function __construct() {}

	public static function value( array $source, string $path ): mixed {
		$value = $source;
		foreach ( self::segments( $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}

		return $value;
	}

	public static function hasValue( mixed $value ): bool {
		return null !== $value && '' !== $value && array() !== $value;
	}

	public static function hasPath( array $source, string $path ): bool {
		$value = $source;
		foreach ( self::segments( $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return false;
			}
			$value = $value[ $part ];
		}

		return true;
	}

	public static function hasPathValue( array $source, string $path, mixed $expected ): bool {
		return self::hasPath( $source, $path ) && self::value( $source, $path ) === $expected;
	}

	public static function hasPresentPath( array $source, string $path ): bool {
		return self::hasPath( $source, $path ) && self::hasValue( self::value( $source, $path ) );
	}

	/** @return array<string,mixed> */
	public static function filterPresent( array $value ): array {
		return array_filter( $value, array( self::class, 'hasValue' ) );
	}

	/** @return array<int,string> */
	private static function segments( string $path ): array {
		return array_values( array_filter( explode( '.', $path ), static fn( string $part ): bool => '' !== $part ) );
	}
}
