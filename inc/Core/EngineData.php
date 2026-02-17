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

		$current = self::retrieve( $job_id );
		$merged  = array_replace_recursive( $current, $data );

		return self::persist( $job_id, $merged );
	}

	/**
	 * Get a value from the engine data.
	 *
	 * @param string $key Data key.
	 * @param mixed  $default Default value if key not found.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}

		$metadata = $this->data['metadata'] ?? array();
		if ( is_array( $metadata ) && array_key_exists( $key, $metadata ) ) {
			return $metadata[ $key ];
		}

		return $default;
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
	 * Get the Image File Path (from Files Repository).
	 *
	 * @return string|null Absolute file path or null.
	 */
	public function getImagePath(): ?string {
		return $this->get( 'image_file_path' );
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
