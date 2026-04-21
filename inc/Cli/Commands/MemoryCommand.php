<?php
/**
 * WP-CLI Agent Command
 *
 * Provides CLI access to the agent Memory Library — core memory files
 * across layers (SOUL.md + MEMORY.md in agent layer, USER.md in user layer),
 * MEMORY.md section operations, and daily memory (YYYY/MM/DD.md).
 *
 * Primary command: `wp datamachine agent`.
 * Backwards-compatible alias: `wp datamachine memory`.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.30.0 Originally as AgentCommand.
 * @since 0.32.0 Renamed to MemoryCommand, registered as `wp datamachine memory`.
 * @since 0.33.0 Primary namespace changed to `wp datamachine agent`, `memory` kept as alias.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachine\Cli\UserResolver;
use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Engine\AI\SectionRegistry;
use DataMachine\Engine\AI\ComposableFileGenerator;

defined( 'ABSPATH' ) || exit;

class MemoryCommand extends BaseCommand {

	/**
	 * Valid write modes.
	 *
	 * @var array
	 */
	private array $valid_modes = array( 'set', 'append' );

	/**
	 * Read an agent file — full file or a specific section.
	 *
	 * Supports any agent file (MEMORY.md, SOUL.md, USER.md, etc.).
	 * If the first argument ends in .md, it is treated as a filename.
	 * Otherwise it is treated as a section name within MEMORY.md.
	 *
	 * ## OPTIONS
	 *
	 * [<file_or_section>]
	 * : Filename (e.g. SOUL.md) or section name (without ##).
	 *   Arguments ending in .md are treated as filenames.
	 *   If omitted, reads full MEMORY.md.
	 *
	 * [<section>]
	 * : Section name when the first argument is a filename.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID. When provided, reads that agent's file
	 *   instead of the current user's agent.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read full MEMORY.md (default)
	 *     wp datamachine agent read
	 *
	 *     # Read a specific section from MEMORY.md
	 *     wp datamachine agent read "Fleet"
	 *
	 *     # Read full SOUL.md
	 *     wp datamachine agent read SOUL.md
	 *
	 *     # Read a section from SOUL.md
	 *     wp datamachine agent read SOUL.md "Identity"
	 *
	 *     # Read USER.md for a specific agent
	 *     wp datamachine agent read USER.md --agent=studio
	 *
	 *     # Read for a specific user
	 *     wp datamachine agent read --user=2
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		$parsed = $this->parseFileAndSection( $args );
		$input  = $this->resolveMemoryScoping( $assoc_args );

		if ( null !== $parsed['file'] ) {
			$input['file'] = $parsed['file'];
		}

		if ( null !== $parsed['section'] ) {
			$input['section'] = $parsed['section'];
		}

		$result = AgentMemoryAbilities::getMemory( $input );

		if ( ! $result['success'] ) {
			$message = $result['message'] ?? 'Failed to read file.';
			if ( ! empty( $result['available_sections'] ) ) {
				$message .= "\nAvailable sections: " . implode( ', ', $result['available_sections'] );
			}
			WP_CLI::error( $message );
			return;
		}

		WP_CLI::log( $result['content'] ?? '' );
	}

	/**
	 * List all sections in an agent file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Target file (e.g. SOUL.md, USER.md). Defaults to MEMORY.md.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
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
	 *     # List MEMORY.md sections (default)
	 *     wp datamachine agent sections
	 *
	 *     # List SOUL.md sections
	 *     wp datamachine agent sections --file=SOUL.md
	 *
	 *     # List USER.md sections as JSON
	 *     wp datamachine agent sections --file=USER.md --format=json
	 *
	 *     # List sections for a specific agent
	 *     wp datamachine agent sections --agent=studio
	 *
	 * @subcommand sections
	 */
	public function sections( array $args, array $assoc_args ): void {
		$scoping = $this->resolveMemoryScoping( $assoc_args );
		$file    = $assoc_args['file'] ?? null;

		if ( null !== $file ) {
			$scoping['file'] = $file;
		}

		$result = AgentMemoryAbilities::listSections( $scoping );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to list sections.' );
			return;
		}

		$sections    = $result['sections'] ?? array();
		$target_file = $result['file'] ?? $file ?? 'MEMORY.md';

		if ( empty( $sections ) ) {
			WP_CLI::log( sprintf( 'No sections found in %s.', $target_file ) );
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
	 * Write to a section of an agent file.
	 *
	 * Supports any agent file (MEMORY.md, SOUL.md, USER.md, etc.).
	 * If the first argument ends in .md, it is treated as a filename
	 * and the next two arguments are section and content.
	 * Otherwise the first two arguments are section and content
	 * targeting MEMORY.md.
	 *
	 * Content can also be supplied via --from-file=<path> or via stdin
	 * by passing `-` as the content positional. These avoid shell-quoting
	 * issues when content contains backticks, `$`, or multi-line markdown.
	 *
	 * ## OPTIONS
	 *
	 * <file_or_section>
	 * : Filename (e.g. SOUL.md) or section name (without ##).
	 *   Arguments ending in .md are treated as filenames.
	 *
	 * [<section_or_content>]
	 * : Section name when first arg is a filename, or content
	 *   when first arg is a section name. Use `-` to read content
	 *   from stdin. Optional when --from-file is used.
	 *
	 * [<content>]
	 * : Content to write when first arg is a filename. Use `-` to
	 *   read content from stdin. Optional when --from-file is used.
	 *
	 * [--from-file=<path>]
	 * : Read content from a file on disk. When set, the content positional
	 *   becomes optional. Cannot be combined with a non-`-` positional content
	 *   or with stdin (`-`).
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
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
	 *     # Replace a section in MEMORY.md (default)
	 *     wp datamachine agent write "State" "- Data Machine v0.30.0 installed"
	 *
	 *     # Append to a section in MEMORY.md
	 *     wp datamachine agent write "Lessons Learned" "- Always check file permissions" --mode=append
	 *
	 *     # Write to a section in SOUL.md
	 *     wp datamachine agent write SOUL.md "Identity" "I am chubes-bot"
	 *
	 *     # Append to a section in USER.md
	 *     wp datamachine agent write USER.md "Goals" "- Ship the feature" --mode=append
	 *
	 *     # Write to a specific agent's file
	 *     wp datamachine agent write SOUL.md "Voice" "Concise and direct" --agent=studio
	 *
	 *     # Load content from a file on disk
	 *     wp datamachine agent write "Session Notes" --from-file=/tmp/notes.md --mode=append
	 *
	 *     # Pipe content via stdin
	 *     echo "- New lesson" | wp datamachine agent write "Lessons Learned" - --mode=append
	 *
	 *     # Heredoc via stdin
	 *     wp datamachine agent write SOUL.md "Identity" - <<'EOF'
	 *     Multi-line content with `backticks` and $vars
	 *     EOF
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		$mode = $assoc_args['mode'] ?? 'set';

		if ( ! in_array( $mode, $this->valid_modes, true ) ) {
			WP_CLI::error( sprintf( 'Invalid mode "%s". Must be one of: %s', $mode, implode( ', ', $this->valid_modes ) ) );
			return;
		}

		$from_file     = $assoc_args['from-file'] ?? null;
		$stdin_marker  = ( ! empty( $args ) && '-' === end( $args ) );
		reset( $args );

		if ( null !== $from_file && $stdin_marker ) {
			WP_CLI::error( 'Cannot use both --from-file and stdin (`-`). Pick one.' );
			return;
		}

		if ( null !== $from_file ) {
			// --from-file path: content positional must NOT be present.
			$file_section = $this->parseFileAndSection( $args );

			if ( null === $file_section['section'] ) {
				WP_CLI::error( 'Usage: wp datamachine agent write [<file.md>] <section> --from-file=<path> [--mode=set|append]' );
				return;
			}

			$expected_count = ( null !== $file_section['file'] ) ? 2 : 1;
			if ( count( $args ) > $expected_count ) {
				WP_CLI::error( 'Cannot supply both --from-file and positional content. Pick one.' );
				return;
			}

			if ( ! file_exists( $from_file ) ) {
				WP_CLI::error( sprintf( '--from-file path does not exist: %s', $from_file ) );
				return;
			}

			if ( ! is_readable( $from_file ) ) {
				WP_CLI::error( sprintf( '--from-file path is not readable: %s', $from_file ) );
				return;
			}

			$content = file_get_contents( $from_file );
			if ( false === $content ) {
				WP_CLI::error( sprintf( 'Failed to read --from-file path: %s', $from_file ) );
				return;
			}

			$file    = $file_section['file'];
			$section = $file_section['section'];
		} elseif ( $stdin_marker ) {
			// Stdin path: pop the `-` and parse remaining args as [file?] section.
			array_pop( $args );
			$file_section = $this->parseFileAndSection( $args );

			if ( null === $file_section['section'] ) {
				WP_CLI::error( 'Usage: wp datamachine agent write [<file.md>] <section> - [--mode=set|append]' );
				return;
			}

			$expected_count = ( null !== $file_section['file'] ) ? 2 : 1;
			if ( count( $args ) > $expected_count ) {
				WP_CLI::error( 'Unexpected extra positional arguments before stdin marker.' );
				return;
			}

			$content = $this->readStdin();
			$file    = $file_section['file'];
			$section = $file_section['section'];
		} else {
			// Positional content (existing behavior).
			$parsed = $this->parseFileSectionContent( $args );

			if ( null === $parsed ) {
				WP_CLI::error( 'Usage: wp datamachine agent write [<file.md>] <section> <content> [--mode=set|append]' );
				return;
			}

			$file    = $parsed['file'];
			$section = $parsed['section'];
			$content = $parsed['content'];
		}

		$scoping = $this->resolveMemoryScoping( $assoc_args );
		$input   = array_merge(
			$scoping,
			array(
				'section' => $section,
				'content' => $content,
				'mode'    => $mode,
			)
		);

		if ( null !== $file ) {
			$input['file'] = $file;
		}

		$result = AgentMemoryAbilities::updateMemory( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Failed to write.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Read content from stdin (php://stdin) and return as string.
	 *
	 * Returns an empty string if stdin yields nothing.
	 *
	 * @since 0.71.0
	 * @return string Content read from stdin.
	 */
	private function readStdin(): string {
		$content = file_get_contents( 'php://stdin' );
		return ( false === $content ) ? '' : $content;
	}

	/**
	 * Search agent file content.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search term (case-insensitive).
	 *
	 * [--file=<file>]
	 * : Target file to search (e.g. SOUL.md, USER.md). Defaults to MEMORY.md.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * [--section=<section>]
	 * : Limit search to a specific section.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search MEMORY.md (default)
	 *     wp datamachine agent search "homeboy"
	 *
	 *     # Search SOUL.md
	 *     wp datamachine agent search "identity" --file=SOUL.md
	 *
	 *     # Search within a section
	 *     wp datamachine agent search "docker" --section="Lessons Learned"
	 *
	 *     # Search a specific agent's file
	 *     wp datamachine agent search "socials" --file=USER.md --agent=studio
	 *
	 * @subcommand search
	 */
	public function search( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Search query is required.' );
			return;
		}

		$query   = $args[0];
		$file    = $assoc_args['file'] ?? null;
		$section = $assoc_args['section'] ?? null;
		$scoping = $this->resolveMemoryScoping( $assoc_args );

		$input = array_merge(
			$scoping,
			array(
				'query'   => $query,
				'section' => $section,
			)
		);

		if ( null !== $file ) {
			$input['file'] = $file;
		}

		$result = AgentMemoryAbilities::searchMemory( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['message'] ?? 'Search failed.' );
			return;
		}

		$target_file = $file ?? 'MEMORY.md';

		if ( empty( $result['matches'] ) ) {
			WP_CLI::log( sprintf( 'No matches for "%s" in %s.', $query, $target_file ) );
			return;
		}

		foreach ( $result['matches'] as $match ) {
			WP_CLI::log( sprintf( '--- [%s] line %d ---', $match['section'], $match['line'] ) );
			WP_CLI::log( $match['context'] );
			WP_CLI::log( '' );
		}

		WP_CLI::success( sprintf( '%d match(es) found in %s.', $result['match_count'], $target_file ) );
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
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all daily memory files
	 *     wp datamachine agent daily list
	 *
	 *     # Read today's daily memory
	 *     wp datamachine agent daily read
	 *
	 *     # Read a specific date
	 *     wp datamachine agent daily read 2026-02-24
	 *
	 *     # Write to today's daily memory (replaces content)
	 *     wp datamachine agent daily write "## Session notes"
	 *
	 *     # Append to a specific date
	 *     wp datamachine agent daily append 2026-02-24 "- Additional discovery"
	 *
	 *     # Delete a daily file
	 *     wp datamachine agent daily delete 2026-02-24
	 *
	 *     # Search daily memory
	 *     wp datamachine agent daily search "homeboy"
	 *
	 *     # Search with date range
	 *     wp datamachine agent daily search "deploy" --from=2026-02-01 --to=2026-02-28
	 *
	 * @subcommand daily
	 */
	public function daily( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent daily <list|read|write|append|delete> [date] [content]' );
			return;
		}

		$action   = $args[0];
		$agent_id = AgentResolver::resolve( $assoc_args );
		$user_id  = ( null === $agent_id ) ? UserResolver::resolve( $assoc_args ) : 0;
		$daily    = new DailyMemory( $user_id, $agent_id ?? 0 );

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
					WP_CLI::error( 'Date is required for delete. Usage: wp datamachine agent daily delete 2026-02-24' );
					return;
				}
				$this->daily_delete( $daily, $date );
				break;
			case 'search':
				$search_query = $args[1] ?? null;
				if ( ! $search_query ) {
					WP_CLI::error( 'Search query is required. Usage: wp datamachine agent daily search "query" [--from=...] [--to=...]' );
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
				$items[]              = array(
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
			WP_CLI::error( 'Content is required. Usage: wp datamachine agent daily write [date] <content>' );
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
			WP_CLI::error( 'Content is required. Usage: wp datamachine agent daily append [date] <content>' );
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
	 * List and check agent memory files (SOUL.md, USER.md, MEMORY.md, etc.).
	 * For reading and writing file content, use `agent read` and `agent write`
	 * which support section-level operations on any file.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: list, check.
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID. When provided, operates on that agent's
	 *   files instead of the current user's agent. Required for managing
	 *   shared agents in multi-agent setups.
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
	 *     # List files for a specific agent
	 *     wp datamachine agent files list --agent=studio
	 *
	 *     # Check for stale files (not updated in 7 days)
	 *     wp datamachine agent files check
	 *
	 *     # Check with custom threshold
	 *     wp datamachine agent files check --days=14
	 *
	 *     # Check a specific agent's files
	 *     wp datamachine agent files check --agent=studio
	 *
	 * @subcommand files
	 */
	public function files( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent files <list|check>' );
			return;
		}

		$action   = $args[0];
		$agent_id = AgentResolver::resolve( $assoc_args );
		$user_id  = ( null === $agent_id ) ? UserResolver::resolve( $assoc_args ) : 0;

		switch ( $action ) {
			case 'list':
				$this->files_list( $assoc_args, $user_id, $agent_id );
				break;
			case 'check':
				$this->files_check( $assoc_args, $user_id, $agent_id );
				break;
			default:
				WP_CLI::error( "Unknown files action: {$action}. Use: list, check" );
		}
	}

	/**
	 * List all agent files with metadata.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_list( array $assoc_args, int $user_id = 0, ?int $agent_id = null ): void {
		$agent_dir = $this->get_agent_dir( $user_id, $agent_id );

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
	 * Check agent files for staleness.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_check( array $assoc_args, int $user_id = 0, ?int $agent_id = null ): void {
		$agent_dir      = $this->get_agent_dir( $user_id, $agent_id );
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
	// =========================================================================
	// Argument parsing helpers
	// =========================================================================

	/**
	 * Check if a string looks like a filename (ends in .md).
	 *
	 * @since 0.45.0
	 * @param string $arg Argument to check.
	 * @return bool
	 */
	private function isFilename( string $arg ): bool {
		return (bool) preg_match( '/\.md$/i', $arg );
	}

	/**
	 * Parse positional args into file and section for read commands.
	 *
	 * Disambiguation rules:
	 * - No args: file=null, section=null (full MEMORY.md)
	 * - One arg ending in .md: file=arg, section=null (full file)
	 * - One arg not .md: file=null, section=arg (MEMORY.md section)
	 * - Two args: file=first, section=second
	 *
	 * @since 0.45.0
	 * @param array $args Positional arguments.
	 * @return array{file: ?string, section: ?string}
	 */
	private function parseFileAndSection( array $args ): array {
		if ( empty( $args ) ) {
			return array(
				'file'    => null,
				'section' => null,
			);
		}

		if ( count( $args ) >= 2 ) {
			return array(
				'file'    => $args[0],
				'section' => $args[1],
			);
		}

		// Single argument — disambiguate.
		if ( $this->isFilename( $args[0] ) ) {
			return array(
				'file'    => $args[0],
				'section' => null,
			);
		}

		return array(
			'file'    => null,
			'section' => $args[0],
		);
	}

	/**
	 * Parse positional args into file, section, and content for write commands.
	 *
	 * Disambiguation rules:
	 * - Two args, first not .md: section=first, content=second (MEMORY.md)
	 * - Three args, first is .md: file=first, section=second, content=third
	 * - Two args, first is .md: error (missing content)
	 *
	 * @since 0.45.0
	 * @param array $args Positional arguments.
	 * @return array{file: ?string, section: string, content: string}|null Null on invalid args.
	 */
	private function parseFileSectionContent( array $args ): ?array {
		if ( count( $args ) >= 3 && $this->isFilename( $args[0] ) ) {
			return array(
				'file'    => $args[0],
				'section' => $args[1],
				'content' => $args[2],
			);
		}

		if ( count( $args ) >= 2 && ! $this->isFilename( $args[0] ) ) {
			return array(
				'file'    => null,
				'section' => $args[0],
				'content' => $args[1],
			);
		}

		return null;
	}

	/**
	 * Resolve memory scoping from CLI flags.
	 *
	 * Returns an input array with agent_id (preferred) or user_id for
	 * memory ability calls. Agent scoping takes precedence over user scoping.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array Input parameters with user_id and/or agent_id.
	 */
	private function resolveMemoryScoping( array $assoc_args ): array {
		$agent_id = AgentResolver::resolve( $assoc_args );

		if ( null !== $agent_id ) {
			return array( 'agent_id' => $agent_id );
		}

		return array( 'user_id' => UserResolver::resolve( $assoc_args ) );
	}

	private function get_agent_dir( int $user_id = 0, ?int $agent_id = null ): string {
		$directory_manager = new DirectoryManager();

		if ( null !== $agent_id && $agent_id > 0 ) {
			$slug = $directory_manager->resolve_agent_slug( array( 'agent_id' => $agent_id ) );
			return $directory_manager->get_agent_identity_directory( $slug );
		}

		return $directory_manager->get_agent_identity_directory_for_user( $user_id );
	}

	// =========================================================================
	// Composable files
	// =========================================================================

	/**
	 * Regenerate composable memory files from registered sections.
	 *
	 * Composable files (like AGENTS.md) are auto-generated from sections
	 * registered by plugins via SectionRegistry. This command regenerates
	 * them on demand.
	 *
	 * ## OPTIONS
	 *
	 * [<filename>]
	 * : Specific composable file to regenerate (e.g. AGENTS.md).
	 *   If omitted, regenerates all composable files.
	 *
	 * [--agent=<slug>]
	 * : Agent slug for context resolution.
	 *
	 * [--list]
	 * : List registered sections instead of regenerating.
	 *
	 * [--format=<format>]
	 * : Output format for --list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--quiet]
	 * : Suppress output on success.
	 *
	 * ## EXAMPLES
	 *
	 *     # Regenerate all composable files
	 *     wp datamachine agent compose
	 *
	 *     # Regenerate a specific file
	 *     wp datamachine agent compose AGENTS.md
	 *
	 *     # List registered sections for a file
	 *     wp datamachine agent compose --list AGENTS.md
	 *
	 *     # List all sections across all composable files
	 *     wp datamachine agent compose --list
	 *
	 * @subcommand compose
	 */
	public function compose( array $args, array $assoc_args ): void {
		$filename = $args[0] ?? '';
		$list     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'list', false );
		$quiet    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'quiet', false );

		if ( $list ) {
			$this->compose_list( $filename, $assoc_args );
			return;
		}

		// Build context from CLI flags.
		$context = $this->build_compose_context( $assoc_args );

		if ( ! empty( $filename ) ) {
			// Regenerate a single file.
			$result = ComposableFileGenerator::regenerate( $filename, $context );

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['message'] );
				return;
			}

			if ( ! $quiet ) {
				WP_CLI::success( $result['message'] );
			}
		} else {
			// Regenerate all composable files.
			$result = ComposableFileGenerator::regenerate_all( $context );

			if ( ! $quiet ) {
				foreach ( $result['results'] as $file_result ) {
					$status = ! empty( $file_result['success'] ) ? 'OK' : 'FAIL';
					WP_CLI::log( sprintf( '  [%s] %s — %s', $status, $file_result['filename'], $file_result['message'] ) );
				}
				WP_CLI::success( $result['message'] );
			}
		}
	}

	/**
	 * List registered sections for composable files.
	 *
	 * @param string $filename Optional filename filter.
	 * @param array  $assoc_args Command arguments.
	 */
	private function compose_list( string $filename, array $assoc_args ): void {
		$items = array();

		if ( ! empty( $filename ) ) {
			// Sections for a single file.
			$sections = SectionRegistry::get_sections( $filename );

			if ( empty( $sections ) ) {
				WP_CLI::log( sprintf( 'No sections registered for "%s".', $filename ) );
				return;
			}

			foreach ( $sections as $slug => $section ) {
				$items[] = array(
					'file'        => $filename,
					'slug'        => $slug,
					'priority'    => $section['priority'],
					'label'       => $section['label'],
					'description' => $section['description'],
				);
			}
		} else {
			// All sections across all composable files.
			$composable = MemoryFileRegistry::get_composable();

			if ( empty( $composable ) ) {
				WP_CLI::log( 'No composable files registered.' );
				return;
			}

			foreach ( $composable as $fname => $meta ) {
				$sections = SectionRegistry::get_sections( $fname );
				foreach ( $sections as $slug => $section ) {
					$items[] = array(
						'file'        => $fname,
						'slug'        => $slug,
						'priority'    => $section['priority'],
						'label'       => $section['label'],
						'description' => $section['description'],
					);
				}

				if ( empty( $sections ) ) {
					$items[] = array(
						'file'        => $fname,
						'slug'        => '(none)',
						'priority'    => '-',
						'label'       => '-',
						'description' => 'No sections registered.',
					);
				}
			}
		}

		if ( empty( $items ) ) {
			WP_CLI::log( 'No sections found.' );
			return;
		}

		$this->format_items( $items, array( 'file', 'slug', 'priority', 'label' ), $assoc_args );
	}

	/**
	 * Build generation context from CLI flags.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array Context array.
	 */
	private function build_compose_context( array $assoc_args ): array {
		$context  = array();
		$agent_id = AgentResolver::resolve( $assoc_args );

		if ( null !== $agent_id ) {
			$context['agent_id'] = $agent_id;
		} else {
			$context['user_id'] = UserResolver::resolve( $assoc_args );
		}

		return $context;
	}

	// =========================================================================
	// Agent Paths — discovery for external consumers
	// =========================================================================

	/**
	 * Show resolved file paths for all agent memory layers.
	 *
	 * External consumers (Kimaki, Claude Code, setup scripts) use this to
	 * discover the correct file paths instead of hardcoding them.
	 * Outputs absolute paths, relative paths (from site root), and layer directories.
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<slug>]
	 * : Agent slug to resolve paths for. When provided, bypasses
	 *   user-to-agent lookup and resolves directly by slug.
	 *   Required for multi-agent setups where a user owns multiple agents.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * [--relative]
	 * : Output paths relative to the WordPress root (for config file injection).
	 *
	 * ## EXAMPLES
	 *
	 *     # Get all resolved paths as JSON (for setup scripts)
	 *     wp datamachine agent paths --format=json
	 *
	 *     # Get paths for a specific agent (multi-agent)
	 *     wp datamachine agent paths --agent=chubes-bot
	 *
	 *     # Get relative paths for config file injection
	 *     wp datamachine agent paths --relative
	 *
	 *     # Table view for debugging
	 *     wp datamachine agent paths --format=table
	 *
	 * @subcommand paths
	 */
	public function paths( array $args, array $assoc_args ): void {
		$directory_manager = new DirectoryManager();
		$explicit_slug     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'agent', null );

		if ( null !== $explicit_slug ) {
			// Direct slug resolution — multi-agent safe.
			$agent_slug = $directory_manager->resolve_agent_slug( array( 'agent_slug' => $explicit_slug ) );
			$agent_dir  = $directory_manager->get_agent_identity_directory( $agent_slug );

			// Look up the agent's owner for the user layer.
			$effective_user_id = 0;
			if ( class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
				$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
				$agent_row   = $agents_repo->get_by_slug( $agent_slug );
				if ( $agent_row ) {
					$effective_user_id = (int) $agent_row['owner_id'];
				}
			}

			if ( 0 === $effective_user_id ) {
				$effective_user_id = DirectoryManager::get_default_agent_user_id();
			}
		} else {
			// Legacy user-based resolution (single-agent compat).
			$user_id           = UserResolver::resolve( $assoc_args );
			$effective_user_id = $directory_manager->get_effective_user_id( $user_id );
			$agent_slug        = $directory_manager->get_agent_slug_for_user( $effective_user_id );
			$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $effective_user_id );
		}

		$shared_dir  = $directory_manager->get_shared_directory();
		$user_dir    = $directory_manager->get_user_directory( $effective_user_id );
		$network_dir = $directory_manager->get_network_directory();

		$site_root = untrailingslashit( ABSPATH );
		$relative  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'relative', false );

		// Build file list from registry (sorted by priority, includes plugin-registered files).
		$layer_dirs = array(
			'shared'  => $shared_dir,
			'agent'   => $agent_dir,
			'user'    => $user_dir,
			'network' => $network_dir,
		);

		$registry   = MemoryFileRegistry::get_all();
		$core_files = array();

		foreach ( $registry as $filename => $meta ) {
			$layer     = $meta['layer'];
			$directory = $layer_dirs[ $layer ] ?? $agent_dir;

			$core_files[] = array(
				'file'      => $filename,
				'layer'     => $layer,
				'directory' => $directory,
			);
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'json' );

		if ( 'json' === $format ) {
			$layers = array(
				'shared'  => $shared_dir,
				'agent'   => $agent_dir,
				'user'    => $user_dir,
				'network' => $network_dir,
			);

			$files          = array();
			$relative_files = array();

			foreach ( $core_files as $entry ) {
				$abs_path = trailingslashit( $entry['directory'] ) . $entry['file'];
				$rel_path = str_replace( $site_root . '/', '', $abs_path );
				$exists   = file_exists( $abs_path );

				$files[ $entry['file'] ] = array(
					'layer'    => $entry['layer'],
					'path'     => $abs_path,
					'relative' => $rel_path,
					'exists'   => $exists,
				);

				if ( $exists ) {
					$relative_files[] = $rel_path;
				}
			}

			$output = array(
				'agent_slug'     => $agent_slug,
				'user_id'        => $effective_user_id,
				'layers'         => $layers,
				'files'          => $files,
				'relative_files' => $relative_files,
			);

			WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$items = array();
			foreach ( $core_files as $entry ) {
				$abs_path = trailingslashit( $entry['directory'] ) . $entry['file'];
				$rel_path = str_replace( $site_root . '/', '', $abs_path );

				$items[] = array(
					'file'   => $entry['file'],
					'layer'  => $entry['layer'],
					'path'   => $relative ? $rel_path : $abs_path,
					'exists' => file_exists( $abs_path ) ? 'yes' : 'no',
				);
			}

			$this->format_items( $items, array( 'file', 'layer', 'path', 'exists' ), $assoc_args );
		}
	}
}
