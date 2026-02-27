<?php
/**
 * WP-CLI Memory Command
 *
 * Provides CLI access to the agent Memory Library — core memory files
 * (SOUL.md, MEMORY.md, USER.md) and daily memory (YYYY/MM/DD.md).
 *
 * @package DataMachine\Cli\Commands
 * @since 0.30.0 Originally as AgentCommand.
 * @since 0.32.0 Renamed to MemoryCommand, registered as `wp datamachine memory`.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class MemoryCommand extends BaseCommand {

	/**
	 * Valid write modes.
	 *
	 * @var array
	 */
	private array $valid_modes = array( 'set', 'append' );

	/**
	 * Read agent memory — full file or a specific section.
	 *
	 * ## OPTIONS
	 *
	 * [<section>]
	 * : Section name to read (without ##). If omitted, returns full file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read full memory file
	 *     wp datamachine memory read
	 *
	 *     # Read a specific section
	 *     wp datamachine memory read "Fleet"
	 *
	 *     # Read lessons learned
	 *     wp datamachine memory read "Lessons Learned"
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		$section = $args[0] ?? null;

		$input = array();
		if ( null !== $section ) {
			$input['section'] = $section;
		}

		$result = AgentMemoryAbilities::getMemory( $input );

		if ( ! $result['success'] ) {
			$message = $result['message'] ?? 'Failed to read memory.';
			if ( ! empty( $result['available_sections'] ) ) {
				$message .= "\nAvailable sections: " . implode( ', ', $result['available_sections'] );
			}
			WP_CLI::error( $message );
			return;
		}

		WP_CLI::log( $result['content'] ?? '' );
	}

	/**
	 * List all sections in agent memory.
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
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List memory sections
	 *     wp datamachine memory sections
	 *
	 *     # List as JSON
	 *     wp datamachine memory sections --format=json
	 *
	 * @subcommand sections
	 */
	public function sections( array $args, array $assoc_args ): void {
		$result = AgentMemoryAbilities::listSections( array() );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to list sections.' );
			return;
		}

		$sections = $result['sections'] ?? array();

		if ( empty( $sections ) ) {
			WP_CLI::log( 'No sections found in memory file.' );
			return;
		}

		$items = array_map(
			function ( $section ) {
				return array( 'section' => $section );
			},
			$sections
		);

		$this->format_items( $items, array( 'section' ), $assoc_args );
	}

	/**
	 * Write to a section of agent memory.
	 *
	 * ## OPTIONS
	 *
	 * <section>
	 * : Section name (without ##). Created if it does not exist.
	 *
	 * <content>
	 * : Content to write. Use quotes for multi-word content.
	 *
	 * [--mode=<mode>]
	 * : Write mode.
	 * ---
	 * default: set
	 * options:
	 *   - set
	 *   - append
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace a section
	 *     wp datamachine memory write "State" "- Data Machine v0.30.0 installed"
	 *
	 *     # Append to a section
	 *     wp datamachine memory write "Lessons Learned" "- Always check file permissions" --mode=append
	 *
	 *     # Create a new section
	 *     wp datamachine memory write "New Section" "Initial content"
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Both section name and content are required.' );
			return;
		}

		$section = $args[0];
		$content = $args[1];
		$mode    = $assoc_args['mode'] ?? 'set';

		if ( ! in_array( $mode, $this->valid_modes, true ) ) {
			WP_CLI::error( sprintf( 'Invalid mode "%s". Must be one of: %s', $mode, implode( ', ', $this->valid_modes ) ) );
			return;
		}

		$result = AgentMemoryAbilities::updateMemory(
			array(
				'section' => $section,
				'content' => $content,
				'mode'    => $mode,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to write memory.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Search agent memory content.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search term (case-insensitive).
	 *
	 * [--section=<section>]
	 * : Limit search to a specific section.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search all memory
	 *     wp datamachine memory search "homeboy"
	 *
	 *     # Search within a section
	 *     wp datamachine memory search "docker" --section="Lessons Learned"
	 *
	 * @subcommand search
	 */
	public function search( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Search query is required.' );
			return;
		}

		$query   = $args[0];
		$section = $assoc_args['section'] ?? null;

		$result = AgentMemoryAbilities::searchMemory(
			array(
				'query'   => $query,
				'section' => $section,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Search failed.' );
			return;
		}

		if ( empty( $result['matches'] ) ) {
			WP_CLI::log( sprintf( 'No matches for "%s" in agent memory.', $query ) );
			return;
		}

		foreach ( $result['matches'] as $match ) {
			WP_CLI::log( sprintf( '--- [%s] line %d ---', $match['section'], $match['line'] ) );
			WP_CLI::log( $match['context'] );
			WP_CLI::log( '' );
		}

		WP_CLI::success( sprintf( '%d match(es) found.', $result['match_count'] ) );
	}

	/**
	 * Daily memory operations.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: list, read, write, append, delete, search.
	 *
	 * [<date>]
	 * : Date in YYYY-MM-DD format. Defaults to today for write/append.
	 *
	 * [<content>]
	 * : Content for write/append actions.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all daily memory files
	 *     wp datamachine memory daily list
	 *
	 *     # Read today's daily memory
	 *     wp datamachine memory daily read
	 *
	 *     # Read a specific date
	 *     wp datamachine memory daily read 2026-02-24
	 *
	 *     # Write to today's daily memory (replaces content)
	 *     wp datamachine memory daily write "## Session notes"
	 *
	 *     # Append to a specific date
	 *     wp datamachine memory daily append 2026-02-24 "- Additional discovery"
	 *
	 *     # Delete a daily file
	 *     wp datamachine memory daily delete 2026-02-24
	 *
	 *     # Search daily memory
	 *     wp datamachine memory daily search "homeboy"
	 *
	 *     # Search with date range
	 *     wp datamachine memory daily search "deploy" --from=2026-02-01 --to=2026-02-28
	 *
	 * @subcommand daily
	 */
	public function daily( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine memory daily <list|read|write|append|delete> [date] [content]' );
			return;
		}

		$action = $args[0];
		$daily  = new DailyMemory();

		switch ( $action ) {
			case 'list':
				$this->daily_list( $daily, $assoc_args );
				break;
			case 'read':
				$date = $args[1] ?? gmdate( 'Y-m-d' );
				$this->daily_read( $daily, $date );
				break;
			case 'write':
				$this->daily_write( $daily, $args );
				break;
			case 'append':
				$this->daily_append( $daily, $args );
				break;
			case 'delete':
				$date = $args[1] ?? null;
				if ( ! $date ) {
					WP_CLI::error( 'Date is required for delete. Usage: wp datamachine memory daily delete 2026-02-24' );
					return;
				}
				$this->daily_delete( $daily, $date );
				break;
			case 'search':
				$search_query = $args[1] ?? null;
				if ( ! $search_query ) {
					WP_CLI::error( 'Search query is required. Usage: wp datamachine memory daily search "query" [--from=...] [--to=...]' );
					return;
				}
				$this->daily_search( $daily, $search_query, $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown daily action: {$action}. Use: list, read, write, append, delete, search" );
		}
	}

	/**
	 * List daily memory files.
	 */
	private function daily_list( DailyMemory $daily, array $assoc_args ): void {
		$result = $daily->list_all();
		$months = $result['months'];

		if ( empty( $months ) ) {
			WP_CLI::log( 'No daily memory files found.' );
			return;
		}

		$items = array();
		foreach ( $months as $month_key => $days ) {
			foreach ( $days as $day ) {
				list( $year, $month ) = explode( '/', $month_key );
				$items[] = array(
					'date'  => "{$year}-{$month}-{$day}",
					'month' => $month_key,
				);
			}
		}

		// Sort descending by date.
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		$this->format_items( $items, array( 'date', 'month' ), $assoc_args );
		WP_CLI::log( sprintf( 'Total: %d daily memory file(s).', count( $items ) ) );
	}

	/**
	 * Read a daily memory file.
	 */
	private function daily_read( DailyMemory $daily, string $date ): void {
		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->read( $parts['year'], $parts['month'], $parts['day'] );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::log( $result['content'] );
	}

	/**
	 * Write (replace) a daily memory file.
	 */
	private function daily_write( DailyMemory $daily, array $args ): void {
		// write [date] <content> — date defaults to today.
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Content is required. Usage: wp datamachine memory daily write [date] <content>' );
			return;
		}

		// If 3 args: write date content. If 2 args: write content (today).
		if ( count( $args ) >= 3 ) {
			$date    = $args[1];
			$content = $args[2];
		} else {
			$date    = gmdate( 'Y-m-d' );
			$content = $args[1];
		}

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->write( $parts['year'], $parts['month'], $parts['day'], $content );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Append to a daily memory file.
	 */
	private function daily_append( DailyMemory $daily, array $args ): void {
		// append [date] <content> — date defaults to today.
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Content is required. Usage: wp datamachine memory daily append [date] <content>' );
			return;
		}

		if ( count( $args ) >= 3 ) {
			$date    = $args[1];
			$content = $args[2];
		} else {
			$date    = gmdate( 'Y-m-d' );
			$content = $args[1];
		}

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->append( $parts['year'], $parts['month'], $parts['day'], $content );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Search daily memory files.
	 */
	private function daily_search( DailyMemory $daily, string $query, array $assoc_args ): void {
		$from = $assoc_args['from'] ?? null;
		$to   = $assoc_args['to'] ?? null;

		$result = $daily->search( $query, $from, $to );

		if ( empty( $result['matches'] ) ) {
			WP_CLI::log( sprintf( 'No matches for "%s" in daily memory.', $query ) );
			return;
		}

		foreach ( $result['matches'] as $match ) {
			WP_CLI::log( sprintf( '--- [%s] line %d ---', $match['date'], $match['line'] ) );
			WP_CLI::log( $match['context'] );
			WP_CLI::log( '' );
		}

		WP_CLI::success( sprintf( '%d match(es) found.', $result['match_count'] ) );
	}

	/**
	 * Delete a daily memory file.
	 */
	private function daily_delete( DailyMemory $daily, string $date ): void {
		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			WP_CLI::error( sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ) );
			return;
		}

		$result = $daily->delete( $parts['year'], $parts['month'], $parts['day'] );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	// =========================================================================
	// Agent Files — multi-file operations (SOUL.md, USER.md, MEMORY.md, etc.)
	// =========================================================================

	/**
	 * Agent files operations.
	 *
	 * Manage all agent memory files (SOUL.md, USER.md, MEMORY.md, etc.).
	 * Supports listing, reading, writing, and staleness detection.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: list, read, write, check.
	 *
	 * [<filename>]
	 * : Filename for read/write actions (e.g., SOUL.md, USER.md).
	 *
	 * [--days=<days>]
	 * : Staleness threshold in days for the check action.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for list/check actions.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all agent files with timestamps and sizes
	 *     wp datamachine agent files list
	 *
	 *     # Read an agent file
	 *     wp datamachine agent files read SOUL.md
	 *
	 *     # Write to an agent file via stdin
	 *     cat new-soul.md | wp datamachine agent files write SOUL.md
	 *
	 *     # Check for stale files (not updated in 7 days)
	 *     wp datamachine agent files check
	 *
	 *     # Check with custom threshold
	 *     wp datamachine agent files check --days=14
	 *
	 * @subcommand files
	 */
	public function files( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent files <list|read|write|check> [filename]' );
			return;
		}

		$action = $args[0];

		switch ( $action ) {
			case 'list':
				$this->files_list( $assoc_args );
				break;
			case 'read':
				$filename = $args[1] ?? null;
				if ( ! $filename ) {
					WP_CLI::error( 'Filename is required. Usage: wp datamachine agent files read <filename>' );
					return;
				}
				$this->files_read( $filename );
				break;
			case 'write':
				$filename = $args[1] ?? null;
				if ( ! $filename ) {
					WP_CLI::error( 'Filename is required. Usage: wp datamachine agent files write <filename>' );
					return;
				}
				$this->files_write( $filename );
				break;
			case 'check':
				$this->files_check( $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown files action: {$action}. Use: list, read, write, check" );
		}
	}

	/**
	 * List all agent files with metadata.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_list( array $assoc_args ): void {
		$agent_dir = $this->get_agent_dir();

		if ( ! is_dir( $agent_dir ) ) {
			WP_CLI::error( 'Agent directory does not exist.' );
			return;
		}

		$files = glob( $agent_dir . '/*.md' );

		if ( empty( $files ) ) {
			WP_CLI::log( 'No agent files found.' );
			return;
		}

		$items = array();
		$now   = time();

		foreach ( $files as $file ) {
			$mtime    = filemtime( $file );
			$age_days = floor( ( $now - $mtime ) / 86400 );

			$items[] = array(
				'file'     => basename( $file ),
				'size'     => size_format( filesize( $file ) ),
				'modified' => wp_date( 'Y-m-d H:i:s', $mtime ),
				'age'      => $age_days . 'd',
			);
		}

		$this->format_items( $items, array( 'file', 'size', 'modified', 'age' ), $assoc_args );
	}

	/**
	 * Read an agent file by name.
	 *
	 * @param string $filename File name (e.g., SOUL.md).
	 */
	private function files_read( string $filename ): void {
		$agent_dir = $this->get_agent_dir();
		$filepath  = $agent_dir . '/' . $this->sanitize_agent_filename( $filename );

		if ( ! file_exists( $filepath ) ) {
			$available = $this->list_agent_filenames();
			WP_CLI::error( sprintf( 'File "%s" not found. Available files: %s', $filename, implode( ', ', $available ) ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		WP_CLI::log( file_get_contents( $filepath ) );
	}

	/**
	 * Write to an agent file from stdin.
	 *
	 * @param string $filename File name (e.g., SOUL.md).
	 */
	private function files_write( string $filename ): void {
		$safe_name = $this->sanitize_agent_filename( $filename );

		// Only allow .md files.
		if ( '.md' !== substr( $safe_name, -3 ) ) {
			WP_CLI::error( 'Only .md files can be written to the agent directory.' );
			return;
		}

		$agent_dir = $this->get_agent_dir();
		$filepath  = $agent_dir . '/' . $safe_name;

		// Read from stdin.
		$content = file_get_contents( 'php://stdin' );

		if ( false === $content || '' === trim( $content ) ) {
			WP_CLI::error( 'No content received from stdin. Pipe content in: echo "content" | wp datamachine agent files write SOUL.md' );
			return;
		}

		$directory_manager = new DirectoryManager();
		$directory_manager->ensure_directory_exists( $agent_dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $filepath, $content );

		if ( false === $written ) {
			WP_CLI::error( sprintf( 'Failed to write file: %s', $safe_name ) );
			return;
		}

		WP_CLI::success( sprintf( 'Wrote %s (%s).', $safe_name, size_format( $written ) ) );
	}

	/**
	 * Check agent files for staleness.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_check( array $assoc_args ): void {
		$agent_dir      = $this->get_agent_dir();
		$threshold_days = (int) ( $assoc_args['days'] ?? 7 );

		if ( ! is_dir( $agent_dir ) ) {
			WP_CLI::error( 'Agent directory does not exist.' );
			return;
		}

		$files = glob( $agent_dir . '/*.md' );

		if ( empty( $files ) ) {
			WP_CLI::log( 'No agent files found.' );
			return;
		}

		$items     = array();
		$now       = time();
		$threshold = $now - ( $threshold_days * 86400 );
		$stale     = 0;

		foreach ( $files as $file ) {
			$mtime    = filemtime( $file );
			$age_days = floor( ( $now - $mtime ) / 86400 );
			$is_stale = $mtime < $threshold;

			if ( $is_stale ) {
				++$stale;
			}

			$items[] = array(
				'file'     => basename( $file ),
				'modified' => wp_date( 'Y-m-d H:i:s', $mtime ),
				'age'      => $age_days . 'd',
				'status'   => $is_stale ? 'STALE' : 'OK',
			);
		}

		$this->format_items( $items, array( 'file', 'modified', 'age', 'status' ), $assoc_args );

		if ( $stale > 0 ) {
			WP_CLI::warning( sprintf( '%d file(s) not updated in %d+ days. Review for accuracy.', $stale, $threshold_days ) );
		} else {
			WP_CLI::success( sprintf( 'All %d file(s) updated within the last %d days.', count( $files ), $threshold_days ) );
		}
	}

	/**
	 * Get the agent directory path.
	 *
	 * @return string
	 */
	private function get_agent_dir(): string {
		$directory_manager = new DirectoryManager();
		return $directory_manager->get_agent_directory();
	}

	/**
	 * Sanitize an agent filename (allow only alphanumeric, hyphens, underscores, dots).
	 *
	 * @param string $filename Raw filename.
	 * @return string Sanitized filename.
	 */
	private function sanitize_agent_filename( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( $filename ) );
	}

	/**
	 * List available agent filenames.
	 *
	 * @return string[]
	 */
	private function list_agent_filenames(): array {
		$agent_dir = $this->get_agent_dir();
		$files     = glob( $agent_dir . '/*.md' );
		return array_map( 'basename', $files ?: array() );
	}

}
