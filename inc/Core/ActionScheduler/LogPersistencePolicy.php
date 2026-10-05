<?php
/**
 * Action Scheduler log persistence policy for file-backed database runtimes.
 *
 * @package DataMachine\Core\ActionScheduler
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds the Action Scheduler log snapshot without changing its live table.
 */
class LogPersistencePolicy {

	public const DEFAULT_ROW_BUDGET = 10000;

	private const MAXIMUM_ROW_BUDGET = 100000;
	private const TABLE_SUFFIX       = 'actionscheduler_logs';

	/**
	 * Register the optional Markdown Database Integration query policy.
	 *
	 * Registering a WordPress filter is inert unless an integration applies it.
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'markdown_db_persistent_table_query', array( self::class, 'filterPersistentTableQuery' ), 100, 4 );
	}

	/**
	 * Retain a deterministic recent log window in chronological output order.
	 *
	 * The supplied query already owns table quoting and prefix handling. Wrapping
	 * it also preserves predicates or projections added by earlier policy owners.
	 *
	 * @param mixed      $query        Persistence query supplied by Markdown Database Integration.
	 * @param string     $table_suffix Table name without the WordPress prefix.
	 * @param string     $table        Full table name.
	 * @param array|bool|null $policy  Persistence policy, if configured.
	 * @return mixed
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The filter contract supplies four arguments to this callback.
	public static function filterPersistentTableQuery( $query, string $table_suffix, string $table, $policy ) {
		if ( self::TABLE_SUFFIX !== $table_suffix || ! is_string( $query ) || '' === $query ) {
			return $query;
		}

		$budget = self::rowBudget();

		return sprintf(
			'SELECT * FROM ( SELECT * FROM ( %s ) AS datamachine_action_scheduler_log_source ORDER BY log_date_gmt DESC, log_id DESC LIMIT %d ) AS datamachine_action_scheduler_log_window ORDER BY log_date_gmt ASC, log_id ASC',
			$query,
			$budget
		);
	}

	/**
	 * Get the validated maximum number of rows retained in a log snapshot.
	 */
	public static function rowBudget(): int {
		$budget = function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_action_scheduler_log_persistence_row_budget', self::DEFAULT_ROW_BUDGET )
			: self::DEFAULT_ROW_BUDGET;

		if ( is_int( $budget ) ) {
			return min( self::MAXIMUM_ROW_BUDGET, max( 1, $budget ) );
		}

		if ( is_string( $budget ) && 1 === preg_match( '/\A\d+\z/D', $budget ) ) {
			return min( self::MAXIMUM_ROW_BUDGET, max( 1, (int) $budget ) );
		}

		return self::DEFAULT_ROW_BUDGET;
	}
}
