<?php
/**
 * Generic pending-action approval decision.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable accept/reject decision for a pending action.
 */
final class WP_Agent_Approval_Decision {

	public const ACCEPTED = 'accepted';
	public const REJECTED = 'rejected';

	/** @var string Normalized decision value. */
	private string $value;

	/** @var bool Whether the accepted policy should be remembered by the host. */
	private bool $remember;

	/**
	 * @param string $value    Decision value.
	 * @param bool   $remember Whether the accepted policy should be remembered.
	 */
	private function __construct( string $value, bool $remember = false ) {
		if ( ! in_array( $value, array( self::ACCEPTED, self::REJECTED ), true ) ) {
			throw new \InvalidArgumentException( 'Approval decision must be accepted or rejected.' );
		}

		$this->value    = $value;
		$this->remember = $remember;
	}

	/** @return self Accepted decision. */
	public static function accepted(): self {
		return new self( self::ACCEPTED );
	}

	/** @return self Rejected decision. */
	public static function rejected(): self {
		return new self( self::REJECTED );
	}

	/**
	 * Build a decision from a stored or request value.
	 *
	 * @param string $value Decision value.
	 * @return self
	 */
	public static function from_string( string $value ): self {
		return new self( $value );
	}

	/**
	 * Return a copy with explicit memory intent.
	 *
	 * @param bool $remember Whether the accepted policy should be remembered.
	 * @return self
	 */
	public function with_remember( bool $remember = true ): self {
		return new self( $this->value, $remember );
	}

	/** @return bool Whether the pending action was accepted. */
	public function is_accepted(): bool {
		return self::ACCEPTED === $this->value;
	}

	/** @return bool Whether the pending action was rejected. */
	public function is_rejected(): bool {
		return self::REJECTED === $this->value;
	}

	/** @return string Normalized decision value. */
	public function value(): string {
		return $this->value;
	}

	/** @return bool Whether the accepted policy should be remembered by the host. */
	public function remember(): bool {
		return $this->remember;
	}

	/** @return string Normalized decision value. */
	public function __toString(): string {
		return $this->value;
	}
}
