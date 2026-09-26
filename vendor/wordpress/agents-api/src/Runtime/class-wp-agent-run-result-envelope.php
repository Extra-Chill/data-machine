<?php
/**
 * Canonical run result envelope.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Shared deterministic run/result envelope for tasks, workflows, and runtimes.
 */
final class WP_Agent_Run_Result_Envelope {

	public const SCHEMA  = 'agents-api/run-result/v1';
	public const VERSION = 1;

	public const STATUS_PENDING              = 'pending';
	public const STATUS_QUEUED               = 'queued';
	public const STATUS_RUNNING              = 'running';
	public const STATUS_CANCELLING           = 'cancelling';
	public const STATUS_CANCELLED            = 'cancelled';
	public const STATUS_SUCCEEDED            = 'succeeded';
	public const STATUS_FAILED               = 'failed';
	public const STATUS_SKIPPED              = 'skipped';
	public const STATUS_COMPLETED            = 'completed';
	public const STATUS_INCOMPLETE           = 'incomplete';
	public const STATUS_APPROVAL_REQUIRED    = 'approval_required';
	public const STATUS_RUNTIME_TOOL_PENDING = 'runtime_tool_pending';
	public const STATUS_BUDGET_EXCEEDED      = 'budget_exceeded';
	public const STATUS_STALLED              = 'stalled';
	public const STATUS_INTERRUPTED          = 'interrupted';

	/**
	 * @param array<string,mixed>            $outputs       Consumer-defined outputs.
	 * @param array<int,array<string,mixed>> $artifact_refs Canonical artifact references.
	 * @param array<int,array<string,mixed>> $evidence_refs Canonical evidence/log references.
	 * @param array<string,mixed>            $replay        Replay/materialization metadata.
	 * @param array<string,mixed>            $provenance    Producer/source metadata.
	 * @param array<string,mixed>            $timestamps    started_at/ended_at/updated_at values.
	 * @param array<string,mixed>            $error         Stable error envelope.
	 * @param array<string,mixed>            $cancellation  Cancellation request/result metadata.
	 * @param array<string,mixed>            $metadata      Host/runtime metadata.
	 * @param array<int,array<string,mixed>> $logs          Canonical log entries.
	 */
	public function __construct(
		private string $run_id,
		private string $status,
		private array $outputs = array(),
		private array $artifact_refs = array(),
		private array $evidence_refs = array(),
		private array $replay = array(),
		private array $provenance = array(),
		private array $timestamps = array(),
		private array $error = array(),
		private array $cancellation = array(),
		private array $metadata = array(),
		private array $logs = array()
	) {
		$this->status        = self::normalize_status( $this->status );
		$this->outputs       = self::map_value( $this->outputs );
		$this->artifact_refs = self::normalize_refs( $this->artifact_refs );
		$this->evidence_refs = self::normalize_refs( $this->evidence_refs );
		$this->logs          = self::normalize_entries( $this->logs );
		$this->replay        = self::map_value( $this->replay );
		$this->provenance    = self::map_value( $this->provenance );
		$this->timestamps    = self::timestamps_value( $this->timestamps );
		$this->error         = self::map_value( $this->error );
		$this->cancellation  = self::map_value( $this->cancellation );
		$this->metadata      = self::map_value( $this->metadata );
	}

