<?php
/**
 * WP-CLI Flows Webhook Command
 *
 * Manages webhook triggers for flows.
 * Extracted from FlowsCommand to follow the focused command pattern.
 *
 * @package DataMachine\Cli\Commands\Flows
 * @since 0.31.0
 * @see https://github.com/Extra-Chill/data-machine/issues/345
 */

namespace DataMachine\Cli\Commands\Flows;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class WebhookCommand extends BaseCommand {

	/**
	 * Dispatch a webhook subcommand.
	 *
	 * Called from FlowsCommand to route webhook operations to this class.
	 *
	 * @param array $args       Positional arguments (action, flow_id).
	 * @param array $assoc_args Associative arguments.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook <enable|disable|regenerate|set-secret|status|list|rate-limit> [flow_id]' );
			return;
		}

		$action    = $args[0];
		$remaining = array_slice( $args, 1 );

		switch ( $action ) {
			case 'enable':
				$this->enable( $remaining, $assoc_args );
				break;
			case 'disable':
				$this->disable( $remaining, $assoc_args );
				break;
			case 'regenerate':
				$this->regenerate( $remaining, $assoc_args );
				break;
			case 'set-secret':
			case 'set_secret':
				$this->set_secret( $remaining, $assoc_args );
				break;
			case 'status':
				$this->status( $remaining, $assoc_args );
				break;
			case 'list':
				$this->list_webhooks( $remaining, $assoc_args );
				break;
			case 'rate-limit':
				$this->rate_limit( $remaining, $assoc_args );
				break;
			default:
				WP_CLI::error( "Unknown webhook action: {$action}. Use: enable, disable, regenerate, set-secret, status, list, rate-limit" );
		}
	}

	/**
	 * Enable webhook trigger for a flow.
	 *
	 * Supports two authentication modes:
	 * - `bearer` (default): per-flow Bearer token.
	 * - `hmac_sha256`:      HMAC-SHA256 signature verification against the raw body.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to enable webhook trigger for.
	 *
	 * [--auth-mode=<mode>]
	 * : Authentication mode.
	 * ---
	 * default: bearer
	 * options:
	 *   - bearer
	 *   - hmac_sha256
	 * ---
	 *
	 * [--signature-header=<header>]
	 * : HMAC signature header name (e.g. X-Hub-Signature-256, Stripe-Signature, X-Shopify-Hmac-Sha256). Only used when --auth-mode=hmac_sha256.
	 *
	 * [--signature-format=<format>]
	 * : HMAC signature encoding. Only used when --auth-mode=hmac_sha256.
	 * ---
	 * default: sha256=hex
	 * options:
	 *   - sha256=hex
	 *   - hex
	 *   - base64
	 * ---
	 *
	 * [--generate-secret]
	 * : Generate a random 32-byte hex secret for HMAC mode.
	 *
	 * [--secret=<value>]
	 * : Use this explicit HMAC secret (e.g. the value you configured on the upstream service).
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable with default Bearer auth
	 *     wp datamachine flows webhook enable 42
	 *
	 *     # Enable with HMAC-SHA256 auth and a generated secret (GitHub-style)
	 *     wp datamachine flows webhook enable 42 --auth-mode=hmac_sha256 --generate-secret
	 *
	 *     # Enable with HMAC-SHA256 auth and an explicit secret
	 *     wp datamachine flows webhook enable 42 --auth-mode=hmac_sha256 --secret=abc123...
	 *
	 *     # Enable with HMAC-SHA256 auth for Shopify (base64 header)
	 *     wp datamachine flows webhook enable 42 \
	 *       --auth-mode=hmac_sha256 \
	 *       --signature-header=X-Shopify-Hmac-Sha256 \
	 *       --signature-format=base64 \
	 *       --secret=<shopify_secret>
	 *
	 * @subcommand enable
	 */
	public function enable( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook enable <flow_id> [--auth-mode=<mode>] [--signature-header=<header>] [--signature-format=<format>] [--generate-secret] [--secret=<value>]' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$input = array( 'flow_id' => $flow_id );

		if ( isset( $assoc_args['auth-mode'] ) ) {
			$input['auth_mode'] = (string) $assoc_args['auth-mode'];
		}
		if ( isset( $assoc_args['signature-header'] ) ) {
			$input['signature_header'] = (string) $assoc_args['signature-header'];
		}
		if ( isset( $assoc_args['signature-format'] ) ) {
			$input['signature_format'] = (string) $assoc_args['signature-format'];
		}
		if ( ! empty( $assoc_args['generate-secret'] ) ) {
			$input['generate_secret'] = true;
		}
		if ( isset( $assoc_args['secret'] ) ) {
			$input['secret'] = (string) $assoc_args['secret'];
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeEnable( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to enable webhook trigger' );
			return;
		}

		$auth_mode = $result['auth_mode'] ?? 'bearer';

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'URL:       %s', $result['webhook_url'] ) );
		WP_CLI::log( sprintf( 'Auth mode: %s', $auth_mode ) );

