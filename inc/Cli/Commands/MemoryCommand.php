<?php
/**
 * WP-CLI Agent Command
 *
 * Provides CLI access to the agent Memory Library — core memory files
 * across layers (SOUL.md + MEMORY.md in agent layer, USER.md in user layer),
 * MEMORY.md section operations, and daily memory (YYYY/MM/DD.md).
 *
 * Primary command: `wp datamachine agent`.
 * Backwards-compatible alias: `wp datamachine memory`.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.30.0 Originally as AgentCommand.
 * @since 0.32.0 Renamed to MemoryCommand, registered as `wp datamachine memory`.
 * @since 0.33.0 Primary namespace changed to `wp datamachine agent`, `memory` kept as alias.
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Abilities\AgentMemoryAbilities;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachine\Cli\UserResolver;

defined( 'ABSPATH' ) || exit;

class MemoryCommand extends BaseCommand {

	/**
	 * Valid write modes.
	 *
	 * @var array
	 */
	private array $valid_modes = array( 'set', 'append' );

	// =========================================================================
	// Agent Files — multi-file operations (SOUL.md, USER.md, MEMORY.md, etc.)
	// =========================================================================

	/**
	 * Get the agent directory path.
	 *
	 * @return string
	 */
	// =========================================================================
	// Agent Paths — discovery for external consumers
	// =========================================================================
}
