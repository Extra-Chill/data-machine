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
			WP_CLI::error( 'Usage: wp datamachine flows webhook <enable|disable|regenerate|set-secret|rotate|forget|presets|status|list|rate-limit> [flow_id]' );
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
				WP_CLI::error( "Unknown webhook action: {$action}. Use: enable, disable, regenerate, set-secret, rotate, forget, presets, status, list, rate-limit" );
		}
	}

	/**
	 * Enable webhook trigger for a flow.
	 *
	 * Two auth modes, both generic primitives:
	 *
	 * - `bearer` (default): per-flow Bearer token.
	 * - `hmac`:             template-based HMAC verification. Requires either
	 *                       `--preset=<name>` (registered via the
	 *                       `datamachine_webhook_auth_presets` filter) or
	 *                       `--config=@file.json` (an explicit template).
	 *
	 * Core ships zero presets. Run `wp datamachine flows webhook presets`
	 * to see what has been registered on this install.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to enable webhook trigger for.
	 *
	 * [--auth-mode=<mode>]
	 * : Authentication primitive.
	 * ---
	 * default: bearer
	 * options:
	 *   - bearer
	 *   - hmac
	 * ---
	 *
	 * [--preset=<name>]
	 * : Name of a preset registered via `datamachine_webhook_auth_presets`.
	 * Implies HMAC mode. Expands server-side to a full template.
	 *
	 * [--config=<file>]
	 * : Path to a JSON file containing an explicit template config. Use @-
	 * prefix or plain path. Implies HMAC mode.
	 *
	 * [--overrides=<file>]
	 * : Path to a JSON file with deep-merge overrides applied on top of the
	 * preset or template.
	 *
	 * [--generate-secret]
	 * : Generate a random 32-byte hex secret for HMAC mode.
	 *
	 * [--secret=<value>]
	 * : Use this explicit HMAC secret (takes precedence over --generate-secret).
	 *
	 * [--secret-id=<id>]
	 * : Secret id for multi-secret rotation (default: current).
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable with default Bearer auth
	 *     wp datamachine flows webhook enable 42
	 *
	 *     # Enable via a preset (provider-agnostic; preset names come from filters)
	 *     wp datamachine flows webhook enable 42 --preset=my-preset --generate-secret
	 *
	 *     # Enable with an explicit template and a known secret
	 *     wp datamachine flows webhook enable 42 \
	 *       --config=@template.json \
	 *       --secret=<upstream_secret>
	 *
	 * @subcommand enable
	 */
	public function enable( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook enable <flow_id> [--auth-mode=<mode>] [--preset=<name>] [--config=<file>] [--overrides=<file>] [--generate-secret] [--secret=<value>] [--secret-id=<id>]' );
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
		if ( isset( $assoc_args['preset'] ) ) {
			$input['preset'] = (string) $assoc_args['preset'];
		}
		if ( isset( $assoc_args['config'] ) ) {
			$template = self::read_json_file( (string) $assoc_args['config'], 'config' );
			if ( null === $template ) {
				return;
			}
			$input['template'] = $template;
		}
		if ( isset( $assoc_args['overrides'] ) ) {
			$overrides = self::read_json_file( (string) $assoc_args['overrides'], 'overrides' );
			if ( null === $overrides ) {
				return;
			}
			$input['template_overrides'] = $overrides;
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

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeEnable( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to enable webhook trigger' );
			return;
		}

		$auth_mode = $result['auth_mode'] ?? 'bearer';

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'URL:       %s', $result['webhook_url'] ) );
		WP_CLI::log( sprintf( 'Auth mode: %s', $auth_mode ) );

		if ( 'bearer' === $auth_mode ) {
			WP_CLI::log( sprintf( 'Token:     %s', $result['token'] ) );
			WP_CLI::log( '' );
			WP_CLI::log( 'Usage:' );
			WP_CLI::log( sprintf( '  curl -X POST %s \\', $result['webhook_url'] ) );
			WP_CLI::log( sprintf( '    -H "Authorization: Bearer %s" \\', $result['token'] ) );
			WP_CLI::log( '    -H "Content-Type: application/json" \\' );
			WP_CLI::log( '    -d \'{"key": "value"}\'' );
			return;
		}

		// HMAC output.
		if ( isset( $result['secret'] ) ) {
			WP_CLI::log( sprintf( 'Secret:    %s', $result['secret'] ) );
			WP_CLI::warning( 'Save this secret now — it will not be shown again.' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Paste this secret into the upstream provider configuration.' );
		} else {
			WP_CLI::log( 'Secret:    (unchanged — use `set-secret` or `rotate` to change)' );
		}

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
	 * Load + parse a JSON file referenced by `--config=@path` or `--config=path`.
	 * Returns null on error (after printing a CLI error).
	 *
	 * @param string $raw
	 * @param string $label Used in error messages.
	 * @return array|null
	 */
	private static function read_json_file( string $raw, string $label ): ?array {
		$path = 0 === strpos( $raw, '@' ) ? substr( $raw, 1 ) : $raw;
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s file: %s', $label, $path ) );
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $path );
		$decoded = json_decode( (string) $content, true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( sprintf( '%s file is not valid JSON: %s', ucfirst( $label ), $path ) );
			return null;
		}
		return $decoded;
	}

	/**
	 * Set or replace the HMAC shared secret for an existing HMAC flow.
	 *
	 * The flow must already be in HMAC mode — run `enable --preset=<name>`
	 * or `enable --config=@template.json` first.
	 *
	 * Prefer `rotate` over `set-secret` when you need a grace window.
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
	 * [--secret-id=<id>]
	 * : Secret id (default: current). Use `rotate` for zero-downtime swaps.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine flows webhook set-secret 42 --secret=<value>
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
		if ( isset( $assoc_args['secret-id'] ) ) {
			$input['secret_id'] = (string) $assoc_args['secret-id'];
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeSetSecret( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to set webhook secret' );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Flow:      %d', $flow_id ) );
		WP_CLI::log( sprintf( 'Auth mode: %s', $result['auth_mode'] ?? 'hmac' ) );
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
			$auth_mode = $result['auth_mode'] ?? 'bearer';
			WP_CLI::log( sprintf( 'URL:       %s', $result['webhook_url'] ) );
			WP_CLI::log( sprintf( 'Auth mode: %s', $auth_mode ) );
			WP_CLI::log( sprintf( 'Created:   %s', $result['created_at'] ?? 'unknown' ) );

			if ( 'bearer' !== $auth_mode ) {
				if ( ! empty( $result['template'] ) ) {
					WP_CLI::log( 'Template:' );
					WP_CLI::log( (string) wp_json_encode( $result['template'], JSON_PRETTY_PRINT ) );
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
			$raw_config = $flow['scheduling_config'] ?? array();
			if ( empty( $raw_config['webhook_enabled'] ) ) {
				continue;
			}
			// Normalise auth_mode label for list display (v1 → v2 on the fly).
			$migration       = \DataMachine\Api\WebhookAuthResolver::migrate_legacy( $raw_config );
			$config          = $migration['config'];
			$webhook_flows[] = array(
				'flow_id'     => $flow['flow_id'],
				'flow_name'   => $flow['flow_name'],
				'auth_mode'   => $config['webhook_auth_mode'] ?? 'bearer',
				'webhook_url' => \DataMachine\Abilities\Flow\WebhookTriggerAbility::get_webhook_url( (int) $flow['flow_id'] ),
				'created_at'  => $config['webhook_created_at'] ?? '',
			);
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
	 * Demotes `current` → `previous` (keeps verifying for --previous-ttl-seconds,
	 * default 7 days), installs a fresh `current`. Zero-downtime swap window:
	 * rotate here, update the upstream provider, then `forget previous`.
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
	 *     wp datamachine flows webhook rotate 42 --secret=<new> --previous-ttl-seconds=86400
	 *
	 * @subcommand rotate
	 */
	public function rotate( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook rotate <flow_id> (--secret=<value> | --generate) [--previous-ttl-seconds=<n>]' );
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

		if ( empty( $result['success'] ) ) {
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
	 * Removes the secret from the rotation list immediately. Typical use:
	 * `forget previous` after the upstream provider has been updated.
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

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to forget secret' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * List webhook auth presets registered via the
	 * `datamachine_webhook_auth_presets` filter.
	 *
	 * Core ships zero presets — they come from companion plugins or site
	 * mu-plugins. This command simply inventories what's registered on the
	 * current install.
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
			WP_CLI::log( 'No presets registered. Add presets via the datamachine_webhook_auth_presets filter.' );
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
				'has_timestamp'    => $ts ? 'yes' : 'no',
				'replay_tolerance' => isset( $cfg['tolerance_seconds'] ) ? (string) (int) $cfg['tolerance_seconds'] : '',
			);
		}

		$this->format_items(
			$rows,
			array( 'name', 'mode', 'signed_template', 'signature_header', 'encoding', 'has_timestamp', 'replay_tolerance' ),
			$assoc_args,
			'name'
		);
	}
}
