<?php

namespace DataMachine\Core\Database\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit repair support for literal JSON solidus escapes in flow config.
 */
final class FlowConfigEscaping {

	/**
	 * Replace only literal `\/` pairs and report every changed value.
	 *
	 * Other backslashes are byte-preserved. This is intentionally not called by
	 * normal persistence because a literal backslash before a slash can be valid.
	 *
	 * @param array<string,mixed> $config Flow config.
	 * @return array{config: array<string,mixed>, changes: array<int,array{path:string,before:string,after:string}>}
	 */
	public static function repair( array $config ): array {
		$changes = array();
		$config  = self::repair_value( $config, '', $changes );

		return array(
			'config'  => $config,
			'changes' => $changes,
		);
	}

	/**
	 * @param mixed                                                   $value Value to inspect.
	 * @param string                                                  $path Dot-separated config path.
	 * @param array<int,array{path:string,before:string,after:string}> $changes Collected changes.
	 * @return mixed
	 */
	private static function repair_value( mixed $value, string $path, array &$changes ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$child_path    = '' === $path ? (string) $key : $path . '.' . $key;
				$value[ $key ] = self::repair_value( $child, $child_path, $changes );
			}
			return $value;
		}

		if ( ! is_string( $value ) || ! str_contains( $value, '\\/' ) ) {
			return $value;
		}

		$repaired  = str_replace( '\\/', '/', $value );
		$changes[] = array(
			'path'   => $path,
			'before' => $value,
			'after'  => $repaired,
		);

		return $repaired;
	}
}
