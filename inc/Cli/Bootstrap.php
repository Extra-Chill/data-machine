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
WP_CLI::add_command( 'datamachine flows', Commands\Flows\FlowsCommand::class );
WP_CLI::add_command( 'datamachine alt-text', Commands\AltTextCommand::class );
WP_CLI::add_command( 'datamachine jobs', Commands\JobsCommand::class );
WP_CLI::add_command( 'datamachine pipelines', Commands\PipelinesCommand::class );
WP_CLI::add_command( 'datamachine posts', Commands\PostsCommand::class );
WP_CLI::add_command( 'datamachine logs', Commands\LogsCommand::class );
WP_CLI::add_command( 'datamachine agent', Commands\MemoryCommand::class );
WP_CLI::add_command( 'datamachine agents', Commands\AgentsCommand::class );

// Backwards-compatible alias: `wp datamachine memory` → agent.
WP_CLI::add_command( 'datamachine memory', Commands\MemoryCommand::class );
WP_CLI::add_command( 'datamachine workspace', Commands\WorkspaceCommand::class );
WP_CLI::add_command( 'datamachine batch', Commands\BatchCommand::class );
WP_CLI::add_command( 'datamachine image', Commands\ImageCommand::class );
WP_CLI::add_command( 'datamachine github', Commands\GitHubCommand::class );
WP_CLI::add_command( 'datamachine auth', Commands\AuthCommand::class );
WP_CLI::add_command( 'datamachine email', Commands\EmailCommand::class );
WP_CLI::add_command( 'datamachine system', Commands\SystemCommand::class );
WP_CLI::add_command( 'datamachine handlers', Commands\HandlersCommand::class );
WP_CLI::add_command( 'datamachine taxonomy', Commands\TaxonomyCommand::class );
WP_CLI::add_command( 'datamachine step-types', Commands\StepTypesCommand::class );
WP_CLI::add_command( 'datamachine processed-items', Commands\ProcessedItemsCommand::class );

// Aliases for AI agent compatibility (singular/plural variants).
WP_CLI::add_command( 'datamachine setting', Commands\SettingsCommand::class );
WP_CLI::add_command( 'datamachine flow', Commands\Flows\FlowsCommand::class );
WP_CLI::add_command( 'datamachine job', Commands\JobsCommand::class );
WP_CLI::add_command( 'datamachine pipeline', Commands\PipelinesCommand::class );
WP_CLI::add_command( 'datamachine post', Commands\PostsCommand::class );
WP_CLI::add_command( 'datamachine log', Commands\LogsCommand::class );
WP_CLI::add_command( 'datamachine links', Commands\LinksCommand::class );
WP_CLI::add_command( 'datamachine link', Commands\LinksCommand::class );
WP_CLI::add_command( 'datamachine blocks', Commands\BlocksCommand::class );
WP_CLI::add_command( 'datamachine block', Commands\BlocksCommand::class );
WP_CLI::add_command( 'datamachine analytics', Commands\AnalyticsCommand::class );
WP_CLI::add_command( 'datamachine meta-description', Commands\MetaDescriptionCommand::class );
WP_CLI::add_command( 'datamachine indexnow', Commands\IndexNowCommand::class );
WP_CLI::add_command( 'datamachine chat', Commands\ChatCommand::class );
WP_CLI::add_command( 'datamachine handler', Commands\HandlersCommand::class );
WP_CLI::add_command( 'datamachine step-type', Commands\StepTypesCommand::class );
WP_CLI::add_command( 'datamachine processed-item', Commands\ProcessedItemsCommand::class );
