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
			WP_CLI::error( 'Usage: wp datamachine flows webhook <enable|disable|regenerate|status|list|rate-limit> [flow_id]' );
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
				WP_CLI::error( "Unknown webhook action: {$action}. Use: enable, disable, regenerate, status, list, rate-limit" );
		}
	}

	/**
	 * Enable webhook trigger for a flow.
	 *
	 * Generates a per-flow Bearer token and displays the webhook URL
	 * with a curl example for testing.
	 *
	 * ## OPTIONS
	 *
	 * <flow_id>
	 * : The flow ID to enable webhook trigger for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable webhook trigger
	 *     wp datamachine flows webhook enable 42
	 *
	 * @subcommand enable
	 */
	public function enable( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine flows webhook enable <flow_id>' );
			return;
		}

		$flow_id = (int) $args[0];
		if ( $flow_id <= 0 ) {
			WP_CLI::error( 'flow_id must be a positive integer' );
			return;
		}

		$ability = new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		$result  = $ability->executeEnable( array( 'flow_id' => $flow_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to enable webhook trigger' );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'URL:   %s', $result['webhook_url'] ) );
		WP_CLI::log( sprintf( 'Token: %s', $result['token'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Usage:' );
		WP_CLI::log( sprintf( '  curl -X POST %s \\', $result['webhook_url'] ) );
		WP_CLI::log( sprintf( '    -H "Authorization: Bearer %s" \\', $result['token'] ) );
		WP_CLI::log( '    -H "Content-Type: application/json" \\' );
		WP_CLI::log( '    -d \'{"key": "value"}\'' );
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

		WP_CLI::log( sprintf( 'Flow:    %d — %s', $result['flow_id'], $result['flow_name'] ) );
		WP_CLI::log( sprintf( 'Webhook: %s', $result['webhook_enabled'] ? 'enabled' : 'disabled' ) );

		if ( $result['webhook_enabled'] ) {
			WP_CLI::log( sprintf( 'URL:     %s', $result['webhook_url'] ) );
			WP_CLI::log( sprintf( 'Created: %s', $result['created_at'] ?? 'unknown' ) );
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
					'webhook_url' => \DataMachine\Abilities\Flow\WebhookTriggerAbility::get_webhook_url( (int) $flow['flow_id'] ),
					'created_at'  => $config['webhook_created_at'] ?? '',
				);
			}
		}

		if ( empty( $webhook_flows ) ) {
			WP_CLI::log( 'No flows have webhook triggers enabled.' );
			return;
		}

		$this->format_items( $webhook_flows, array( 'flow_id', 'flow_name', 'webhook_url', 'created_at' ), $assoc_args, 'flow_id' );
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
