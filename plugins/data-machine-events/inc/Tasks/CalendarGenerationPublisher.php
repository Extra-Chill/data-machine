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
use DataMachineEvents\Blocks\Calendar\Cache\CalendarGenerationFence;

defined( 'ABSPATH' ) || exit;

class CalendarGenerationPublisher extends SystemTask {

	public const TASK_TYPE       = 'data_machine_events_publish_calendar_generation';
	public const COALESCE_WINDOW = 5 * MINUTE_IN_SECONDS;
	public const ENGINE_KEY      = 'data_machine_events_calendar_generation_publication';

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
	public static function deferBatch( int $parent_job_id, ?callable $token_generator = null ): bool {
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
		$existing = is_array( $parent[ self::ENGINE_KEY ] ?? null ) ? $parent[ self::ENGINE_KEY ] : array();
		if ( ! empty( $existing ) ) {
			return (int) ( $existing['site_id'] ?? 0 ) === $site_id
				&& '' !== (string) ( $existing['obligation_token'] ?? '' );
		}
		if ( ! CalendarGenerationFence::isSupported() ) {
			return false;
		}

		$token_generator = $token_generator ?? 'wp_generate_uuid4';
		$token           = $token_generator();
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$mutation = EngineData::mutate(
			$parent_job_id,
			static function ( array $current ) use ( $site_id, $token ): array {
				$existing = is_array( $current[ self::ENGINE_KEY ] ?? null ) ? $current[ self::ENGINE_KEY ] : array();
				if ( ! empty( $existing ) ) {
					return $current;
				}

				$current[ self::ENGINE_KEY ] = array(
					'site_id'          => $site_id,
					'obligation_token' => $token,
					'dirty_at'         => current_time( 'mysql', true ),
				);
				return $current;
			},
			'events_calendar_generation_dirty'
		);

		$stored = is_array( $mutation['snapshot'][ self::ENGINE_KEY ] ?? null ) ? $mutation['snapshot'][ self::ENGINE_KEY ] : array();
		return ! empty( $mutation['success'] )
			&& (int) ( $stored['site_id'] ?? 0 ) === $site_id
			&& '' !== (string) ( $stored['obligation_token'] ?? '' );
	}

	/** Schedule one publisher for the terminal batch's site and time window. */
	public static function publishForTerminalBatch( int $job_id, string $status ): bool {
		unset( $status );
		return self::scheduleForTerminalBatch( $job_id );
	}

