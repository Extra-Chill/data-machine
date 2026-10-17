<?php
/**
 * Agent consent decision value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a consent policy result with audit-safe metadata.
 */
final class WP_Agent_Consent_Decision {

	/** @var bool */
	private $allowed;

	/** @var string */
	private $operation;

	/** @var string */
	private $reason;

	/** @var array<mixed> */
	private $audit_metadata;

	/**
	 * @param bool   $allowed        Whether the operation is allowed.
	 * @param string $operation      Consent operation value.
	 * @param string $reason         Stable reason code.
	 * @param array<mixed>  $audit_metadata JSON-friendly audit metadata.
	 */
	private function __construct( bool $allowed, string $operation, string $reason, array $audit_metadata = array() ) {
		$normalized_operation = WP_Agent_Consent_Operation::normalize( $operation );

		$this->allowed        = $allowed;
		$this->operation      = null === $normalized_operation ? '' : $normalized_operation;
		$this->reason         = self::normalize_key( $reason );
		$this->audit_metadata = self::normalize_metadata( $audit_metadata );
	}

	/**
	 * Build an allowed decision.
	 *
	 * @param string $operation      Consent operation value.
	 * @param string $reason         Stable reason code.
	 * @param array<mixed>  $audit_metadata JSON-friendly audit metadata.
	 * @return self
	 */
	public static function allowed( string $operation, string $reason = 'allowed', array $audit_metadata = array() ): self {
		return new self( true, $operation, $reason, $audit_metadata );
	}

	/**
	 * Build a denied decision.
	 *
	 * @param string $operation      Consent operation value.
	 * @param string $reason         Stable reason code.
	 * @param array<mixed>  $audit_metadata JSON-friendly audit metadata.
	 * @return self
	 */
	public static function denied( string $operation, string $reason = 'denied', array $audit_metadata = array() ): self {
		return new self( false, $operation, $reason, $audit_metadata );
	}

	/**
	 * Whether the operation is allowed.
	 *
	 * @return bool
	 */
	public function is_allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Consent operation value.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Stable reason code.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * JSON-friendly audit metadata.
	 *
	 * @return array<mixed>
	 */
	public function audit_metadata(): array {
		return $this->audit_metadata;
	}

	/**
	 * Return JSON-friendly shape.
	 *
	 * @return array<mixed>
	 */
	public function to_array(): array {
		return array(
			'allowed'        => $this->allowed,
			'operation'      => $this->operation,
			'reason'         => $this->reason,
			'audit_metadata' => $this->audit_metadata,
		);
	}

	/**
	 * Normalize arbitrary metadata to JSON-friendly scalar/array values.
	 *
	 * @param array<mixed> $metadata Raw metadata.
	 * @return array<mixed>
	 */
	private static function normalize_metadata( array $metadata ): array {
		$normalized = array();

		foreach ( $metadata as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$normalized[ self::normalize_key( (string) $key ) ] = $value;
			} elseif ( is_array( $value ) ) {
				$normalized[ self::normalize_key( (string) $key ) ] = self::normalize_metadata( $value );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a string to a stable machine key without requiring WordPress helpers.
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	private static function normalize_key( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9_\-]+/', '_', $value );

		return trim( is_string( $value ) ? $value : '', '_-' );
	}
}
