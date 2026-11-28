<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Liveness verdicts require fresh scheduler evidence.
/**
 * Scheduler evidence for job liveness.
 *
 * One batch Action Scheduler query per pass loads every pending or
 * in-progress Data Machine action and parses `COALESCE(extended_args, args)`
 * into a `job_id => actions` map. Every liveness consumer (the Core
 * `JobLiveness` classifier, `recover-stuck`, pathless batch recovery, and the
 * `jobs liveness` CLI) reads that map instead of issuing per-job `LIKE`
 * queries or hand-parsing action args.
 *
 * The join key is parsed from `COALESCE(extended_args, args)` because Action
 * Scheduler stores an md5 in `args` when the JSON exceeds 191 characters.
 * Evidence fails closed: a query error or a result larger than the scan limit
 * makes the snapshot incomplete, and consumers must then refuse to infer that
 * any job lacks a live action.
 *
 * @package DataMachine\Core\Jobs
 * @since 0.180.0
 */

namespace DataMachine\Core\Jobs;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\DirectJobEnqueuer;
use DataMachine\Engine\AI\AIConcurrencyBackpressure;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Scheduling\FlowRoutines;
use DataMachine\Engine\Tasks\TaskScheduler;

defined( 'ABSPATH' ) || exit;

final class SchedulerEvidence {

	/** Live-action rows loaded per pass; exceeding it fails the pass closed. */
	public const SCAN_LIMIT = 5000;

	public const STATUS_PENDING     = 'pending';
	public const STATUS_IN_PROGRESS = 'in-progress';

	/** Snapshots older than this are reloaded by long-running consumers. */
	public const MAX_AGE_SECONDS = 30;

	private const ZERO_DATETIME = '0000-00-00 00:00:00';

	/** @var array<int,array<int,array<string,mixed>>> job_id => actions ordered by action_id. */
	private array $by_job;

	private bool $complete;

	private int $loaded_at;

	/**
	 * @param array<int,array<int,array<string,mixed>>> $by_job Actions keyed by owning job ID.
	 */
	private function __construct( array $by_job, bool $complete, int $loaded_at ) {
		$this->by_job    = $by_job;
		$this->complete  = $complete;
		$this->loaded_at = $loaded_at;
	}

	/**
	 * Data Machine hooks whose pending/in-progress actions can advance a job.
	 *
	 * `arg` is the named argument that carries the owning job ID; `position`
	 * is the positional fallback for hooks registered with a bare argument
	 * list, and `positional_only` marks hooks that never carry a named key.
	 *
	 * @return array<string,array{arg:string,position?:int,positional_only?:bool}>
	 */
	public static function hookJobArgs(): array {
		return array(
			DirectJobEnqueuer::HOOK                => array( 'arg' => 'job_id' ),
			AIConcurrencyBackpressure::RESUME_HOOK => array( 'arg' => 'job_id' ),
			PipelineBatchScheduler::BATCH_HOOK     => array( 'arg' => 'parent_job_id' ),
			TaskScheduler::BATCH_HOOK              => array( 'arg' => 'parent_job_id' ),
			FlowRoutines::LEGACY_HOOK              => array(
				'arg'      => 'job_id',
				'position' => 1,
			),
			SystemTask::RETRY_HOOK                 => array(
				'arg'             => 'job_id',
				'position'        => 0,
				'positional_only' => true,
			),
		);
	}

	/**
	 * Hooks that run one pipeline step (or its AI continuation) for a job.
	 *
	 * @return array<int,string>
	 */
	public static function stepHooks(): array {
		return array( DirectJobEnqueuer::HOOK, AIConcurrencyBackpressure::RESUME_HOOK );
	}

	/**
	 * Whether the most recent wpdb query recorded an error.
	 *
	 * Read through a call so static analysis does not treat last_error as the
	 * literal it was reset to before the query ran.
	 */
	private static function queryFailed( object $db ): bool {
		return '' !== (string) ( $db->last_error ?? '' );
	}

	/**
	 * Load the current live-action evidence with ONE bounded query.
	 */
	public static function load(): self {
		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$hooks         = array_keys( self::hookJobArgs() );
		$placeholders  = implode( ', ', array_fill( 0, count( $hooks ), '%s' ) );
		$args          = array_merge( array( $actions_table ), $hooks, array( self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::SCAN_LIMIT + 1 ) );

		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated placeholders only; every value is bound via the spread.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, COALESCE(extended_args, args) AS action_args
				 FROM %i
				 WHERE hook IN ( {$placeholders} )
				 AND status IN ( %s, %s )
				 LIMIT %d",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// With ARRAY_A, wpdb::get_results() returns an empty array (not null)
		// when the query fails; the failure is visible only in last_error, which
		// query() sets. Fail closed so absence is never inferred from an error.
		if ( ! is_array( $rows ) || self::queryFailed( $wpdb ) ) {
			return new self( array(), false, time() );
		}

		return self::fromRows( $rows, count( $rows ) <= self::SCAN_LIMIT );
	}

