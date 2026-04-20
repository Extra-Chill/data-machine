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
require_once __DIR__ . '/Engine/Filters/OAuth.php';
require_once __DIR__ . '/Engine/Actions/DataMachineActions.php';
require_once __DIR__ . '/Engine/Filters/EngineData.php';
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
require_once __DIR__ . '/Api/Chat/ChatFilters.php';
require_once __DIR__ . '/Engine/AI/Directives/CoreMemoryFilesDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentModeDirective.php';

/*
|--------------------------------------------------------------------------
| Default memory file registrations
|--------------------------------------------------------------------------
| Core files register through the same API any plugin or theme would use.
| Each specifies its layer, protection status, and metadata.
*/

use DataMachine\Engine\AI\MemoryFileRegistry;
use DataMachine\Engine\AI\AgentModeRegistry;
use DataMachine\Engine\AI\IterationBudgetRegistry;
use DataMachine\Core\PluginSettings;

/*
|--------------------------------------------------------------------------
| Iteration budget registrations
|--------------------------------------------------------------------------
| Named bounded-iteration budgets shared across the engine. Each budget
| declares its ceiling-resolution rules (default, site-setting key,
| clamp bounds). Consumers instantiate a fresh IterationBudget per run
| via IterationBudgetRegistry::create().
|
| Registration is side-effect free (static map mutation) and safe to
| run at file-load time — instance creation reads options lazily.
*/

IterationBudgetRegistry::register( 'conversation_turns', array(
	'default' => PluginSettings::DEFAULT_MAX_TURNS,
	'min'     => 1,
	'max'     => 50,
	'setting' => 'max_turns',
) );

/*
|--------------------------------------------------------------------------
| Execution mode registrations
|--------------------------------------------------------------------------
| Core modes register through the same API any extension would use.
| Each specifies a priority for sort order, a label, and a description.
*/

add_action(
	'init',
	function () {
		AgentModeRegistry::register( 'chat', 10, array(
			'label'       => __( 'Chat Agent', 'data-machine' ),
			'description' => __( 'Interactive chat conversations. Benefits from capable models for complex reasoning.', 'data-machine' ),
		) );
		AgentModeRegistry::register( 'pipeline', 20, array(
			'label'       => __( 'Pipeline Agent', 'data-machine' ),
			'description' => __( 'Structured workflow execution. Operates within defined steps — efficient models work well.', 'data-machine' ),
		) );
		AgentModeRegistry::register( 'system', 30, array(
			'label'       => __( 'System Agent', 'data-machine' ),
			'description' => __( 'Background tasks like alt text generation and issue creation.', 'data-machine' ),
		) );
	},
	0
);

// Shared layer — site-wide context, visible to all agents.
MemoryFileRegistry::register( 'SITE.md', 10, array(
	'layer'       => MemoryFileRegistry::LAYER_SHARED,
	'protected'   => true,
	'editable'    => false,
	'label'       => 'Site Context',
	'description' => 'Auto-generated site context. Read-only — extend via PHP filters.',
) );
MemoryFileRegistry::register( 'RULES.md', 15, array(
	'layer'       => MemoryFileRegistry::LAYER_SHARED,
	'protected'   => true,
	'editable'    => 'manage_options',
	'label'       => 'Site Rules',
	'description' => 'Behavioral constraints that apply to every agent. Admin-editable.',
) );

// Agent layer — identity and knowledge, scoped to a single agent.
// Injected in interactive modes only (chat, pipeline). Excluded from
// system mode so autonomous maintenance tasks (e.g. daily memory
// compaction) are not primed with the agent's identity while operating
// on these files.
MemoryFileRegistry::register( 'SOUL.md', 20, array(
	'layer'       => MemoryFileRegistry::LAYER_AGENT,
	'protected'   => true,
	'modes'       => array( 'chat', 'pipeline' ),
	'label'       => 'Agent Identity',
	'description' => 'Agent identity, voice, rules. Injected in interactive modes only.',
) );
MemoryFileRegistry::register( 'MEMORY.md', 30, array(
	'layer'       => MemoryFileRegistry::LAYER_AGENT,
	'protected'   => true,
	'modes'       => array( 'chat', 'pipeline' ),
	'label'       => 'Agent Memory',
	'description' => 'Accumulated knowledge. Injected in interactive modes only.',
) );

// User layer — human preferences, network-scoped on multisite.
// Only injected in interactive modes where a human is present.
// Pipelines can still opt in via pipeline memory file selection.
MemoryFileRegistry::register( 'USER.md', 25, array(
	'layer'       => MemoryFileRegistry::LAYER_USER,
	'protected'   => true,
	'modes'       => array( 'chat', 'editor' ),
	'label'       => 'User Profile',
	'description' => 'Information about the human the agent works with. Injected in chat and editor modes only.',
) );

// Network layer — multisite topology, only meaningful on multisite installs.
MemoryFileRegistry::register( 'NETWORK.md', 5, array(
	'layer'       => MemoryFileRegistry::LAYER_NETWORK,
	'protected'   => true,
	'editable'    => false,
	'label'       => 'Network Context',
	'description' => 'Auto-generated multisite network topology. Read-only — extend via PHP filters.',
) );

// SITE.md auto-regeneration — replaces the former SiteContext + SiteContextDirective system.
// SITE.md is now the single source of truth for site context, auto-refreshing on structural changes.
add_action( 'init', 'datamachine_register_site_md_invalidation' );

// NETWORK.md auto-regeneration — keeps multisite topology in sync with live WordPress state.
// Only registers hooks on multisite installs (guard is inside the function).
add_action( 'init', 'datamachine_register_network_md_invalidation' );

// Composable file auto-regeneration — rebuilds AGENTS.md (and any other composable files)
// when plugins are activated/deactivated, since those events change which sections are registered.
add_action( 'init', 'datamachine_register_composable_file_invalidation' );

require_once __DIR__ . '/Engine/AI/Directives/ClientContextDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentDailyMemoryDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php';
require_once __DIR__ . '/Core/FilesRepository/FileCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/ClaimsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/JobsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/CompletedJobsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/LogCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/ProcessedItemsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/ActionsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/QueueTuning.php';
