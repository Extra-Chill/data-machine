<?php
/**
 * WP-CLI Jobs Command
 *
 * Provides CLI access to job management operations including
 * stuck job recovery and job listing.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.14.6
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Cli\UserResolver;
use DataMachine\Abilities\JobAbilities;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\Tasks\TaskRegistry;

defined( 'ABSPATH' ) || exit;

class JobsCommand extends BaseCommand {

	/**
	 * Default fields for job list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'source', 'flow', 'status', 'created', 'completed' );

	/**
	 * Job abilities instance.
	 *
	 * @var JobAbilities
	 */
	private JobAbilities $abilities;
}
