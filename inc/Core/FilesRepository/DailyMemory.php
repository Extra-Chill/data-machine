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
 * Persistence is delegated to the {@see AgentMemoryStoreInterface}
 * registered for the agent layer (resolved through the
 * `datamachine_memory_store` filter). Daily files are addressed as
 * relative paths within the agent layer (`daily/YYYY/MM/DD.md`), so a
 * single store swap covers MEMORY.md, daily memory, and context files
 * uniformly.
 *
 * Plugins that need a completely different daily backend (separate from
 * the memory store swap) can still implement {@see DailyMemoryStorage}
 * and register it via the `datamachine_daily_memory_storage` filter —
 * this class is the default implementation.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.32.0
 * @since next   Whole-file IO delegated to AgentMemory facade / store.
 * @see https://github.com/Extra-Chill/data-machine/issues/348
 */

namespace DataMachine\Core\FilesRepository;

use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class DailyMemory implements DailyMemoryStorage {

	/**
	 * Path prefix within the agent layer that scopes all daily files.
	 *
	 * @var string
	 */
	private const PREFIX = 'daily';

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	/**
	 * Effective scoped user ID.
	 *
	 * @since 0.37.0
	 * @var int
	 */
	private int $user_id;

	/**
	 * Agent ID for direct scope resolution. 0 = resolve from user_id.
	 *
	 * @since next
	 * @var int
	 */
	private int $agent_id;

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
		$this->agent_id          = $agent_id;
	}

	/**
	 * Get the base path for daily memory files.
	 *
	 * Disk-only convenience — returns the directory daily files would
	 * live in if backed by the disk store. Non-disk stores persist
	 * elsewhere; the path may not exist in those environments.
	 *
	 * @return string
	 */
	public function get_base_path(): string {
		$agent_dir = $this->directory_manager->resolve_agent_directory( array(
			'agent_id' => $this->agent_id,
			'user_id'  => $this->user_id,
		) );
		return "{$agent_dir}/" . self::PREFIX;
	}

	/**
	 * Build the file path for a given date.
	 *
	 * Disk-only convenience — see {@see self::get_base_path()}.
	 *
	 * @param string $year  Four-digit year (e.g. '2026').
	 * @param string $month Two-digit month (e.g. '02').
	 * @param string $day   Two-digit day (e.g. '24').
	 * @return string Full file path.
	 */
	public function get_file_path( string $year, string $month, string $day ): string {
		return $this->get_base_path() . "/{$year}/{$month}/{$day}.md";
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
	 * Build the relative filename within the agent layer for a given date.
	 *
	 * @since next
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return string Relative filename (e.g. 'daily/2026/04/17.md').
	 */
	private function relative_filename( string $year, string $month, string $day ): string {
		return self::PREFIX . "/{$year}/{$month}/{$day}.md";
	}

	/**
	 * Build an AgentMemory facade for a given daily date.
	 */
	private function memory_for( string $year, string $month, string $day ): AgentMemory {
		return new AgentMemory(
			$this->user_id,
			$this->agent_id,
			$this->relative_filename( $year, $month, $day ),
			MemoryFileRegistry::LAYER_AGENT
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
		return $this->memory_for( $year, $month, $day )->exists();
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
		$result = $this->memory_for( $year, $month, $day )->read();

		if ( ! $result->exists ) {
			return array(
				'success' => false,
				'message' => sprintf( 'No daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

		return array(
			'success' => true,
			'date'    => "{$year}-{$month}-{$day}",
			'content' => $result->content,
		);
	}

	/**
	 * Write (replace) content for a daily memory file.
	 *
	 * @param string $year    Four-digit year.
	 * @param string $month   Two-digit month.
	 * @param string $day     Two-digit day.
	 * @param string $content Full file content.
	 * @return array{success: bool, message: string}
	 */
	public function write( string $year, string $month, string $day, string $content ): array {
		$result = $this->memory_for( $year, $month, $day )->replace_all( $content );

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'message' => $result['message'] ?? sprintf( 'Failed to write daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

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
		$memory  = $this->memory_for( $year, $month, $day );
		$current = $memory->read();

		if ( ! $current->exists ) {
			// First write of the day: scaffold a date header inline.
			// (The previous ScaffoldAbilities path was disk-coupled; we
			// keep the same header format to preserve agent-readable
			// structure across stores.)
			$header  = "# Daily Memory: {$year}-{$month}-{$day}\n\n";
			$payload = $header . $content . "\n";
		} else {
			$payload = $current->content . $content . "\n";
		}

		$result = $memory->replace_all( $payload );

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'message' => $result['message'] ?? sprintf( 'Failed to append to daily memory for %s-%s-%s.', $year, $month, $day ),
			);
		}

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
		$memory = $this->memory_for( $year, $month, $day );

		if ( ! $memory->exists() ) {
			return array(
				'success' => false,
				'message' => sprintf( 'No daily memory for %s-%s-%s to delete.', $year, $month, $day ),
			);
		}

		$result = $memory->delete();

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'message' => $result['message'] ?? sprintf( 'Failed to delete daily memory for %s-%s-%s.', $year, $month, $day ),
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
		$entries = AgentMemory::list_subtree(
			MemoryFileRegistry::LAYER_AGENT,
			$this->user_id,
			$this->agent_id,
			self::PREFIX
		);

		$months = array();

		foreach ( $entries as $entry ) {
			// Filename is relative to the layer root, e.g. 'daily/2026/04/17.md'.
			// Strip the prefix and the .md extension, then split into Y/M/D.
			$path = substr( $entry->filename, strlen( self::PREFIX ) + 1 );

			if ( '.md' !== substr( $path, -3 ) ) {
				continue;
			}

			$path  = substr( $path, 0, -3 );
			$parts = explode( '/', $path );

			if ( 3 !== count( $parts ) ) {
				continue;
			}

			[ $year, $month, $day ] = $parts;

			if ( ! preg_match( '/^\d{4}$/', $year )
				|| ! preg_match( '/^\d{2}$/', $month )
				|| ! preg_match( '/^\d{2}$/', $day ) ) {
				continue;
			}

			$key = "{$year}/{$month}";
			if ( ! isset( $months[ $key ] ) ) {
				$months[ $key ] = array();
			}
			$months[ $key ][] = $day;
		}

		// Days within each month sorted ascending; months descending (newest first).
		foreach ( $months as $key => $days ) {
			sort( $days );
			$months[ $key ] = $days;
		}
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
			[ $year, $month ] = explode( '/', $month_key );

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
}
