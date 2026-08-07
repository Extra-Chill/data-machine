<?php
/**
 * Immutable execution outcome of a single workflow run.
 *
 * Captures the per-step record (status, output, error), the overall
 * status, the final output map, and neutral evidence references. Run
 * recorders persist this; callers inspect it to act on the result.
 *
 * Statuses:
 *   `pending`   — recorded but not yet executed (used by recorders that
 *                 commit a row before the runner picks the work up).
 *   `running`   — currently executing.
 *   `succeeded` — every step that ran returned without error.
 *   `failed`    — at least one step returned a WP_Error or threw.
 *   `skipped`   — the runner declined to execute (e.g. unknown step
 *                 type that no consumer registered a handler for).
 *   `suspended` — the run dispatched one or more parallel branches for
 *                 out-of-band execution and parked mid-flight, waiting on
 *                 a reconcile to complete + resume it. The suspension frame
 *                 rides in `metadata._suspension` (see {@see self::get_suspension()}).
 *
 * Step records have the same statuses minus `pending` (steps either ran
 * or didn't), plus a `started_at` / `ended_at` pair for timing.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use AgentsAPI\AI\WP_Agent_Run_Result_Envelope;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Run_Result {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_SUCCEEDED = 'succeeded';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_SKIPPED   = 'skipped';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * @since 0.103.0
	 *
	 * @param string $run_id      Caller-stable id (UUID, post id, custom-table row id).
	 * @param string $workflow_id Spec id this run belongs to.
	 * @param string $status      One of the STATUS_* constants.
	 * @param array<mixed>  $inputs      Resolved inputs the run was started with.
	 * @param array<mixed>  $output      Final aggregated output map (or partial if failed).
	 * @param array<mixed>  $steps       List of step records, each shaped as
	 *                            `[ id, type, status, output, error?, started_at, ended_at ]`.
	 * @param array<mixed>  $error       Top-level error info (`code`, `message`, `data`) when status === failed.
	 * @param int    $started_at  Unix timestamp.
	 * @param int    $ended_at    Unix timestamp; 0 while running.
	 * @param array<mixed>  $metadata      Free-form metadata for recorders / tracers (Langfuse trace ids, etc.).
	 * @param array<mixed>  $evidence_refs   Neutral JSON-serializable artifact/log references owned by the host.
	 * @param array<mixed>  $replay_metadata Deterministic metadata needed to replay or audit this run.
	 * @param array<mixed>  $artifacts       Normalized artifact descriptors produced by this run.
	 * @param array<mixed>  $logs            Normalized log entries produced by this run.
	 */
	public function __construct(
		private string $run_id,
		private string $workflow_id,
		private string $status,
		private array $inputs,
		private array $output,
		private array $steps,
		private array $error,
		private int $started_at,
		private int $ended_at,
		private array $metadata,
		private array $evidence_refs = array(),
		private array $replay_metadata = array(),
		private array $artifacts = array(),
		private array $logs = array()
	) {
		$this->artifacts = self::normalized_list( $this->artifacts );
		$this->logs      = self::normalized_list( $this->logs );
	}

	/**
	 * @param array<mixed> $inputs
	 */
	public static function pending( string $run_id, string $workflow_id, array $inputs, int $started_at ): self {
		return new self( $run_id, $workflow_id, self::STATUS_PENDING, $inputs, array(), array(), array(), $started_at, 0, array(), array(), array() );
	}

	/**
	 * Rebuild a run result from its serialized array shape.
	 *
	 * @since 0.108.0
	 *
	 * @param array<string, mixed> $value Serialized run result.
	 * @return self
	 */
	public static function from_array( array $value ): self {
		return new self(
			self::string_value( $value['run_id'] ?? '' ),
			self::string_value( $value['workflow_id'] ?? '' ),
			self::string_value( $value['status'] ?? self::STATUS_PENDING ),
			self::array_value( $value['inputs'] ?? array() ),
			self::array_value( $value['output'] ?? array() ),
			self::array_value( $value['steps'] ?? array() ),
			self::array_value( $value['error'] ?? array() ),
			self::int_value( $value['started_at'] ?? 0 ),
			self::int_value( $value['ended_at'] ?? 0 ),
			self::array_value( $value['metadata'] ?? array() ),
			self::array_value( $value['evidence_refs'] ?? array() ),
			self::array_value( $value['replay'] ?? array() ),
			self::array_value( $value['artifacts'] ?? array() ),
			self::array_value( $value['logs'] ?? array() )
		);
	}

	public function get_run_id(): string {
		return $this->run_id;
	}

	public function get_workflow_id(): string {
		return $this->workflow_id;
	}

	public function get_status(): string {
		return $this->status;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_inputs(): array {
		return $this->inputs;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_output(): array {
		return $this->output;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_steps(): array {
		return $this->steps;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_error(): array {
		return $this->error;
	}

	public function get_started_at(): int {
		return $this->started_at;
	}

	public function get_ended_at(): int {
		return $this->ended_at;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_evidence_refs(): array {
		return $this->evidence_refs;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_replay_metadata(): array {
		return $this->replay_metadata;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_artifacts(): array {
		return $this->artifacts;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_logs(): array {
		return $this->logs;
	}

	public function is_succeeded(): bool {
		return self::STATUS_SUCCEEDED === $this->status;
	}

	public function is_failed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Whether this run is suspended mid-flight waiting on a branch reconcile.
	 *
	 * @since 0.5.0
	 */
	public function is_suspended(): bool {
		return self::STATUS_SUSPENDED === $this->status;
	}

	/**
	 * Return the suspension frame carried in `metadata._suspension`, or an
	 * empty array when the run is not suspended. No new constructor argument
	 * is needed — the frame rides the existing `metadata` field, so it
	 * round-trips through `to_array()`/`from_array()`/`with()` for free.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_suspension(): array {
		$suspension = $this->metadata['_suspension'] ?? array();
		if ( ! is_array( $suspension ) ) {
			return array();
		}

		/** @var array<string,mixed> $result */
		$result = array();
		foreach ( $suspension as $key => $value ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	public function to_run_result_envelope(): WP_Agent_Run_Result_Envelope {
		return WP_Agent_Run_Result_Envelope::from_array(
			array(
				'run_id'        => $this->run_id,
				'status'        => $this->status,
				'outputs'       => $this->output,
				'artifact_refs' => $this->artifacts,
				'evidence_refs' => $this->evidence_refs,
				'logs'          => $this->logs,
				'replay'        => $this->replay_metadata,
				'provenance'    => array( 'workflow_id' => $this->workflow_id ),
				'timestamps'    => array(
					'started_at' => $this->started_at,
					'ended_at'   => $this->ended_at,
				),
				'error'         => $this->error,
				'metadata'      => $this->metadata + array(
					'steps'  => $this->steps,
					'inputs' => $this->inputs,
				),
			)
		);
	}

	public function to_canonical_envelope(): WP_Agent_Run_Result_Envelope {
		return $this->to_run_result_envelope();
	}

	/**
	 * Return a new result with updated fields. Run results are immutable; the
	 * runner builds a fresh instance per state change rather than mutating.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $patch Field => new value. Unknown keys are ignored.
	 * @return self
	 */
	public function with( array $patch ): self {
		return new self(
			self::string_patch_value( $patch, 'run_id', $this->run_id ),
			self::string_patch_value( $patch, 'workflow_id', $this->workflow_id ),
			self::string_patch_value( $patch, 'status', $this->status ),
			self::array_patch_value( $patch, 'inputs', $this->inputs ),
			self::array_patch_value( $patch, 'output', $this->output ),
			self::array_patch_value( $patch, 'steps', $this->steps ),
			self::array_patch_value( $patch, 'error', $this->error ),
			self::int_patch_value( $patch, 'started_at', $this->started_at ),
			self::int_patch_value( $patch, 'ended_at', $this->ended_at ),
			self::array_patch_value( $patch, 'metadata', $this->metadata ),
			self::array_patch_value( $patch, 'evidence_refs', $this->evidence_refs ),
			self::array_patch_value( $patch, 'replay', $this->replay_metadata ),
			self::array_patch_value( $patch, 'artifacts', $this->artifacts ),
			self::array_patch_value( $patch, 'logs', $this->logs ),
		);
	}

	/**
	 * @param array<mixed> $patch
	 */
	private static function string_patch_value( array $patch, string $key, string $fallback ): string {
		return isset( $patch[ $key ] ) && is_scalar( $patch[ $key ] ) ? (string) $patch[ $key ] : $fallback;
	}

	/**
	 * @param array<mixed> $patch
	 */
	private static function int_patch_value( array $patch, string $key, int $fallback ): int {
		return isset( $patch[ $key ] ) && is_numeric( $patch[ $key ] ) ? (int) $patch[ $key ] : $fallback;
	}

	/**
	 * @param array<mixed> $patch
	 * @param array<mixed> $fallback
	 * @return array<mixed>
	 */
	private static function array_patch_value( array $patch, string $key, array $fallback ): array {
		return isset( $patch[ $key ] ) && is_array( $patch[ $key ] ) ? $patch[ $key ] : $fallback;
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @return array<mixed>
	 */
	private static function array_value( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<mixed> $value
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalized_list( array $value ): array {
		$normalized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$item = array();
			foreach ( $entry as $key => $entry_value ) {
				if ( is_string( $key ) ) {
					$item[ $key ] = $entry_value;
				}
			}

			if ( array() !== $item ) {
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	/**
	 * Render to a plain array. Useful for recorders that want to serialise
	 * the run record verbatim (CPT meta, JSON column, REST response).
	 *
	 * @since 0.103.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'        => $this->run_id,
			'workflow_id'   => $this->workflow_id,
			'status'        => $this->status,
			'inputs'        => $this->inputs,
			'output'        => $this->output,
			'steps'         => $this->steps,
			'error'         => $this->error,
			'started_at'    => $this->started_at,
			'ended_at'      => $this->ended_at,
			'metadata'      => $this->metadata,
			'evidence_refs' => $this->evidence_refs,
			'replay'        => $this->replay_metadata,
			'artifacts'     => $this->artifacts,
			'logs'          => $this->logs,
		);
	}
}
