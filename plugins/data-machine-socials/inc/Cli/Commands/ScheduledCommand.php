<?php
/**
 * WP-CLI Scheduled Command
 *
 * List and cancel scheduled social publishes. Schedule with the
 * `--publish-at` flag on platform publish commands.
 *
 * @package    DataMachineSocials
 * @subpackage Cli\Commands
 */

namespace DataMachineSocials\Cli\Commands;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage scheduled social publishes.
 *
 * ## EXAMPLES
 *
 *     # Schedule an Instagram post
 *     wp datamachine-socials instagram publish "Tonight!" --image=https://example.com/flyer.jpg --publish-at=2026-10-19T12:00:00-04:00
 *
 *     # List pending scheduled publishes
 *     wp datamachine-socials scheduled list
 *
 *     # Cancel one
 *     wp datamachine-socials scheduled cancel 12345
 */
class ScheduledCommand {

	/**
	 * List scheduled social publishes.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * default: pending
	 * options:
	 *   - pending
	 *   - complete
	 *   - failed
	 *   - canceled
	 *   - any
	 * ---
	 *
	 * [--platform=<platform>]
	 * : Filter by platform slug.
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
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$ability = $this->ability( 'datamachine/social-publish-scheduled-list' );
		$input   = array( 'status' => $assoc_args['status'] ?? 'pending' );
		if ( ! empty( $assoc_args['platform'] ) ) {
			$input['platform'] = (string) $assoc_args['platform'];
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$items = $result['items'] ?? array();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::log( 'No scheduled publishes found.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'schedule_id', 'platform', 'publish_at', 'status', 'attempt', 'caption', 'last_log' ) );
	}

	/**
	 * Cancel a pending scheduled publish.
	 *
	 * ## OPTIONS
	 *
	 * <schedule_id>
	 * : Schedule ID returned when the publish was scheduled.
	 */
	public function cancel( $args ) {
		$result = $this->ability( 'datamachine/social-publish-scheduled-cancel' )->execute( array( 'schedule_id' => (string) ( $args[0] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Canceled scheduled publish ' . $result['schedule_id'] . '.' );
	}

	private function ability( string $name ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			WP_CLI::error( $name . ' ability not registered.' );
		}

		return $ability;
	}
}
