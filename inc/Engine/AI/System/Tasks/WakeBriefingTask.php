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
	 * Default disk-pressure thresholds. Surface a disk line when free space
	 * crosses EITHER bar (whichever triggers first): below this fraction of
	 * total, or below this many free bytes. Both are independently filterable.
	 */
	private const DISK_MIN_FREE_PCT   = 0.15;          // 15% free.
	private const DISK_MIN_FREE_BYTES = 20000000000;   // 20 GB free.

	/**
	 * Default Action Scheduler bloat ceilings. Surface a line when either the
	 * `actionscheduler_actions` or `actionscheduler_logs` table crosses EITHER
	 * the row ceiling or the byte ceiling. Both are filterable.
	 */
	private const AS_MAX_ROWS  = 1000000;     // 1M rows.
	private const AS_MAX_BYTES = 2000000000;  // 2 GB.

	/**
	 * Max bytes of the debug.log tail to scan for PHP fatals. Bounds the read
	 * so a runaway multi-GB log never blows the task up. 5 MiB of tail is far
	 * more than a rolling window of fatals would ever occupy.
	 */
	private const FATAL_TAIL_BYTES = 5242880; // 5 MiB.

	/**
	 * Tracks whether the disk-pressure line has already been emitted in the
	 * current run. Disk is a host-global signal, but gatherSiteSignals() runs
	 * once per blog under the network switch_to_blog loop — without this guard
	 * the same disk warning would repeat on every site. Emit it once.
	 *
	 * @var bool
	 */
	private bool $disk_emitted = false;

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

		$agent_id = (int) ( $params['agent_id'] ?? 0 );
		$user_id  = (int) ( $params['user_id'] ?? 0 );
		$scope    = $this->resolveScope( $params, $agent_id );

		$signals = ( 'network' === $scope )
			? $this->gatherNetworkSignals( $since )
			: $this->gatherSiteSignals( $since );

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

		$content = $this->render( $signals, $window_hours, $scope );

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
				'scope'        => $scope,
				'signal_count' => count( $signals ),
				'bytes'        => strlen( $content ),
			)
		);
	}

	/**
	 * Resolve the briefing scope (`site` | `network`) for this run.
	 *
	 * Resolution order: explicit param → per-agent config → global setting →
	 * default `site`. `network` is only honored on multisite; on single-site
	 * installs it falls back to `site`. This keeps the task multisite-agnostic
	 * by default — single-site installs are never affected — while letting a
	 * network-managing agent opt into a whole-network briefing.
	 *
	 * @param array $params   Task params.
	 * @param int   $agent_id Owning agent ID (0 when none).
	 * @return string `site` or `network`.
	 */
	private function resolveScope( array $params, int $agent_id ): string {
		$scope = '';

		if ( isset( $params['scope'] ) && is_string( $params['scope'] ) ) {
			$scope = $params['scope'];
		}

		if ( '' === $scope && $agent_id > 0 ) {
			$agent  = ( new \DataMachine\Core\Database\Agents\Agents() )->get_agent( $agent_id );
			$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
			if ( isset( $config['wake_briefing_scope'] ) && is_string( $config['wake_briefing_scope'] ) ) {
				$scope = $config['wake_briefing_scope'];
			}
		}

		if ( '' === $scope ) {
			$global = \DataMachine\Core\PluginSettings::get( 'wake_briefing_scope', '' );
			if ( is_string( $global ) ) {
				$scope = $global;
			}
		}

		$scope = 'network' === $scope ? 'network' : 'site';

		if ( 'network' === $scope && ! is_multisite() ) {
			$scope = 'site';
		}

		return $scope;
	}

	/**
	 * Gather threshold-crossing signal lines for the current blog only.
	 *
	 * @param string $since Window start (UTC).
	 * @return string[] Terse signal lines (may be empty).
	 */
	private function gatherSiteSignals( string $since ): array {
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

		$fatals = $this->getPhpFatals( $since );
		if ( ! empty( $fatals ) ) {
			$signals[] = $fatals;
		}

		// Disk is host-global; emit it once per run, not once per blog.
		if ( ! $this->disk_emitted ) {
			$disk = $this->getDiskPressure();
			if ( ! empty( $disk ) ) {
				$signals[]          = $disk;
				$this->disk_emitted = true;
			}
		}

		$as_bloat = $this->getActionSchedulerBloat();
		if ( ! empty( $as_bloat ) ) {
			$signals[] = $as_bloat;
		}

		return $signals;
	}

	/**
	 * Gather signals across every site in the network, one labeled line per
	 * site that has anything to report.
	 *
	 * Runs the same per-site pulse queries under switch_to_blog() and collapses
	 * each site's signals into a single site-tagged line. Sites with nothing to
	 * report are omitted (the network briefing should not list quiet sites — it
	 * would defeat the 3-second-glance bar on a large network).
	 *
	 * @param string $since Window start (UTC).
	 * @return string[] Per-site signal lines (may be empty when the whole
	 *                  network is quiet).
	 */
	private function gatherNetworkSignals( string $since ): array {
		$lines = array();

		$sites = get_sites(
			array(
				'number'   => 0,
				'fields'   => 'ids',
				'spam'     => 0,
				'deleted'  => 0,
				'archived' => 0,
			)
		);

		foreach ( $sites as $blog_id ) {
			$blog_id = (int) $blog_id;
			switch_to_blog( $blog_id );
			try {
				$site_signals = $this->gatherSiteSignals( $since );
				$label        = $this->siteLabel( $blog_id );
			} finally {
				restore_current_blog();
			}

			if ( empty( $site_signals ) ) {
				continue;
			}

			$lines[] = sprintf( '**%s** — %s', $label, implode( ' · ', $site_signals ) );
		}

		return $lines;
	}

	/**
	 * Human-readable site label (host, falling back to blog id).
	 *
	 * @param int $blog_id Blog ID (must be the current switched blog).
	 * @return string
	 */
	private function siteLabel( int $blog_id ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : ( 'blog ' . $blog_id );
	}

	/**
	 * Render the digest. Ruthless terseness: one grouped line per signal,
	 * a single quiet line when nothing crosses the bar.
	 *
	 * @param string[] $signals      Threshold-crossing signal lines.
	 * @param int      $window_hours Window size in hours.
	 * @return string Markdown content.
	 */
	private function render( array $signals, int $window_hours, string $scope = 'site' ): string {
		$reach   = 'network' === $scope ? 'across the network' : 'on this site';
		$header  = "# Wake Briefing\n\n";
		$header .= sprintf( "_What changed in the last %dh %s (recomputed each session; not personal to one session)._\n\n", $window_hours, $reach );

		if ( empty( $signals ) ) {
			$where = 'network' === $scope ? 'across the network' : 'here';
			return $header . "Nothing notable in the last {$window_hours}h {$where}.\n";
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
	 * New PHP fatals from the live debug.log tail, grouped by normalized
	 * signature. The single highest-value infra signal: a `claim_actions`
	 * fatal storm leaves ZERO rows in datamachine_logs — it lives only in
	 * debug.log — so getGroupedErrors() is structurally blind to it.
	 *
	 * This reads only the tail of the current debug.log (bounded to
	 * FATAL_TAIL_BYTES), folds multi-line stack traces into one entry, filters
	 * to the rolling window by parsed timestamp, normalizes each fatal to a
	 * stable signature (severity + file + numeric-stripped message), and emits
	 * a single grouped line with the total count and the top signature.
	 *
	 * Fail-soft: any unreadable/absent log, or any error, returns ''. Never
	 * throws — the briefing must compose even when the log is missing.
	 *
	 * @param string $since Window start (UTC `Y-m-d H:i:s`).
	 * @return string Single grouped line, or '' when no fatals in-window.
	 */
	private function getPhpFatals( string $since ): string {
		$path = $this->resolveDebugLogPath();
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$since_ts = strtotime( $since . ' UTC' );
		if ( false === $since_ts ) {
			$since_ts = 0;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Tail-reading debug.log requires direct fopen/fseek; WP_Filesystem offers no seek API.
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return '';
		}

		$size = (int) filesize( $path );
		if ( $size > self::FATAL_TAIL_BYTES ) {
			fseek( $handle, $size - self::FATAL_TAIL_BYTES );
			fgets( $handle ); // Discard the partial first line after the seek.
		}

		$groups  = array(); // signature => [ 'count' => int, 'sample' => string ].
		$total   = 0;
		$current = null;

		$flush = function () use ( &$current, &$groups, &$total, $since_ts ) {
			if ( null === $current ) {
				return;
			}
			$entry   = $current;
			$current = null;

			if ( null !== $entry['ts'] && $entry['ts'] < $since_ts ) {
				return;
			}

			$norm = $this->normalizeFatal( $entry['raw'] );
			if ( null === $norm ) {
				return;
			}

			++$total;
			if ( ! isset( $groups[ $norm['signature'] ] ) ) {
				$groups[ $norm['signature'] ] = array(
					'count'  => 0,
					'sample' => $norm['sample'],
				);
			}
			++$groups[ $norm['signature'] ]['count'];
		};

		for ( $line = fgets( $handle ); false !== $line; $line = fgets( $handle ) ) {
			$ts = $this->parseLogTimestamp( $line );
			if ( null !== $ts || ( '' !== $line && '[' === $line[0] ) ) {
				$flush();
				$current = array(
					'ts'  => $ts,
					'raw' => rtrim( $line, "\r\n" ),
				);
				continue;
			}
			if ( null !== $current ) {
				$current['raw'] .= "\n" . rtrim( $line, "\r\n" );
			}
		}
		$flush();
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 0 === $total || empty( $groups ) ) {
			return '';
		}

		uasort(
			$groups,
			static fn( $a, $b ) => $b['count'] <=> $a['count']
		);
		$top = reset( $groups );

		return sprintf(
			'⚠ %d PHP fatal(s) in debug.log, top: "%s" ×%d.',
			$total,
			$this->truncate( (string) $top['sample'], 80 ),
			(int) $top['count']
		);
	}

	/**
	 * Resolve the active PHP error log path robustly.
	 *
	 * WP_DEBUG_LOG may be a bool (true => canonical wp-content/debug.log) or an
	 * explicit path string. We also honor a real-file `error_log` ini target.
	 * Always falls back to WP_CONTENT_DIR/debug.log so a sane default exists.
	 *
	 * @return string Absolute path (may not exist), or '' when undeterminable.
	 */
	private function resolveDebugLogPath(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}

		$ini_path = ini_get( 'error_log' );
		if ( is_string( $ini_path ) && '' !== $ini_path && 'syslog' !== $ini_path && false === strpos( $ini_path, '://' ) ) {
			return $ini_path;
		}

		if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
			return rtrim( WP_CONTENT_DIR, '/\\' ) . '/debug.log';
		}

		return '';
	}

	/**
	 * Extract a Unix timestamp from a debug.log line prefix.
	 *
	 * Matches the WordPress/PHP default format: [DD-Mon-YYYY HH:MM:SS UTC].
	 *
	 * @param string $line Raw log line.
	 * @return int|null Unix timestamp, or null when the line has no prefix.
	 */
	private function parseLogTimestamp( string $line ): ?int {
		if ( ! preg_match( '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z]+)?)\]/', $line, $m ) ) {
			return null;
		}
		$ts = strtotime( $m[1] );
		return false === $ts ? null : $ts;
	}

	/**
	 * Normalize a raw (possibly multi-line) log entry to a stable FATAL
	 * signature. Returns null for non-fatal severities (warnings, notices,
	 * deprecations) so WAKE only surfaces the reddest signal.
	 *
	 * @param string $raw Raw log entry.
	 * @return array{signature:string, sample:string}|null
	 */
	private function normalizeFatal( string $raw ): ?array {
		$first_line = strtok( $raw, "\n" );
		if ( false === $first_line ) {
			$first_line = $raw;
		}

		$body = (string) preg_replace( '/^\[[^\]]*\]\s*/', '', $first_line );

		// Fatals and parse errors only — the highest-signal, lowest-noise band.
		if ( ! preg_match( '/\bPHP (Parse error|Fatal error|Recoverable fatal error)\b:?/i', $body ) ) {
			return null;
		}

		$message = trim( (string) preg_replace( '/^.*?\bPHP [^:]+:\s*/i', '', $body ) );
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		$file = '';
		if ( preg_match( '/thrown in (.+?) on line (\d+)/', $raw, $tm ) ) {
			$file = basename( $tm[1] );
		} elseif ( preg_match( '/ in (.+?) on line (\d+)/', $first_line, $fm ) ) {
			$file = basename( $fm[1] );
		}

		// Build the signature off the numeric-stripped message + file basename
		// so request-specific ids/addresses collapse into one group.
		$norm = (string) preg_replace( '/ in .+? on line \d+.*$/', '', $message );
		$norm = (string) preg_replace( '/0x[0-9a-fA-F]+/', '0xADDR', $norm );
		$norm = (string) preg_replace( '/\d+/', 'N', $norm );
		$norm = trim( (string) preg_replace( '/\s+/', ' ', $norm ) );

		$signature = substr( md5( $file . '|' . $norm ), 0, 12 );

		$sample = '' !== $file ? $file . ': ' . $norm : $norm;

		return array(
			'signature' => $signature,
			'sample'    => $sample,
		);
	}

	/**
	 * Disk pressure on the filesystem holding WordPress. One line when free
	 * space crosses EITHER the percent floor or the byte floor. Both bars are
	 * filterable. Fail-soft: returns '' if the filesystem can't be probed.
	 *
	 * @return string Single line, or '' when disk is healthy.
	 */
	private function getDiskPressure(): string {
		$root = defined( 'ABSPATH' ) ? ABSPATH : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '/' );

		$free  = @disk_free_space( $root );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = @disk_total_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_numeric( $free ) || ! is_numeric( $total ) || $total <= 0 ) {
			return '';
		}

		$free  = (float) $free;
		$total = (float) $total;

		/**
		 * Filter the disk-pressure minimum-free-fraction threshold.
		 *
		 * @param float $pct Default 0.15 (15%).
		 */
		$min_pct = (float) apply_filters( 'datamachine_wake_briefing_disk_min_free_pct', self::DISK_MIN_FREE_PCT );

		/**
		 * Filter the disk-pressure minimum-free-bytes threshold.
		 *
		 * @param float $bytes Default 20000000000 (20 GB).
		 */
		$min_bytes = (float) apply_filters( 'datamachine_wake_briefing_disk_min_free_bytes', (float) self::DISK_MIN_FREE_BYTES );

		$free_fraction = $free / $total;

		if ( $free_fraction >= $min_pct && $free >= $min_bytes ) {
			return '';
		}

		$used_pct = (int) round( ( 1 - $free_fraction ) * 100 );

		return sprintf(
			'⚠ Disk %d%% full (%s free).',
			$used_pct,
			$this->formatBytes( $free )
		);
	}

	/**
	 * Action Scheduler table bloat for the current blog. One line when either
	 * `actionscheduler_actions` or `actionscheduler_logs` crosses EITHER the
	 * row ceiling or the byte ceiling. Reads information_schema.TABLES (cheap;
	 * row counts are approximate but plenty for a "this is runaway" alarm).
	 * Both ceilings are filterable. Fail-soft: returns '' on any error.
	 *
	 * @return string Single line, or '' when both tables are within bounds.
	 */
	private function getActionSchedulerBloat(): string {
		global $wpdb;

		/**
		 * Filter the Action Scheduler row-count ceiling.
		 *
		 * @param int $rows Default 1,000,000.
		 */
		$max_rows = (int) apply_filters( 'datamachine_wake_briefing_as_max_rows', self::AS_MAX_ROWS );

		/**
		 * Filter the Action Scheduler byte (data + index) ceiling.
		 *
		 * @param int $bytes Default 2,000,000,000 (2 GB).
		 */
		$max_bytes = (int) apply_filters( 'datamachine_wake_briefing_as_max_bytes', self::AS_MAX_BYTES );

		$tables = array(
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'actionscheduler_logs',
		);

		$offenders = array();

		foreach ( $tables as $table ) {
			// phpcs:disable WordPress.DB.PreparedSQL -- Query is built via $wpdb->prepare() above; sizing probe of information_schema.
			$query = $wpdb->prepare(
				'SELECT table_rows AS n, (data_length + index_length) AS bytes
				 FROM information_schema.TABLES
				 WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			);

			$row = $wpdb->get_row( $query, ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL

			if ( empty( $row ) ) {
				continue;
			}

			$rows  = (int) $row['n'];
			$bytes = (int) $row['bytes'];

			if ( $rows <= $max_rows && $bytes <= $max_bytes ) {
				continue;
			}

			$offenders[] = sprintf(
				'%s %s rows / %s',
				$table,
				$this->formatCount( $rows ),
				$this->formatBytes( (float) $bytes )
			);
		}

		if ( empty( $offenders ) ) {
			return '';
		}

		return '⚠ Action Scheduler bloat: ' . implode( ', ', $offenders ) . '.';
	}

	/**
	 * Format a byte count into a compact human-readable string (GB/MB/etc).
	 *
	 * @param float $bytes Byte count.
	 * @return string
	 */
	private function formatBytes( float $bytes ): string {
		$units      = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$unit_count = count( $units );
		$i          = 0;
		while ( $bytes >= 1000 && $i < $unit_count - 1 ) {
			$bytes /= 1000;
			++$i;
		}
		// Whole numbers for raw bytes/KB/MB; one decimal from GB up so a
		// multi-GB runaway reads precisely (e.g. "23.9GB", not "24GB").
		$precision = $i >= 3 ? 1 : 0;
		return number_format( $bytes, $precision ) . $units[ $i ];
	}

	/**
	 * Format a large integer into a compact human-readable string (e.g. 28.1M).
	 *
	 * @param int $n Count.
	 * @return string
	 */
	private function formatCount( int $n ): string {
		if ( $n >= 1000000 ) {
			return number_format( $n / 1000000, 1 ) . 'M';
		}
		if ( $n >= 1000 ) {
			return number_format( $n / 1000, 1 ) . 'K';
		}
		return (string) $n;
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
