<?php
/**
 * WP-CLI Flows Command
 *
 * Provides CLI access to flow operations including listing, creation, and execution.
 * Wraps FlowAbilities API primitive.
 *
 * Queue and webhook subcommands are handled by dedicated command classes:
 * - QueueCommand (flows queue)
 * - WebhookCommand (flows webhook)
 *
 * @package DataMachine\Cli\Commands\Flows
 * @since 0.15.3 Added create subcommand.
 * @since 0.31.0 Extracted queue and webhook to dedicated command classes.
 */

namespace DataMachine\Cli\Commands\Flows;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Cli\UserResolver;

defined( 'ABSPATH' ) || exit;

class FlowsCommand extends BaseCommand {

	/**
	 * Default fields for flow list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'name', 'pipeline_id', 'handlers', 'config', 'schedule', 'max_items', 'status', 'next_run' );
}
