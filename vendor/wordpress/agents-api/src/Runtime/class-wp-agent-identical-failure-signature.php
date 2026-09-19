<?php
/**
 * Identical failure signature value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic identity for a repeated failed tool call.
 */
final class WP_Agent_Identical_Failure_Signature {

	private string $tool_name;
	private string $parameters_hash;
	private string $error_code;
	private string $signature_hash;

	/**
	 * @param string               $tool_name  Tool name.
	 * @param array<string, mixed> $parameters Tool call parameters.
	 * @param array<string, mixed> $result     Tool execution result.
	 */
	public function __construct( string $tool_name, array $parameters, array $result ) {
		$this->tool_name       = trim( $tool_name );
		$this->parameters_hash = self::stable_sha256( $parameters );
		$this->error_code      = self::extract_error_code( $result );
		$this->signature_hash  = self::stable_sha256(
			array(
				'tool_name'       => $this->tool_name,
				'parameters_hash' => $this->parameters_hash,
				'error_code'      => $this->error_code,
			)
		);
	}

	public function tool_name(): string {
		return $this->tool_name;
	}

	public function parameters_hash(): string {
		return $this->parameters_hash;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function hash(): string {
		return $this->signature_hash;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'tool_name'       => $this->tool_name,
			'parameters_hash' => $this->parameters_hash,
			'error_code'      => $this->error_code,
			'signature_hash'  => $this->signature_hash,
		);
	}

	/** @param array<string, mixed> $result Tool execution result. */
	private static function extract_error_code( array $result ): string {
		foreach ( array( 'error_code', 'code' ) as $key ) {
			$value = $result[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return self::normalize_error_code( (string) $value );
			}
		}

		$metadata = isset( $result['metadata'] ) && is_array( $result['metadata'] )
			? $result['metadata']
			: array();
		foreach ( array( 'error_type', 'error_code', 'code' ) as $key ) {
			$value = $metadata[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return self::normalize_error_code( (string) $value );
			}
		}

		$error = is_string( $result['error'] ?? null ) ? $result['error'] : 'tool_execution_failed';
		return 'error_' . substr( self::stable_sha256( $error ), 7, 12 );
	}

	private static function normalize_error_code( string $code ): string {
		$code = strtolower( trim( $code ) );
		$code = (string) preg_replace( '/[^a-z0-9_\-]+/', '_', $code );
		return '' === $code ? 'tool_execution_failed' : $code;
	}

	/**
	 * @param mixed $data Data to hash.
	 */
	private static function stable_sha256( $data ): string {
		$encoded = wp_json_encode( self::sort_for_hash( $data ) );
		if ( false === $encoded ) {
			$encoded = '';
		}

		return 'sha256:' . hash( 'sha256', (string) $encoded );
	}

	/**
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private static function sort_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = self::sort_for_hash( $item );
		}

		if (
			array() !== $normalized
			&& array_keys( $normalized ) !== range( 0, count( $normalized ) - 1 )
		) {
			ksort( $normalized );
		}

		return $normalized;
	}
}