		if ( 'hmac_sha256' === $auth_mode ) {
			WP_CLI::log( sprintf( 'Header:    %s', $result['signature_header'] ?? 'X-Hub-Signature-256' ) );
			WP_CLI::log( sprintf( 'Format:    %s', $result['signature_format'] ?? 'sha256=hex' ) );

			if ( isset( $result['secret'] ) ) {
				WP_CLI::log( sprintf( 'Secret:    %s', $result['secret'] ) );
				WP_CLI::warning( 'Save this secret now — it will not be shown again.' );
				WP_CLI::log( '' );
				WP_CLI::log( 'Paste this secret into your webhook provider (e.g. GitHub → Settings → Webhooks → Secret).' );
			} else {
				WP_CLI::log( 'Secret:    (unchanged — use `set-secret` to rotate)' );
			}
		} else {
			WP_CLI::log( sprintf( 'Token:     %s', $result['token'] ) );
			WP_CLI::log( '' );
			WP_CLI::log( 'Usage:' );
			WP_CLI::log( sprintf( '  curl -X POST %s \\', $result['webhook_url'] ) );
			WP_CLI::log( sprintf( '    -H "Authorization: Bearer %s" \\', $result['token'] ) );
			WP_CLI::log( '    -H "Content-Type: application/json" \\' );
			WP_CLI::log( '    -d \'{"key": "value"}\'' );
		}
	}

	/**
	 * Set or rotate the HMAC shared secret for a flow.
	 *
	 * Switches the flow to hmac_sha256 auth mode if it isn't already.
	 * Provide exactly one of --secret or --generate.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to set the secret for.
	 *
	 * [--secret=<value>]
	 * : Explicit secret value (typically copied from the upstream provider UI).
	 *
	 * [--generate]
	 * : Generate a random 32-byte hex secret and print it once.
	 *
	 * ## EXAMPLES
	 *
	 *     # Paste a secret from GitHub
	 *     wp datamachine flows webhook set-secret 42 --secret=<value>
	 *
	 *     # Generate a fresh secret (you will paste it into the provider)
	 *     wp datamachine flows webhook set-secret 42 --generate
	 *
	 * @subcommand set-secret
	 */
	public function set_secret( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook set-secret <flow_id> (--secret=<value> | --generate)' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$has_secret   = isset( $assoc_args['secret'] );
		$has_generate = ! empty( $assoc_args['generate'] );

		if ( ! $has_secret && ! $has_generate ) {
			WP_CLI::error( 'Provide exactly one of --secret=<value> or --generate.' );
			return;
		}
		if ( $has_secret && $has_generate ) {
			WP_CLI::error( 'Pass either --secret=<value> or --generate, not both.' );
			return;
		}

		$input = array( 'flow_id' => $flow_id );
		if ( $has_secret ) {
			$input['secret'] = (string) $assoc_args['secret'];
		} else {
			$input['generate'] = true;
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeSetSecret( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to set webhook secret' );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Flow:      %d', $flow_id ) );
		WP_CLI::log( sprintf( 'Auth mode: %s', $result['auth_mode'] ?? 'hmac_sha256' ) );
		WP_CLI::log( sprintf( 'Secret:    %s', $result['secret'] ) );
		WP_CLI::warning( 'Save this secret now — it will not be shown again.' );
	}

	/**
	 * Disable webhook trigger for a flow.
	 *
	 * Revokes the token and disables the webhook endpoint.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to disable webhook trigger for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable webhook trigger
	 *     wp datamachine flows webhook disable 42
	 *
	 * @subcommand disable
	 */
	public function disable( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook disable <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeDisable( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to disable webhook trigger' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Regenerate webhook token for a flow.
	 *
	 * Invalidates the old token and generates a new one.
	 * External services using the old token must be updated.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to regenerate webhook token for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Regenerate webhook token
	 *     wp datamachine flows webhook regenerate 42
	 *
	 * @subcommand regenerate
	 */
	public function regenerate( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook regenerate <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeRegenerate( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to regenerate webhook token' );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'URL:       %s', $result['webhook_url'] ) );
		WP_CLI::log( sprintf( 'New Token: %s', $result['token'] ) );
		WP_CLI::warning( 'Update any external services using the old token.' );
	}

	/**
	 * Show webhook trigger status for a flow.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to check.
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
	 *     # Check webhook status
	 *     wp datamachine flows webhook status 42
	 *
	 *     # Check webhook status as JSON
	 *     wp datamachine flows webhook status 42 --format=json
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook status <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeStatus( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get webhook status' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::log( sprintf( 'Flow:      %d — %s', $result['flow_id'], $result['flow_name'] ) );
		WP_CLI::log( sprintf( 'Webhook:   %s', $result['webhook_enabled'] ? 'enabled' : 'disabled' ) );

		if ( $result['webhook_enabled'] ) {
			WP_CLI::log( sprintf( 'URL:       %s', $result['webhook_url'] ) );
			WP_CLI::log( sprintf( 'Auth mode: %s', $result['auth_mode'] ?? 'bearer' ) );
			WP_CLI::log( sprintf( 'Created:   %s', $result['created_at'] ?? 'unknown' ) );

			if ( 'hmac_sha256' === ( $result['auth_mode'] ?? 'bearer' ) ) {
				WP_CLI::log( sprintf( 'Header:    %s', $result['signature_header'] ?? 'X-Hub-Signature-256' ) );
				WP_CLI::log( sprintf( 'Format:    %s', $result['signature_format'] ?? 'sha256=hex' ) );
				if ( isset( $result['max_body_bytes'] ) ) {
					WP_CLI::log( sprintf( 'Max body:  %d bytes', (int) $result['max_body_bytes'] ) );
				}
			}
		}
	}

	/**
	 * List all flows with webhook triggers enabled.
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
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all webhook-enabled flows
	 *     wp datamachine flows webhook list
	 *
	 *     # List as JSON
	 *     wp datamachine flows webhook list --format=json
	 *
	 * @subcommand list
	 */
	public function list_webhooks( array $args, array $assoc_args ): void {
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$flows    = $db_flows->get_all_flows();

		$webhook_flows = array();
		foreach ( $flows as $flow ) {
			$config = $flow['scheduling_config'] ?? array();
			if ( ! empty( $config['webhook_enabled'] ) ) {
				$webhook_flows[] = array(
					'flow_id'     => $flow['flow_id'],
					'flow_name'   => $flow['flow_name'],
					'auth_mode'   => $config['webhook_auth_mode'] ?? 'bearer',
					'webhook_url' => \DataMachine\Abilities\Flow\WebhookTriggerAbility::get_webhook_url( (int) $flow['flow_id'] ),
					'created_at'  => $config['webhook_created_at'] ?? '',
				);
			}
		}

		if ( empty( $webhook_flows ) ) {
			WP_CLI::log( 'No flows have webhook triggers enabled.' );
			return;
		}

		$this->format_items( $webhook_flows, array( 'flow_id', 'flow_name', 'auth_mode', 'webhook_url', 'created_at' ), $assoc_args, 'flow_id' );
		WP_CLI::log( sprintf( 'Total: %d flow(s) with webhook triggers enabled.', count( $webhook_flows ) ) );
	}

	/**
	 * Configure rate limiting for a flow webhook trigger.
	 *
	 * When called without --max or --window, shows the current config.
	 * Set --max=0 to disable rate limiting.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to configure.
	 *
	 * [--max=<number>]
	 * : Maximum requests per window. Set to 0 to disable rate limiting.
	 *
	 * [--window=<seconds>]
	 * : Time window in seconds.
	 *
	 * ## EXAMPLES
	 *
	 *     # View current rate limit
	 *     wp datamachine flows webhook rate-limit 42
	 *
	 *     # Set custom limit
	 *     wp datamachine flows webhook rate-limit 42 --max=100 --window=120
	 *
	 *     # Disable rate limiting
	 *     wp datamachine flows webhook rate-limit 42 --max=0
	 *
	 * @subcommand rate-limit
	 */
	public function rate_limit( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook rate-limit <flow_id> [--max=<number>] [--window=<seconds>]' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		// If no --max or --window provided, show current config.
		if ( ! isset( $assoc_args['max'] ) && ! isset( $assoc_args['window'] ) ) {
			$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
			$result  = $ability->executeStatus( array( 'flow_id' => $flow_id ) );

			if ( ! $result['success'] ) {
				WP_CLI::error( $result['error'] ?? 'Failed to get webhook status' );
				return;
			}

			if ( empty( $result['webhook_enabled'] ) ) {
				WP_CLI::error( sprintf( 'Webhook trigger is not enabled for flow %d.', $flow_id ) );
				return;
			}

			$rate_limit = $result['rate_limit'] ?? array();
			$max        = $rate_limit['max'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_MAX;
			$window     = $rate_limit['window'] ?? \DataMachine\Api\WebhookTrigger::DEFAULT_RATE_LIMIT_WINDOW;

			if ( 0 === $max ) {
				WP_CLI::log( sprintf( 'Flow %d: Rate limiting disabled.', $flow_id ) );
			} else {
				WP_CLI::log( sprintf( 'Flow %d: %d requests per %d seconds.', $flow_id, $max, $window ) );
			}
			return;
		}

		$input = array( 'flow_id' => $flow_id );

		if ( isset( $assoc_args['max'] ) ) {
			$input['max'] = (int) $assoc_args['max'];
		}
		if ( isset( $assoc_args['window'] ) ) {
			$input['window'] = (int) $assoc_args['window'];
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeSetRateLimit( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to set rate limit' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}
}