	/**
	 * Schedule the bounded publisher through Data Machine's durable task runtime.
	 *
	 * @param callable|null $scheduler         Test seam matching TaskScheduler::schedule().
	 * @param int|null      $now               Test seam for the current Unix timestamp.
	 * @param callable|null $revision_reserver Test seam matching CalendarCache::reserve_revision().
	 */
	public static function scheduleForTerminalBatch( int $job_id, ?callable $scheduler = null, ?int $now = null, ?callable $revision_reserver = null ): bool {
		$engine  = EngineData::retrieve( $job_id );
		$marker  = is_array( $engine[ self::ENGINE_KEY ] ?? null ) ? $engine[ self::ENGINE_KEY ] : array();
		$site_id = absint( $marker['site_id'] ?? 0 );
		if ( $site_id <= 0 || '' === (string) ( $marker['obligation_token'] ?? '' ) || ! get_site( $site_id ) ) {
			return true;
		}

		$now               = $now ?? time();
		$candidate_window  = intdiv( $now, self::COALESCE_WINDOW );
		$scheduler         = $scheduler ?? array( TaskScheduler::class, 'schedule' );
		$revision_reserver = $revision_reserver ?? array( CalendarCache::class, 'reserve_revision' );
		$switched          = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			$revision = absint( $marker['publication_revision'] ?? 0 );
			if ( $revision <= 0 ) {
				$reserved = $revision_reserver();
				if ( ! is_int( $reserved ) || $reserved <= 0 ) {
					return false;
				}
				$mutation = EngineData::mutate(
					$job_id,
					static function ( array $current ) use ( $site_id, $reserved, $candidate_window ): array {
						$current_marker = is_array( $current[ self::ENGINE_KEY ] ?? null ) ? $current[ self::ENGINE_KEY ] : array();
						if ( (int) ( $current_marker['site_id'] ?? 0 ) !== $site_id ) {
							return $current;
						}
						if ( (int) ( $current_marker['publication_revision'] ?? 0 ) <= 0 ) {
							$current_marker['publication_revision'] = $reserved;
							$current_marker['publication_window']   = $candidate_window;
							$current[ self::ENGINE_KEY ]            = $current_marker;
						}
						return $current;
					},
					'events_calendar_generation_publication_revision'
				);
				$marker   = is_array( $mutation['snapshot'][ self::ENGINE_KEY ] ?? null ) ? $mutation['snapshot'][ self::ENGINE_KEY ] : array();
				$revision = absint( $marker['publication_revision'] ?? 0 );
				if ( empty( $mutation['success'] ) || $revision <= 0 || (int) ( $marker['site_id'] ?? 0 ) !== $site_id ) {
					return false;
				}
			}
			$window = absint( $marker['publication_window'] ?? 0 );
			if ( $window <= 0 ) {
				return false;
			}
			$scheduled_at = ( $window + 1 ) * self::COALESCE_WINDOW;
			$generation   = hash( 'sha256', 'data-machine-events-calendar-generation:' . $site_id . ':' . $revision );

			$job_id = $scheduler(
				self::TASK_TYPE,
				array(
					'site_id'          => $site_id,
					'generation'       => $generation,
					'revision'         => $revision,
					'scheduled_at'     => $scheduled_at,
					'coalesced_window' => $window,
				),
				array(),
				0,
				self::operationKey( $site_id, $window, $revision )
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return false !== $job_id;
	}

	/** Build the replay key for one exact site/window/revision obligation. */
	public static function operationKey( int $site_id, int $window, int $revision ): string {
		return 'data-machine-events:calendar-generation:' . $site_id . ':' . $window . ':' . $revision;
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
		$result = $this->runPublication( $jobId, $params );
		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$this->completeJob( $jobId, $result );
	}

	/** Execute the fenced transition with an injectable warmer scheduler for failure tests. */
	public function runPublication( int $jobId, array $params, ?callable $warmer_scheduler = null ): array|\WP_Error {
		$site_id    = absint( $params['site_id'] ?? 0 );
		$revision   = absint( $params['revision'] ?? 0 );
		$generation = sanitize_text_field( (string) ( $params['generation'] ?? '' ) );
		if ( $site_id <= 0 || $revision <= 0 || '' === $generation || ! get_site( $site_id ) ) {
			return new \WP_Error( 'invalid_calendar_publication_scope', 'Invalid Calendar generation publication scope.' );
		}

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			$transition = CalendarGenerationFence::publish( $revision, $generation, $jobId );
			if ( false === $transition ) {
				return new \WP_Error( 'calendar_generation_publish_failed', 'Calendar generation could not be published.' );
			}

			$result = array(
				'site_id'    => $site_id,
				'generation' => $generation,
				'revision'   => $revision,
			);
			if ( 'superseded' === $transition['status'] || 'duplicate_publisher' === $transition['status'] ) {
				$result['skipped'] = $transition['status'];
				return $result;
			}
			if ( ! empty( $transition['schedule_warmer'] ) ) {
				$warmer_scheduler = $warmer_scheduler ?? apply_filters(
					'data_machine_events_calendar_warmer_scheduler',
					array( TaskScheduler::class, 'schedule' ),
					$jobId,
					$params
				);
				if ( ! is_callable( $warmer_scheduler ) ) {
					return new \WP_Error( 'calendar_warmer_scheduler_invalid', 'Calendar taxonomy warmer scheduler is not callable.' );
				}
				$warmer_job_id = $warmer_scheduler(
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
					return new \WP_Error( 'calendar_warmer_schedule_failed', 'Calendar generation published but its taxonomy warmer could not be scheduled.' );
				}
				if ( ! CalendarGenerationFence::markWarmerScheduled( $revision, $generation, $jobId ) ) {
					return new \WP_Error( 'calendar_warmer_receipt_failed', 'Calendar taxonomy warmer was scheduled but its durable receipt could not be recorded.' );
				}
				$result['warmer_job_id'] = $warmer_job_id;
			} else {
				$result['skipped'] = 'warmer_already_scheduled';
			}

			return $result;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
