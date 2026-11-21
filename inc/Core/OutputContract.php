<?php
/**
 * Generic output contract helpers.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and evaluates typed artifact output contracts.
 */
final class OutputContract {

	private function __construct() {}

	/**
	 * @param mixed $value Raw typed artifact output assertions.
	 * @return array<int, array{output_key: string, schema: string, artifact: string}>
	 */
	public static function normalizeRequiredArtifactOutputs( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$outputs = array();
		foreach ( $value as $key => $entry ) {
			if ( is_string( $entry ) ) {
				$output_key = trim( $entry );
				$schema     = '';
				$artifact   = '';
			} elseif ( is_array( $entry ) ) {
				$output_key = trim( (string) ( $entry['output_key'] ?? ( is_string( $key ) ? $key : '' ) ) );
				$schema     = trim( (string) ( $entry['schema'] ?? '' ) );
				$artifact   = trim( (string) ( $entry['artifact'] ?? '' ) );
			} else {
				continue;
			}

			if ( '' !== $output_key ) {
				$outputs[ $output_key ] = array(
					'output_key' => $output_key,
					'schema'     => $schema,
					'artifact'   => $artifact,
				);
			}
		}

		return array_values( $outputs );
	}

	/**
	 * @param array<int, array{output_key: string, schema: string, artifact: string}> $outputs Required outputs.
	 * @return array<int,string>
	 */
	public static function requiredArtifactOutputKeys( array $outputs ): array {
		return array_values( array_unique( array_map( static fn( array $output ): string => $output['output_key'], $outputs ) ) );
	}

	public static function hasOutputPayload( array $output ): bool {
		return array_key_exists( 'payload', $output ) && DataPath::hasValue( $output['payload'] );
	}

	/** @param array{output_key: string, schema: string, artifact: string} $required_output Required output contract. */
	public static function artifactOutputSatisfied( array $required_output, array $typed_artifacts ): bool {
		$output_key = $required_output['output_key'];
		$output     = is_array( $typed_artifacts[ $output_key ] ?? null ) ? $typed_artifacts[ $output_key ] : array();
		if ( ! self::hasOutputPayload( $output ) ) {
			return false;
		}

		if ( '' !== $required_output['schema'] && (string) ( $output['schema'] ?? '' ) !== $required_output['schema'] ) {
			return false;
		}

		if ( '' !== $required_output['artifact'] && (string) ( $output['artifact'] ?? '' ) !== $required_output['artifact'] ) {
			return false;
		}

		return true;
	}
}
