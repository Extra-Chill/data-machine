<?php
/**
 * WP-CLI Bootstrap
 *
 * Registers WP-CLI commands for Data Machine.
 *
 * @package DataMachine\Cli
 * @since 0.11.0
 */

namespace DataMachine\Cli;

use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Primary commands.
WP_CLI::add_command( 'datamachine settings', Commands\SettingsCommand::class );
WP_CLI::add_command( 'datamachine flows', Commands\FlowsCommand::class );
WP_CLI::add_command( 'datamachine alt-text', Commands\AltTextCommand::class );
WP_CLI::add_command( 'datamachine jobs', Commands\JobsCommand::class );
WP_CLI::add_command( 'datamachine pipelines', Commands\PipelinesCommand::class );
WP_CLI::add_command( 'datamachine posts', Commands\PostsCommand::class );
WP_CLI::add_command( 'datamachine logs', Commands\LogsCommand::class );

// Aliases for AI agent compatibility (singular/plural variants).
WP_CLI::add_command( 'datamachine setting', Commands\SettingsCommand::class );
WP_CLI::add_command( 'datamachine flow', Commands\FlowsCommand::class );
WP_CLI::add_command( 'datamachine job', Commands\JobsCommand::class );
WP_CLI::add_command( 'datamachine pipeline', Commands\PipelinesCommand::class );
WP_CLI::add_command( 'datamachine post', Commands\PostsCommand::class );
WP_CLI::add_command( 'datamachine log', Commands\LogsCommand::class );
