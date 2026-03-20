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

/*
|--------------------------------------------------------------------------
| Default memory file registrations
|--------------------------------------------------------------------------
| Core files register through the same API any plugin or theme would use.
| Each specifies its layer, protection status, and metadata.
*/

use DataMachine\Engine\AI\MemoryFileRegistry;

// Shared layer — site-wide context, visible to all agents.
MemoryFileRegistry::register( 'SITE.md', 10, array(
	'layer'       => MemoryFileRegistry::LAYER_SHARED,
	'protected'   => true,
	'label'       => 'Site Context',
	'description' => 'Site-wide context shared by all agents.',
) );
MemoryFileRegistry::register( 'RULES.md', 15, array(
	'layer'       => MemoryFileRegistry::LAYER_SHARED,
	'protected'   => true,
	'label'       => 'Site Rules',
	'description' => 'Behavioral constraints that apply to every agent.',
) );

// Agent layer — identity and knowledge, scoped to a single agent.
MemoryFileRegistry::register( 'SOUL.md', 20, array(
	'layer'       => MemoryFileRegistry::LAYER_AGENT,
	'protected'   => true,
	'label'       => 'Agent Identity',
	'description' => 'Agent identity, voice, rules. Rarely changes.',
) );
MemoryFileRegistry::register( 'MEMORY.md', 30, array(
	'layer'       => MemoryFileRegistry::LAYER_AGENT,
	'protected'   => true,
	'label'       => 'Agent Memory',
	'description' => 'Accumulated knowledge. Grows over time.',
) );

// User layer — human preferences, network-scoped on multisite.
MemoryFileRegistry::register( 'USER.md', 25, array(
	'layer'       => MemoryFileRegistry::LAYER_USER,
	'protected'   => true,
	'label'       => 'User Profile',
	'description' => 'Information about the human the agent works with.',
) );

// Network layer — multisite topology, only meaningful on multisite installs.
MemoryFileRegistry::register( 'NETWORK.md', 5, array(
	'layer'       => MemoryFileRegistry::LAYER_NETWORK,
	'protected'   => true,
	'label'       => 'Network Context',
	'description' => 'WordPress multisite network topology and shared resources.',
) );
// SITE.md auto-regeneration — replaces the former SiteContext + SiteContextDirective system.
// SITE.md is now the single source of truth for site context, auto-refreshing on structural changes.
add_action( 'init', 'datamachine_register_site_md_invalidation' );

// NETWORK.md auto-regeneration — keeps multisite topology in sync with live WordPress state.
// Only registers hooks on multisite installs (guard is inside the function).
add_action( 'init', 'datamachine_register_network_md_invalidation' );

require_once __DIR__ . '/Engine/AI/Directives/DailyMemorySelectorDirective.php';
require_once __DIR__ . '/Api/Chat/ChatContextDirective.php';
require_once __DIR__ . '/Api/System/SystemContextDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineContextDirective.php';
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
