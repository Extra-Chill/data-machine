<?php
/**
 * Runtime package run result value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized status/result/evidence envelope for portable package runs.
 *
 * @since 0.3.0
 */
final class WP_Agent_Runtime_Package_Run_Result {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_SUCCEEDED = 'succeeded';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_SKIPPED   = 'skipped';

	/**
	 * @param array<string,mixed> $result        Consumer-defined output.
	 * @param array<string,mixed> $error         Stable error envelope.
	 * @param array<int,array<string,mixed>> $evidence_refs Neutral artifact/log refs.
	 * @param array<string,mixed> $metadata      Host/runtime metadata.
	 * @param array<string,mixed> $replay        Replay/materialization metadata.
	 * @param array<int,array<string,mixed>> $artifact_refs Canonical artifact refs.
	 */
	public function __construct(
		private string $status,
		private string $run_id = '',
		private array $result = array(),
		private array $error = array(),
		private array $evidence_refs = array(),
		private array $metadata = array(),
		private array $replay = array(),
		private array $artifact_refs = array()
	) {}

	/**
	 * @param array<mixed> $value Raw handler result.
	 */
	public static function from_array( array $value ): self {
		$status = self::string_value( $value['status'] ?? '' );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::STATUS_SUCCEEDED;
		}

		return new self(
			$status,
			self::string_value( $value['run_id'] ?? '' ),
			self::array_value( $value['result'] ?? array() ),
			self::array_value( $value['error'] ?? array() ),
			self::list_of_arrays( $value['evidence_refs'] ?? array() ),
			self::array_value( $value['metadata'] ?? array() ),
			self::array_value( $value['replay'] ?? array() ),
			self::list_of_arrays( $value['artifact_refs'] ?? array() )
		);
	}

	/** @return array<int,string> */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_RUNNING,
			self::STATUS_SUCCEEDED,
			self::STATUS_FAILED,
			self::STATUS_CANCELLED,
			self::STATUS_SKIPPED,
		);
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_run_id(): string {
		return $this->run_id;
	}

	/** @return array<string,mixed> */
	public function get_result(): array {
		return $this->result;
	}

	/** @return array<string,mixed> */
	public function get_error(): array {
		return $this->error;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_evidence_refs(): array {
		return $this->evidence_refs;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_artifact_refs(): array {
		return self::list_of_arrays( $this->artifact_refs );
	}

	/** @return array<string,mixed> */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/** @return array<string,mixed> */
	public function get_replay(): array {
		return $this->replay;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'status'        => $this->status,
			'run_id'        => $this->run_id,
			'result'        => $this->result,
			'error'         => $this->error,
			'artifact_refs' => $this->artifact_refs,
			'evidence_refs' => $this->evidence_refs,
			'metadata'      => $this->metadata,
			'replay'        => $this->replay,
		);
	}

	public function to_run_result_envelope(): WP_Agent_Run_Result_Envelope {
		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'        => $this->run_id,
				'status'        => $this->status,
				'outputs'       => $this->result,
				'artifact_refs' => $this->artifact_refs,
				'evidence_refs' => $this->evidence_refs,
				'replay'        => $this->replay,
				'error'         => $this->error,
				'metadata'      => $this->metadata,
			)
		);
	}

	public function to_canonical_envelope(): WP_Agent_Run_Result_Envelope {
		return $this->to_run_result_envelope();
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @return array<string,mixed> */
	private static function array_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}
		return $normalized;
	}

	/** @return array<int,array<string,mixed>> */
	private static function list_of_arrays( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$items[] = self::array_value( $item );
			}
		}
		return $items;
	}
}
