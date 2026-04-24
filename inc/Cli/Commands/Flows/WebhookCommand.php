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
			WP_CLI::error( 'Usage: wp datamachine flows webhook <enable|disable|regenerate|set-secret|rotate|forget|test|presets|status|list|rate-limit> [flow_id]' );
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
			case 'rotate':
				$this->rotate( $remaining, $assoc_args );
				break;
			case 'forget':
				$this->forget( $remaining, $assoc_args );
				break;
			case 'test':
				$this->test( $remaining, $assoc_args );
				break;
			case 'presets':
				$this->presets( $remaining, $assoc_args );
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
				WP_CLI::error( "Unknown webhook action: {$action}. Use: enable, disable, regenerate, set-secret, rotate, forget, test, presets, status, list, rate-limit" );
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
	 * [--secret-id=<id>]
	 * : Optional secret id for multi-secret rotation (default: `current`).
	 *
	 * [--preset=<name>]
	 * : Name of a preset registered via the `datamachine_webhook_auth_presets` filter.
	 * Implies HMAC mode and resolves the full signing template server-side. Run
	 * `wp datamachine flows webhook presets` to list available presets.
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
	 *     # Enable via a registered preset (Stripe, Slack, ...)
	 *     wp datamachine flows webhook enable 42 --preset=stripe --secret=whsec_...
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
		if ( isset( $assoc_args['secret-id'] ) ) {
			$input['secret_id'] = (string) $assoc_args['secret-id'];
		}
		if ( isset( $assoc_args['preset'] ) ) {
			$input['preset'] = (string) $assoc_args['preset'];
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
			if ( ! empty( $result['preset'] ) ) {
				WP_CLI::log( sprintf( 'Preset:    %s', $result['preset'] ) );
			} else {
				WP_CLI::log( sprintf( 'Header:    %s', $result['signature_header'] ?? 'X-Hub-Signature-256' ) );
				WP_CLI::log( sprintf( 'Format:    %s', $result['signature_format'] ?? 'sha256=hex' ) );
			}

			if ( isset( $result['secret'] ) ) {
				WP_CLI::log( sprintf( 'Secret:    %s', $result['secret'] ) );
				WP_CLI::warning( 'Save this secret now — it will not be shown again.' );
				WP_CLI::log( '' );
				WP_CLI::log( 'Paste this secret into your webhook provider (e.g. GitHub → Settings → Webhooks → Secret).' );
			} else {
				WP_CLI::log( 'Secret:    (unchanged — use `set-secret` or `rotate` to change)' );
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
				if ( ! empty( $result['preset'] ) ) {
					WP_CLI::log( sprintf( 'Preset:    %s', $result['preset'] ) );
				} else {
					WP_CLI::log( sprintf( 'Header:    %s', $result['signature_header'] ?? 'X-Hub-Signature-256' ) );
					WP_CLI::log( sprintf( 'Format:    %s', $result['signature_format'] ?? 'sha256=hex' ) );
				}
				if ( isset( $result['max_body_bytes'] ) ) {
					WP_CLI::log( sprintf( 'Max body:  %d bytes', (int) $result['max_body_bytes'] ) );
				}
				if ( ! empty( $result['secret_ids'] ) ) {
					WP_CLI::log( 'Secrets:' );
					foreach ( $result['secret_ids'] as $entry ) {
						$line = '  - ' . ( $entry['id'] ?? '' );
						if ( ! empty( $entry['expires_at'] ) ) {
							$line .= ' (expires ' . $entry['expires_at'] . ')';
						}
						WP_CLI::log( $line );
					}
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

	/**
	 * Rotate the HMAC shared secret with a grace period.
	 *
	 * Demotes `current` → `previous` (keeps verifying for --previous-ttl-seconds),
	 * installs a fresh `current`. Use this when you want to swap secrets without
	 * a downtime window: rotate here, update the provider, then forget `previous`.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to rotate the secret for.
	 *
	 * [--secret=<value>]
	 * : Explicit new secret value (takes precedence over --generate).
	 *
	 * [--generate]
	 * : Generate a random 32-byte hex secret.
	 *
	 * [--previous-ttl-seconds=<seconds>]
	 * : How long the old secret keeps verifying (default: 604800 = 7 days).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine flows webhook rotate 42 --generate
	 *     wp datamachine flows webhook rotate 42 --secret=whsec_new... --previous-ttl-seconds=86400
	 *
	 * @subcommand rotate
	 */
	public function rotate( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook rotate <flow_id> (--generate | --secret=<value>) [--previous-ttl-seconds=<n>]' );
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
		if ( isset( $assoc_args['previous-ttl-seconds'] ) ) {
			$input['previous_ttl_seconds'] = (int) $assoc_args['previous-ttl-seconds'];
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeRotateSecret( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to rotate secret' );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'New secret:           %s', $result['new_secret'] ) );
		WP_CLI::log( sprintf( 'Previous expires at:  %s', $result['previous_expires_at'] ) );
		WP_CLI::warning( 'Save this secret now — it will not be shown again.' );

		if ( ! empty( $result['secret_ids'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Active secret ids:' );
			foreach ( $result['secret_ids'] as $entry ) {
				$line = '  - ' . ( $entry['id'] ?? '' );
				if ( ! empty( $entry['expires_at'] ) ) {
					$line .= ' (expires ' . $entry['expires_at'] . ')';
				}
				WP_CLI::log( $line );
			}
		}
	}

	/**
	 * Forget a specific secret by id.
	 *
	 * Removes the secret from the rotation list immediately — no grace window.
	 * Typical use: `forget previous` after you've updated the provider side.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID.
	 *
	 * <secret_id>
	 * : The secret id to forget (e.g. `previous`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine flows webhook forget 42 previous
	 *
	 * @subcommand forget
	 */
	public function forget( array $args, array $assoc_args ): void {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook forget <flow_id> <secret_id>' );
			return;
		}
		$flow_id   = (int) $args[0];
		$secret_id = (string) $args[1];

		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeForgetSecret(
			array(
				'flow_id'   => $flow_id,
				'secret_id' => $secret_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to forget secret' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Run offline signature verification against a captured payload.
	 *
	 * Invokes the verifier without spawning a job or hitting rate limits.
	 * Useful for debugging upstream signature configuration or replaying
	 * captured deliveries. Prints the full verification result including
	 * which secret matched (if any) and the extracted timestamp skew.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID (its auth config is used for verification).
	 *
	 * [--body=<value>]
	 * : Raw request body. Use @/path/to/file.json to read from disk.
	 *
	 * [--header=<header>]
	 * : Request header in "Name: value" form. Repeatable.
	 *
	 * [--now=<unix_seconds>]
	 * : Override "now" for deterministic replay-window checks.
	 *
	 * ## EXAMPLES
	 *
	 *     # Verify a captured GitHub ping payload
	 *     wp datamachine flows webhook test 42 \\
	 *       --body=@fixtures/github-ping.json \\
	 *       --header="X-Hub-Signature-256: sha256=abc123..." \\
	 *       --header="X-GitHub-Event: ping"
	 *
	 * @subcommand test
	 * @when after_wp_load
	 */
	public function test( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook test <flow_id> [--body=@file.json] [--header="Name: value"]... [--now=<unix>]' );
			return;
		}
		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		// --body may be literal or @path.
		$body_arg = $assoc_args['body'] ?? '';
		$body     = '';
		if ( is_string( $body_arg ) && '' !== $body_arg ) {
			if ( 0 === strpos( $body_arg, '@' ) ) {
				$path = substr( $body_arg, 1 );
				if ( ! is_readable( $path ) ) {
					WP_CLI::error( sprintf( 'Cannot read body file: %s', $path ) );
					return;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$body = (string) file_get_contents( $path );
			} else {
				$body = $body_arg;
			}
		}

		// --header can be repeated; WP-CLI represents repeated flags as arrays when passed --header=foo --header=bar.
		$raw_headers = array();
		if ( isset( $assoc_args['header'] ) ) {
			$raw_headers = is_array( $assoc_args['header'] ) ? $assoc_args['header'] : array( $assoc_args['header'] );
		}
		$headers = array();
		foreach ( $raw_headers as $header_line ) {
			$line = (string) $header_line;
			$pos  = strpos( $line, ':' );
			if ( false === $pos ) {
				WP_CLI::warning( sprintf( 'Skipping malformed header (expected "Name: value"): %s', $line ) );
				continue;
			}
			$name  = trim( substr( $line, 0, $pos ) );
			$value = trim( substr( $line, $pos + 1 ) );
			if ( '' === $name ) {
				continue;
			}
			$headers[ $name ] = $value;
		}

		$input = array(
			'flow_id' => $flow_id,
			'body'    => $body,
			'headers' => $headers,
		);
		if ( isset( $assoc_args['now'] ) ) {
			$input['now'] = (int) $assoc_args['now'];
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeTest( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Test failed' );
			return;
		}

		if ( $result['ok'] ) {
			WP_CLI::success( sprintf( 'Signature verified (secret_id=%s).', $result['secret_id'] ?? 'current' ) );
		} else {
			WP_CLI::warning( sprintf( 'Verification failed: %s', $result['reason'] ) );
		}

		WP_CLI::log( sprintf( 'Reason:        %s', $result['reason'] ) );
		if ( isset( $result['secret_id'] ) ) {
			WP_CLI::log( sprintf( 'Secret id:     %s', $result['secret_id'] ) );
		}
		if ( isset( $result['timestamp'] ) ) {
			WP_CLI::log( sprintf( 'Timestamp:     %d', $result['timestamp'] ) );
		}
		if ( isset( $result['skew_seconds'] ) ) {
			WP_CLI::log( sprintf( 'Skew seconds:  %d', $result['skew_seconds'] ) );
		}
		if ( ! empty( $result['detail'] ) ) {
			WP_CLI::log( sprintf( 'Detail:        %s', $result['detail'] ) );
		}
	}

	/**
	 * List webhook auth presets registered via the
	 * `datamachine_webhook_auth_presets` filter.
	 *
	 * Core ships zero presets. Third-party packages (or site-specific
	 * mu-plugins) register them; this command simply displays what is available
	 * on the current install.
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
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine flows webhook presets
	 *
	 * @subcommand presets
	 */
	public function presets( array $args, array $assoc_args ): void {
		$presets = \DataMachine\Api\WebhookAuthResolver::get_presets();

		if ( empty( $presets ) ) {
			WP_CLI::log( 'No presets registered. Add one via the datamachine_webhook_auth_presets filter.' );
			return;
		}

		$rows = array();
		foreach ( $presets as $name => $cfg ) {
			$sig    = $cfg['signature_source'] ?? array();
			$ts     = $cfg['timestamp_source'] ?? array();
			$rows[] = array(
				'name'             => (string) $name,
				'mode'             => (string) ( $cfg['mode'] ?? 'hmac' ),
				'algo'             => (string) ( $cfg['algo'] ?? 'sha256' ),
				'signed_template'  => (string) ( $cfg['signed_template'] ?? '{body}' ),
				'signature_header' => (string) ( $sig['header'] ?? ( $sig['param'] ?? '' ) ),
				'encoding'         => (string) ( $sig['encoding'] ?? '' ),
				'replay_tolerance' => isset( $cfg['tolerance_seconds'] ) ? (string) (int) $cfg['tolerance_seconds'] : '',
				'has_timestamp'    => $ts ? 'yes' : 'no',
			);
		}

		$this->format_items( $rows, array( 'name', 'mode', 'signed_template', 'signature_header', 'encoding', 'has_timestamp', 'replay_tolerance' ), $assoc_args, 'name' );
	}
}
