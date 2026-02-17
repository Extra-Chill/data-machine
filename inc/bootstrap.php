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
require_once __DIR__ . '/Core/Admin/Pages/Logs/LogsFilters.php';
require_once __DIR__ . '/Core/Admin/Pages/Jobs/JobsFilters.php';
require_once __DIR__ . '/Api/Providers.php';
require_once __DIR__ . '/Api/StepTypes.php';
require_once __DIR__ . '/Api/Handlers.php';
require_once __DIR__ . '/Api/Tools.php';
require_once __DIR__ . '/Api/Chat/ChatFilters.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentSoulDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/SiteContext.php';
require_once __DIR__ . '/Api/Chat/ChatAgentDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineCoreDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineContextDirective.php';
require_once __DIR__ . '/Core/FilesRepository/FileCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/ClaimsCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/QueueTuning.php';
