<?php
/**
 * Remote bridge queue item value object.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Channels;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Bridge_Queue_Item {

	public readonly string $queue_id;
	public readonly string $client_id;
	public readonly ?string $connector_id;
	public readonly string $agent;
	public readonly ?string $session_id;
	public readonly string $role;
	public readonly string $content;
	public readonly bool $completed;
	public readonly string $created_at;
	public readonly string $delivery_status;
	/** @var array<string,mixed> */
	public readonly array $metadata;

	/**
	 * @param array<string,mixed> $args Queue item fields.
	 */
	public function __construct( array $args ) {
		$this->queue_id        = self::normalize_required_string( self::string_value( $args['queue_id'] ?? self::new_queue_id() ), 'queue_id' );
		$this->client_id       = self::normalize_required_slug( self::string_value( $args['client_id'] ?? '' ), 'client_id' );
		$this->connector_id    = isset( $args['connector_id'] ) ? self::normalize_optional_slug( self::string_value( $args['connector_id'] ) ) : null;
		$this->agent           = self::normalize_required_slug( self::string_value( $args['agent'] ?? '' ), 'agent' );
		$this->session_id      = isset( $args['session_id'] ) ? self::normalize_optional_string( self::string_value( $args['session_id'] ) ) : null;
		$this->role            = self::normalize_role( self::string_value( $args['role'] ?? 'assistant' ) );
		$this->content         = self::normalize_required_string( self::string_value( $args['content'] ?? '' ), 'content' );
		$this->completed       = (bool) ( $args['completed'] ?? true );
		$this->created_at      = isset( $args['created_at'] ) ? self::string_value( $args['created_at'] ) : gmdate( 'c' );
		$this->delivery_status = self::normalize_delivery_status( self::string_value( $args['delivery_status'] ?? 'pending' ) );
		$this->metadata        = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? self::string_keyed_array( $args['metadata'] ) : array();
	}

	/**
	 * Build a queue item from a stored array.
	 *
	 * @param array<string,mixed> $data Stored data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

	/**
	 * Export to a JSON-friendly array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'queue_id'        => $this->queue_id,
			'client_id'       => $this->client_id,
			'connector_id'    => $this->connector_id,
			'agent'           => $this->agent,
			'session_id'      => $this->session_id,
			'role'            => $this->role,
			'content'         => $this->content,
			'completed'       => $this->completed,
			'created_at'      => $this->created_at,
			'delivery_status' => $this->delivery_status,
			'metadata'        => $this->metadata,
		);
	}

	/**
	 * Return a copy with an updated delivery status.
	 *
	 * @param string $delivery_status Delivery status.
	 * @return self
	 */
	public function with_delivery_status( string $delivery_status ): self {
		$data                    = $this->to_array();
		$data['delivery_status'] = $delivery_status;
		return new self( $data );
	}

	private static function new_queue_id(): string {
		return 'bridge_' . bin2hex( random_bytes( 16 ) );
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
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

	private static function normalize_required_slug( string $value, string $field ): string {
		$value = self::normalize_slug( $value );
		if ( '' === $value ) {
			if ( 'client_id' === $field ) {
				throw new InvalidArgumentException( 'client_id must be a non-empty slug.' );
			}
			throw new InvalidArgumentException( 'agent must be a non-empty slug.' );
		}
		return $value;
	}

	private static function normalize_optional_slug( string $value ): ?string {
		$value = self::normalize_slug( $value );
		return '' === $value ? null : $value;
	}

	private static function normalize_slug( string $value ): string {
		$value = trim( strtolower( str_replace( '_', '-', $value ) ) );
		$value = preg_replace( '/[^a-z0-9-]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	private static function normalize_required_string( string $value, string $field ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			if ( 'queue_id' === $field ) {
				throw new InvalidArgumentException( 'queue_id must be non-empty.' );
			}
			throw new InvalidArgumentException( 'content must be non-empty.' );
		}
		return $value;
	}

	private static function normalize_optional_string( string $value ): ?string {
		$value = trim( $value );
		return '' === $value ? null : $value;
	}

	private static function normalize_role( string $role ): string {
		$role = self::normalize_slug( $role );
		if ( ! in_array( $role, array( 'assistant', 'system', 'tool' ), true ) ) {
			throw new InvalidArgumentException( 'role must be assistant, system, or tool.' );
		}
		return $role;
	}

	private static function normalize_delivery_status( string $delivery_status ): string {
		$delivery_status = self::normalize_slug( $delivery_status );
		if ( ! in_array( $delivery_status, array( 'pending', 'delivered', 'failed' ), true ) ) {
			throw new InvalidArgumentException( 'delivery_status must be pending, delivered, or failed.' );
		}
		return $delivery_status;
	}
}
