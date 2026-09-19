<?php
/**
 * Remote bridge client registration value object.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Bridge_Client {

	public readonly string $client_id;
	public readonly ?string $connector_id;
	public readonly ?string $callback_url;
	/** @var array<string,mixed> */
	public readonly array $context;
	public readonly string $registered_at;

	/**
	 * @param string              $client_id     Stable remote bridge client id.
	 * @param string|null         $connector_id  Optional Core Connectors API connector id.
	 * @param string|null         $callback_url  Optional callback URL for best-effort delivery.
	 * @param array<string,mixed> $context       Opaque client metadata.
	 * @param string|null         $registered_at Registration timestamp.
	 */
	public function __construct( string $client_id, ?string $connector_id = null, ?string $callback_url = null, array $context = array(), ?string $registered_at = null ) {
		$this->client_id     = self::normalize_required_id( $client_id, 'client_id' );
		$this->connector_id  = self::normalize_optional_id( $connector_id );
		$this->callback_url  = self::normalize_callback_url( $callback_url );
		$this->context       = $context;
		$this->registered_at = $registered_at ?? gmdate( 'c' );
	}

	/**
	 * Build from an array payload.
	 *
	 * @param array<string,mixed> $data Client data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			self::string_value( $data['client_id'] ?? '' ),
			self::nullable_string_value( $data['connector_id'] ?? null ),
			self::nullable_string_value( $data['callback_url'] ?? null ),
			isset( $data['context'] ) && is_array( $data['context'] ) ? self::string_keyed_array( $data['context'] ) : array(),
			self::nullable_string_value( $data['registered_at'] ?? null )
		);
	}

	/**
	 * Export to a JSON-friendly array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'client_id'     => $this->client_id,
			'connector_id'  => $this->connector_id,
			'callback_url'  => $this->callback_url,
			'context'       => $this->context,
			'registered_at' => $this->registered_at,
		);
	}

	/**
	 * Resolve Core Connectors metadata for this client when available.
	 *
	 * @return array<string,mixed>|null Connector metadata, or null when unavailable.
	 */
	public function connector(): ?array {
		if ( null === $this->connector_id || ! function_exists( 'wp_get_connector' ) ) {
			return null;
		}

		$connector = wp_get_connector( $this->connector_id );
		return is_array( $connector ) ? self::string_keyed_array( $connector ) : null;
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}

	private static function nullable_string_value( mixed $value ): ?string {
		return null === $value ? null : self::string_value( $value );
	}

	/**
	 * @param array<mixed> $data
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $data ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}

	private static function normalize_required_id( string $value, string $field ): string {
		$value = self::normalize_id( $value );
		if ( '' === $value ) {
			if ( 'client_id' === $field ) {
				throw new InvalidArgumentException( 'client_id must be a non-empty slug.' );
			}
			throw new InvalidArgumentException( 'id must be a non-empty slug.' );
		}
		return $value;
	}

	private static function normalize_optional_id( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = self::normalize_id( $value );
		return '' === $value ? null : $value;
	}

	private static function normalize_id( string $value ): string {
		$value = trim( strtolower( str_replace( '_', '-', $value ) ) );
		$value = preg_replace( '/[^a-z0-9-]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	private static function normalize_callback_url( ?string $callback_url ): ?string {
		if ( null === $callback_url ) {
			return null;
		}

		$callback_url = trim( $callback_url );
		if ( '' === $callback_url ) {
			return null;
		}

		if ( false === filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
			throw new InvalidArgumentException( 'callback_url must be a valid URL.' );
		}

		return $callback_url;
	}
}
