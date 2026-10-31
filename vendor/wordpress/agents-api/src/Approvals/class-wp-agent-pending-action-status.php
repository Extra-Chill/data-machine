<?php
/**
 * Pending action status vocabulary.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Approvals;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical lifecycle states for durable pending actions.
 */
final class WP_Agent_Pending_Action_Status {

	public const PENDING  = 'pending';
	public const ACCEPTED = 'accepted';
	public const REJECTED = 'rejected';
	public const EXPIRED  = 'expired';
	public const DELETED  = 'deleted';

	/**
	 * Return every canonical status value.
	 *
	 * @return array<int,string>
	 */
	public static function values(): array {
		return array( self::PENDING, self::ACCEPTED, self::REJECTED, self::EXPIRED, self::DELETED );
	}

	/**
	 * Whether a status is part of the canonical vocabulary.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::values(), true );
	}

	/**
	 * Normalize a status string or throw when it is not supported.
	 */
	public static function normalize( string $status ): string {
		$status = trim( $status );
		if ( ! self::is_valid( $status ) ) {
			throw new \InvalidArgumentException( 'invalid_ai_pending_action_status: status must be pending, accepted, rejected, expired, or deleted' );
		}

		return $status;
	}

	/**
	 * Whether the status is terminal for audit purposes.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( self::normalize( $status ), array( self::ACCEPTED, self::REJECTED, self::EXPIRED, self::DELETED ), true );
	}
}
