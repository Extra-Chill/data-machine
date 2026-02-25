<?php
/**
 * WP-CLI Memory Command
 *
 * Provides CLI access to the agent Memory Library — core memory files
 * (SOUL.md, MEMORY.md) and daily memory (YYYY/MM/DD.md).
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

}
