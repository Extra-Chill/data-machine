<?php
/**
 * WP-CLI Data Machine drain command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\WorkerLock;
use DataMachine\Core\ActionScheduler\ScopedDrainService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Drain due Data Machine Action Scheduler work.
 */
class DrainCommand extends BaseCommand {

	public const HOOK_BATCH_CHUNK = ScopedDrainService::HOOK_BATCH_CHUNK;

	public const HOOK_EXECUTE_STEP = ScopedDrainService::HOOK_EXECUTE_STEP;

	/**
	 * Drain due Data Machine actions until empty or a budget is reached.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum actions to execute. 0 means no action-count limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Maximum actions to ask Action Scheduler to claim per batch.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--time-limit=<seconds>]
	 * : Maximum wall-clock seconds to drain. 0 means no time limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--stop-before-timeout=<seconds>]
	 * : Stop this many seconds before the wall-clock limit so the drain exits
	 * cleanly before an external supervisor timeout. Only applies when
	 * --time-limit is greater than 0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--job-id=<ids>]
	 * : Optional comma-separated Data Machine job IDs to drain. Useful when
	 * unrelated due work is blocked ahead of a known cleanup or retry run.
	 *
	 * [--lane=<lane>]
	 * : Optional worker lane to drain. Supported lanes: publish, background.
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
	 *     wp datamachine drain
	 *     wp datamachine drain --limit=500 --time-limit=300 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$stats = self::drain(
			array(
				'limit'               => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0,
				'batch_size'          => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 25,
				'time_limit'          => isset( $assoc_args['time-limit'] ) ? (int) $assoc_args['time-limit'] : 0,
				'stop_before_timeout' => isset( $assoc_args['stop-before-timeout'] ) ? (int) $assoc_args['stop-before-timeout'] : 0,
				'job_ids'             => isset( $assoc_args['job-id'] ) ? (string) $assoc_args['job-id'] : '',
				'lane'                => isset( $assoc_args['lane'] ) ? (string) $assoc_args['lane'] : '',
			)
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Drain due Data Machine actions and return a compact summary.
	 *
	 * Scheduler health checks count the full Data Machine Action Scheduler group,
	 * so this drain runs concrete due action IDs from that same group instead of
	 * a hand-maintained hook allow-list.
	 *
	 * @param array{limit?:int,batch_size?:int,time_limit?:int,stop_before_timeout?:int,hooks?:string[],job_ids?:string|int[],lane?:string,acquire_lock?:bool,lock_owner?:string} $options Drain options.
	 * @return array<string,int|string> Drain stats.
	 */
	public static function drain( array $options = array() ): array {
		self::ensureCliMemoryLimit();

		$time_limit          = max( 0, (int) ( $options['time_limit'] ?? 0 ) );
		$stop_before_timeout = max( 0, (int) ( $options['stop_before_timeout'] ?? 0 ) );
		$service             = new ScopedDrainService();
		$hooks               = is_array( $options['hooks'] ?? null ) ? $options['hooks'] : null;
		$job_ids             = self::normalizeJobIds( $options['job_ids'] ?? null );
		$lane                = self::normalizeLane( $options['lane'] ?? '' );
		$acquire_lock        = (bool) ( $options['acquire_lock'] ?? true );
		$lock                = array();

		if ( $acquire_lock ) {
			$lock_ttl = $time_limit > 0 ? $time_limit + max( 60, $stop_before_timeout ) : 600;
			$lock     = WorkerLock::acquire( (string) ( $options['lock_owner'] ?? self::defaultLockOwner( 'drain', $lane ) ), $lock_ttl, $lane );
			if ( empty( $lock['acquired'] ) ) {
				return self::withLockStatus( $service->lockedStats( $hooks, $job_ids, $lane ), $lock );
			}

			$lock_token = (string) ( $lock['lock_token'] ?? '' );
			$lock_lane  = $lane;
			register_shutdown_function(
				static function () use ( $lock_token, $lock_lane ): void {
					WorkerLock::release( $lock_token, $lock_lane );
				}
			);
		}

		try {
			$stats = $service->drain(
				$options + array(
					'execution_context' => 'Data Machine CLI drain',
					'warning_callback'  => static function ( string $message ): void {
						WP_CLI::warning( $message );
					},
				)
			);
			return self::withLockStatus( $stats, $lock );
		} finally {
			if ( $acquire_lock ) {
				WorkerLock::release( (string) ( $lock['lock_token'] ?? '' ), $lane );
			}
		}
	}

	/**
	 * Return a read-only Data Machine Action Scheduler status snapshot.
	 *
	 * @param array{hooks?:string[],job_ids?:string|int[],lane?:string} $options Status options.
	 * @return array<string,int|string> Status stats.
	 */
	public static function status( array $options = array() ): array {
		$lane   = self::normalizeLane( $options['lane'] ?? '' );
		$lock   = WorkerLock::snapshot( null, 600, $lane );
		$status = ( new ScopedDrainService() )->status( $options );

		return $status + self::publicLockStatus( $lock );
	}

	/**
	 * Raise the CLI memory floor for large Action Scheduler drains.
	 */
	public static function ensureCliMemoryLimit( int $minimum_bytes = 1073741824 ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		ScopedDrainService::ensureMemoryLimit( $minimum_bytes );
	}

	/**
	 * Add operator-facing lock fields to stats.
	 *
	 * @param array<string,int|string>      $stats Stats.
	 * @param array<string,int|string|bool> $lock  Lock state.
	 * @return array<string,int|string> Stats with lock fields.
	 */
	private static function withLockStatus( array $stats, array $lock ): array {
		return $stats + self::publicLockStatus( $lock );
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
	private static function defaultLockOwner( string $command, string $lane = '' ): string {
		$pid = getmypid();
		if ( '' !== $lane ) {
			$command .= ':' . $lane;
		}

		return sprintf( '%s pid:%d host:%s', $command, false === $pid ? 0 : $pid, php_uname( 'n' ) );
	}

	/**
	 * Normalize a worker lane identifier.
	 */
	private static function normalizeLane( mixed $lane ): string {
		$lane = is_string( $lane ) ? strtolower( trim( $lane ) ) : '';
		return in_array( $lane, array( 'publish', 'background' ), true ) ? $lane : '';
	}

	/**
	 * Normalize an optional job-id scope.
	 *
	 * @param mixed $job_ids Optional comma-separated string or ID list.
	 * @return int[] Job IDs.
	 */
	private static function normalizeJobIds( mixed $job_ids ): array {
		if ( is_string( $job_ids ) ) {
			$job_ids = preg_split( '/\s*,\s*/', $job_ids, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $job_ids ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $job_ids as $job_id ) {
			$job_id = absint( $job_id );
			if ( $job_id > 0 ) {
				$normalized[] = $job_id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
