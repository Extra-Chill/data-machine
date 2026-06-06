<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Data Machine owns these operational tables and the wake briefing requires fresh runtime state read directly.
/**
 * Wake Briefing Task
 *
 * System agent task that composes a terse "what changed recently" digest and
 * writes it to a dedicated, always-injected memory file (WAKE.md). The goal is
 * felt continuity on wake: when an agent session starts, its context already
 * holds a short glance at anything red that happened across the install
 * recently — failing tasks, stuck jobs, new error signatures, mid-flight work.
 *
 * ## Stateless rolling window (no shared clock)
 *
 * An agent is not a single linear consumer — it is a fan-out of many concurrent
 * sessions. A single mutable "last_wake" timestamp would be a race: whichever
 * session composed first would reset the clock and blind every concurrent
 * session. So this task keeps NO state. The digest is always "what changed in
 * the last N hours", recomputed from the current time on every run. There is
 * nothing to race because there is nothing to reset.
 *
 * ## Signal discipline is the feature
 *
 * The briefing rides in every session's context window, so every line is a
 * permanent tax. The bar is ruthless terseness:
 *   - Repeated/identical events are GROUPED into one line with a count, never
 *     enumerated. 213 identical errors render as one line.
 *   - Only threshold-crossing items appear (repeated failures, stuck jobs,
 *     deploy-relevant signals). Single transient events are dropped.
 *   - The empty state is a single quiet line, not a green-checkmark dashboard.
 *
 * This task is deterministic — pure SQL reads + markdown formatting. No AI call.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @see https://github.com/Extra-Chill/data-machine/issues/2557
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\FilesRepository\AgentMemory;

class WakeBriefingTask extends SystemTask {

	/**
	 * Memory filename the digest is written to and injected from.
	 */
	public const BRIEFING_FILENAME = 'WAKE.md';

	/**
	 * Default rolling-window size, in hours.
	 */
	private const DEFAULT_WINDOW_HOURS = 24;

	/**
	 * A failing task_type must reach this many failures within the window
	 * before it is surfaced. Single transient failures are noise.
	 */
	private const REPEATED_FAILURE_THRESHOLD = 3;

	/**
	 * This is pure site-scoped maintenance reading operational tables and
	 * writing a file. It does not act as an agent or invoke agent-scoped
	 * abilities, so it opts out of the agent-context gate (see
	 * data-machine-code#564 for the failure mode when this is left default).
	 *
	 * @return bool
	 */
	public function requiresAgentContext(): bool {
		return false;
	}

	/**
	 * Compose the rolling-window digest and write WAKE.md.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters. Optional: window_hours.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$window_hours = (int) ( $params['window_hours'] ?? self::DEFAULT_WINDOW_HOURS );
		if ( $window_hours < 1 ) {
			$window_hours = self::DEFAULT_WINDOW_HOURS;
		}

		/**
		 * Filter the wake-briefing rolling-window size in hours.
		 *
		 * @param int   $window_hours Default 24.
		 * @param array $params       Task params.
		 */
		$window_hours = (int) apply_filters( 'datamachine_wake_briefing_window_hours', $window_hours, $params );
		$since        = gmdate( 'Y-m-d H:i:s', time() - ( $window_hours * HOUR_IN_SECONDS ) );

		$signals = array();

		$failing = $this->getRepeatedJobFailures( $since );
		if ( ! empty( $failing ) ) {
			$signals[] = $failing;
		}

		$stuck = $this->getStuckJobs( $since );
		if ( ! empty( $stuck ) ) {
			$signals[] = $stuck;
		}

		$errors = $this->getGroupedErrors( $since );
		if ( ! empty( $errors ) ) {
			$signals[] = $errors;
		}

		/**
		 * Filter the composed wake-briefing signal lines before rendering.
		 *
		 * Each entry is a single terse markdown line. Integrations may append
		 * their own threshold-crossing signals (e.g. deploy drift) here.
		 *
		 * @param string[] $signals      Threshold-crossing signal lines.
		 * @param string   $since        Window start (UTC `Y-m-d H:i:s`).
		 * @param int      $window_hours Window size in hours.
		 */
		$signals = (array) apply_filters( 'datamachine_wake_briefing_signals', $signals, $since, $window_hours );

		$content = $this->render( $signals, $window_hours );

		$user_id  = (int) ( $params['user_id'] ?? 0 );
		$agent_id = (int) ( $params['agent_id'] ?? 0 );

		$memory = new AgentMemory( $user_id, $agent_id, self::BRIEFING_FILENAME );
		$write  = $memory->replace_all( $content );

		if ( empty( $write['success'] ) ) {
			$this->failJob( $jobId, $write['message'] ?? 'Failed to write wake briefing.' );
			return;
		}

		$this->completeJob(
			$jobId,
			array(
				'window_hours' => $window_hours,
				'signal_count' => count( $signals ),
				'bytes'        => strlen( $content ),
			)
		);
	}

	/**
	 * Render the digest. Ruthless terseness: one grouped line per signal,
	 * a single quiet line when nothing crosses the bar.
	 *
	 * @param string[] $signals      Threshold-crossing signal lines.
	 * @param int      $window_hours Window size in hours.
	 * @return string Markdown content.
	 */
	private function render( array $signals, int $window_hours ): string {
		$header  = "# Wake Briefing\n\n";
		$header .= sprintf( "_What changed in the last %dh (recomputed each session; not personal to one session)._\n\n", $window_hours );

		if ( empty( $signals ) ) {
			return $header . "Nothing notable in the last {$window_hours}h.\n";
		}

		return $header . implode( "\n", array_map( static fn( $line ) => '- ' . $line, $signals ) ) . "\n";
	}

	/**
	 * Job failures grouped by task_type, surfaced only when a single
	 * task_type crossed the repeated-failure threshold within the window.
	 *
	 * @param string $since Window start (UTC).
	 * @return string Single grouped line, or '' when nothing crosses the bar.
	 */
	private function getRepeatedJobFailures( string $since ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(task_type, ''), 'unknown') AS task_type, COUNT(*) AS n
				 FROM {$table}
				 WHERE created_at >= %s AND status LIKE %s
				 GROUP BY task_type
				 HAVING n >= %d
				 ORDER BY n DESC",
				$since,
				'failed%',
				self::REPEATED_FAILURE_THRESHOLD
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $rows ) ) {
			return '';
		}

		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = sprintf( '`%s` ×%d', $row['task_type'], (int) $row['n'] );
		}

		return sprintf(
			'⚠ %d task type(s) failing repeatedly — %s. Investigate?',
			count( $rows ),
			implode( ', ', $parts )
		);
	}

	/**
	 * Jobs stuck in processing — created in-window but never completed.
	 *
	 * @param string $since Window start (UTC).
	 * @return string Single grouped line, or ''.
	 */
	private function getStuckJobs( string $since ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE created_at >= %s AND status = %s",
				$since,
				'processing'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( $count < 1 ) {
			return '';
		}

		return sprintf( '⚠ %d job(s) stuck in processing.', $count );
	}

	/**
	 * Error-log entries grouped by message signature. Surfaces only the
	 * grouped count, never individual rows — alarm fatigue is the enemy.
	 *
	 * @param string $since Window start (UTC).
	 * @return string Single grouped line, or ''.
	 */
	private function getGroupedErrors( string $since ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_logs';

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT message, COUNT(*) AS n
				 FROM {$table}
				 WHERE created_at >= %s AND level = %s
				 GROUP BY message
				 ORDER BY n DESC
				 LIMIT 3",
				$since,
				'ERROR'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $rows ) ) {
			return '';
		}

		$total     = 0;
		$top       = $rows[0];
		$signature = $this->truncate( (string) $top['message'], 80 );
		foreach ( $rows as $row ) {
			$total += (int) $row['n'];
		}

		return sprintf(
			'⚠ %d error(s) logged, top signature ×%d: "%s".',
			$total,
			(int) $top['n'],
			$signature
		);
	}

	/**
	 * Truncate a string to a max length with an ellipsis.
	 *
	 * @param string $text Input.
	 * @param int    $max  Max length.
	 * @return string
	 */
	private function truncate( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTaskType(): string {
		return 'wake_briefing';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Wake Briefing',
			'description'     => 'Composes a terse rolling-window digest of recent threshold-crossing activity (failing tasks, stuck jobs, grouped errors) into WAKE.md for passive injection into agent context.',
			'setting_key'     => 'wake_briefing_enabled',
			'default_enabled' => false,
			'supports_run'    => true,
		);
	}
}
