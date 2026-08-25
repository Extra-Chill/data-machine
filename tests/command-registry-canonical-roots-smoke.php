<?php
/** Ensure command registration exposes canonical roots only. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );

final class WP_CLI {
	/** @var array<string, class-string> */
	public static array $commands = array();

	public static function add_command( string $command, string $class ): void {
		self::$commands[ $command ] = $class;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/CliServiceProvider.php';

\DataMachine\Core\Bootstrap\CliServiceProvider::register();

$expected = array(
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
$map      = WP_CLI::$commands;
$aliases = array_values(
	array_filter(
		array_keys( $map ),
		static fn( string $command ): bool => in_array( $command, array( 'datamachine flow', 'datamachine agent', 'datamachine setting', 'datamachine job', 'datamachine cycles', 'datamachine pipeline', 'datamachine post', 'datamachine log', 'datamachine pending-action', 'datamachine handler', 'datamachine step-type', 'datamachine processed-item', 'datamachine tracked-item', 'datamachine fetch test', 'datamachine link', 'datamachine block' ), true )
	)
);

sort( $aliases );
if ( array() !== $aliases ) {
	fwrite( STDERR, 'FAIL: provider exposes non-canonical command aliases.' . PHP_EOL );
	exit( 1 );
}
if ( $expected !== $map ) {
	fwrite( STDERR, 'FAIL: provider command map differs from the canonical 31-command contract.' . PHP_EOL );
	exit( 1 );
}
if ( ! isset( $map['datamachine flows'], $map['datamachine agents'] ) ) {
	fwrite( STDERR, 'FAIL: canonical flow and agent roots are missing.' . PHP_EOL );
	exit( 1 );
}

$registered = $map;
\DataMachine\Core\Bootstrap\CliServiceProvider::register();
if ( $registered !== WP_CLI::$commands ) {
	fwrite( STDERR, 'FAIL: repeated provider registration changed the command map.' . PHP_EOL );
	exit( 1 );
}

echo "CLI provider canonical roots smoke passed.\n";
