<?php
/**
 * Plugin bootstrap — procedural includes and side-effect registrations.
 *
 * Namespaced classes without file-level side effects are autoloaded by
 * Composer (see composer.json PSR-4 config). Only files that define
 * global functions or register hooks/filters at load time are listed here.
 *
 * @package DataMachine
 * @since   0.26.0
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Procedural function files (no namespace, no class)
|--------------------------------------------------------------------------
| These define global functions and cannot be autoloaded by Composer.
*/

require_once __DIR__ . '/Engine/Filters/SchedulerIntervals.php';
require_once __DIR__ . '/Engine/Filters/DataMachineFilters.php';
require_once __DIR__ . '/Engine/Filters/Handlers.php';
require_once __DIR__ . '/Engine/Filters/Admin.php';
require_once __DIR__ . '/Engine/Logger.php';
require_once __DIR__ . '/Engine/MCP/functions.php';
require_once __DIR__ . '/Engine/Filters/OAuth.php';
require_once __DIR__ . '/Engine/Actions/DataMachineActions.php';
require_once __DIR__ . '/Engine/Filters/EngineData.php';
require_once __DIR__ . '/Engine/AI/Tools/ability-tool-projections.php';
require_once __DIR__ . '/Core/Admin/Settings/SettingsFilters.php';

/*
|--------------------------------------------------------------------------
| Namespaced files with file-level side effects
|--------------------------------------------------------------------------
| These contain namespaced functions or classes but register hooks/filters
| at the file level (outside any class method). They must be explicitly
| loaded so those registrations fire at include time.
*/

require_once __DIR__ . '/Core/Admin/Modal/ModalFilters.php';
require_once __DIR__ . '/Core/Admin/AdminRootFilters.php';
require_once __DIR__ . '/Core/Admin/Pages/Pipelines/PipelinesFilters.php';
require_once __DIR__ . '/Core/Admin/Pages/Agent/AgentFilters.php';
require_once __DIR__ . '/Core/Admin/Pages/Logs/LogsFilters.php';
require_once __DIR__ . '/Core/Admin/Pages/Jobs/JobsFilters.php';
require_once __DIR__ . '/Api/Providers.php';
require_once __DIR__ . '/Api/StepTypes.php';
require_once __DIR__ . '/Api/Handlers.php';
require_once __DIR__ . '/Api/Tools.php';
require_once __DIR__ . '/Api/AgentBundles.php';
require_once __DIR__ . '/Api/Chat/ChatFilters.php';
require_once __DIR__ . '/Engine/Bundle/register-agent-package-artifacts.php';
require_once __DIR__ . '/Engine/Bundle/AgentBundleUpgradeActionHandlers.php';
require_once __DIR__ . '/Engine/AI/Directives/CoreMemoryFilesDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentModeDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/CallerContextDirective.php';
require_once __DIR__ . '/Engine/Agents/datamachine-register-agents.php';

/*
|--------------------------------------------------------------------------
| Default memory file registrations
|--------------------------------------------------------------------------
| Core files register through the same API any plugin or theme would use.
| Each specifies its layer, protection status, and metadata.
*/

use DataMachine\Engine\AI\MemoryFileRegistry;

