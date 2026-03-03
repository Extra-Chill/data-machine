<?php
/**
 * WP-CLI Auth Command
 *
 * CLI surface for authentication management operations.
 * Wraps the AuthAbilities layer for status, connect, disconnect, and config.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.36.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class AuthCommand extends BaseCommand {

	/**
	 * Auth abilities instance.
	 *
	 * @var AuthAbilities
	 */
	private AuthAbilities $abilities;

	public function __construct() {
		$this->abilities = new AuthAbilities();
	}

	/**
	 * Show authentication status for all providers or a specific handler.
	 *
	 * ## OPTIONS
	 *
	 * [<handler_slug>]
	 * : Show detailed status for a specific handler.
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
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show all auth providers
	 *     wp datamachine auth status
	 *
	 *     # Show status for a specific handler
	 *     wp datamachine auth status twitter
	 *
	 *     # JSON output
	 *     wp datamachine auth status --format=json
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$handler_slug = $args[0] ?? null;

		if ( $handler_slug ) {
			$this->showHandlerStatus( $handler_slug, $assoc_args );
			return;
		}

		$this->showAllProviders( $assoc_args );
	}

	/**
	 * Start the authentication flow for a handler.
	 *
	 * For OAuth providers (Twitter, Instagram, Facebook, Reddit, Threads),
	 * displays the authorization URL for the user to visit in a browser.
	 *
	 * For non-OAuth providers (e.g. Bluesky), accepts credentials directly
	 * via --<field>=<value> flags and saves them.
	 *
	 * ## OPTIONS
	 *
	 * <handler_slug>
	 * : Handler to authenticate (e.g., twitter, facebook, bluesky).
	 *
	 * [--<field>=<value>]
	 * : Credential fields for non-OAuth handlers (e.g. --handle=user --app-password=xyz).
	 *
	 * ## EXAMPLES
	 *
	 *     # Start OAuth flow for Twitter
	 *     wp datamachine auth connect twitter
	 *
	 *     # Connect a non-OAuth handler with credentials
	 *     wp datamachine auth connect bluesky --handle=user.bsky.social --app-password=xyz
	 *
	 * @subcommand connect
	 */
	public function connect( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$handler_slug = sanitize_text_field( $args[0] );

		// Check if provider exists.
		if ( ! $this->abilities->providerExists( $handler_slug ) ) {
			// Provider not registered — check if handler exists but doesn't require auth.
			$result = $this->abilities->executeGetAuthStatus( array( 'handler_slug' => $handler_slug ) );

			if ( ! empty( $result['success'] ) && ( $result['requires_auth'] ?? true ) === false ) {
				WP_CLI::success( sprintf( '%s does not require authentication.', ucfirst( $handler_slug ) ) );
				return;
			}

			WP_CLI::error( sprintf( 'Auth provider "%s" not found. Use "wp datamachine auth status" to see available providers.', $handler_slug ) );
			return;
		}

		$provider = $this->abilities->getProviderForHandler( $handler_slug );

		// Determine if this is an OAuth provider.
		$is_oauth = method_exists( $provider, 'get_authorization_url' );

		if ( $is_oauth ) {
			$this->connectOAuth( $handler_slug, $provider );
		} else {
			$this->connectDirect( $handler_slug, $provider, $assoc_args );
		}
	}

	/**
	 * Disconnect authentication for a handler.
	 *
	 * Clears stored account data (tokens, credentials). Does not remove
	 * API configuration (client ID, client secret).
	 *
	 * ## OPTIONS
	 *
	 * <handler_slug>
	 * : Handler to disconnect (e.g., twitter, facebook).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disconnect Twitter
	 *     wp datamachine auth disconnect twitter
	 *
	 *     # Disconnect without confirmation
	 *     wp datamachine auth disconnect twitter --yes
	 *
	 * @subcommand disconnect
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$handler_slug = sanitize_text_field( $args[0] );

		if ( ! $this->abilities->providerExists( $handler_slug ) ) {
			WP_CLI::error( sprintf( 'Auth provider "%s" not found.', $handler_slug ) );
			return;
		}

		// Check current status.
		$auth_status = $this->abilities->getAuthStatus( $handler_slug );

		if ( ! $auth_status['authenticated'] ) {
			WP_CLI::warning( sprintf( '%s is not currently authenticated.', ucfirst( $handler_slug ) ) );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Disconnect %s? This will clear stored account data.', ucfirst( $handler_slug ) ) );
		}

		$result = $this->abilities->executeDisconnectAuth( array( 'handler_slug' => $handler_slug ) );

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( $result['message'] ?? sprintf( '%s disconnected.', ucfirst( $handler_slug ) ) );
		} else {
			WP_CLI::error( $result['error'] ?? 'Failed to disconnect.' );
		}
	}

	/**
	 * View or save API configuration for a handler.
	 *
	 * Without --<field>=<value> flags, shows the current config and required fields.
	 * With flags, saves the provided configuration values.
	 *
	 * ## OPTIONS
	 *
	 * <handler_slug>
	 * : Handler to configure (e.g., twitter, facebook).
	 *
	 * [--<field>=<value>]
	 * : Configuration values to save (e.g. --client_id=abc --client_secret=xyz).
	 *
	 * [--show-secrets]
	 * : Show full secret values instead of masking them.
	 *
	 * ## EXAMPLES
	 *
	 *     # View current config and required fields
	 *     wp datamachine auth config twitter
	 *
	 *     # Save API credentials
	 *     wp datamachine auth config twitter --client_id=abc123 --client_secret=xyz789
	 *
	 *     # Show unmasked secret values
	 *     wp datamachine auth config twitter --show-secrets
	 *
	 * @subcommand config
	 */
	public function config( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$handler_slug = sanitize_text_field( $args[0] );

		if ( ! $this->abilities->providerExists( $handler_slug ) ) {
			WP_CLI::error( sprintf( 'Auth provider "%s" not found.', $handler_slug ) );
			return;
		}

		$provider = $this->abilities->getProviderForHandler( $handler_slug );

		if ( ! method_exists( $provider, 'get_config_fields' ) ) {
			WP_CLI::error( sprintf( '%s does not have configurable fields.', ucfirst( $handler_slug ) ) );
			return;
		}

		$config_fields = $provider->get_config_fields();

		if ( empty( $config_fields ) ) {
			WP_CLI::success( sprintf( '%s has no configuration fields.', ucfirst( $handler_slug ) ) );
			return;
		}

		// Strip non-config flags from assoc_args.
		$show_secrets = isset( $assoc_args['show-secrets'] );
		$config_input = array_diff_key(
			$assoc_args,
			array_flip( array( 'format', 'fields', 'show-secrets' ) )
		);

		// If config values were provided, save them.
		if ( ! empty( $config_input ) ) {
			$this->saveConfig( $handler_slug, $config_input );
			return;
		}

		// Otherwise, display current config.
		$this->showConfig( $handler_slug, $provider, $config_fields, $show_secrets );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Show table of all registered auth providers.
	 *
	 * @param array $assoc_args CLI arguments.
	 */
	private function showAllProviders( array $assoc_args ): void {
		$providers = $this->abilities->getAllProviders();

		if ( empty( $providers ) ) {
			WP_CLI::warning( 'No auth providers registered. Install a Data Machine extension (e.g. data-machine-socials) to register providers.' );
			return;
		}

		$items = array();

		foreach ( $providers as $key => $provider ) {
			$authenticated = method_exists( $provider, 'is_authenticated' ) ? $provider->is_authenticated() : false;
			$configured    = method_exists( $provider, 'is_configured' ) ? $provider->is_configured() : false;
			$is_oauth      = method_exists( $provider, 'get_authorization_url' );

			$items[] = array(
				'provider'      => $key,
				'type'          => $is_oauth ? 'oauth' : 'direct',
				'configured'    => $configured ? 'yes' : 'no',
				'authenticated' => $authenticated ? 'yes' : 'no',
			);
		}

		$this->format_items( $items, array( 'provider', 'type', 'configured', 'authenticated' ), $assoc_args );
	}

	/**
	 * Show detailed status for a specific handler.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param array  $assoc_args   CLI arguments.
	 */
	private function showHandlerStatus( string $handler_slug, array $assoc_args ): void {
		$auth_status = $this->abilities->getAuthStatus( $handler_slug );
		$format      = $assoc_args['format'] ?? 'table';

		if ( ! $auth_status['exists'] ) {
			WP_CLI::error( sprintf( 'Auth provider "%s" not found.', $handler_slug ) );
			return;
		}

		$provider      = $auth_status['provider'];
		$authenticated = $auth_status['authenticated'];
		$configured    = method_exists( $provider, 'is_configured' ) ? $provider->is_configured() : false;
		$is_oauth      = method_exists( $provider, 'get_authorization_url' );

		if ( 'json' === $format ) {
			$data = array(
				'provider'      => $handler_slug,
				'type'          => $is_oauth ? 'oauth' : 'direct',
				'configured'    => $configured,
				'authenticated' => $authenticated,
			);

			if ( $authenticated && method_exists( $provider, 'get_account_details' ) ) {
				$details = $provider->get_account_details();
				if ( $details ) {
					$data['account'] = $details;
				}
			}

			WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::log( sprintf( 'Provider: %s', $handler_slug ) );
		WP_CLI::log( sprintf( 'Type: %s', $is_oauth ? 'OAuth' : 'Direct' ) );
		WP_CLI::log( sprintf( 'Configured: %s', $configured ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Authenticated: %s', $authenticated ? 'yes' : 'no' ) );

		// Show account details if authenticated.
		if ( $authenticated && method_exists( $provider, 'get_account_details' ) ) {
			$details = $provider->get_account_details();
			if ( $details ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Account:' );
				foreach ( $details as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
				}
			}
		}

		// Show config field info.
		if ( method_exists( $provider, 'get_config_fields' ) ) {
			$fields = $provider->get_config_fields();
			if ( ! empty( $fields ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Config Fields:' );
				foreach ( $fields as $field_name => $field_def ) {
					$required = ! empty( $field_def['required'] ) ? ' (required)' : '';
					$label    = $field_def['label'] ?? $field_name;
					WP_CLI::log( sprintf( '  %s: %s%s', $field_name, $label, $required ) );
				}
			}
		}
	}

	/**
	 * Handle OAuth connect flow — print auth URL.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param object $provider     Auth provider instance.
	 */
	private function connectOAuth( string $handler_slug, object $provider ): void {
		// Check if configured first.
		if ( method_exists( $provider, 'is_configured' ) && ! $provider->is_configured() ) {
			WP_CLI::error( sprintf(
				'%s OAuth credentials not configured. Run "wp datamachine auth config %s --client_id=... --client_secret=..." first.',
				ucfirst( $handler_slug ),
				$handler_slug
			) );
			return;
		}

		$result = $this->abilities->executeGetAuthStatus( array( 'handler_slug' => $handler_slug ) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get auth status.' );
			return;
		}

		if ( ! empty( $result['authenticated'] ) && ( $result['requires_auth'] ?? true ) === true ) {
			WP_CLI::success( sprintf( '%s is already authenticated.', ucfirst( $handler_slug ) ) );
			return;
		}

		if ( empty( $result['oauth_url'] ) ) {
			WP_CLI::error( 'No authorization URL returned. Check provider configuration.' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Authorize %s by visiting this URL:', ucfirst( $handler_slug ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( $result['oauth_url'] );
		WP_CLI::log( '' );
		WP_CLI::log( 'After authorizing, you will be redirected back to your site to complete the connection.' );
	}

	/**
	 * Handle direct credential connect — save account data.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param object $provider     Auth provider instance.
	 * @param array  $assoc_args   CLI arguments containing credential fields.
	 */
	private function connectDirect( string $handler_slug, object $provider, array $assoc_args ): void {
		if ( ! method_exists( $provider, 'get_config_fields' ) ) {
			WP_CLI::error( sprintf( '%s does not support direct credential input.', ucfirst( $handler_slug ) ) );
			return;
		}

		$config_fields = $provider->get_config_fields();

		if ( empty( $config_fields ) ) {
			WP_CLI::error( sprintf( '%s has no credential fields defined.', ucfirst( $handler_slug ) ) );
			return;
		}

		// Collect field values from assoc_args.
		// Map hyphenated CLI flags to underscored field names.
		$config_data = array();
		$missing     = array();

		foreach ( $config_fields as $field_name => $field_def ) {
			$cli_key = str_replace( '_', '-', $field_name );
			$value   = $assoc_args[ $cli_key ] ?? $assoc_args[ $field_name ] ?? '';

			if ( empty( $value ) && ! empty( $field_def['required'] ) ) {
				$missing[] = sprintf( '--%s', $cli_key );
			}

			$config_data[ $field_name ] = sanitize_text_field( $value );
		}

		if ( ! empty( $missing ) ) {
			WP_CLI::error( sprintf(
				'Missing required fields: %s',
				implode( ', ', $missing )
			) );
			return;
		}

		// Save via abilities layer.
		$result = $this->abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => $handler_slug,
				'config'       => $config_data,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( $result['message'] ?? sprintf( '%s credentials saved.', ucfirst( $handler_slug ) ) );
		} else {
			WP_CLI::error( $result['error'] ?? 'Failed to save credentials.' );
		}
	}

	/**
	 * Show current config for a handler.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param object $provider     Auth provider instance.
	 * @param array  $config_fields Config field definitions.
	 * @param bool   $show_secrets  Whether to show unmasked secrets.
	 */
	private function showConfig( string $handler_slug, object $provider, array $config_fields, bool $show_secrets ): void {
		$current_config = array();

		if ( method_exists( $provider, 'get_config' ) ) {
			$current_config = $provider->get_config();
		}

		WP_CLI::log( sprintf( 'Configuration for %s:', ucfirst( $handler_slug ) ) );
		WP_CLI::log( '' );

		foreach ( $config_fields as $field_name => $field_def ) {
			$label    = $field_def['label'] ?? $field_name;
			$required = ! empty( $field_def['required'] ) ? ' (required)' : '';
			$value    = $current_config[ $field_name ] ?? '';
			$is_secret = $this->isSecretField( $field_name, $field_def );

			if ( ! empty( $value ) ) {
				$display_value = ( $is_secret && ! $show_secrets )
					? $this->maskValue( $value )
					: $value;
			} else {
				$display_value = WP_CLI::colorize( '%y(not set)%n' );
			}

			WP_CLI::log( sprintf( '  %s%s: %s', $label, $required, $display_value ) );
		}

		if ( ! $show_secrets ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Use --show-secrets to reveal masked values.' );
		}

		WP_CLI::log( '' );
		$cli_key_example = str_replace( '_', '-', array_key_first( $config_fields ) );
		WP_CLI::log( sprintf(
			'To update: wp datamachine auth config %s --%s=<value>',
			$handler_slug,
			$cli_key_example
		) );
	}

	/**
	 * Save config values for a handler.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param array  $config_input Config key-value pairs from CLI.
	 */
	private function saveConfig( string $handler_slug, array $config_input ): void {
		// Map hyphenated CLI keys back to underscored field names.
		$config = array();
		foreach ( $config_input as $key => $value ) {
			$field_name            = str_replace( '-', '_', $key );
			$config[ $field_name ] = $value;
		}

		$result = $this->abilities->executeSaveAuthConfig(
			array(
				'handler_slug' => $handler_slug,
				'config'       => $config,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			WP_CLI::success( $result['message'] ?? sprintf( '%s configuration saved.', ucfirst( $handler_slug ) ) );
		} else {
			WP_CLI::error( $result['error'] ?? 'Failed to save configuration.' );
		}
	}

	/**
	 * Check if a field likely contains a secret value.
	 *
	 * @param string $field_name Field name.
	 * @param array  $field_def  Field definition.
	 * @return bool True if field is likely a secret.
	 */
	private function isSecretField( string $field_name, array $field_def ): bool {
		$secret_patterns = array( 'secret', 'password', 'token', 'key' );
		$name_lower      = strtolower( $field_name );

		foreach ( $secret_patterns as $pattern ) {
			if ( str_contains( $name_lower, $pattern ) ) {
				return true;
			}
		}

		// Check field type if available.
		$type = $field_def['type'] ?? '';
		if ( 'password' === $type ) {
			return true;
		}

		return false;
	}

	/**
	 * Mask a secret value, showing only the last 4 characters.
	 *
	 * @param string $value Value to mask.
	 * @return string Masked value.
	 */
	private function maskValue( string $value ): string {
		$length = strlen( $value );

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - 4 ) . substr( $value, -4 );
	}
}
