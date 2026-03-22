<?php
/**
 * WP-CLI External Sites Command
 *
 * Manages external Data Machine site connections — stores bearer tokens
 * received from other DM instances via the authorize flow or manual entry.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.56.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Auth\AgentAuthCallback;

defined( 'ABSPATH' ) || exit;

/**
 * Manage connections to external Data Machine sites.
 *
 * Register, list, and remove bearer tokens for authenticating
 * with other Data Machine instances.
 *
 * @since 0.56.0
 */
class ExternalCommand extends BaseCommand {

	/**
	 * Register an external site with a bearer token.
	 *
	 * Stores the token so this Data Machine instance can authenticate
	 * with the remote site's API.
	 *
	 * ## OPTIONS
	 *
	 * <site>
	 * : Remote site domain (e.g., extrachill.com).
	 *
	 * <agent_slug>
	 * : Agent slug on the remote site.
	 *
	 * [--token=<token>]
	 * : Bearer token. If omitted, reads from STDIN.
	 *
	 * [--agent-id=<id>]
	 * : Agent ID on the remote site (optional metadata).
	 *
	 * [--verify]
	 * : Test the token against the remote site before storing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Register with inline token
	 *     wp datamachine external add extrachill.com sarai --token="datamachine_sarai_abc123..."
	 *
	 *     # Register with token from STDIN (for piping)
	 *     echo "datamachine_sarai_abc123..." | wp datamachine external add extrachill.com sarai
	 *
	 *     # Register and verify the token works
	 *     wp datamachine external add extrachill.com sarai --token="..." --verify
	 *
	 * @subcommand add
	 */
	public function add( array $args, array $assoc_args ): void {
		$site       = $args[0] ?? '';
		$agent_slug = $args[1] ?? '';

		if ( empty( $site ) || empty( $agent_slug ) ) {
			WP_CLI::error( 'Usage: wp datamachine external add <site> <agent_slug> [--token=...]' );
			return;
		}

		// Clean up domain.
		$site = str_replace( array( 'https://', 'http://' ), '', rtrim( $site, '/' ) );

		// Get token from flag or STDIN.
		$token = $assoc_args['token'] ?? null;
		if ( null === $token ) {
			$token = trim( file_get_contents( 'php://stdin' ) );
		}

		if ( empty( $token ) ) {
			WP_CLI::error( 'A bearer token is required. Pass via --token= or pipe to STDIN.' );
			return;
		}

		$agent_id = isset( $assoc_args['agent-id'] ) ? (int) $assoc_args['agent-id'] : 0;

		// Optionally verify the token works.
		if ( isset( $assoc_args['verify'] ) ) {
			WP_CLI::log( sprintf( 'Verifying token against https://%s...', $site ) );

			$response = wp_remote_get(
				sprintf( 'https://%s/wp-json/wp/v2/users/me', $site ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_CLI::error( sprintf( 'Verification failed: %s', $response->get_error_message() ) );
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				WP_CLI::error( sprintf( 'Verification failed: HTTP %d — %s', $code, $body['message'] ?? 'Unknown error' ) );
				return;
			}

			WP_CLI::log( sprintf( '  Authenticated as: %s (ID: %d)', $body['name'] ?? 'unknown', $body['id'] ?? 0 ) );
		}

		// Store the token.
		$key    = $site . '/' . $agent_slug;
		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );

		$tokens[ $key ] = array(
			'remote_site' => $site,
			'agent_slug'  => $agent_slug,
			'agent_id'    => $agent_id,
			'token'       => $token,
			'received_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		update_option( AgentAuthCallback::OPTION_KEY, $tokens, false );

		WP_CLI::success( sprintf( 'Stored token for %s/%s.', $site, $agent_slug ) );
	}

	/**
	 * List registered external site connections.
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
	 *     wp datamachine external list
	 *     wp datamachine external list --format=json
	 *
	 * @subcommand list
	 */
	public function list_sites( array $args, array $assoc_args ): void {
		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );

		if ( empty( $tokens ) ) {
			WP_CLI::log( 'No external sites registered.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		$items  = array();

		foreach ( $tokens as $key => $data ) {
			$items[] = array(
				'key'         => $key,
				'remote_site' => $data['remote_site'] ?? '',
				'agent_slug'  => $data['agent_slug'] ?? '',
				'agent_id'    => $data['agent_id'] ?? 0,
				'received_at' => $data['received_at'] ?? '',
				'has_token'   => ! empty( $data['token'] ) ? 'Yes' : 'No',
			);
		}

		$this->format_items( $items, array( 'key', 'remote_site', 'agent_slug', 'agent_id', 'received_at', 'has_token' ), $assoc_args, 'key' );
	}

	/**
	 * Remove an external site connection.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Connection key (site/agent_slug, e.g., "extrachill.com/sarai").
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine external remove extrachill.com/sarai
	 *     wp datamachine external remove extrachill.com/sarai --yes
	 *
	 * @subcommand remove
	 */
	public function remove( array $args, array $assoc_args ): void {
		$key = $args[0] ?? '';

		if ( empty( $key ) ) {
			WP_CLI::error( 'Connection key is required (e.g., "extrachill.com/sarai").' );
			return;
		}

		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );

		if ( ! isset( $tokens[ $key ] ) ) {
			WP_CLI::error( sprintf( 'No connection found for "%s".', $key ) );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Remove external connection "%s"?', $key ) );
		}

		unset( $tokens[ $key ] );
		update_option( AgentAuthCallback::OPTION_KEY, $tokens, false );

		WP_CLI::success( sprintf( 'Removed connection "%s".', $key ) );
	}

	/**
	 * Show connection details including the stored token.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Connection key (site/agent_slug, e.g., "extrachill.com/sarai").
	 *
	 * [--show-token]
	 * : Display the bearer token value (hidden by default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine external show extrachill.com/sarai
	 *     wp datamachine external show extrachill.com/sarai --show-token
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		$key = $args[0] ?? '';

		if ( empty( $key ) ) {
			WP_CLI::error( 'Connection key is required.' );
			return;
		}

		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );

		if ( ! isset( $tokens[ $key ] ) ) {
			WP_CLI::error( sprintf( 'No connection found for "%s".', $key ) );
			return;
		}

		$data = $tokens[ $key ];

		WP_CLI::log( sprintf( 'Remote site:  %s', $data['remote_site'] ?? '' ) );
		WP_CLI::log( sprintf( 'Agent slug:   %s', $data['agent_slug'] ?? '' ) );
		WP_CLI::log( sprintf( 'Agent ID:     %s', $data['agent_id'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( 'Received:     %s', $data['received_at'] ?? '' ) );

		if ( isset( $assoc_args['show-token'] ) && ! empty( $data['token'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Bearer token: %s', $data['token'] ) );
		} else {
			$prefix = substr( $data['token'] ?? '', 0, 20 );
			WP_CLI::log( sprintf( 'Token:        %s... (use --show-token to reveal)', $prefix ) );
		}
	}

	/**
	 * Test connectivity to an external site using the stored token.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Connection key (site/agent_slug, e.g., "extrachill.com/sarai").
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine external test extrachill.com/sarai
	 *
	 * @subcommand test
	 */
	public function test( array $args, array $assoc_args ): void {
		$key = $args[0] ?? '';

		if ( empty( $key ) ) {
			WP_CLI::error( 'Connection key is required.' );
			return;
		}

		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );

		if ( ! isset( $tokens[ $key ] ) ) {
			WP_CLI::error( sprintf( 'No connection found for "%s".', $key ) );
			return;
		}

		$data  = $tokens[ $key ];
		$site  = $data['remote_site'];
		$token = $data['token'];

		WP_CLI::log( sprintf( 'Testing connection to %s...', $site ) );

		// Test 1: Authentication.
		$response = wp_remote_get(
			sprintf( 'https://%s/wp-json/wp/v2/users/me', $site ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( sprintf( 'Connection failed: %s', $response->get_error_message() ) );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			WP_CLI::error( sprintf( 'Authentication failed: HTTP %d — %s', $code, $body['message'] ?? 'Unknown error' ) );
			return;
		}

		WP_CLI::log( sprintf( '  Auth:      OK — %s (ID: %d)', $body['name'] ?? 'unknown', $body['id'] ?? 0 ) );

		// Test 2: Agent memory access.
		$memory_response = wp_remote_get(
			sprintf( 'https://%s/wp-json/datamachine/v1/files/agent', $site ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);

		$memory_code = wp_remote_retrieve_response_code( $memory_response );
		if ( 200 === $memory_code ) {
			$files = json_decode( wp_remote_retrieve_body( $memory_response ), true );
			$count = is_array( $files ) ? count( $files ) : 0;
			WP_CLI::log( sprintf( '  Memory:    OK — %d file(s) accessible', $count ) );
		} else {
			WP_CLI::log( sprintf( '  Memory:    HTTP %d (Data Machine may not be active on this site)', $memory_code ) );
		}

		WP_CLI::success( sprintf( 'Connection to %s is working.', $site ) );
	}
}
