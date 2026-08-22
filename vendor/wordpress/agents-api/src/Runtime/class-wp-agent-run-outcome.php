<?php
/**
 * Generic run outcome contract.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes runtime-neutral run outcomes and maps them to run-control status.
 */
class WP_Agent_Run_Outcome {

	public const SCHEMA  = 'agents-api.run-outcome';
	public const VERSION = 1;

	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_INTERRUPTED          = 'interrupted';
	public const STATUS_INCOMPLETE           = 'incomplete';

	public const STOP_NATURAL        = 'natural';
	public const STOP_MAX_TURNS      = 'max_turns';
	public const STOP_PROVIDER_ERROR = 'provider_error';

	/** @return string[] */
	public static function statuses(): array {
		return array(
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_CANCELLED,
			self::STATUS_INTERRUPTED,
			self::STATUS_INCOMPLETE,
		);
	}

	/**
	 * Normalize a stable run outcome envelope.
	 *
	 * @param mixed        $outcome Raw outcome value.
	 * @param array<mixed> $result  Normalized conversation result fields.
	 * @return array<string,mixed>
	 */
	public static function normalize( $outcome, array $result = array() ): array {
		$raw       = is_array( $outcome ) ? $outcome : array();
		$status    = self::normalize_status( $raw['status'] ?? null );
		$completed = array_key_exists( 'completed', $raw ) ? (bool) $raw['completed'] : (bool) ( $result['completed'] ?? true );

		if ( '' === $status ) {
			$status = self::derive_status( $result, $completed );
		}
		if ( self::STATUS_COMPLETED !== $status ) {
			$completed = false;
		}

		$normalized = array(
			'schema'      => self::SCHEMA,
			'version'     => self::VERSION,
			'status'      => $status,
			'completed'   => $completed,
			'stop_reason' => self::derive_stop_reason( $raw, $result, $status, $completed ),
			'retryable'   => array_key_exists( 'retryable', $raw ) ? (bool) $raw['retryable'] : self::derive_retryable( $result, $status ),
		);

		if ( isset( $raw['failure'] ) && is_array( $raw['failure'] ) ) {
			$normalized['failure'] = self::string_keyed_array( $raw['failure'] );
		} elseif ( isset( $result['failure'] ) && is_array( $result['failure'] ) ) {
			$normalized['failure'] = self::string_keyed_array( $result['failure'] );
		}

		if ( isset( $raw['assertions'] ) && is_array( $raw['assertions'] ) ) {
			$normalized['assertions'] = self::string_keyed_array( $raw['assertions'] );
		}

		if ( isset( $raw['provider_error'] ) && is_array( $raw['provider_error'] ) ) {
			$normalized['provider_error'] = self::string_keyed_array( $raw['provider_error'] );
		} elseif ( isset( $result['failure'] ) && is_array( $result['failure'] ) && self::STOP_PROVIDER_ERROR === $normalized['stop_reason'] ) {
			$normalized['provider_error'] = self::string_keyed_array( $result['failure'] );
		}

		if ( isset( $raw['metadata'] ) && is_array( $raw['metadata'] ) ) {
			$normalized['metadata'] = self::string_keyed_array( $raw['metadata'] );
		}

		return $normalized;
	}

	/**
	 * Map an outcome or result to the generic run-control status vocabulary.
	 *
	 * @param array<mixed> $outcome_or_result Normalized outcome or conversation result.
	 */
	public static function run_control_status( array $outcome_or_result ): string {
		$outcome = self::normalize( $outcome_or_result['run_outcome'] ?? null, $outcome_or_result );

		switch ( $outcome['status'] ) {
			case self::STATUS_RUNTIME_TOOL_PENDING:
				return WP_Agent_Run_Control::STATUS_RUNTIME_TOOL_PENDING;
			case self::STATUS_APPROVAL_REQUIRED:
				return WP_Agent_Run_Control::STATUS_APPROVAL_REQUIRED;
			case self::STATUS_BUDGET_EXCEEDED:
				return WP_Agent_Run_Control::STATUS_BUDGET_EXCEEDED;
			case self::STATUS_STALLED:
				return WP_Agent_Run_Control::STATUS_STALLED;
			case self::STATUS_CANCELLED:
				return WP_Agent_Run_Control::STATUS_CANCELLED;
			case self::STATUS_INTERRUPTED:
				return WP_Agent_Run_Control::STATUS_INTERRUPTED;
			case self::STATUS_FAILED:
				return WP_Agent_Run_Control::STATUS_FAILED;
			case self::STATUS_COMPLETED:
				return WP_Agent_Run_Control::STATUS_COMPLETED;
			default:
				return WP_Agent_Run_Control::STATUS_RUNNING;
		}
	}

	private static function normalize_status( mixed $status ): string {
		$status = self::string_value( $status );
		return in_array( $status, self::statuses(), true ) ? $status : '';
	}

	/** @param array<mixed> $result */
	private static function derive_status( array $result, bool $completed ): string {
		$status = self::string_value( $result['status'] ?? null );
		if ( self::STATUS_RUNTIME_TOOL_PENDING === $status ) {
			return self::STATUS_RUNTIME_TOOL_PENDING;
		}
		if ( self::STATUS_APPROVAL_REQUIRED === $status ) {
			return self::STATUS_APPROVAL_REQUIRED;
		}
		if ( self::STATUS_BUDGET_EXCEEDED === $status ) {
			return self::STATUS_BUDGET_EXCEEDED;
		}
		if ( self::STATUS_STALLED === $status ) {
			return self::STATUS_STALLED;
		}
		if ( self::STATUS_INTERRUPTED === $status ) {
			return self::STATUS_INTERRUPTED;
		}
		if ( self::STATUS_CANCELLED === $status ) {
			return self::STATUS_CANCELLED;
		}
		if ( self::STATUS_FAILED === $status || isset( $result['failure'] ) ) {
			return self::STATUS_FAILED;
		}
		return $completed ? self::STATUS_COMPLETED : self::STATUS_INCOMPLETE;
	}

	/**
	 * @param array<mixed> $raw    Raw outcome fields.
	 * @param array<mixed> $result Normalized result fields.
	 */
	private static function derive_stop_reason( array $raw, array $result, string $status, bool $completed ): string {
		$stop_reason = self::string_value( $raw['stop_reason'] ?? null );
		if ( '' !== $stop_reason ) {
			return $stop_reason;
		}

		$result_status = self::string_value( $result['status'] ?? null );
		if ( self::STATUS_BUDGET_EXCEEDED === $result_status && 'turns' === self::string_value( $result['budget'] ?? null ) ) {
			return self::STOP_MAX_TURNS;
		}
		if ( self::STATUS_FAILED === $status ) {
			return self::STOP_PROVIDER_ERROR;
		}
		if ( self::STATUS_COMPLETED === $status && $completed ) {
			return self::STOP_NATURAL;
		}
		return '' !== $result_status ? $result_status : $status;
	}

	/** @param array<mixed> $result */
	private static function derive_retryable( array $result, string $status ): bool {
		if ( in_array( $status, array( self::STATUS_RUNTIME_TOOL_PENDING, self::STATUS_APPROVAL_REQUIRED, self::STATUS_CANCELLED, self::STATUS_INTERRUPTED ), true ) ) {
			return false;
		}
		if ( self::STATUS_BUDGET_EXCEEDED === self::string_value( $result['status'] ?? null ) ) {
			return true;
		}
		return self::STATUS_FAILED === $status;
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? strtolower( trim( (string) $value ) ) : '';
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}
		return $normalized;
	}
}
