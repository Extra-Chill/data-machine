<?php
/**
 * WP-CLI Pinterest Command
 *
 * Provides CLI access to Pinterest board management and publishing.
 *
 * @package DataMachineSocials\Cli\Commands
 * @since 0.1.0
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;
use DataMachineSocials\Abilities\Pinterest\PinterestBoardsAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Pinterest integration for Data Machine Socials.
 *
 * ## EXAMPLES
 *
 *     # Sync Pinterest boards
 *     wp datamachine-socials pinterest sync-boards
 *
 *     # List cached boards
 *     wp datamachine-socials pinterest list-boards
 *
 *     # Check Pinterest status
 *     wp datamachine-socials pinterest status
 */
class PinterestCommand {

	/**
	 * Sync Pinterest boards from API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials pinterest sync-boards
	 *
	 * @subcommand sync-boards
	 */
	public function sync_boards( $args, $assoc_args ) {
		WP_CLI::log( 'Syncing Pinterest boards...' );

		$result = PinterestBoardsAbility::sync_boards();

		if ( $result['success'] ) {
			WP_CLI::success( "Synced {$result['count']} boards." );
			$this->format_items( $result['boards'], array( 'id', 'name', 'description' ), $assoc_args );
		} else {
			WP_CLI::error( $result['error'] );
		}
	}

	/**
	 * List cached Pinterest boards.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials pinterest list-boards
	 *     wp datamachine-socials pinterest list-boards --format=json
	 *
	 * @subcommand list-boards
	 */
	public function list_boards( $args, $assoc_args ) {
		$boards = PinterestBoardsAbility::get_cached_boards();

		if ( empty( $boards ) ) {
			WP_CLI::warning( 'No cached boards. Run: wp datamachine-socials pinterest sync-boards' );
			return;
		}

		$this->format_items( $boards, array( 'id', 'name', 'description' ), $assoc_args );
	}

	/**
	 * Show Pinterest integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials pinterest status
	 */
	public function status( $args, $assoc_args ) {
		$authenticated = PinterestBoardsAbility::is_configured();
		$sync_status = PinterestBoardsAbility::get_sync_status();

		WP_CLI::log( 'Pinterest Integration Status' );
		WP_CLI::log( '---' );
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes ✓' : 'No ✗' ) );
		WP_CLI::log( 'Cached boards: ' . $sync_status['board_count'] );
		WP_CLI::log( 'Last synced: ' . $sync_status['last_synced'] );
	}

	/**
	 * List your recent pins.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of pins to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--bookmark=<bookmark>]
	 * : Pagination bookmark for next page.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials pinterest pins
	 *     wp datamachine-socials pinterest pins --limit=10 --format=json
	 */
	public function pins( $args, $assoc_args ) {
		$ability = $this->get_read_ability();

		$result = $ability->execute( array(
			'action'   => 'pins',
			'limit'    => absint( $assoc_args['limit'] ?? 25 ),
			'bookmark' => $assoc_args['bookmark'] ?? '',
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$pins   = $data['pins'] ?? array();
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $pins ) ) {
			WP_CLI::warning( 'No pins found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( "Found {$data['count']} pins" );
		WP_CLI::log( '' );

		foreach ( $pins as $pin ) {
			$title = mb_substr( $pin['title'] ?? '(no title)', 0, 50 );
			$desc  = mb_substr( $pin['description'] ?? '', 0, 40 );
			$id    = $pin['id'] ?? '';

			WP_CLI::log( sprintf( '  %s  %s  %s', $id, $title, $desc ) );
		}

		if ( $data['has_next'] && ! empty( $data['bookmark'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Next page: --bookmark={$data['bookmark']}" );
		}
	}

	/**
	 * Get details for a specific pin.
	 *
	 * ## OPTIONS
	 *
	 * <pin_id>
	 * : The Pinterest pin ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function pin( $args, $assoc_args ) {
		$pin_id  = $args[0];
		$ability = $this->get_read_ability();

		$result = $ability->execute( array(
			'action' => 'pin',
			'pin_id' => $pin_id,
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::log( wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List pins from a specific board.
	 *
	 * ## OPTIONS
	 *
	 * <board_id>
	 * : The Pinterest board ID.
	 *
	 * [--limit=<limit>]
	 * : Number of pins to return.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-socials pinterest board-pins 123456789
	 *
	 * @subcommand board-pins
	 */
	public function board_pins( $args, $assoc_args ) {
		$board_id = $args[0];
		$ability  = $this->get_read_ability();

		$result = $ability->execute( array(
			'action'   => 'board_pins',
			'board_id' => $board_id,
			'limit'    => absint( $assoc_args['limit'] ?? 25 ),
			'bookmark' => $assoc_args['bookmark'] ?? '',
		) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] );
		}

		$data   = $result['data'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$pins = $data['pins'] ?? array();
		if ( empty( $pins ) ) {
			WP_CLI::warning( 'No pins found on this board.' );
			return;
		}

		WP_CLI::success( "Found {$data['count']} pins" );
		WP_CLI::log( '' );

		foreach ( $pins as $pin ) {
			$title = mb_substr( $pin['title'] ?? '(no title)', 0, 50 );
			WP_CLI::log( sprintf( '  %s  %s', $pin['id'] ?? '', $title ) );
		}
	}

	/**
	 * Get the Pinterest read ability.
	 *
	 * @return \DataMachineSocials\Abilities\Pinterest\PinterestReadAbility
	 */
	private function get_read_ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API not available (requires WP 6.9+).' );
		}

		$ability = wp_get_ability( 'datamachine/pinterest-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/pinterest-read ability not registered.' );
		}

		return new \DataMachineSocials\Abilities\Pinterest\PinterestReadAbility();
	}

	/**
	 * Format items for output.
	 *
	 * @param array  $items      Items to format.
	 * @param array  $fields     Fields to display.
	 * @param array  $assoc_args CLI arguments.
	 */
	private function format_items( $items, $fields, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'csv' === $format ) {
			// Output CSV header
			echo implode( ',', $fields ) . "\n";
			foreach ( $items as $item ) {
				$row = array();
				foreach ( $fields as $field ) {
					$value = $item[ $field ] ?? '';
					// Escape quotes and wrap in quotes if contains comma
					if ( strpos( $value, ',' ) !== false || strpos( $value, '"' ) !== false ) {
						$value = '"' . str_replace( '"', '""', $value ) . '"';
					}
					$row[] = $value;
				}
				echo implode( ',', $row ) . "\n";
			}
			return;
		}

		// Table format
		$max_lengths = array();
		foreach ( $fields as $field ) {
			$max_lengths[ $field ] = strlen( $field );
		}

		foreach ( $items as $item ) {
			foreach ( $fields as $field ) {
				$len = strlen( $item[ $field ] ?? '' );
				if ( $len > $max_lengths[ $field ] ) {
					$max_lengths[ $field ] = $len;
				}
			}
		}

		// Print header
		$header = '| ';
		foreach ( $fields as $field ) {
			$header .= str_pad( $field, $max_lengths[ $field ] + 2 ) . '| ';
		}
		WP_CLI::log( $header );

		// Print separator
		$sep = '|';
		foreach ( $fields as $field ) {
			$sep .= str_repeat( '-', $max_lengths[ $field ] + 3 ) . '|';
		}
		WP_CLI::log( $sep );

		// Print rows
		foreach ( $items as $item ) {
			$row = '| ';
			foreach ( $fields as $field ) {
				$row .= str_pad( $item[ $field ] ?? '', $max_lengths[ $field ] + 2 ) . '| ';
			}
			WP_CLI::log( $row );
		}
	}
}
