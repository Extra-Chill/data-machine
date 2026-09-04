<?php
/**
 * Spin signature value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic identity for a tool call shape that may repeat across turns.
 */
final class WP_Agent_Spin_Signature {

	private string $tool_name;
	/** @var array<mixed> */
	private array $parameters;
	private string $parameters_hash;
	private string $signature_hash;

	/**
	 * @param string               $tool_name  Tool name.
	 * @param array<string, mixed> $parameters Tool call parameters.
	 */
	public function __construct( string $tool_name, array $parameters = array() ) {
		$this->tool_name       = trim( $tool_name );
		$this->parameters      = self::sort_for_hash_array( $parameters );
		$this->parameters_hash = self::stable_sha256( $this->parameters );
		$this->signature_hash  = self::stable_sha256(
			array(
				'tool_name'       => $this->tool_name,
				'parameters_hash' => $this->parameters_hash,
			)
		);
	}

	public function tool_name(): string {
		return $this->tool_name;
	}

	public function parameters_hash(): string {
		return $this->parameters_hash;
	}

	public function hash(): string {
		return $this->signature_hash;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'tool_name'       => $this->tool_name,
			'parameters_hash' => $this->parameters_hash,
			'signature_hash'  => $this->signature_hash,
		);
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
	 * @param array<mixed> $value Value to normalize.
	 * @return array<mixed> Normalized value.
	 */
	private static function sort_for_hash_array( array $value ): array {
		$normalized = self::sort_for_hash( $value );
		return is_array( $normalized ) ? $normalized : array();
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

		if ( array() !== $normalized && array_keys( $normalized ) !== range( 0, count( $normalized ) - 1 ) ) {
			ksort( $normalized );
		}

		return $normalized;
	}
}
