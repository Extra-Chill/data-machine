<?php
/**
 * Daily Memory Service
 *
 * Provides structured read/write operations for daily agent memory files.
 * Files are stored at agent/daily/YYYY/MM/DD.md following the WordPress
 * Media Library date convention (wp-content/uploads/YYYY/MM/).
 *
 * Daily memory is append-only cognitive history — what happened each day.
 * It is NOT logs (operational telemetry). It persists agent knowledge and
 * is never auto-cleared.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/348
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

class DailyMemory {

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	/**
	 * @var string Base path for daily memory files (agent/daily/).
	 */
	private string $base_path;

	/**
	 * Effective scoped user ID.
	 *
	 * @since 0.37.0
	 * @var int
	 */
	private int $user_id;

	/**
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 * @since 0.41.0 Added $agent_id parameter for agent-first resolution.
	 *
	 * @param int $user_id  WordPress user ID. 0 = legacy shared directory.
	 * @param int $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 */
	public function __construct( int $user_id = 0, int $agent_id = 0 ) {
		$this->directory_manager = new DirectoryManager();
		$this->user_id           = $this->directory_manager->get_effective_user_id( $user_id );
		$agent_dir               = $this->directory_manager->resolve_agent_directory( array(
			'agent_id' => $agent_id,
			'user_id'  => $this->user_id,
		) );
		$this->base_path         = "{$agent_dir}/daily";
	}

	/**
	 * Get the base path for daily memory files.
	 *
	 * @return string
	 */
	public function get_base_path(): string {
		return $this->base_path;
	}

	/**
	 * Build the file path for a given date.
	 *
	 * @param string $year  Four-digit year (e.g. '2026').
	 * @param string $month Two-digit month (e.g. '02').
	 * @param string $day   Two-digit day (e.g. '24').
	 * @return string Full file path.
	 */
	public function get_file_path( string $year, string $month, string $day ): string {
		return "{$this->base_path}/{$year}/{$month}/{$day}.md";
	}

	/**
	 * Get today's file path.
	 *
	 * @return string Full file path for today.
	 */
	public function get_today_path(): string {
		return $this->get_file_path(
			gmdate( 'Y' ),
			gmdate( 'm' ),
			gmdate( 'd' )
		);
	}

	/**
	 * Check if a daily file exists.
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return bool
	 */
	public function exists( string $year, string $month, string $day ): bool {
		return file_exists( $this->get_file_path( $year, $month, $day ) );
	}

	/**
	 * Read a daily memory file.
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return array{success: bool, content?: string, date?: string, message?: string}
	 */
	public function read( string $year, string $month, string $day ): array {
		$fs        = FilesystemHelper::get();
		$file_path = $this->get_file_path( $year, $month, $day );

		if ( ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'No daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		$content = $fs->get_contents( $file_path );

		return array(
			'success' => true,
			'date'    => "{$year}-{$month}-{$day}",
			'content' => $content,
		);
	}

	/**
	 * Write (replace) content for a daily memory file.
	 *
	 * Creates the directory structure if needed.
	 *
	 * @param string $year    Four-digit year.
	 * @param string $month   Two-digit month.
	 * @param string $day     Two-digit day.
	 * @param string $content Full file content.
	 * @return array{success: bool, message: string}
	 */
	public function write( string $year, string $month, string $day, string $content ): array {
		$fs        = FilesystemHelper::get();
		$file_path = $this->get_file_path( $year, $month, $day );
		$dir       = dirname( $file_path );

		if ( ! $this->ensure_daily_directory( $dir ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to create directory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		$written = $fs->put_contents( $file_path, $content );

		if ( false === $written ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to write daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		FilesystemHelper::make_group_writable( $file_path );

		return array(
			'success' => true,
			'message' => sprintf( 'Daily memory for %s-%s-%s saved.', $year, $month, $day ),
		);
	}

	/**
	 * Append content to a daily memory file.
	 *
	 * Creates the file with a date header if it doesn't exist.
	 *
	 * @param string $year    Four-digit year.
	 * @param string $month   Two-digit month.
	 * @param string $day     Two-digit day.
	 * @param string $content Content to append.
	 * @return array{success: bool, message: string}
	 */
	public function append( string $year, string $month, string $day, string $content ): array {
		$fs        = FilesystemHelper::get();
		$file_path = $this->get_file_path( $year, $month, $day );
		$dir       = dirname( $file_path );

		if ( ! $this->ensure_daily_directory( $dir ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to create directory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		// If file doesn't exist, create with date header.
		if ( ! file_exists( $file_path ) ) {
			$header  = "# {$year}-{$month}-{$day}\n\n";
			$written = $fs->put_contents( $file_path, $header . $content . "\n" );
		} else {
			$_existing_content = $fs->get_contents( $file_path );
			$_existing_content = ( false !== $_existing_content ) ? $_existing_content : '';
			$written           = $fs->put_contents( $file_path, $_existing_content . $content . "\n" );
		}

		if ( false === $written ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to append to daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		FilesystemHelper::make_group_writable( $file_path );

		return array(
			'success' => true,
			'message' => sprintf( 'Content appended to daily memory for %s-%s-%s.', $year, $month, $day ),
		);
	}

	/**
	 * Delete a daily memory file.
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return array{success: bool, message: string}
	 */
	public function delete( string $year, string $month, string $day ): array {
		$file_path = $this->get_file_path( $year, $month, $day );

		if ( ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'No daily memory for %s-%s-%s to delete.', $year, $month, $day ),
			);
		}

		wp_delete_file( $file_path );

		// wp_delete_file returns void, check if file still exists.
		if ( file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to delete daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Daily memory for %s-%s-%s deleted.', $year, $month, $day ),
		);
	}

	/**
	 * List all daily memory files grouped by month.
	 *
	 * Returns structure: [ '2026/02' => [ '24', '25' ], '2026/01' => [ '15' ] ]
	 *
	 * @return array{success: bool, months: array<string, string[]>}
	 */
	public function list_all(): array {
		$months = array();

		if ( ! is_dir( $this->base_path ) ) {
			return array(
				'success' => true,
				'months'  => $months,
			);
		}

		$year_dirs = $this->scan_sorted_dirs( $this->base_path );

		foreach ( $year_dirs as $year ) {
			if ( ! preg_match( '/^\d{4}$/', $year ) ) {
				continue;
			}

			$year_path  = "{$this->base_path}/{$year}";
			$month_dirs = $this->scan_sorted_dirs( $year_path );

			foreach ( $month_dirs as $month ) {
				if ( ! preg_match( '/^\d{2}$/', $month ) ) {
					continue;
				}

				$month_path = "{$year_path}/{$month}";
				$day_files  = $this->scan_sorted_files( $month_path, '.md' );

				if ( ! empty( $day_files ) ) {
					$key            = "{$year}/{$month}";
					$months[ $key ] = $day_files;
				}
			}
		}

		// Sort by key descending (newest months first).
		krsort( $months );

		return array(
			'success' => true,
			'months'  => $months,
		);
	}

	/**
	 * List months that have daily memory files.
	 *
	 * @return array{success: bool, months: string[]}
	 */
	public function list_months(): array {
		$result = $this->list_all();
		return array(
			'success' => true,
			'months'  => array_keys( $result['months'] ),
		);
	}

	/**
	 * Search across daily memory files for a query string.
	 *
	 * Case-insensitive substring search with surrounding context lines.
	 * Optional date range filtering. Results capped at 50 matches.
	 *
	 * @param string      $query         Search term.
	 * @param string|null $from          Start date (YYYY-MM-DD, inclusive). Null for no lower bound.
	 * @param string|null $to            End date (YYYY-MM-DD, inclusive). Null for no upper bound.
	 * @param int         $context_lines Number of context lines above/below each match.
	 * @return array{success: bool, query: string, matches: array, match_count: int}
	 */
	public function search( string $query, ?string $from = null, ?string $to = null, int $context_lines = 2 ): array {
		$all         = $this->list_all();
		$matches     = array();
		$query_lower = mb_strtolower( $query );

		foreach ( $all['months'] as $month_key => $days ) {
			list( $year, $month ) = explode( '/', $month_key );

			foreach ( $days as $day ) {
				$date = "{$year}-{$month}-{$day}";

				// Apply date range filter.
				if ( null !== $from && $date < $from ) {
					continue;
				}
				if ( null !== $to && $date > $to ) {
					continue;
				}

				$result = $this->read( $year, $month, $day );
				if ( ! $result['success'] ) {
					continue;
				}

				$lines      = explode( "\n", $result['content'] );
				$line_count = count( $lines );

				foreach ( $lines as $index => $line ) {
					if ( false !== mb_strpos( mb_strtolower( $line ), $query_lower ) ) {
						$ctx_start = max( 0, $index - $context_lines );
						$ctx_end   = min( $line_count - 1, $index + $context_lines );
						$context   = array_slice( $lines, $ctx_start, $ctx_end - $ctx_start + 1 );

						$matches[] = array(
							'date'    => $date,
							'line'    => $index + 1,
							'content' => $line,
							'context' => implode( "\n", $context ),
						);
					}
				}

				// Early exit if we've hit the cap.
				if ( count( $matches ) >= 50 ) {
					break 2;
				}
			}
		}

		return array(
			'success'     => true,
			'query'       => $query,
			'matches'     => array_slice( $matches, 0, 50 ),
			'match_count' => count( $matches ),
		);
	}

	// =========================================================================
	// Internal Helpers
	// =========================================================================

	/**
	 * Ensure a daily memory directory exists with group-writable permissions.
	 *
	 * Creates all intermediate directories (daily/YYYY/MM/) and sets 0775
	 * so the coding agent user can also write daily memory files.
	 *
	 * @since 0.32.0
	 * @param string $dir Directory path.
	 * @return bool True if directory exists.
	 */
	private function ensure_daily_directory( string $dir ): bool {
		if ( ! $this->directory_manager->ensure_directory_exists( $dir ) ) {
			return false;
		}

		$perms = FilesystemHelper::AGENT_DIR_PERMISSIONS;

		// Walk up from the leaf (MM/) through YYYY/ and daily/ — set each.
		$current = $dir;
		$stops   = 3; // MM, YYYY, daily.
		for ( $i = 0; $i < $stops; $i++ ) {
			if ( is_dir( $current ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				chmod( $current, $perms );
			}
			$current = dirname( $current );
		}

		return true;
	}

	/**
	 * Parse a YYYY-MM-DD date string into year/month/day components.
	 *
	 * @param string $date Date string (e.g. '2026-02-24').
	 * @return array{year: string, month: string, day: string}|null Parsed parts or null if invalid.
	 */
	public static function parse_date( string $date ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return null;
		}

		return array(
			'year'  => $matches[1],
			'month' => $matches[2],
			'day'   => $matches[3],
		);
	}

	/**
	 * Scan a directory for sorted subdirectory names.
	 *
	 * @param string $path Directory to scan.
	 * @return string[] Sorted directory names.
	 */
	private function scan_sorted_dirs( string $path ): array {
		$entries = array();

		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			if ( is_dir( "{$path}/{$entry}" ) ) {
				$entries[] = $entry;
			}
		}
		sort( $entries );

		return $entries;
	}

	/**
	 * Scan a directory for sorted file names (without extension).
	 *
	 * @param string $path      Directory to scan.
	 * @param string $extension File extension to filter by (e.g. '.md').
	 * @return string[] Sorted file basenames (without extension).
	 */
	private function scan_sorted_files( string $path, string $extension ): array {
		$entries = array();

		$ext_len = strlen( $extension );

		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			if ( is_file( "{$path}/{$entry}" ) && substr( $entry, -$ext_len ) === $extension ) {
				$entries[] = substr( $entry, 0, -$ext_len );
			}
		}
		sort( $entries );

		return $entries;
	}
}
