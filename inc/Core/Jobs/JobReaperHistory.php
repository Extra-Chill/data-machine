<?php
/**
 * Bounded run history for the recurring job reaper.
 *
 * The reaper ships in dry-run mode so the false-positive rate on healthy
 * in-flight jobs can be measured before apply is enabled
 * (Extra-Chill/data-machine#3481). This store is that measurement: one small
 * per-site option holding the last {@see self::MAX_RUNS} run records plus
 * lifetime totals, so the data survives the rolling window.
 *
 * Size bound: a run record is a few hundred bytes; only the newest run keeps
 * its flagged-job list (at most {@see self::MAX_FLAGGED} entries), because the
 * next run consumes it for the false-positive check and then drops it.
 *
 * @package DataMachine\Core\Jobs
 * @since 0.180.0
 */

namespace DataMachine\Core\Jobs;

defined( 'ABSPATH' ) || exit;

final class JobReaperHistory {

	/** Per-site option holding `{ runs: list, totals: array }`. */
	public const OPTION = 'datamachine_job_reaper_history';

	/** Run records retained: 24 hours at the 15-minute cadence. */
	public const MAX_RUNS = 96;

	/** Flagged jobs remembered from the newest run for the next run's false-positive check. */
	public const MAX_FLAGGED = 50;

	/**
	 * Read the stored history, normalized so callers can rely on the shape.
	 *
	 * @return array{runs:list<array<string,mixed>>,totals:array<string,mixed>}
	 */
	public static function load(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$runs   = isset( $stored['runs'] ) && is_array( $stored['runs'] ) ? array_values( $stored['runs'] ) : array();
		$totals = isset( $stored['totals'] ) && is_array( $stored['totals'] ) ? $stored['totals'] : array();

		return array(
			'runs'   => $runs,
			'totals' => array_merge( self::emptyTotals(), $totals ),
		);
	}

	/**
	 * Zeroed lifetime totals.
	 *
	 * @return array<string,int|string>
	 */
	public static function emptyTotals(): array {
		return array(
			'runs'         => 0,
			'dry_run_runs' => 0,
			'apply_runs'   => 0,
			'would_act'    => 0,
			'acted'        => 0,
			'fp_checked'   => 0,
			'fp_hits'      => 0,
			'first_run_at' => '',
		);
	}

	/**
	 * Flagged jobs from the newest recorded run (input to the next false-positive check).
	 *
	 * @return list<array{job_id:int,status:string,verdict:string}>
	 */
	public static function previousFlagged(): array {
		$runs = self::load()['runs'];
		$last = end( $runs );
		if ( ! is_array( $last ) || ! isset( $last['flagged'] ) || ! is_array( $last['flagged'] ) ) {
			return array();
		}

		$flagged = array();
		foreach ( $last['flagged'] as $entry ) {
			if ( ! is_array( $entry ) || (int) ( $entry['job_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$flagged[] = array(
				'job_id'  => (int) $entry['job_id'],
				'status'  => (string) ( $entry['status'] ?? '' ),
				'verdict' => (string) ( $entry['verdict'] ?? '' ),
			);
		}

		return $flagged;
	}

	/**
	 * Append one run and fold it into the lifetime totals.
	 *
	 * @param array<string,mixed> $run Run record (see JobReaper::run()).
	 * @return array{runs:list<array<string,mixed>>,totals:array<string,mixed>} The stored history.
	 */
	public static function record( array $run ): array {
		$history = self::load();

		// The previous run's flagged list has been consumed by this run's check.
		foreach ( $history['runs'] as $index => $previous ) {
			unset( $history['runs'][ $index ]['flagged'] );
		}

		$run['flagged'] = array_slice( is_array( $run['flagged'] ?? null ) ? array_values( $run['flagged'] ) : array(), 0, self::MAX_FLAGGED );

		$history['runs'][] = $run;
		$history['runs']   = array_slice( $history['runs'], -self::MAX_RUNS );

		$totals                 = $history['totals'];
		$check                  = is_array( $run['previous_check'] ?? null ) ? $run['previous_check'] : array();
		$totals['runs']         = (int) $totals['runs'] + 1;
		$applied                = JobReaper::MODE_APPLY === ( $run['mode'] ?? '' );
		$totals['dry_run_runs'] = (int) $totals['dry_run_runs'] + ( $applied ? 0 : 1 );
		$totals['apply_runs']   = (int) $totals['apply_runs'] + ( $applied ? 1 : 0 );
		$totals['would_act']    = (int) $totals['would_act'] + (int) ( $run['would_act'] ?? 0 );
		$totals['acted']        = (int) $totals['acted'] + (int) ( $run['acted'] ?? 0 );
		$totals['fp_checked']   = (int) $totals['fp_checked'] + (int) ( $check['checked'] ?? 0 );
		$totals['fp_hits']      = (int) $totals['fp_hits'] + (int) ( $check['alive'] ?? 0 ) + (int) ( $check['progressed'] ?? 0 );
		if ( '' === (string) $totals['first_run_at'] ) {
			$totals['first_run_at'] = (string) ( $run['ran_at'] ?? '' );
		}
		$history['totals'] = $totals;

		update_option( self::OPTION, $history, false );

		return $history;
	}

	/**
	 * Newest-first slice of the run history.
	 *
	 * @param int $limit Maximum runs to return.
	 * @return list<array<string,mixed>>
	 */
	public static function recent( int $limit ): array {
		return array_slice( array_reverse( self::load()['runs'] ), 0, max( 1, $limit ) );
	}

	/**
	 * Lifetime false-positive rate: flagged jobs later seen alive or progressed.
	 *
	 * @param array<string,mixed> $totals Lifetime totals from load().
	 * @return float|null Rate in [0,1], or null before any flagged job has been re-checked.
	 */
	public static function falsePositiveRate( array $totals ): ?float {
		$checked = (int) ( $totals['fp_checked'] ?? 0 );
		return $checked > 0 ? (int) ( $totals['fp_hits'] ?? 0 ) / $checked : null;
	}
}
