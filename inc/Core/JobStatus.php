<?php
/**
 * JobStatus Value Object
 *
 * Centralized job status definitions, validation, and categorization.
 * Single source of truth for all job status handling across the ecosystem.
 *
 * Supports compound statuses like "agent_skipped - not a music event" where
 * the base status is "agent_skipped" and the reason is "not a music event".
 *
 * @package DataMachine\Core
 * @since 0.9.7
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class JobStatus {

	// Base status constants
	public const PENDING            = 'pending';
	public const PROCESSING         = 'processing';
	public const WAITING            = 'waiting';
	public const COMPLETED          = 'completed';
	public const FAILED             = 'failed';
	public const COMPLETED_NO_ITEMS = 'completed_no_items';
	public const AGENT_SKIPPED      = 'agent_skipped';

	/**
	 * Final statuses that indicate job execution is complete.
	 */
	public const FINAL_STATUSES = array(
		self::COMPLETED,
		self::FAILED,
		self::COMPLETED_NO_ITEMS,
		self::AGENT_SKIPPED,
	);

	/**
	 * Success statuses that should reset failure counters.
	 */
	public const SUCCESS_STATUSES = array(
		self::COMPLETED,
		self::COMPLETED_NO_ITEMS,
		self::AGENT_SKIPPED,
	);

	private string $baseStatus;
	private ?string $reason;

	/**
	 * Private constructor - use factory methods.
	 */
	private function __construct( string $baseStatus, ?string $reason = null ) {
		$this->baseStatus = $baseStatus;
		$this->reason     = $reason;
	}

	/**
	 * Create JobStatus from a status string (may be compound).
	 *
	 * Parses strings like "agent_skipped - not a music event" into
	 * base status and reason components.
	 */
	public static function fromString( string $status ): self {
		$baseStatus = self::parseBaseStatus( $status );
		$reason     = self::parseReason( $status );

		return new self( $baseStatus, $reason );
	}

	/**
	 * Create an agent_skipped status with reason.
	 */
	public static function agentSkipped( string $reason ): self {
		return new self( self::AGENT_SKIPPED, $reason );
	}

	/**
	 * Create a completed status.
	 */
	public static function completed(): self {
		return new self( self::COMPLETED );
	}

	/**
	 * Create a failed status with optional reason.
	 *
	 * @param ?string $reason Optional failure reason for compound status.
	 */
	public static function failed( ?string $reason = null ): self {
		return new self( self::FAILED, $reason );
	}

	/**
	 * Create a completed_no_items status.
	 */
	public static function completedNoItems(): self {
		return new self( self::COMPLETED_NO_ITEMS );
	}

	/**
	 * Create a pending status.
	 */
	public static function pending(): self {
		return new self( self::PENDING );
	}

	/**
	 * Create a processing status.
	 */
	public static function processing(): self {
		return new self( self::PROCESSING );
	}

	/**
	 * Check if this is a final status (job execution complete).
	 */
	public function isFinal(): bool {
		return self::isStatusFinal( $this->baseStatus );
	}

	/**
	 * Check if a status string represents a final status.
	 */
	public static function isStatusFinal( string $status ): bool {
		$base = self::parseBaseStatus( $status );
		return in_array( $base, self::FINAL_STATUSES, true );
	}

	/**
	 * Check if this is a success status.
	 */
	public function isSuccess(): bool {
		return self::isStatusSuccess( $this->baseStatus );
	}

	/**
	 * Check if a status string represents success.
	 */
	public static function isStatusSuccess( string $status ): bool {
		$base = self::parseBaseStatus( $status );
		return in_array( $base, self::SUCCESS_STATUSES, true );
	}

	/**
	 * Check if this is a failure status.
	 */
	public function isFailure(): bool {
		return $this->baseStatus === self::FAILED;
	}

	/**
	 * Check if a status string represents failure.
	 */
	public static function isStatusFailure( string $status ): bool {
		$base = self::parseBaseStatus( $status );
		return $base === self::FAILED;
	}

	/**
	 * Check if this status should reset the consecutive failure counter.
	 */
	public function shouldResetFailureCount(): bool {
		return $this->isSuccess();
	}

	/**
	 * Check if this status should increment the no-items counter.
	 */
	public function shouldIncrementNoItemsCount(): bool {
		return $this->baseStatus === self::COMPLETED_NO_ITEMS;
	}

	/**
	 * Check if this status should trigger processed items cleanup.
	 */
	public function shouldCleanupProcessedItems(): bool {
		return $this->isFailure();
	}

	/**
	 * Check if this is a waiting status (pipeline parked at webhook gate).
	 */
	public function isWaiting(): bool {
		return $this->baseStatus === self::WAITING;
	}

	/**
	 * Check if a status string represents waiting.
	 */
	public static function isStatusWaiting( string $status ): bool {
		$base = self::parseBaseStatus( $status );
		return $base === self::WAITING;
	}

	/**
	 * Create a waiting status.
	 */
	public static function waiting(): self {
		return new self( self::WAITING );
	}

	/**
	 * Check if this is an agent_skipped status.
	 */
	public function isAgentSkipped(): bool {
		return $this->baseStatus === self::AGENT_SKIPPED;
	}

	/**
	 * Check if this is a completed status (not completed_no_items).
	 */
	public function isCompleted(): bool {
		return $this->baseStatus === self::COMPLETED;
	}

	/**
	 * Check if this is a completed_no_items status.
	 */
	public function isCompletedNoItems(): bool {
		return $this->baseStatus === self::COMPLETED_NO_ITEMS;
	}

	/**
	 * Get the base status (without reason).
	 */
	public function getBaseStatus(): string {
		return $this->baseStatus;
	}

	/**
	 * Get the reason (if any).
	 */
	public function getReason(): ?string {
		return $this->reason;
	}

	/**
	 * Check if this status has a reason.
	 */
	public function hasReason(): bool {
		return $this->reason !== null && $this->reason !== '';
	}

	/**
	 * Convert to string for database storage.
	 *
	 * Returns compound format "base_status - reason" if reason exists,
	 * otherwise just the base status.
	 */
	public function toString(): string {
		if ( $this->hasReason() ) {
			return "{$this->baseStatus} - {$this->reason}";
		}
		return $this->baseStatus;
	}

	/**
	 * Magic method for string casting.
	 */
	public function __toString(): string {
		return $this->toString();
	}

	/**
	 * Parse base status from a potentially compound status string.
	 */
	private static function parseBaseStatus( string $status ): string {
		foreach ( self::FINAL_STATUSES as $base ) {
			if ( str_starts_with( $status, $base ) ) {
				return $base;
			}
		}

		if ( str_starts_with( $status, self::PROCESSING ) ) {
			return self::PROCESSING;
		}

		if ( str_starts_with( $status, self::WAITING ) ) {
			return self::WAITING;
		}

		if ( str_starts_with( $status, self::PENDING ) ) {
			return self::PENDING;
		}

		return $status;
	}

	/**
	 * Parse reason from a compound status string.
	 */
	private static function parseReason( string $status ): ?string {
		if ( str_contains( $status, ' - ' ) ) {
			$parts = explode( ' - ', $status, 2 );
			return trim( $parts[1] ?? '' );
		}
		return null;
	}
}
