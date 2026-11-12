<?php
/**
 * Portable auth reference value object.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Symbolic credential handle used by portable bundles.
 */
final class AuthRef {

	private string $provider;
	private string $account;
	private array $metadata;

	public function __construct( string $provider, string $account, array $metadata = array() ) {
		$this->provider = self::validate_segment( $provider, 'provider' );
		$this->account  = self::validate_segment( $account, 'account' );
		$this->metadata = self::sanitize_metadata( $metadata );
	}

	public static function from_string( string $ref, array $metadata = array() ): self {
		$parts = explode( ':', trim( $ref ), 2 );
		if ( 2 !== count( $parts ) ) {
			throw new BundleValidationException( 'auth_ref must use provider:account format.' );
		}

		return new self( $parts[0], $parts[1], $metadata );
	}

	public static function from_array( array $data ): self {
		if ( isset( $data['ref'] ) ) {
			return self::from_string( (string) $data['ref'], is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array() );
		}

		foreach ( array( 'provider', 'account' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new BundleValidationException( sprintf( 'auth_ref is missing required field %s.', esc_html( $field ) ) );
			}
		}

		return new self( (string) $data['provider'], (string) $data['account'], is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array() );
	}

	public function ref(): string {
		return $this->provider . ':' . $this->account;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function account(): string {
		return $this->account;
	}

	public function metadata(): array {
		return $this->metadata;
	}

	public function to_array(): array {
		return array(
			'ref'      => $this->ref(),
			'provider' => $this->provider,
			'account'  => $this->account,
			'metadata' => $this->metadata,
		);
	}

	private static function validate_segment( string $value, string $field ): string {
		$value = trim( strtolower( $value ) );
		if ( '' === $value || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $value ) ) {
			throw new BundleValidationException( sprintf( 'auth_ref %s must be lowercase letters, numbers, dots, underscores, or dashes.', esc_html( $field ) ) );
		}

		return $value;
	}

	private static function sanitize_metadata( array $metadata ): array {
		$clean = array();
		foreach ( $metadata as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/(secret|token|password|credential|key)/i', $key ) ) {
				continue;
			}
			$clean[ $key ] = is_scalar( $value ) || null === $value ? $value : (string) wp_json_encode( $value );
		}

		ksort( $clean, SORT_STRING );
		return $clean;
	}
}