	/**
	 * Build evidence from already-loaded rows (also the seam for pure tests).
	 *
	 * @param array<int,array<string,mixed>|object> $rows     Rows with `action_args` (or `args`); arrays from wpdb, objects from callers passing stdClass rows.
	 * @param bool                           $complete Whether the rows are the whole live set.
	 */
	public static function fromRows( array $rows, bool $complete = true ): self {
		if ( ! $complete ) {
			return new self( array(), false, time() );
		}

		$by_job = array();
		foreach ( $rows as $row ) {
			$row    = is_object( $row ) ? get_object_vars( $row ) : $row;
			$args   = (string) ( $row['action_args'] ?? $row['args'] ?? '' );
			$hook   = (string) ( $row['hook'] ?? '' );
			$job_id = self::extractJobId( $args, $hook );
			if ( $job_id <= 0 ) {
				continue;
			}

			$decoded             = json_decode( $args, true );
			$row['args']         = $args;
			$row['action_id']    = (int) ( $row['action_id'] ?? 0 );
			$row['decoded_args'] = is_array( $decoded ) ? $decoded : array();
			unset( $row['action_args'] );
			$by_job[ $job_id ][] = $row;
		}
		foreach ( $by_job as &$actions ) {
			usort( $actions, static fn( array $a, array $b ): int => $a['action_id'] <=> $b['action_id'] );
		}
		unset( $actions );

		return new self( $by_job, true, time() );
	}

	/** Whether the snapshot proves absence: false on query error or over-limit. */
	public function isComplete(): bool {
		return $this->complete;
	}

	/** Whether a long-running consumer should reload before trusting this snapshot. */
	public function isStale( int $now ): bool {
		return ( $now - $this->loaded_at ) > self::MAX_AGE_SECONDS;
	}

	/**
	 * Pending and in-progress actions owned by one job.
	 *
	 * @param int                   $job_id Owning job ID (the parent for batch chunk hooks).
	 * @param array<int,string>|null $hooks  Optional hook allow-list.
	 * @return array<int,array<string,mixed>>
	 */
	public function actionsFor( int $job_id, ?array $hooks = null ): array {
		$actions = $this->by_job[ $job_id ] ?? array();
		if ( null === $hooks ) {
			return $actions;
		}

		return array_values(
			array_filter(
				$actions,
				static fn( array $action ): bool => in_array( (string) ( $action['hook'] ?? '' ), $hooks, true )
			)
		);
	}

	/**
	 * Live action IDs for one job: pending, or in-progress inside the timeout window.
	 *
	 * @param array<int,string>|null $hooks Optional hook allow-list.
	 * @return array<int,int>
	 */
	public function liveActionIds( int $job_id, int $now, int $timeout_seconds, ?array $hooks = null ): array {
		$ids = array();
		foreach ( $this->actionsFor( $job_id, $hooks ) as $action ) {
			if ( self::isLive( $action, $now, $timeout_seconds ) ) {
				$ids[] = (int) $action['action_id'];
			}
		}
		return $ids;
	}

	/**
	 * Live action IDs for every job in the snapshot.
	 *
	 * @return array<int,array<int,int>> job_id => action IDs.
	 */
	public function liveActionMap( int $now, int $timeout_seconds ): array {
		$map = array();
		foreach ( array_keys( $this->by_job ) as $job_id ) {
			$ids = $this->liveActionIds( $job_id, $now, $timeout_seconds );
			if ( ! empty( $ids ) ) {
				$map[ $job_id ] = $ids;
			}
		}
		return $map;
	}

	/**
	 * Whether one action counts as live evidence.
	 *
	 * In-progress actions older than the timeout window are not live, matching
	 * timeout recovery. Unparseable timestamps are treated as live.
	 *
	 * @param array<string,mixed> $action Action row.
	 */
	public static function isLive( array $action, int $now, int $timeout_seconds ): bool {
		$status = (string) ( $action['status'] ?? '' );
		if ( self::STATUS_PENDING === $status ) {
			return true;
		}
		if ( self::STATUS_IN_PROGRESS !== $status ) {
			return false;
		}

		$last_attempt = (string) ( $action['last_attempt_gmt'] ?? '' );
		$reference    = '' !== $last_attempt && self::ZERO_DATETIME !== $last_attempt ? $last_attempt : (string) ( $action['scheduled_date_gmt'] ?? '' );
		$started_at   = '' !== $reference ? strtotime( $reference . ' UTC' ) : false;

		return false === $started_at || ( $now - $started_at ) < max( 1, $timeout_seconds );
	}

	/**
	 * Extract the owning job ID from an Action Scheduler args payload.
	 *
	 * Reads keyed args (top level or one array level deep) from JSON or
	 * serialized payloads, then the hook's positional fallback.
	 *
	 * @param string $args Action Scheduler args payload.
	 * @param string $hook Action Scheduler hook.
	 * @return int Job ID, or 0 when the action has no paired job.
	 */
	public static function extractJobId( string $args, string $hook ): int {
		$spec = self::hookJobArgs()[ $hook ] ?? array( 'arg' => 'job_id' );

		$decoded      = json_decode( $args, true );
		$unserialized = is_array( $decoded ) ? null : maybe_unserialize( $args );
		$payload      = is_array( $decoded ) ? $decoded : ( is_array( $unserialized ) ? $unserialized : array() );

		$named = self::namedInt( $payload, $spec['arg'] );
		if ( $named > 0 ) {
			return $named;
		}

		$position = $spec['position'] ?? null;
		if ( null !== $position && isset( $payload[ $position ] ) && is_numeric( $payload[ $position ] ) ) {
			return (int) $payload[ $position ];
		}

		return 0;
	}

	/**
	 * Read a numeric named arg at the top level or one array level deep.
	 *
	 * @param array<int|string,mixed> $payload Decoded args.
	 */
	private static function namedInt( array $payload, string $name ): int {
		if ( isset( $payload[ $name ] ) && is_numeric( $payload[ $name ] ) ) {
			return (int) $payload[ $name ];
		}
		foreach ( $payload as $value ) {
			if ( is_array( $value ) && isset( $value[ $name ] ) && is_numeric( $value[ $name ] ) ) {
				return (int) $value[ $name ];
			}
		}
		return 0;
	}
}
