<?php
/**
 * WP-CLI Data Machine worker command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Cli\BaseCommand;
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
	 * [--once]
	 * : Run one recovery/drain pass and exit.
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
			'once'                    => isset( $assoc_args['once'] ),
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
	 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$status = self::statusSnapshot();

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
		$started_at              = time();
		$time_limit              = max( 0, (int) ( $options['time_limit'] ?? 300 ) );
		$batch_size              = max( 1, (int) ( $options['batch_size'] ?? 10 ) );
		$drain_limit             = max( 0, (int) ( $options['drain_limit'] ?? 25 ) );
		$drain_time_limit        = max( 1, (int) ( $options['drain_time_limit'] ?? 120 ) );
		$sleep                   = max( 0, (int) ( $options['sleep'] ?? 30 ) );
		$stuck_timeout           = max( 1, (int) ( $options['stuck_timeout'] ?? 2 ) );
		$recover_stuck           = (bool) ( $options['recover_stuck'] ?? true );
		$stop_on_pending_actions = (bool) ( $options['stop_on_pending_actions'] ?? false );
		$once                    = (bool) ( $options['once'] ?? false );

		$passes      = 0;
		$recoveries  = 0;
		$timed_out   = 0;
		$reconciled  = 0;
		$completions = 0;
		$failures    = 0;
		$warnings    = 0;
		$stop_reason = 'time_limit';

		while ( true ) {
			if ( $time_limit > 0 && ( time() - $started_at ) >= $time_limit ) {
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

			$remaining_time = $time_limit > 0 ? max( 1, $time_limit - ( time() - $started_at ) ) : $drain_time_limit;
			$drain          = DrainCommand::drain(
				array(
					'limit'      => $drain_limit,
					'batch_size' => $batch_size,
					'time_limit' => min( $drain_time_limit, $remaining_time ),
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

				sleep( min( $sleep, $time_limit > 0 ? max( 0, $time_limit - ( time() - $started_at ) ) : $sleep ) );
			}
		}

		$status = self::statusSnapshot();

		return array(
			'passes'                  => $passes,
			'job_recoveries'          => $recoveries,
			'job_timeouts'            => $timed_out,
			'stale_actions_reconciled' => $reconciled,
			'action_completions'      => $completions,
			'action_failures'         => $failures,
			'pending_actions'         => (int) $status['pending_actions'],
			'warnings'                => $warnings,
			'duration_seconds'        => time() - $started_at,
			'stop_reason'             => $stop_reason,
		);
	}

	/**
	 * Build a lightweight worker status snapshot.
	 *
	 * @return array<string,int|string>
	 */
	private static function statusSnapshot(): array {
		$pending_summary = PendingActionStore::summary( array( 'status' => 'pending' ) );

		return array(
			'pending_actions' => (int) ( $pending_summary['total'] ?? 0 ),
			'pending_kinds'   => implode( ',', array_keys( (array) ( $pending_summary['by_kind'] ?? array() ) ) ),
		);
	}

	/**
	 * Count pending approval actions.
	 */
	private static function pendingActionCount(): int {
		$status = self::statusSnapshot();
		return (int) $status['pending_actions'];
	}
}
