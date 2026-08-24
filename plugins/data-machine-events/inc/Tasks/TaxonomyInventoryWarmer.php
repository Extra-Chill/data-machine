<?php
/**
 * Taxonomy Inventory Cache Warmer
 *
 * @package DataMachineEvents\Tasks
 */

namespace DataMachineEvents\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskScheduler;
use DataMachineEvents\Abilities\UpcomingCountAbilities;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;

defined( 'ABSPATH' ) || exit;

class TaxonomyInventoryWarmer extends SystemTask {

	public const TASK_TYPE = 'data_machine_events_warm_taxonomy_inventories';

	private static bool $initialized = false;

	/** @var array<int,string> Latest invalidated generation for each site in this request. */
	private static array $pending_generations = array();

	/** Register task, retry policy, and generation listener. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'datamachine_tasks', array( __CLASS__, 'registerTask' ) );
		add_filter( 'datamachine_job_retry_policy', array( __CLASS__, 'filterRetryPolicy' ), 10, 6 );
		add_action( 'update_option_' . CalendarCache::GENERATION_OPTION, array( __CLASS__, 'queueGeneration' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'flushPending' ), 10, 0 );
	}

	/** @param array<string,string> $tasks Registered task handlers. */
	public static function registerTask( array $tasks ): array {
		$tasks[ self::TASK_TYPE ] = self::class;
		return $tasks;
	}

	/**
	 * Keep only the final generation for each site during a mutation-heavy request.
	 */
	public static function queueGeneration( $old_generation, $new_generation ): void {
		$new_generation = (string) $new_generation;
		if ( '' !== $new_generation && $new_generation !== (string) $old_generation ) {
			self::$pending_generations[ get_current_blog_id() ] = $new_generation;
		}
	}

	/**
	 * Schedule one durable, idempotent task for each site's latest generation.
	 *
	 * @param callable|null $scheduler Test seam matching TaskScheduler::schedule().
	 */
	public static function flushPending( ?callable $scheduler = null ): void {
		$pending                   = self::$pending_generations;
		self::$pending_generations = array();
		$scheduler                 = $scheduler ?? array( TaskScheduler::class, 'schedule' );

		foreach ( $pending as $site_id => $generation ) {
			$switched = get_current_blog_id() !== $site_id;
			if ( $switched ) {
				switch_to_blog( $site_id );
			}

			try {
				$job_id = $scheduler(
					self::TASK_TYPE,
					array(
						'site_id'    => $site_id,
						'generation' => $generation,
					),
					array(),
					0,
					self::operationKey( $site_id, $generation )
				);
				if ( false === $job_id ) {
					do_action(
						'datamachine_log',
						'error',
						'Taxonomy inventory cache warmer could not be scheduled',
						array(
							'site_id'    => $site_id,
							'generation' => $generation,
						)
					);
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/** Build the durable single-winner key for one site generation. */
	public static function operationKey( int $site_id, string $generation ): string {
		return 'data-machine-events:taxonomy-inventories:' . $site_id . ':' . $generation;
	}

	/** Bound retries for this idempotent maintenance task. */
	public static function filterRetryPolicy( array $policy, int $job_id, string $reason, array $context_data, array $engine_data, array $job ): array {
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

	/** This is bounded internal maintenance and needs no agent identity. */
	public function requiresAgentContext(): bool {
		return false;
	}

	public function getTaskType(): string {
		return self::TASK_TYPE;
	}

	/** @return array<string,mixed> */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Warm Event Taxonomy Inventories',
			'description'     => 'Warm the finite unfiltered upcoming-event taxonomy inventories for a cache generation.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => false,
			'mutates'         => false,
		);
	}

	/** Warm every finite inventory shape without rotating the generation. */
	public function executeTask( int $jobId, array $params ): void {
		$site_id    = absint( $params['site_id'] ?? 0 );
		$generation = sanitize_text_field( (string) ( $params['generation'] ?? '' ) );
		$result     = $this->warmGeneration( $site_id, $generation );
		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, 'Taxonomy inventory warming temporarily failed: ' . $result->get_error_message() );
			return;
		}

		$this->completeJob( $jobId, $result );
	}

	/**
	 * Warm one site's generation and return task completion data.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function warmGeneration( int $site_id, string $generation ): array|\WP_Error {
		if ( $site_id < 1 || '' === $generation || ! get_site( $site_id ) ) {
			return new \WP_Error( 'invalid_warm_scope', 'Invalid site or generation parameters.' );
		}

		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			if ( CalendarCache::get_generation() !== $generation ) {
				return array( 'skipped' => 'superseded_generation' );
			}

			$abilities = new UpcomingCountAbilities();
			$warmed    = array();
			foreach ( UpcomingCountAbilities::inventoryCacheShapes() as $shape ) {
				$result = $abilities->executeGetUpcomingCounts( $shape, $generation );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$key = UpcomingCountAbilities::inventoryCacheKey( $shape['taxonomy'], $shape['exclude_roots'] );
				if ( ! is_array( CalendarCache::get( $key, $generation ) ) ) {
					return new \WP_Error( 'warm_cache_write_failed', 'Failed to persist an inventory cache value.' );
				}
				$warmed[] = $shape;

				if ( CalendarCache::get_generation() !== $generation ) {
					return array( 'skipped' => 'superseded_during_warm' );
				}
			}

			return array(
				'generation' => $generation,
				'site_id'    => $site_id,
				'warmed'     => $warmed,
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
