<?php
/**
 * Calendar Generation Publisher Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Tasks\CalendarGenerationPublisher;
use WP_UnitTestCase;

class CalendarGenerationPublisherTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		CacheInvalidator::resume();
		delete_option( CalendarGenerationPublisher::WINDOW_OPTION );
		CalendarCache::get_generation();
	}

	public function tearDown(): void {
		CacheInvalidator::resume();
		parent::tearDown();
	}

	public function test_many_import_requests_keep_one_reusable_generation(): void {
		$parent_id  = $this->createBatchParent();
		$generation = CalendarCache::get_generation();
		$cache_key  = CalendarCache::PREFIX . 'batch-reuse-' . uniqid();
		CalendarCache::set( $cache_key, 'reused', HOUR_IN_SECONDS, $generation );

		$this->deferImportRequests( $parent_id, 100 );

		$this->assertSame( $generation, CalendarCache::get_generation() );
		$this->assertSame( 'reused', CalendarCache::get( $cache_key, $generation ) );
	}

	public function test_terminal_replays_schedule_one_bounded_publication(): void {
		$parent_id = $this->createBatchParent();
		$this->deferImportRequests( $parent_id, 2 );

		$scheduled = array();
		$scheduler = static function ( $task_type, $params, $context, $parent_job_id, $operation_key ) use ( &$scheduled ): int {
			if ( ! isset( $scheduled[ $operation_key ] ) ) {
				$scheduled[ $operation_key ] = compact( 'task_type', 'params', 'context', 'parent_job_id', 'operation_key' );
			}
			return 123;
		};
		$this->assertTrue( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, $scheduler, 1000 ) );
		$this->assertTrue( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, $scheduler, 1000 ) );
		$this->assertCount( 1, $scheduled, 'Terminal callback replay must reuse one durable operation key.' );

		$publication = reset( $scheduled );
		$this->assertSame( 1200, $publication['params']['scheduled_at'] );
		$this->assertSame( get_current_blog_id(), $publication['params']['site_id'] );
		$this->assertLessThanOrEqual( CalendarGenerationPublisher::COALESCE_WINDOW, $publication['params']['scheduled_at'] - 1000 );
	}

	public function test_exact_generation_replay_transitions_once(): void {
		$target      = hash( 'sha256', 'crash-safe-calendar-generation' );

		$transitions = 0;
		$observer    = static function () use ( &$transitions ): void {
			++$transitions;
		};
		add_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );
		try {
			$this->assertTrue( CalendarCache::publish_generation( $target ) );
			$this->assertTrue( CalendarCache::publish_generation( $target ) );
		} finally {
			remove_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );
		}

		$this->assertSame( 1, $transitions, 'Crash replay must publish the exact target only once.' );
		$this->assertSame( $target, CalendarCache::get_generation() );
	}

	public function test_failed_durable_setup_falls_back_to_immediate_freshness(): void {
		$ordinary_job = $this->createJob( array() );
		$before       = CalendarCache::get_generation();

		$this->assertFalse( CalendarGenerationPublisher::deferBatch( $ordinary_job ) );
		CacheInvalidator::invalidate_all();

		$this->assertNotSame( $before, CalendarCache::get_generation() );
	}

	public function test_terminal_scheduler_failure_requests_replay_with_same_target(): void {
		$parent_id = $this->createBatchParent();
		$this->assertTrue( CalendarGenerationPublisher::deferBatch( $parent_id ) );

		$this->assertFalse( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, static fn() => false, 2000 ) );

		$calls = array();
		$this->assertTrue(
			CalendarGenerationPublisher::scheduleForTerminalBatch(
				$parent_id,
				static function ( $task_type, $params, $context, $parent_job_id, $operation_key ) use ( &$calls ): int {
					$calls[] = compact( 'task_type', 'params', 'context', 'parent_job_id', 'operation_key' );
					return 456;
				},
				2000
			)
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( CalendarGenerationPublisher::operationKey( get_current_blog_id(), intdiv( 2000, CalendarGenerationPublisher::COALESCE_WINDOW ) ), $calls[0]['operation_key'] );
	}

	public function test_sites_and_windows_have_isolated_publication_keys(): void {
		$site_id   = get_current_blog_id();
		$other_id  = self::factory()->blog->create();
		$window    = 100;
		$next      = $window + 1;

		$this->assertNotSame( CalendarGenerationPublisher::operationKey( $site_id, $window ), CalendarGenerationPublisher::operationKey( $other_id, $window ) );
		$this->assertNotSame( CalendarGenerationPublisher::operationKey( $site_id, $window ), CalendarGenerationPublisher::operationKey( $site_id, $next ) );

		$first_generation = CalendarCache::get_generation();
		switch_to_blog( $other_id );
		try {
			$other_generation = CalendarCache::get_generation();
			CalendarCache::publish_generation( hash( 'sha256', 'other-site-generation' ) );
			$this->assertNotSame( $other_generation, CalendarCache::get_generation() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $first_generation, CalendarCache::get_generation() );
	}

	public function test_terminal_callback_registry_preserves_existing_callbacks(): void {
		$existing  = static fn(): bool => true;
		$callbacks = CalendarGenerationPublisher::registerTerminalCallback( array( 'existing' => $existing ), 1, 'completed' );

		$this->assertSame( $existing, $callbacks['existing'] );
		$this->assertSame( array( CalendarGenerationPublisher::class, 'publishForTerminalBatch' ), $callbacks['data_machine_events_calendar_generation'] );
	}

	/** @param array<string,mixed> $engine Engine data for the job. */
	private function createJob( array $engine ): int {
		$job_id = ( new Jobs() )->create_job(
			array(
				'pipeline_id' => 1,
				'flow_id'     => 1,
				'label'       => 'Calendar generation publisher test',
				'user_id'     => 0,
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertTrue( EngineData::persist( $job_id, $engine ) );
		return $job_id;
	}

	private function createBatchParent(): int {
		return $this->createJob(
			array(
				'batch'                     => true,
				'batch_context'             => PipelineBatchScheduler::BATCH_CONTEXT,
				'batch_completion_strategy' => BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE,
			)
		);
	}

	private function deferImportRequests( int $parent_id, int $requests ): void {
		for ( $request = 0; $request < $requests; ++$request ) {
			$this->assertTrue( CalendarGenerationPublisher::deferBatch( $parent_id ) );
			CacheInvalidator::defer();
			try {
				CacheInvalidator::invalidate_all();
				CacheInvalidator::invalidate_all();
			} finally {
				CacheInvalidator::resume();
			}
		}
	}
}
