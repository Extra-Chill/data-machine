<?php
/**
 * Command Registry
 *
 * Single source of truth declaring canonical `wp datamachine ...` commands,
 * their compatibility aliases, and implementing command classes. The WP-CLI
 * bootstrap calls WP_CLI::add_command for each flattened map entry. Generated
 * agent guidance advertises a bounded set of routing entrypoints and tests
 * those roots against this map; live `--help` remains authoritative for the
 * complete command surface.
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
	 * Canonical command declarations.
	 *
	 * Keys are canonical command strings. Compatibility spellings are declared
	 * beside the implementation rather than as independent commands.
	 *
	 * @return array<string, array{class: class-string, aliases?: string[]}>
	 */
	public static function declarations(): array {
		return array(
			'datamachine settings'         => array(
				'class'   => Commands\SettingsCommand::class,
				'aliases' => array( 'datamachine setting' ),
			),
			'datamachine flows'            => array(
				'class'   => Commands\Flows\FlowsCommand::class,
				'aliases' => array( 'datamachine flow' ),
			),
			'datamachine alt-text'         => array( 'class' => Commands\AltTextCommand::class ),
			'datamachine jobs'             => array(
				'class'   => Commands\JobsCommand::class,
				'aliases' => array( 'datamachine job' ),
			),
			'datamachine cycle'            => array(
				'class'   => Commands\CycleCommand::class,
				'aliases' => array( 'datamachine cycles' ),
			),
			'datamachine drain'            => array( 'class' => Commands\DrainCommand::class ),
			'datamachine worker'           => array( 'class' => Commands\WorkerCommand::class ),
			'datamachine ai'               => array( 'class' => Commands\AICommand::class ),
			'datamachine pipelines'        => array(
				'class'   => Commands\PipelinesCommand::class,
				'aliases' => array( 'datamachine pipeline' ),
			),
			'datamachine posts'            => array(
				'class'   => Commands\PostsCommand::class,
				'aliases' => array( 'datamachine post' ),
			),
			'datamachine logs'             => array(
				'class'   => Commands\LogsCommand::class,
				'aliases' => array( 'datamachine log' ),
			),
			'datamachine agents'           => array(
				'class'   => Commands\AgentsCommand::class,
				'aliases' => array( 'datamachine agent' ),
			),
			'datamachine pending-actions'  => array(
				'class'   => Commands\PendingActionsCommand::class,
				'aliases' => array( 'datamachine pending-action' ),
			),
			'datamachine memory'           => array( 'class' => Commands\MemoryCommand::class ),
			'datamachine batch'            => array( 'class' => Commands\BatchCommand::class ),
			'datamachine image'            => array( 'class' => Commands\ImageCommand::class ),
			'datamachine auth'             => array( 'class' => Commands\AuthCommand::class ),
			'datamachine email'            => array( 'class' => Commands\EmailCommand::class ),
			'datamachine system'           => array( 'class' => Commands\SystemCommand::class ),
			'datamachine handlers'         => array(
				'class'   => Commands\HandlersCommand::class,
				'aliases' => array( 'datamachine handler' ),
			),
			'datamachine taxonomy'         => array( 'class' => Commands\TaxonomyCommand::class ),
			'datamachine step-types'       => array(
				'class'   => Commands\StepTypesCommand::class,
				'aliases' => array( 'datamachine step-type' ),
			),
			'datamachine processed-items'  => array(
				'class'   => Commands\ProcessedItemsCommand::class,
				'aliases' => array( 'datamachine processed-item' ),
			),
			'datamachine tracked-items'    => array(
				'class'   => Commands\TrackedItemsCommand::class,
				'aliases' => array( 'datamachine tracked-item' ),
			),
			'datamachine retention'        => array( 'class' => Commands\RetentionCommand::class ),
			'datamachine test'             => array(
				'class'   => Commands\TestCommand::class,
				'aliases' => array( 'datamachine fetch test' ),
			),
			'datamachine external'         => array( 'class' => Commands\ExternalCommand::class ),
			'datamachine links'            => array(
				'class'   => Commands\LinksCommand::class,
				'aliases' => array( 'datamachine link' ),
			),
			'datamachine blocks'           => array(
				'class'   => Commands\BlocksCommand::class,
				'aliases' => array( 'datamachine block' ),
			),
			'datamachine meta-description' => array( 'class' => Commands\MetaDescriptionCommand::class ),
			'datamachine indexnow'         => array( 'class' => Commands\IndexNowCommand::class ),
			'datamachine chat'             => array( 'class' => Commands\ChatCommand::class ),
		);
	}

	/**
	 * Map every accepted command string to its implementation.
	 *
	 * @return array<string, class-string>
	 */
	public static function map(): array {
		$map = array();

		foreach ( self::declarations() as $command => $declaration ) {
			$map[ $command ] = $declaration['class'];

			foreach ( $declaration['aliases'] ?? array() as $alias ) {
				$map[ $alias ] = $declaration['class'];
			}
		}

		return $map;
	}
}
