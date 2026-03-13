<?php
/**
 * WP-CLI System Command
 *
 * Wraps SystemAbilities for health checks and session title generation.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\SystemAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * System health checks and diagnostics.
 *
 * @since 0.41.0
 */
class SystemCommand extends BaseCommand {

	/**
	 * Run system health checks.
	 *
	 * ## OPTIONS
	 *
	 * [--types=<types>]
	 * : Comma-separated check types to run. Use "all" for all default checks.
	 * ---
	 * default: all
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
	 *     wp datamachine system health
	 *     wp datamachine system health --types=system
	 *     wp datamachine system health --format=json
	 *
	 * @subcommand health
	 */
	public function health( array $args, array $assoc_args ): void {
		$types_raw = $assoc_args['types'] ?? 'all';
		$format    = $assoc_args['format'] ?? 'table';
		$types     = array_map( 'trim', explode( ',', $types_raw ) );

		$ability = new SystemAbilities();
		$result  = $ability->executeHealthCheck( array( 'types' => $types ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Health check failed.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table output.
		WP_CLI::log( $result['summary'] );
		WP_CLI::log( '' );

		foreach ( $result['results'] as $type_id => $data ) {
			WP_CLI::log( sprintf( '--- %s ---', $data['label'] ) );

			$check_result = $data['result'] ?? array();

			if ( isset( $check_result['version'] ) ) {
				WP_CLI::log( sprintf( '  Plugin version: %s', $check_result['version'] ) );
			}
			if ( isset( $check_result['php_version'] ) ) {
				WP_CLI::log( sprintf( '  PHP version:    %s', $check_result['php_version'] ) );
			}
			if ( isset( $check_result['wp_version'] ) ) {
				WP_CLI::log( sprintf( '  WP version:     %s', $check_result['wp_version'] ) );
			}
			if ( isset( $check_result['abilities'] ) ) {
				WP_CLI::log( sprintf( '  Abilities:      %d registered', count( $check_result['abilities'] ) ) );
			}
			if ( isset( $check_result['rest_status'] ) ) {
				$rest_ok = $check_result['rest_status']['namespace_registered'] ?? false;
				WP_CLI::log( sprintf( '  REST API:       %s', $rest_ok ? 'registered' : 'NOT registered' ) );
			}

			WP_CLI::log( '' );
		}

		WP_CLI::log( sprintf( 'Available check types: %s', implode( ', ', $result['available'] ) ) );
	}

	/**
	 * Generate a title for a chat session.
	 *
	 * ## OPTIONS
	 *
	 * <session_id>
	 * : UUID of the chat session.
	 *
	 * [--force]
	 * : Force regeneration even if title already exists.
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
	 *     wp datamachine system title abc123-def456
	 *     wp datamachine system title abc123-def456 --force
	 *
	 * @subcommand title
	 */
	public function title( array $args, array $assoc_args ): void {
		$session_id = $args[0] ?? '';
		$force      = isset( $assoc_args['force'] );
		$format     = $assoc_args['format'] ?? 'table';

		if ( empty( $session_id ) ) {
			WP_CLI::error( 'Session ID is required.' );
			return;
		}

		$result = SystemAbilities::generateSessionTitle(
			array(
				'session_id' => $session_id,
				'force'      => $force,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? $result['message'] ?? 'Title generation failed.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Title:  %s', $result['title'] ) );
		WP_CLI::log( sprintf( 'Method: %s', $result['method'] ) );
	}
}
