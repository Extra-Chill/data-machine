<?php
/**
 * WP-CLI Pinterest Command
 *
 * Provides CLI access to Pinterest board management.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.28.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Pinterest\PinterestAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Pinterest integration for Data Machine.
 *
 * ## EXAMPLES
 *
 *     wp datamachine pinterest sync-boards
 *     wp datamachine pinterest list-boards
 *     wp datamachine pinterest status
 */
class PinterestCommand extends BaseCommand {

	/**
	 * Sync Pinterest boards from API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pinterest sync-boards
	 *
	 * @subcommand sync-boards
	 */
	public function sync_boards( $args, $assoc_args ) {
		WP_CLI::log( 'Syncing Pinterest boards...' );
		$result = PinterestAbilities::sync_boards();

		if ( $result['success'] ) {
			WP_CLI::success( "Synced {$result['count']} boards." );
			$this->format_items( $result['boards'], [ 'id', 'name', 'description' ], $assoc_args );
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
	 *     wp datamachine pinterest list-boards
	 *     wp datamachine pinterest list-boards --format=json
	 *
	 * @subcommand list-boards
	 */
	public function list_boards( $args, $assoc_args ) {
		$boards = PinterestAbilities::get_cached_boards();

		if ( empty( $boards ) ) {
			WP_CLI::warning( 'No cached boards. Run: wp datamachine pinterest sync-boards' );
			return;
		}

		$this->format_items( $boards, [ 'id', 'name', 'description' ], $assoc_args );
	}

	/**
	 * Show Pinterest integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pinterest status
	 */
	public function status( $args, $assoc_args ) {
		$authenticated = PinterestAbilities::is_configured();
		$sync_status   = PinterestAbilities::get_sync_status();

		WP_CLI::log( 'Pinterest Integration Status' );
		WP_CLI::log( '---' );
		WP_CLI::log( 'Authenticated: ' . ( $authenticated ? 'Yes ✓' : 'No ✗' ) );
		WP_CLI::log( 'Cached boards: ' . $sync_status['board_count'] );
		WP_CLI::log( 'Last synced: ' . $sync_status['last_synced'] );
	}
}
