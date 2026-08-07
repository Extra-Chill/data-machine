<?php
/**
 * Command Registry
 *
 * Single source of truth mapping `wp datamachine ...` command strings to their
 * implementing command classes. The WP-CLI bootstrap calls
 * WP_CLI::add_command for each entry. Generated agent guidance advertises a
 * bounded set of routing entrypoints and tests those roots against this map;
 * live `--help` remains authoritative for the complete command surface.
 *
 * This keeps command registration centralized while Data Machine itself owns
 * the concise routing guidance previously narrated by a downstream plugin
 * (Extra-Chill/data-machine#2640, #2613).
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

class CommandRegistry {

	/**
	 * Map of command string => fully-qualified command class.
	 *
	 * Keys are the exact strings passed to WP_CLI::add_command (the command
	 * namespace, e.g. "datamachine memory" or "datamachine step-types").
	 * Order here determines registration order.
	 *
	 * Singular/plural aliases that resolve to the same class are intentionally
	 * included so `WP_CLI::add_command` registers every accepted spelling. The
	 * AGENTS.md introspection helpers de-duplicate by class when grouping.
	 *
	 * @return array<string, class-string>
	 */
	public static function map(): array {
		return array(
			// Primary commands.
			'datamachine settings'         => Commands\SettingsCommand::class,
			'datamachine flows'            => Commands\Flows\FlowsCommand::class,
			'datamachine alt-text'         => Commands\AltTextCommand::class,
			'datamachine jobs'             => Commands\JobsCommand::class,
			'datamachine cycle'            => Commands\CycleCommand::class,
			'datamachine cycles'           => Commands\CycleCommand::class,
			'datamachine drain'            => Commands\DrainCommand::class,
			'datamachine worker'           => Commands\WorkerCommand::class,
			'datamachine ai'               => Commands\AICommand::class,
			'datamachine pipelines'        => Commands\PipelinesCommand::class,
			'datamachine posts'            => Commands\PostsCommand::class,
			'datamachine logs'             => Commands\LogsCommand::class,
			'datamachine agent'            => Commands\AgentsCommand::class,
			'datamachine agents'           => Commands\AgentsCommand::class,
			'datamachine pending-actions'  => Commands\PendingActionsCommand::class,
			'datamachine pending-action'   => Commands\PendingActionsCommand::class,

			// Canonical home for agent memory-file operations.
			'datamachine memory'           => Commands\MemoryCommand::class,
			'datamachine batch'            => Commands\BatchCommand::class,
			'datamachine image'            => Commands\ImageCommand::class,
			'datamachine auth'             => Commands\AuthCommand::class,
			'datamachine email'            => Commands\EmailCommand::class,
			'datamachine system'           => Commands\SystemCommand::class,
			'datamachine handlers'         => Commands\HandlersCommand::class,
			'datamachine taxonomy'         => Commands\TaxonomyCommand::class,
			'datamachine step-types'       => Commands\StepTypesCommand::class,
			'datamachine processed-items'  => Commands\ProcessedItemsCommand::class,
			'datamachine tracked-items'    => Commands\TrackedItemsCommand::class,
			'datamachine retention'        => Commands\RetentionCommand::class,
			'datamachine test'             => Commands\TestCommand::class,
			'datamachine fetch test'       => Commands\TestCommand::class,
			'datamachine external'         => Commands\ExternalCommand::class,

			// Aliases for AI agent compatibility (singular/plural variants).
			'datamachine setting'          => Commands\SettingsCommand::class,
			'datamachine flow'             => Commands\Flows\FlowsCommand::class,
			'datamachine job'              => Commands\JobsCommand::class,
			'datamachine pipeline'         => Commands\PipelinesCommand::class,
			'datamachine post'             => Commands\PostsCommand::class,
			'datamachine log'              => Commands\LogsCommand::class,
			'datamachine links'            => Commands\LinksCommand::class,
			'datamachine link'             => Commands\LinksCommand::class,
			'datamachine blocks'           => Commands\BlocksCommand::class,
			'datamachine block'            => Commands\BlocksCommand::class,
			'datamachine meta-description' => Commands\MetaDescriptionCommand::class,
			'datamachine indexnow'         => Commands\IndexNowCommand::class,
			'datamachine chat'             => Commands\ChatCommand::class,
			'datamachine handler'          => Commands\HandlersCommand::class,
			'datamachine step-type'        => Commands\StepTypesCommand::class,
			'datamachine processed-item'   => Commands\ProcessedItemsCommand::class,
			'datamachine tracked-item'     => Commands\TrackedItemsCommand::class,
		);
	}
}