function datamachine_register_default_memory_files(): void {
	// Shared layer — site-wide context, visible to all agents.
	// Composable: content assembled from sections registered against SectionRegistry
	// (see inc/migrations/site-md.php). `editable` is forced to false by composable=true.
	MemoryFileRegistry::register( 'SITE.md', 10, array(
		'layer'       => MemoryFileRegistry::LAYER_SHARED,
		'protected'   => true,
		'composable'  => true,
		'modes'       => array( MemoryFileRegistry::MODE_ALL ),
		'label'       => 'Site Context',
		'description' => 'Auto-generated site context. Composable — extend via SectionRegistry.',
	) );
	MemoryFileRegistry::register( 'RULES.md', 15, array(
		'layer'       => MemoryFileRegistry::LAYER_SHARED,
		'protected'   => true,
		'editable'    => 'manage_options',
		'modes'       => array( MemoryFileRegistry::MODE_ALL ),
		'label'       => 'Site Rules',
		'description' => 'Behavioral constraints that apply to every agent. Admin-editable.',
	) );

	// Agent layer — identity and knowledge, scoped to a single agent.
	// Injected only when an execution mode activates the matching semantic
	// memory context. Excluded from
	// system mode so autonomous maintenance tasks (e.g. daily memory
	// compaction) are not primed with the agent's identity while operating
	// on these files.
	MemoryFileRegistry::register( 'SOUL.md', 20, array(
		'layer'              => MemoryFileRegistry::LAYER_AGENT,
		'protected'          => true,
		'injection_contexts' => array( 'agent_identity' ),
		'label'              => 'Agent Identity',
		'description'        => 'Agent identity, voice, rules. Injected when the mode activates agent identity memory.',
	) );
	MemoryFileRegistry::register( 'MEMORY.md', 30, array(
		'layer'              => MemoryFileRegistry::LAYER_AGENT,
		'protected'          => true,
		'injection_contexts' => array( 'agent_memory' ),
		'label'              => 'Agent Memory',
		'description'        => 'Accumulated knowledge. Injected when the mode activates agent memory.',
	) );
	// Wake briefing — a terse, stateless rolling-window continuity digest
	// composed by WakeBriefingTask and overwritten each run. Injected with
	// agent memory so a fresh session opens already holding a glance at
	// anything red that happened recently. Read-only: it is machine-written,
	// never hand-edited. Priority 35 places it just after MEMORY.md.
	MemoryFileRegistry::register( 'WAKE.md', 35, array(
		'layer'              => MemoryFileRegistry::LAYER_AGENT,
		'protected'          => true,
		'editable'           => false,
		'injection_contexts' => array( 'agent_memory' ),
		'label'              => 'Wake Briefing',
		'description'        => 'Auto-generated rolling-window digest of recent threshold-crossing activity. Machine-written by the wake_briefing task; not hand-editable.',
	) );

	// User layer — human preferences, network-scoped on multisite.
	// Only injected in interactive modes where a human is present.
	// Pipelines can still opt in via pipeline memory file selection.
	MemoryFileRegistry::register( 'USER.md', 25, array(
		'layer'              => MemoryFileRegistry::LAYER_USER,
		'protected'          => true,
		'injection_contexts' => array( 'user_profile' ),
		'label'              => 'User Profile',
		'description'        => 'Information about the human the agent works with. Injected when the mode activates user profile memory.',
	) );

	// Network layer — multisite topology.
	if ( is_multisite() ) {
		// Composable: content assembled from sections registered against SectionRegistry.
		MemoryFileRegistry::register( 'NETWORK.md', 5, array(
			'layer'       => MemoryFileRegistry::LAYER_NETWORK,
			'protected'   => true,
			'composable'  => true,
			'modes'       => array( MemoryFileRegistry::MODE_ALL ),
			'label'       => 'Network Context',
			'description' => 'Auto-generated multisite network topology. Composable — extend via SectionRegistry.',
		) );
	}

	// AGENTS.md — gated default-OFF behind DATAMACHINE_COMPOSE_AGENTS_MD.
	// Registration is a no-op when the constant is unset/false, so installs
	// with no coding agent keep zero AGENTS.md footprint. Defined in
	// inc/migrations/agents-md.php (required via inc/migrations/load.php).
	if ( function_exists( 'datamachine_register_agents_md_file' ) ) {
		datamachine_register_agents_md_file();
	}
}

\DataMachine\Core\Bootstrap\HostIntegrationServiceProvider::register();

require_once __DIR__ . '/Engine/AI/Directives/ClientContextDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/CrossSiteHandoffDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentDailyMemoryDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php';
require_once __DIR__ . '/Core/FilesRepository/FileCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/QueueTuning.php';
