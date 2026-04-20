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
use DataMachine\Core\Auth\RemoteAgentClient;

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

		$data       = $tokens[ $key ];
		$site       = (string) ( $data['remote_site'] ?? '' );
		$agent_slug = (string) ( $data['agent_slug'] ?? '' );

		WP_CLI::log( sprintf( 'Testing connection to %s...', $site ) );

		// Test 1: Authentication.
		$auth = RemoteAgentClient::request( $site, $agent_slug, 'GET', '/wp-json/wp/v2/users/me', array( 'timeout' => 15 ) );

		if ( ! $auth['success'] ) {
			WP_CLI::error( sprintf( 'Authentication failed: %s', $auth['error'] ?? 'unknown error' ) );
			return;
		}

		$user_body = is_array( $auth['body'] ) ? $auth['body'] : array();
		WP_CLI::log(
			sprintf(
				'  Auth:      OK — %s (ID: %d)',
				$user_body['name'] ?? 'unknown',
				(int) ( $user_body['id'] ?? 0 )
			)
		);

		// Test 2: Agent memory access.
		$memory = RemoteAgentClient::request( $site, $agent_slug, 'GET', '/wp-json/datamachine/v1/files/agent', array( 'timeout' => 15 ) );

		if ( $memory['success'] ) {
			$files = is_array( $memory['body'] ) ? $memory['body'] : array();
			WP_CLI::log( sprintf( '  Memory:    OK — %d file(s) accessible', count( $files ) ) );
		} else {
			WP_CLI::log( sprintf( '  Memory:    HTTP %d (Data Machine may not be active on this site)', $memory['status_code'] ) );
		}

		WP_CLI::success( sprintf( 'Connection to %s is working.', $site ) );
	}

	/**
	 * Print the authorize URL to initiate the cross-site agent auth flow.
	 *
	 * The remote Data Machine site hosts an authorize endpoint that mints
	 * a bearer token after the human approves access via a consent screen.
	 * This command builds the URL for the user to open in a browser.
	 *
	 * After the user clicks Authorize on the remote site, the token is
	 * delivered to this site's /agent/auth/callback endpoint and stored
	 * automatically. The connection will then appear in `external list`.
	 *
	 * ## OPTIONS
	 *
	 * <site>
	 * : Remote site domain (e.g., chubes.net).
	 *
	 * <agent_slug>
	 * : Agent slug on the remote site (e.g., chubes-bot).
	 *
	 * [--label=<label>]
	 * : Optional token label (shown in the remote agent's token list).
	 *
	 * [--redirect-uri=<uri>]
	 * : Custom callback URL. Defaults to this site's
	 *   /wp-json/datamachine/v1/agent/auth/callback endpoint.
	 *
	 * [--open]
	 * : Attempt to open the URL in the system browser (macOS/Linux/Windows).
	 *
	 * ## EXAMPLES
	 *
	 *     # Print the URL for manual opening
	 *     wp datamachine external connect chubes.net chubes-bot
	 *
	 *     # Label the token so it's identifiable on the remote side
	 *     wp datamachine external connect chubes.net chubes-bot --label="franklin-intelligence-chubes4"
	 *
	 *     # Attempt to open the URL in a browser
	 *     wp datamachine external connect chubes.net chubes-bot --open
	 *
	 * @subcommand connect
	 */
	public function connect( array $args, array $assoc_args ): void {
		$site       = $args[0] ?? '';
		$agent_slug = $args[1] ?? '';

		if ( '' === $site || '' === $agent_slug ) {
			WP_CLI::error( 'Usage: wp datamachine external connect <site> <agent_slug> [--label=...] [--redirect-uri=...]' );
			return;
		}

		$site = RemoteAgentClient::normalize_site( $site );

		if ( '' === $site ) {
			WP_CLI::error( 'Invalid site.' );
			return;
		}

		$redirect_uri = (string) ( $assoc_args['redirect-uri'] ?? '' );
		$label        = (string) ( $assoc_args['label'] ?? '' );

		$url = RemoteAgentClient::build_authorize_url( $site, $agent_slug, $redirect_uri, $label );

		WP_CLI::log( '' );
		WP_CLI::log( 'Open the following URL in a browser while logged in to the remote site as a user with access to the agent:' );
		WP_CLI::log( '' );
		WP_CLI::log( '  ' . $url );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'After you click Authorize, a bearer token will be delivered to this site and stored as "%s/%s".', $site, $agent_slug ) );
		WP_CLI::log( 'Verify afterwards with: wp datamachine external list' );

		if ( ! empty( $assoc_args['open'] ) ) {
			$opener = '';
			if ( stripos( PHP_OS, 'DAR' ) === 0 ) {
				$opener = 'open';
			} elseif ( stripos( PHP_OS, 'WIN' ) === 0 ) {
				$opener = 'start ""';
			} elseif ( function_exists( 'shell_exec' ) ) {
				$opener = 'xdg-open';
			}

			if ( '' !== $opener && function_exists( 'shell_exec' ) ) {
				@shell_exec( sprintf( '%s %s > /dev/null 2>&1 &', $opener, escapeshellarg( $url ) ) );
				WP_CLI::log( '(Attempted to open URL in your default browser.)' );
			}
		}
	}

	/**
	 * Make an ad-hoc authenticated call to a connected external site.
	 *
	 * Useful for debugging cross-site agent workflows without wiring
	 * up an ability call. Uses the stored bearer token for the given
	 * connection key and prints the response.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Connection key (site/agent_slug, e.g., "chubes.net/chubes-bot").
	 *
	 * <method>
	 * : HTTP method: GET, POST, PUT, PATCH, DELETE.
	 *
	 * <path>
	 * : Path on the remote site (e.g., "/wp-json/wp/v2/posts") or full URL.
	 *
	 * [--body=<json>]
	 * : JSON-encoded request body.
	 *
	 * [--header=<header>]
	 * : Additional header in "Name: value" form. Repeatable.
	 *
	 * [--timeout=<seconds>]
	 * : Request timeout in seconds. Default 30.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - raw
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Who am I on the remote site?
	 *     wp datamachine external call chubes.net/chubes-bot GET /wp-json/wp/v2/users/me
	 *
	 *     # List wiki articles
	 *     wp datamachine external call chubes.net/chubes-bot GET /wp-json/wp/v2/wiki
	 *
	 *     # Send a chat message to the peer agent
	 *     wp datamachine external call chubes.net/chubes-bot POST /wp-json/datamachine/v1/chat \
	 *         --body='{"message":"hello from franklin"}'
	 *
	 * @subcommand call
	 */
	public function call( array $args, array $assoc_args ): void {
		$key    = $args[0] ?? '';
		$method = $args[1] ?? '';
		$path   = $args[2] ?? '';

		if ( '' === $key || '' === $method || '' === $path ) {
			WP_CLI::error( 'Usage: wp datamachine external call <key> <method> <path> [--body=<json>]' );
			return;
		}

		$tokens = get_option( AgentAuthCallback::OPTION_KEY, array() );
		if ( ! isset( $tokens[ $key ] ) ) {
			WP_CLI::error( sprintf( 'No connection found for "%s". Run `wp datamachine external list` to see registered connections.', $key ) );
			return;
		}

		$data       = $tokens[ $key ];
		$site       = (string) ( $data['remote_site'] ?? '' );
		$agent_slug = (string) ( $data['agent_slug'] ?? '' );

		$request_args = array();

		if ( isset( $assoc_args['timeout'] ) ) {
			$request_args['timeout'] = (int) $assoc_args['timeout'];
		}

		if ( isset( $assoc_args['body'] ) && '' !== $assoc_args['body'] ) {
			$decoded = json_decode( (string) $assoc_args['body'], true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$request_args['body'] = $decoded;
			} else {
				$request_args['body'] = (string) $assoc_args['body'];
			}
		}

		$raw_headers = $assoc_args['header'] ?? array();
		if ( ! is_array( $raw_headers ) ) {
			$raw_headers = array( $raw_headers );
		}

		$headers = array();
		foreach ( $raw_headers as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$headers[ trim( $name ) ] = trim( $value );
		}
		if ( ! empty( $headers ) ) {
			$request_args['headers'] = $headers;
		}

		$result = RemoteAgentClient::request( $site, $agent_slug, $method, $path, $request_args );

		$format = (string) ( $assoc_args['format'] ?? 'json' );

		if ( 'raw' === $format ) {
			WP_CLI::log( $result['raw_body'] );
		} elseif ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'URL:         %s', $result['url'] ) );
			WP_CLI::log( sprintf( 'Status:      %d', $result['status_code'] ) );
			WP_CLI::log( sprintf( 'Success:     %s', $result['success'] ? 'yes' : 'no' ) );
			if ( ! empty( $result['error'] ) ) {
				WP_CLI::log( sprintf( 'Error:       %s', $result['error'] ) );
			}
			WP_CLI::log( '' );
			WP_CLI::log( 'Body:' );
			WP_CLI::log( wp_json_encode( $result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			// Default: full JSON envelope minus the headers/raw_body noise.
			$output = array(
				'success'     => $result['success'],
				'status_code' => $result['status_code'],
				'url'         => $result['url'],
				'body'        => $result['body'],
			);
			if ( ! empty( $result['error'] ) ) {
				$output['error'] = $result['error'];
			}
			WP_CLI::log( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		if ( ! $result['success'] ) {
			WP_CLI::halt( 1 );
		}
	}
}
