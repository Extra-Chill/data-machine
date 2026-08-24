<?php
/**
 * Durable Calendar generation publication for import batches.
 *
 * @package DataMachineEvents\Tasks
 */

namespace DataMachineEvents\Tasks;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\EngineData;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;

defined( 'ABSPATH' ) || exit;

class CalendarGenerationPublisher extends SystemTask {

	public const TASK_TYPE       = 'data_machine_events_publish_calendar_generation';
	public const COALESCE_WINDOW = 5 * MINUTE_IN_SECONDS;
	public const ENGINE_KEY      = 'data_machine_events_calendar_generation_publication';
	public const WINDOW_OPTION   = 'data_machine_events_calendar_generation_window';

	private static bool $initialized = false;

	/** Register the publisher and replayable batch terminal callback. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'datamachine_tasks', array( __CLASS__, 'registerTask' ) );
		add_filter( 'datamachine_job_retry_policy', array( __CLASS__, 'filterRetryPolicy' ), 10, 6 );
		add_filter( 'datamachine_job_terminal_core_callbacks', array( __CLASS__, 'registerTerminalCallback' ), 20, 3 );
	}

	/** @param array<string,string> $tasks Registered task handlers. */
	public static function registerTask( array $tasks ): array {
		$tasks[ self::TASK_TYPE ] = self::class;
		return $tasks;
	}

	/** Add the idempotent publication callback to Data Machine terminal accounting. */
	public static function registerTerminalCallback( array $callbacks, int $job_id, string $status ): array {
		unset( $job_id, $status );
		$callbacks['data_machine_events_calendar_generation'] = array( __CLASS__, 'publishForTerminalBatch' );
		return $callbacks;
	}

	/**
	 * Persist the dirty marker before an import child crosses its write boundary.
	 *
	 * Failure deliberately leaves immediate invalidation enabled.
	 */
	public static function deferBatch( int $parent_job_id ): bool {
		if ( $parent_job_id <= 0 ) {
			return false;
		}

		$parent = EngineData::retrieve( $parent_job_id );
		if (
			empty( $parent['batch'] )
			|| PipelineBatchScheduler::BATCH_CONTEXT !== (string) ( $parent['batch_context'] ?? '' )
			|| BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE !== (string) ( $parent['batch_completion_strategy'] ?? '' )
		) {
			return false;
		}

		$site_id  = get_current_blog_id();
		$mutation = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $site_id ): array {
				$existing = is_array( $current[ self::ENGINE_KEY ] ?? null ) ? $current[ self::ENGINE_KEY ] : array();
				if ( (int) ( $existing['site_id'] ?? 0 ) === $site_id ) {
					return $current;
				}

				$current[ self::ENGINE_KEY ] = array(
					'site_id'  => $site_id,
					'dirty_at' => current_time( 'mysql', true ),
				);
				return $current;
			},
			'events_calendar_generation_dirty'
		);

		return ! empty( $mutation['success'] );
	}

	/** Schedule one publisher for the terminal batch's site and time window. */
	public static function publishForTerminalBatch( int $job_id, string $status ): bool {
		unset( $status );
		return self::scheduleForTerminalBatch( $job_id );
	}

	/**
	 * Schedule the bounded publisher through Data Machine's durable task runtime.
	 *
	 * @param callable|null $scheduler Test seam matching TaskScheduler::schedule().
	 * @param int|null      $now       Test seam for the current Unix timestamp.
	 */
	public static function scheduleForTerminalBatch( int $job_id, ?callable $scheduler = null, ?int $now = null ): bool {
		$engine  = EngineData::retrieve( $job_id );
		$marker  = is_array( $engine[ self::ENGINE_KEY ] ?? null ) ? $engine[ self::ENGINE_KEY ] : array();
		$site_id = absint( $marker['site_id'] ?? 0 );
		if ( $site_id <= 0 || ! get_site( $site_id ) ) {
			return true;
		}

		$now          = $now ?? time();
		$window       = intdiv( $now, self::COALESCE_WINDOW );
		$scheduled_at = ( $window + 1 ) * self::COALESCE_WINDOW;
		$generation   = hash( 'sha256', 'data-machine-events-calendar-generation:' . $site_id . ':' . $window );
		$scheduler    = $scheduler ?? array( TaskScheduler::class, 'schedule' );
		$switched     = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			$latest_window = absint( get_option( self::WINDOW_OPTION, 0 ) );
			if ( $latest_window < $window && ! update_option( self::WINDOW_OPTION, $window, false ) && absint( get_option( self::WINDOW_OPTION, 0 ) ) !== $window ) {
				return false;
			}

			$job_id = $scheduler(
				self::TASK_TYPE,
				array(
					'site_id'          => $site_id,
					'generation'       => $generation,
					'scheduled_at'     => $scheduled_at,
					'coalesced_window' => $window,
				),
				array(),
				0,
				self::operationKey( $site_id, $window )
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return false !== $job_id;
	}

	/** Build the durable single-winner key for one site/window. */
	public static function operationKey( int $site_id, int $window ): string {
		return 'data-machine-events:calendar-generation:' . $site_id . ':' . $window;
	}

	/** Bound retries for idempotent publication and warmer scheduling. */
	public static function filterRetryPolicy( array $policy, int $job_id, string $reason, array $context_data, array $engine_data, array $job ): array {
		unset( $job_id, $reason, $job );
		if ( self::TASK_TYPE !== (string) ( $context_data['task_type'] ?? $engine_data['task_type'] ?? '' ) ) {
			return $policy;
		}

		$policy['retryable']    = true;
		$policy['max_attempts'] = 3;
		$policy['base_delay']   = 30;
		$policy['max_delay']    = 300;
		$policy['backoff']      = 'exponential';
		return $policy;
	}

	public function requiresAgentContext(): bool {
		return false;
	}

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/** @return array<string,mixed> */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Publish Event Calendar Generation',
			'description'     => 'Publish one coalesced Calendar cache generation after an import batch.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => false,
			'mutates'         => true,
		);
	}

	/** Publish the exact generation and durably enqueue its existing warmer. */
	public function executeTask( int $jobId, array $params ): void {
		$site_id    = absint( $params['site_id'] ?? 0 );
		$generation = sanitize_text_field( (string) ( $params['generation'] ?? '' ) );
		if ( $site_id <= 0 || '' === $generation || ! get_site( $site_id ) ) {
			$this->failJob( $jobId, 'Invalid Calendar generation publication scope.' );
			return;
		}

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			$window        = absint( $params['coalesced_window'] ?? 0 );
			$latest_window = absint( get_option( self::WINDOW_OPTION, 0 ) );
			$result        = array(
				'site_id'    => $site_id,
				'generation' => $generation,
			);
			if ( $window > 0 && $latest_window > $window ) {
				$result['skipped'] = 'superseded_window';
			} else {
				if ( ! CalendarCache::publish_generation( $generation ) ) {
					$this->failJob( $jobId, 'Calendar generation could not be published.' );
					return;
				}
				$warmer_job_id = TaskScheduler::schedule(
					TaxonomyInventoryWarmer::TASK_TYPE,
					array(
						'site_id'    => $site_id,
						'generation' => $generation,
					),
					array(),
					0,
					TaxonomyInventoryWarmer::operationKey( $site_id, $generation )
				);
				if ( false === $warmer_job_id ) {
					$this->failJob( $jobId, 'Calendar generation published but its taxonomy warmer could not be scheduled.' );
					return;
				}
				$result['warmer_job_id'] = $warmer_job_id;
			}

			$this->completeJob( $jobId, $result );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
