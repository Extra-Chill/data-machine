<?php
/**
 * Daily Memory Storage Interface
 *
 * Contract for daily memory storage backends used by the Daily Memory
 * abilities. The default implementation is {@see DailyMemory}, which
 * delegates persistence to the active {@see AgentMemoryStoreInterface}
 * resolved through `agents_api_memory_store`.
 *
 * `datamachine_daily_memory_storage` is a narrower escape hatch for
 * replacing the ability-level daily memory backend entirely. When that
 * filter returns a DailyMemoryStorage implementation, it takes precedence
 * for Daily Memory abilities. Otherwise DailyMemory remains active and
 * daily files are stored through the unified memory-store seam as
 * `daily/YYYY/MM/DD.md` in the agent layer.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.47.0
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

interface DailyMemoryStorage {

	/**
	 * Read a daily memory entry.
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return array{success: bool, content?: string, date?: string, message?: string}
	 */
	public function read( string $year, string $month, string $day ): array;

	/**
	 * Write (replace) content for a daily memory entry.
	 *
	 * @param string $year    Four-digit year.
	 * @param string $month   Two-digit month.
	 * @param string $day     Two-digit day.
	 * @param string $content Full content.
	 * @return array{success: bool, message: string}
	 */
	public function write( string $year, string $month, string $day, string $content ): array;

	/**
	 * Append content to a daily memory entry.
	 *
	 * @param string $year    Four-digit year.
	 * @param string $month   Two-digit month.
	 * @param string $day     Two-digit day.
	 * @param string $content Content to append.
	 * @return array{success: bool, message: string}
	 */
	public function append( string $year, string $month, string $day, string $content ): array;

	/**
	 * Delete a daily memory entry.
	 *
	 * @param string $year  Four-digit year.
	 * @param string $month Two-digit month.
	 * @param string $day   Two-digit day.
	 * @return array{success: bool, message: string}
	 */
	public function delete( string $year, string $month, string $day ): array;

	/**
	 * List all daily memory entries grouped by month.
	 *
	 * @return array{success: bool, months: array<string, string[]>}
	 */
	public function list_all(): array;

	/**
	 * Search across daily memory entries.
	 *
	 * @param string      $query         Search term.
	 * @param string|null $from          Start date (YYYY-MM-DD, inclusive).
	 * @param string|null $to            End date (YYYY-MM-DD, inclusive).
	 * @param int         $context_lines Number of context lines around matches.
	 * @return array{success: bool, query: string, matches: array, match_count: int}
	 */
	public function search( string $query, ?string $from = null, ?string $to = null, int $context_lines = 2 ): array;
}