	/** @return array<int,string> */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_CANCELLING,
			self::STATUS_CANCELLED,
			self::STATUS_SUCCEEDED,
			self::STATUS_FAILED,
			self::STATUS_SKIPPED,
			self::STATUS_COMPLETED,
			self::STATUS_INCOMPLETE,
			self::STATUS_APPROVAL_REQUIRED,
			self::STATUS_RUNTIME_TOOL_PENDING,
			self::STATUS_BUDGET_EXCEEDED,
			self::STATUS_STALLED,
			self::STATUS_INTERRUPTED,
		);
	}

	/**
	 * @param array<mixed> $value Raw envelope.
	 */
	public static function from_array( array $value ): self {
		$timestamps = self::map_value( $value['timestamps'] ?? array() );
		foreach ( array( 'started_at', 'ended_at', 'updated_at' ) as $field ) {
			if ( ! isset( $timestamps[ $field ] ) && isset( $value[ $field ] ) ) {
				$timestamps[ $field ] = $value[ $field ];
			}
		}

		return new self(
			self::string_value( $value['run_id'] ?? '' ),
			self::normalize_status( $value['status'] ?? null ),
			self::map_value( $value['outputs'] ?? ( $value['output'] ?? array() ) ),
			self::normalize_refs( $value['artifact_refs'] ?? array() ),
			self::normalize_refs( $value['evidence_refs'] ?? array() ),
			self::map_value( $value['replay'] ?? array() ),
			self::map_value( $value['provenance'] ?? array() ),
			$timestamps,
			self::map_value( $value['error'] ?? array() ),
			self::map_value( $value['cancellation'] ?? array() ),
			self::map_value( $value['metadata'] ?? array() ),
			self::normalize_entries( $value['logs'] ?? array() )
		);
	}

	public static function normalize_status( mixed $status ): string {
		$status = strtolower( trim( self::string_value( $status ) ) );
		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_RUNNING;
	}

	/**
	 * @param mixed $refs Raw refs.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_refs( mixed $refs ): array {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				continue;
			}

			$ref = self::map_value( $ref );
			foreach ( array( 'type', 'label', 'id', 'url', 'path', 'mime_type', 'sha256', 'description' ) as $field ) {
				if ( isset( $ref[ $field ] ) ) {
					$value = trim( self::string_value( $ref[ $field ] ) );
					if ( '' === $value ) {
						unset( $ref[ $field ] );
					} else {
						$ref[ $field ] = $value;
					}
				}
			}

			if ( array() !== $ref ) {
				$normalized[] = $ref;
			}
		}

		return $normalized;
	}

	public function get_run_id(): string {
		return $this->run_id;
	}

	public function get_status(): string {
		return $this->status;
	}

	/** @return array<string,mixed> */
	public function get_outputs(): array {
		return $this->outputs;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_artifact_refs(): array {
		return $this->artifact_refs;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_evidence_refs(): array {
		return $this->evidence_refs;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_logs(): array {
		return $this->logs;
	}

	/** @return array<string,mixed> */
	public function get_replay(): array {
		return $this->replay;
	}

	/** @return array<string,mixed> */
	public function get_provenance(): array {
		return $this->provenance;
	}

	/** @return array<string,mixed> */
	public function get_timestamps(): array {
		return $this->timestamps;
	}

	/** @return array<string,mixed> */
	public function get_error(): array {
		return $this->error;
	}

	/** @return array<string,mixed> */
	public function get_cancellation(): array {
		return $this->cancellation;
	}

	/** @return array<string,mixed> */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'schema'        => self::SCHEMA,
			'version'       => self::VERSION,
			'run_id'        => $this->run_id,
			'status'        => $this->status,
			'outputs'       => $this->outputs,
			'artifact_refs' => $this->artifact_refs,
			'evidence_refs' => $this->evidence_refs,
			'logs'          => $this->logs,
			'replay'        => $this->replay,
			'provenance'    => $this->provenance,
			'timestamps'    => $this->timestamps,
			'error'         => $this->error,
			'cancellation'  => $this->cancellation,
			'metadata'      => $this->metadata,
		);
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}

	/** @return array<string,mixed> */
	private static function map_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$map[ $key ] = $item;
			}
		}

		return $map;
	}

	/**
	 * @param mixed $value Raw entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_entries( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$entries = array();
		foreach ( $value as $entry ) {
			$entry = self::map_value( $entry );
			if ( array() !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param array<string,mixed> $value Raw timestamps.
	 * @return array<string,mixed>
	 */
	private static function timestamps_value( array $value ): array {
		$timestamps = array();
		foreach ( array( 'started_at', 'ended_at', 'updated_at' ) as $field ) {
			if ( isset( $value[ $field ] ) ) {
				$timestamp = trim( self::string_value( $value[ $field ] ) );
				if ( '' !== $timestamp ) {
					$timestamps[ $field ] = $timestamp;
				}
			}
		}

		return $timestamps;
	}
}
