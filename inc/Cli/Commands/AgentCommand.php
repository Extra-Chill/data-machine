<?php
/**
 * WP-CLI Agent Command
 *
 * Provides CLI access to agent memory operations including
 * reading, writing, and listing memory sections.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.30.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\AgentMemoryAbilities;

defined( 'ABSPATH' ) || exit;

class AgentCommand extends BaseCommand {

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
	 *     wp datamachine agent memory
	 *
	 *     # Read a specific section
	 *     wp datamachine agent memory "Fleet"
	 *
	 *     # Read lessons learned
	 *     wp datamachine agent memory "Lessons Learned"
	 *
	 * @subcommand memory
	 */
	public function memory( array $args, array $assoc_args ): void {
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
	 *     wp datamachine agent sections
	 *
	 *     # List as JSON
	 *     wp datamachine agent sections --format=json
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
	 *     wp datamachine agent write "State" "- Data Machine v0.30.0 installed"
	 *
	 *     # Append to a section
	 *     wp datamachine agent write "Lessons Learned" "- Always check file permissions" --mode=append
	 *
	 *     # Create a new section
	 *     wp datamachine agent write "New Section" "Initial content"
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
}
