<?php
/**
 * Command Registry
 *
 * Single source of truth declaring canonical `wp datamachine ...` commands,
 * their implementing command classes. The WP-CLI
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
	 * Singular aliases are retained only where the deployed Intelligence plugin
	 * invokes them directly: its brain CLI dispatches `datamachine flow run`, and
	 * its bundle install guidance invokes `datamachine agent install`.
	 *
	 * @return array<string, array{class:class-string,aliases?:string[]}>
	 */
	public static function declarations(): array {
		return array(
			'datamachine settings'         => array( 'class' => Commands\SettingsCommand::class ),
			'datamachine flows'            => array( 'class' => Commands\Flows\FlowsCommand::class, 'aliases' => array( 'datamachine flow' ) ),
			'datamachine alt-text'         => array( 'class' => Commands\AltTextCommand::class ),
			'datamachine jobs'             => array( 'class' => Commands\JobsCommand::class ),
			'datamachine cycle'            => array( 'class' => Commands\CycleCommand::class ),
			'datamachine drain'            => array( 'class' => Commands\DrainCommand::class ),
			'datamachine worker'           => array( 'class' => Commands\WorkerCommand::class ),
			'datamachine ai'               => array( 'class' => Commands\AICommand::class ),
			'datamachine pipelines'        => array( 'class' => Commands\PipelinesCommand::class ),
			'datamachine posts'            => array( 'class' => Commands\PostsCommand::class ),
			'datamachine logs'             => array( 'class' => Commands\LogsCommand::class ),
			'datamachine agents'           => array( 'class' => Commands\AgentsCommand::class, 'aliases' => array( 'datamachine agent' ) ),
			'datamachine pending-actions'  => array( 'class' => Commands\PendingActionsCommand::class ),
			'datamachine memory'           => array( 'class' => Commands\MemoryCommand::class ),
			'datamachine batch'            => array( 'class' => Commands\BatchCommand::class ),
			'datamachine image'            => array( 'class' => Commands\ImageCommand::class ),
			'datamachine auth'             => array( 'class' => Commands\AuthCommand::class ),
			'datamachine email'            => array( 'class' => Commands\EmailCommand::class ),
			'datamachine system'           => array( 'class' => Commands\SystemCommand::class ),
			'datamachine handlers'         => array( 'class' => Commands\HandlersCommand::class ),
			'datamachine taxonomy'         => array( 'class' => Commands\TaxonomyCommand::class ),
			'datamachine step-types'       => array( 'class' => Commands\StepTypesCommand::class ),
			'datamachine processed-items'  => array( 'class' => Commands\ProcessedItemsCommand::class ),
			'datamachine tracked-items'    => array( 'class' => Commands\TrackedItemsCommand::class ),
			'datamachine retention'        => array( 'class' => Commands\RetentionCommand::class ),
			'datamachine test'             => array( 'class' => Commands\TestCommand::class ),
			'datamachine external'         => array( 'class' => Commands\ExternalCommand::class ),
			'datamachine links'            => array( 'class' => Commands\LinksCommand::class ),
			'datamachine blocks'           => array( 'class' => Commands\BlocksCommand::class ),
			'datamachine meta-description' => array( 'class' => Commands\MetaDescriptionCommand::class ),
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
