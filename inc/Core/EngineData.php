<?php
/**
 * Engine Data Object
 *
 * Encapsulates the "Engine Data" array which persists across the pipeline execution.
 * Provides platform-agnostic data access methods for source URLs, images, and metadata.
 *
 * @package DataMachine\Core
 * @since 0.2.1
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EngineData {

	/**
	 * The raw engine data array.
	 *
	 * @var array
	 */
	private array $data;

	/**
	 * The Job ID associated with this data.
	 *
	 * @var int|string|null
	 */
	private $job_id;

	/**
	 * Constructor.
	 *
	 * @param array           $data Raw engine data array.
	 * @param int|string|null $job_id Optional Job ID for context/logging.
	 */
	public function __construct( array $data, $job_id = null ) {
		$this->data   = $data;
		$this->job_id = $job_id;
	}

	/**
	 * Create an EngineData instance for a given job by retrieving its persisted snapshot.
	 *
	 * @param int $job_id Job ID.
	 * @return self
	 */
	public static function forJob( int $job_id ): self {
		return new self( self::retrieve( $job_id ), $job_id );
	}

	/**
	 * Retrieve engine data snapshot for a job.
	 *
	 * Checks object cache first, falls back to database.
	 *
	 * @param int $job_id Job ID.
	 * @return array Engine data array or empty array on failure.
	 */
	public static function retrieve( int $job_id ): array {
		if ( $job_id <= 0 ) {
			return array();
		}

		$cached = wp_cache_get( $job_id, 'datamachine_engine_data' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$db_jobs     = new Jobs();
		$engine_data = $db_jobs->retrieve_engine_data( $job_id );

		wp_cache_set( $job_id, $engine_data, 'datamachine_engine_data' );

		return $engine_data;
	}

	/**
	 * Persist a complete engine data snapshot for a job.
	 *
	 * @param int   $job_id  Job ID.
	 * @param array $snapshot Engine data snapshot to store.
	 * @return bool True on success, false on failure.
	 */
	public static function persist( int $job_id, array $snapshot ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$db_jobs = new Jobs();
		$success = $db_jobs->store_engine_data( $job_id, $snapshot );

		if ( $success ) {
			wp_cache_set( $job_id, $snapshot, 'datamachine_engine_data' );
		}

		return $success;
	}

	/**
	 * Merge new data into the stored engine snapshot.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Data to merge into existing snapshot.
	 * @return bool True on success, false on failure.
	 */
	public static function merge( int $job_id, array $data ): bool {
		if ( $job_id <= 0 ) {
			return false;
		}

		$result = self::mutate(
			$job_id,
			static fn( array $current ): array => array_replace_recursive( $current, $data ),
			'merge'
		);

		return ! empty( $result['success'] );
	}

	/**
	 * Mutate engine data with compare-and-swap retries to preserve concurrent writes.
	 *
	 * @param int      $job_id       Job ID.
	 * @param callable $callback     Receives the latest snapshot and returns the next snapshot, or null to abort.
	 * @param string   $event_type   Mutation event type for diagnostics.
	 * @param int      $max_attempts Maximum CAS attempts.
	 * @return array{success:bool,conflict:bool,attempts:int,snapshot:array,error:string|null}
	 */
	public static function mutate( int $job_id, callable $callback, string $event_type = 'mutation', int $max_attempts = 3 ): array {
		if ( $job_id <= 0 ) {
			return array(
				'success'  => false,
				'conflict' => false,
				'attempts' => 0,
				'snapshot' => array(),
				'error'    => 'invalid_job_id',
			);
		}

		$db_jobs      = new Jobs();
		$max_attempts = max( 1, $max_attempts );
		$event_type   = sanitize_key( $event_type );

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$current = $db_jobs->retrieve_engine_data( $job_id );
			$current = is_array( $current ) ? $current : array();
			$next    = $callback( $current );

			if ( null === $next ) {
				return array(
					'success'  => false,
					'conflict' => false,
					'attempts' => $attempt,
					'snapshot' => $current,
					'error'    => 'mutation_aborted',
				);
			}

			if ( ! is_array( $next ) ) {
				return array(
					'success'  => false,
					'conflict' => false,
					'attempts' => $attempt,
					'snapshot' => $current,
					'error'    => 'invalid_mutation_result',
				);
			}

			if ( $next === $current ) {
				return array(
					'success'  => true,
					'conflict' => false,
					'attempts' => $attempt,
					'snapshot' => $current,
					'error'    => null,
				);
			}

			$result = $db_jobs->compare_and_swap_engine_data( $job_id, $current, $next );
			if ( ! empty( $result['updated'] ) ) {
				wp_cache_set( $job_id, $next, 'datamachine_engine_data' );
				return array(
					'success'  => true,
					'conflict' => false,
					'attempts' => $attempt,
					'snapshot' => $next,
					'error'    => null,
				);
			}

			if ( empty( $result['conflict'] ) ) {
				return array(
					'success'  => false,
					'conflict' => false,
					'attempts' => $attempt,
					'snapshot' => $current,
					'error'    => (string) ( $result['error'] ?? 'persist_failed' ),
				);
			}

			do_action(
				'datamachine_log',
				'warning',
				'EngineData mutation conflict, retrying latest snapshot',
				array(
					'job_id'     => $job_id,
					'event_type' => $event_type,
					'attempt'    => $attempt,
				)
			);
		}

		return array(
			'success'  => false,
			'conflict' => true,
			'attempts' => $max_attempts,
			'snapshot' => self::retrieve( $job_id ),
			'error'    => 'conflict_exhausted',
		);
	}

	/**
	 * Append a replayable state event and persist its patch as the current snapshot projection.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $type     Generic event type.
	 * @param array  $patch    Engine data patch to project onto the snapshot.
	 * @param array  $metadata Optional event metadata.
	 * @return array|null Appended trace entry on success, null on failure.
	 */
	public static function appendStateEvent( int $job_id, string $type, array $patch, array $metadata = array() ): ?array {
		if ( $job_id <= 0 || '' === trim( $type ) ) {
			return null;
		}

		$event  = null;
		$result = self::mutate(
			$job_id,
			static function ( array $current ) use ( $type, $patch, $metadata, &$event ): ?array {
				$projection = EngineStateLedger::append( $current, $type, $patch, $metadata );
				if ( null === $projection ) {
					return null;
				}

				$event = $projection['event'];

				return $projection['snapshot'];
			},
			$type
		);

		return ! empty( $result['success'] ) ? $event : null;
	}

	/**
	 * Append a replayable state event once per deterministic operation id.
	 *
	 * Duplicate operation ids return the existing ledger event without reapplying the patch.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $op_id    Deterministic operation id.
	 * @param string $type     Generic event type.
	 * @param array  $patch    Engine data patch to project onto the snapshot.
	 * @param array  $metadata Optional event metadata.
	 * @return array|null Appended or existing trace entry on success, null on failure.
	 */
	public static function appendStateEventOnce( int $job_id, string $op_id, string $type, array $patch, array $metadata = array() ): ?array {
		$op_id = trim( $op_id );
		if ( $job_id <= 0 || '' === $op_id || '' === trim( $type ) ) {
			return null;
		}

		$metadata['op_id'] = $op_id;
		$event             = null;
		$result            = self::mutate(
			$job_id,
			static function ( array $current ) use ( $op_id, $type, $patch, $metadata, &$event ): ?array {
				$existing = EngineStateLedger::findByOpId( $current, $op_id );
				if ( null !== $existing ) {
					$event = $existing;
					return $current;
				}

				$projection = EngineStateLedger::append( $current, $type, $patch, $metadata );
				if ( null === $projection ) {
					return null;
				}

				$event = $projection['event'];

				return $projection['snapshot'];
			},
			$type
		);

		return ! empty( $result['success'] ) ? $event : null;
	}

	/**
	 * Return replayable state ledger events from a snapshot.
	 *
	 * @param array $snapshot Engine data snapshot.
	 * @return array Ledger events.
	 */
	public static function stateLedger( array $snapshot ): array {
		return EngineStateLedger::fromSnapshot( $snapshot );
	}

	/**
	 * Replay state ledger events onto a base snapshot.
	 *
	 * @param array $events        Ledger events.
	 * @param array $base_snapshot Optional base snapshot.
	 * @return array Replayed engine data snapshot without ledger metadata.
	 */
	public static function replayStateLedger( array $events, array $base_snapshot = array() ): array {
		return EngineStateLedger::replay( $events, $base_snapshot );
	}

	/**
	 * Set a value in the engine data and persist it.
	 *
	 * @param string $key   Data key.
	 * @param mixed  $value Value to set.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;

		if ( $this->job_id ) {
			self::merge( (int) $this->job_id, array( $key => $value ) );
		}
	}

	/**
	 * Get a value from the engine data.
	 *
	 * @param string $key Data key.
	 * @param mixed  $default Default value if key not found.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		if ( array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}

		$metadata = $this->data['metadata'] ?? array();
		if ( is_array( $metadata ) && array_key_exists( $key, $metadata ) ) {
			return $metadata[ $key ];
		}

		return $default_value;
	}

	/**
	 * Get the Source URL.
	 *
	 * @return string|null Source URL or null.
	 */
	public function getSourceUrl(): ?string {
		$url = $this->get( 'source_url' );
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : null;
	}

	/**
	 * Get the original source publication date in GMT.
	 *
	 * @return string|null MySQL GMT datetime or null.
	 */
	public function getOriginalDateGmt(): ?string {
		return SourceDate::normalizeGmt( $this->get( 'original_date_gmt' ) );
	}

	/**
	 * Get the Image File Path (from Files Repository).
	 *
	 * @return string|null Absolute file path or null.
	 */
	public function getImagePath(): ?string {
		return $this->get( 'image_file_path' );
	}

	/**
	 * Get the Video File Path (from Files Repository).
	 *
	 * @since 0.42.0
	 * @return string|null Absolute file path or null.
	 */
	public function getVideoPath(): ?string {
		return $this->get( 'video_file_path' );
	}

	/**
	 * Return the raw snapshot array.
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * Get stored job context (flow_id, pipeline_id, etc.).
	 */
	public function getJobContext(): array {
		return is_array( $this->data['job'] ?? null ) ? $this->data['job'] : array();
	}

	/**
	 * Get agent ID from job context.
	 *
	 * Returns the agent_id stored in the engine snapshot's job context,
	 * or null if no agent is associated with this execution.
	 *
	 * @since 0.41.0
	 * @return int|null Agent ID or null.
	 */
	public function getAgentId(): ?int {
		$job_context = $this->getJobContext();
		$agent_id    = $job_context['agent_id'] ?? null;

		return null !== $agent_id ? (int) $agent_id : null;
	}

	/**
	 * Get full flow configuration snapshot.
	 */
	public function getFlowConfig(): array {
		return is_array( $this->data['flow_config'] ?? null ) ? $this->data['flow_config'] : array();
	}

	/**
	 * Get configuration for a specific flow step.
	 */
	public function getFlowStepConfig( string $flow_step_id ): array {
		$flow_config = $this->getFlowConfig();
		return $flow_config[ $flow_step_id ] ?? array();
	}

	/**
	 * Get stored pipeline configuration snapshot.
	 */
	public function getPipelineConfig(): array {
		return is_array( $this->data['pipeline_config'] ?? null ) ? $this->data['pipeline_config'] : array();
	}

	/**
	 * Get configuration for a specific pipeline step.
	 *
	 * Pipeline step config contains AI provider settings (provider, model, system_prompt)
	 * while flow step config contains flow-level overrides (handler_slug, handler_config, user_message).
	 *
	 * @param string $pipeline_step_id Pipeline step identifier.
	 * @return array Step configuration array or empty array.
	 */
	public function getPipelineStepConfig( string $pipeline_step_id ): array {
		$pipeline_config = $this->getPipelineConfig();
		return $pipeline_config[ $pipeline_step_id ] ?? array();
	}
}
