<?php
/**
 * CLI service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

use WP_CLI;

/**
 * Loads the Data Machine command surface in WP-CLI requests.
 */
final class CliServiceProvider {

	/**
	 * Register CLI commands when WP-CLI is active.
	 */
	public static function register(): void {
		// @phpstan-ignore-next-line Runtime constant may be defined false outside PHPStan's configured CLI context.
		if ( ! defined( 'WP_CLI' ) || ! (bool) constant( 'WP_CLI' ) ) {
			return;
		}

		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$cli_root = dirname( __DIR__, 2 ) . '/Cli';
		require_once $cli_root . '/ActionSchedulerWPCLICompat.php';

		$commands = array(
			'datamachine settings'         => \DataMachine\Cli\Commands\SettingsCommand::class,
			'datamachine flows'            => \DataMachine\Cli\Commands\Flows\FlowsCommand::class,
			'datamachine alt-text'         => \DataMachine\Cli\Commands\AltTextCommand::class,
			'datamachine jobs'             => \DataMachine\Cli\Commands\JobsCommand::class,
			'datamachine cycle'            => \DataMachine\Cli\Commands\CycleCommand::class,
			'datamachine drain'            => \DataMachine\Cli\Commands\DrainCommand::class,
			'datamachine worker'           => \DataMachine\Cli\Commands\WorkerCommand::class,
			'datamachine ai'               => \DataMachine\Cli\Commands\AICommand::class,
			'datamachine pipelines'        => \DataMachine\Cli\Commands\PipelinesCommand::class,
			'datamachine posts'            => \DataMachine\Cli\Commands\PostsCommand::class,
			'datamachine logs'             => \DataMachine\Cli\Commands\LogsCommand::class,
			'datamachine agents'           => \DataMachine\Cli\Commands\AgentsCommand::class,
			'datamachine pending-actions'  => \DataMachine\Cli\Commands\PendingActionsCommand::class,
			'datamachine memory'           => \DataMachine\Cli\Commands\MemoryCommand::class,
			'datamachine batch'            => \DataMachine\Cli\Commands\BatchCommand::class,
			'datamachine image'            => \DataMachine\Cli\Commands\ImageCommand::class,
			'datamachine auth'             => \DataMachine\Cli\Commands\AuthCommand::class,
			'datamachine email'            => \DataMachine\Cli\Commands\EmailCommand::class,
			'datamachine system'           => \DataMachine\Cli\Commands\SystemCommand::class,
			'datamachine handlers'         => \DataMachine\Cli\Commands\HandlersCommand::class,
			'datamachine taxonomy'         => \DataMachine\Cli\Commands\TaxonomyCommand::class,
			'datamachine step-types'       => \DataMachine\Cli\Commands\StepTypesCommand::class,
			'datamachine processed-items'  => \DataMachine\Cli\Commands\ProcessedItemsCommand::class,
			'datamachine tracked-items'    => \DataMachine\Cli\Commands\TrackedItemsCommand::class,
			'datamachine retention'        => \DataMachine\Cli\Commands\RetentionCommand::class,
			'datamachine test'             => \DataMachine\Cli\Commands\TestCommand::class,
			'datamachine external'         => \DataMachine\Cli\Commands\ExternalCommand::class,
			'datamachine links'            => \DataMachine\Cli\Commands\LinksCommand::class,
			'datamachine blocks'           => \DataMachine\Cli\Commands\BlocksCommand::class,
			'datamachine meta-description' => \DataMachine\Cli\Commands\MetaDescriptionCommand::class,
			'datamachine chat'             => \DataMachine\Cli\Commands\ChatCommand::class,
		);

		foreach ( $commands as $command => $class ) {
			WP_CLI::add_command( $command, $class );
		}
	}
}
