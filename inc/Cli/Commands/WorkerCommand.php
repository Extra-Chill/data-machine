<?php
/**
 * WP-CLI Data Machine worker command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Abilities\Job\JobsSummaryAbility;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\WorkerLock;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Keep Data Machine automation moving from a headless CLI process.
 */
class WorkerCommand extends BaseCommand {

	/**
	 * Run a bounded Data Machine worker loop.
	 *
	 * The worker composes existing runtime primitives: stuck-job recovery and the
	 * first-class Data Machine drain loop. It does not process Action Scheduler or
	 * job rows directly.
	 *
	 * ## OPTIONS
	 *
	 * [--time-limit=<seconds>]
	 * : Maximum wall-clock seconds to run. 0 means no time limit.
	 * ---
	 * default: 300
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Maximum actions to ask the drain loop to claim per batch.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--drain-limit=<number>]
	 * : Maximum actions to execute per drain pass. 0 means no action-count limit.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--drain-time-limit=<seconds>]
	 * : Maximum seconds per drain pass.
	 * ---
	 * default: 120
	 * ---
	 *
	 * [--sleep=<seconds>]
	 * : Seconds to sleep between idle passes.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--stuck-timeout=<hours>]
	 * : Hours before processing jobs are treated as stale.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--no-recover-stuck]
	 * : Skip stuck-job recovery.
	 *
	 * [--stop-on-pending-actions]
	 * : Stop when pending approval actions exist.
	 *
	 * [--max-passes=<number>]
	 * : Maximum worker passes to run. 0 means no pass-count limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--stop-before-timeout=<seconds>]
	 * : Stop this many seconds before the wall-clock limit so the worker exits cleanly before an external supervisor timeout.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--once]
	 * : Run one recovery/drain pass and exit.
	 *
	 * [--lane=<lane>]
	 * : Optional worker lane to run. Supported lanes: publish, background.
	 * Publish drains AI/upsert step executions; background drains non-publish work.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine worker run --time-limit=3600 --sleep=30
	 *     wp datamachine worker run --time-limit=900 --max-passes=10 --stop-before-timeout=60
	 *     wp datamachine worker run --once --stop-on-pending-actions
	 *
	 * @subcommand run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$options = array(
			'time_limit'              => isset( $assoc_args['time-limit'] ) ? max( 0, (int) $assoc_args['time-limit'] ) : 300,
			'batch_size'              => isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 10,
			'drain_limit'             => isset( $assoc_args['drain-limit'] ) ? max( 0, (int) $assoc_args['drain-limit'] ) : 25,
			'drain_time_limit'        => isset( $assoc_args['drain-time-limit'] ) ? max( 1, (int) $assoc_args['drain-time-limit'] ) : 120,
			'sleep'                   => isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 30,
			'stuck_timeout'           => isset( $assoc_args['stuck-timeout'] ) ? max( 1, (int) $assoc_args['stuck-timeout'] ) : 2,
			'recover_stuck'           => ! isset( $assoc_args['no-recover-stuck'] ),
			'stop_on_pending_actions' => isset( $assoc_args['stop-on-pending-actions'] ),
			'max_passes'              => isset( $assoc_args['max-passes'] ) ? max( 0, (int) $assoc_args['max-passes'] ) : 0,
			'stop_before_timeout'     => isset( $assoc_args['stop-before-timeout'] ) ? max( 0, (int) $assoc_args['stop-before-timeout'] ) : 30,
			'once'                    => isset( $assoc_args['once'] ),
			'lane'                    => isset( $assoc_args['lane'] ) ? (string) $assoc_args['lane'] : '',
		);

		$stats = self::runLoop( $options );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Render a lightweight worker status snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * [--lane=<lane>]
		 * : Optional worker lane status. Supported lanes: publish, background.
		 *
		 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$status = self::statusSnapshot( isset( $assoc_args['lane'] ) ? (string) $assoc_args['lane'] : '' );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $status ), array_keys( $status ), array( 'format' => 'table' ) );
	}

	/**
	 * Execute the worker loop.
	 *
	 * @param array<string,mixed> $options Worker options.
	 * @return array<string,int|string> Worker stats.
	 */
	public static function runLoop( array $options ): array {
		DrainCommand::ensureCliMemoryLimit();

		$started_at              = time();
		$time_limit              = max( 0, (int) ( $options['time_limit'] ?? 300 ) );
		$batch_size              = max( 1, (int) ( $options['batch_size'] ?? 10 ) );
		$drain_limit             = max( 0, (int) ( $options['drain_limit'] ?? 25 ) );
		$drain_time_limit        = max( 1, (int) ( $options['drain_time_limit'] ?? 120 ) );
		$sleep                   = max( 0, (int) ( $options['sleep'] ?? 30 ) );
		$stuck_timeout           = max( 1, (int) ( $options['stuck_timeout'] ?? 2 ) );
		$recover_stuck           = (bool) ( $options['recover_stuck'] ?? true );
		$stop_on_pending_actions = (bool) ( $options['stop_on_pending_actions'] ?? false );
		$max_passes              = max( 0, (int) ( $options['max_passes'] ?? 0 ) );
		$stop_before_timeout     = max( 0, (int) ( $options['stop_before_timeout'] ?? 30 ) );
		$once                    = (bool) ( $options['once'] ?? false );
		$lane                    = self::normalizeLane( $options['lane'] ?? '' );
		$lock                    = WorkerLock::acquire( self::defaultLockOwner( $lane ), $time_limit > 0 ? $time_limit + max( 60, $stop_before_timeout ) : 600, $lane );

		if ( empty( $lock['acquired'] ) ) {
			return self::lockedStats( $lock, $lane );
		}

		$lock_token = (string) ( $lock['lock_token'] ?? '' );
		$lock_lane  = $lane;
		register_shutdown_function(
			static function () use ( $lock_token, $lock_lane ): void {
				WorkerLock::release( $lock_token, $lock_lane );
			}
		);

		$passes      = 0;
		$recoveries  = 0;
		$timed_out   = 0;
		$reconciled  = 0;
		$completions = 0;
		$failures    = 0;
		$warnings    = 0;
		$stop_reason = 'time_limit';

		try {
			while ( true ) {
				$elapsed = time() - $started_at;
				if ( $time_limit > 0 && $elapsed >= $time_limit ) {
					break;
				}
				if ( $time_limit > 0 && ( $time_limit - $elapsed ) <= $stop_before_timeout ) {
					$stop_reason = 'timeout_margin';
					break;
				}
				if ( $max_passes > 0 && $passes >= $max_passes ) {
					$stop_reason = 'max_passes';
					break;
				}

				$pending_actions = self::pendingActionCount();
				if ( $stop_on_pending_actions && $pending_actions > 0 ) {
					$stop_reason = 'pending_actions';
					break;
				}

				++$passes;

				if ( $recover_stuck ) {
					$recovery = ( new RecoverStuckJobsAbility() )->execute(
						array(
							'dry_run'       => false,
							'timeout_hours' => $stuck_timeout,
						)
					);

					if ( empty( $recovery['success'] ) ) {
						++$warnings;
					} else {
						$recoveries += (int) ( $recovery['recovered'] ?? 0 );
						$timed_out  += (int) ( $recovery['timed_out'] ?? 0 );
						$reconciled += (int) ( $recovery['stale_actions'] ?? 0 );
					}
				}

				$remaining_time = $time_limit > 0 ? max( 1, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $drain_time_limit;
				$drain          = DrainCommand::drain(
					array(
						'limit'        => $drain_limit,
						'batch_size'   => $batch_size,
						'time_limit'   => min( $drain_time_limit, $remaining_time ),
						'acquire_lock' => false,
						'lane'         => $lane,
					)
				);

				$completions += (int) ( $drain['completions'] ?? 0 );
				$failures    += (int) ( $drain['failures'] ?? 0 );
				$warnings    += (int) ( $drain['warnings'] ?? 0 );

				if ( $once ) {
					$stop_reason = 'once';
					break;
				}

				if ( (int) ( $drain['remaining_pending'] ?? 0 ) <= 0 ) {
					$stop_reason = 'idle';
					if ( $sleep <= 0 ) {
						break;
					}

					sleep( min( $sleep, $time_limit > 0 ? max( 0, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $sleep ) );
				}
			}

			$status = self::statusSnapshot( $lane );

			return array(
				'passes'                   => $passes,
				'job_recoveries'           => $recoveries,
				'job_timeouts'             => $timed_out,
				'stale_actions_reconciled' => $reconciled,
				'action_completions'       => $completions,
				'action_failures'          => $failures,
				'pending_actions'          => (int) $status['pending_actions'],
				'due_actions'              => (int) $status['due_actions'],
				'total_pending_actions'    => (int) $status['total_pending_actions'],
				'processing_jobs'          => (int) $status['processing_jobs'],
				'pending_jobs'             => (int) $status['pending_jobs'],
				'stuck_jobs'               => (int) $status['stuck_jobs'],
				'warnings'                 => $warnings,
				'duration_seconds'         => time() - $started_at,
				'stop_reason'              => $stop_reason,
				'lane'                     => $lane,
			) + self::publicLockStatus( $lock );
		} finally {
			WorkerLock::release( (string) ( $lock['lock_token'] ?? '' ), $lane );
		}
	}

	/**
	 * Build a lightweight worker status snapshot.
	 *
	 * @return array<string,int|string>
	 */
	private static function statusSnapshot( string $lane = '' ): array {
		$lane            = self::normalizeLane( $lane );
		$pending_summary = PendingActionStore::summary( array( 'status' => 'pending' ) );
		$drain_status    = DrainCommand::status( array( 'lane' => $lane ) );
		$jobs_summary    = ( new JobsSummaryAbility() )->execute( array( 'compact' => true ) );
		$jobs            = ! empty( $jobs_summary['success'] ) && is_array( $jobs_summary['summary'] ?? null ) ? $jobs_summary['summary'] : array();
		$stuck_jobs      = RecoverStuckJobsAbility::countStuckCandidates();
		$lock            = WorkerLock::snapshot( null, 600, $lane );

		return array(
			'pending_actions'       => (int) ( $pending_summary['total'] ?? 0 ),
			'pending_kinds'         => implode( ',', array_keys( (array) ( $pending_summary['by_kind'] ?? array() ) ) ),
			'due_actions'           => (int) ( $drain_status['due_pending'] ?? 0 ),
			'total_pending_actions' => (int) ( $drain_status['total_pending'] ?? 0 ),
			'action_hooks'          => (string) ( $drain_status['hooks'] ?? '' ),
			'processing_jobs'       => self::jobStatusCount( $jobs, 'processing' ),
			'pending_jobs'          => self::jobStatusCount( $jobs, 'pending' ),
			'failed_jobs'           => (int) ( $jobs['failed_count'] ?? self::jobStatusCount( $jobs, 'failed' ) ),
			'stuck_jobs'            => $stuck_jobs,
			'lane'                  => $lane,
		) + self::publicLockStatus( $lock );
	}

	/**
	 * Read one normalized status bucket from a jobs summary result.
	 *
	 * @param array<string,mixed> $jobs   Jobs summary payload.
	 * @param string              $status Normalized status bucket.
	 * @return int Bucket count.
	 */
	private static function jobStatusCount( array $jobs, string $status ): int {
		foreach ( (array) ( $jobs['status'] ?? array() ) as $row ) {
			if ( (string) ( $row['status'] ?? '' ) === $status ) {
				return (int) ( $row['count'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Build a lock-skipped worker result.
	 *
	 * @param array<string,int|string|bool> $lock Lock state.
	 * @return array<string,int|string> Worker stats.
	 */
	private static function lockedStats( array $lock, string $lane = '' ): array {
		$status = self::statusSnapshot( $lane );

		return array(
			'passes'                   => 0,
			'job_recoveries'           => 0,
			'job_timeouts'             => 0,
			'stale_actions_reconciled' => 0,
			'action_completions'       => 0,
			'action_failures'          => 0,
			'pending_actions'          => (int) $status['pending_actions'],
			'due_actions'              => (int) $status['due_actions'],
			'total_pending_actions'    => (int) $status['total_pending_actions'],
			'processing_jobs'          => (int) $status['processing_jobs'],
			'pending_jobs'             => (int) $status['pending_jobs'],
			'stuck_jobs'               => (int) $status['stuck_jobs'],
			'warnings'                 => 0,
			'duration_seconds'         => 0,
			'stop_reason'              => 'locked',
			'lane'                     => $lane,
		) + self::publicLockStatus( $lock );
	}

	/**
	 * Strip private lock fields before CLI output.
	 *
	 * @param array<string,int|string|bool> $lock Lock state.
	 * @return array<string,int|string> Public lock state.
	 */
	private static function publicLockStatus( array $lock ): array {
		return array(
			'lock_status'      => (string) ( $lock['lock_status'] ?? 'unlocked' ),
			'lock_owner'       => (string) ( $lock['lock_owner'] ?? '' ),
			'lock_age_seconds' => (int) ( $lock['lock_age_seconds'] ?? 0 ),
			'lock_expires_at'  => (int) ( $lock['lock_expires_at'] ?? 0 ),
			'lock_lane'        => (string) ( $lock['lock_lane'] ?? '' ),
		);
	}

	/**
	 * Build a compact default owner string for lock diagnostics.
	 */
	private static function defaultLockOwner( string $lane = '' ): string {
		$pid = getmypid();
		$kind = '' === $lane ? 'worker' : 'worker:' . $lane;

		return sprintf( '%s pid:%d host:%s', $kind, false === $pid ? 0 : $pid, php_uname( 'n' ) );
	}

	/**
	 * Normalize a worker lane identifier.
	 */
	private static function normalizeLane( mixed $lane ): string {
		$lane = is_string( $lane ) ? strtolower( trim( $lane ) ) : '';
		return in_array( $lane, array( 'publish', 'background' ), true ) ? $lane : '';
	}

	/**
	 * Count pending approval actions.
	 */
	private static function pendingActionCount(): int {
		$status = self::statusSnapshot();
		return (int) $status['pending_actions'];
	}
}
