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
use DataMachine\Engine\AI\AgentModeRegistry;
use DataMachine\Engine\AI\IterationBudgetRegistry;
use DataMachine\Engine\AI\WpAiClientCache;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use DataMachine\Abilities\AbilityScopePermissionFilter;
use DataMachine\Abilities\AgentAbilities;
use DataMachine\Core\Content\ContentFormat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Auth\AgentAccessFilterBridge;
use DataMachine\Core\Auth\AgentAccessStoreAdapter;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
use DataMachine\Core\OAuth\HttpBasicAuthProvider;
use DataMachine\Core\PluginSettings;

add_action( 'plugins_loaded', array( WpAiClientCache::class, 'install' ), 20 );
AbilityScopePermissionFilter::register();
ContentFormat::register();

add_filter( 'wp_agent_runtime_import_bundle', array( AgentAbilities::class, 'importRuntimeAgentBundle' ), 5, 4 );
add_filter( 'wp_agent_runtime_run_bundle', array( AgentAbilities::class, 'runRuntimeAgentBundle' ), 10, 4 );
add_filter( 'wp_agent_runtime_package_run_handler', array( AgentAbilities::class, 'runtimePackageRunHandler' ), 10, 3 );

add_filter(
	'datamachine_auth_providers',
	static function ( array $providers ): array {
		$providers[ HttpBasicAuthProvider::PROVIDER_SLUG ] ??= new HttpBasicAuthProvider();
		return $providers;
	}
);

add_filter(
	'datamachine_auth_encrypted_fields',
	static function ( array $fields, string $provider_slug ): array {
		if ( HttpBasicAuthProvider::PROVIDER_SLUG === $provider_slug ) {
			$fields[] = 'password';
		}

		return $fields;
	},
	10,
	2
);

AgentAccessStoreAdapter::register();
AgentAccessFilterBridge::register();
AgentIdentityStoreAdapter::register();

add_filter(
	'wp_agent_conversation_store',
	static function () {
		return ConversationStoreFactory::get_transcript_store();
	}
);

add_filter(
	'wp_agent_pending_action_store',
	static function () {
		return PendingActionStore::adapter();
	}
);

add_filter(
	'wp_agent_pending_action_resolver',
	static function () {
		return ResolvePendingActionAbility::adapter();
	}
);

add_filter(
	'agents_pending_action_permission',
	static function (): bool {
		return \DataMachine\Abilities\PermissionHelper::can( 'chat' );
	}
);

/*
|--------------------------------------------------------------------------
| Core image templates
|--------------------------------------------------------------------------
| Core ships only brand-agnostic, structural templates (generic primitives).
| Domain-specific templates (event cards, quote cards) belong downstream.
*/
add_filter(
	// phpcs:ignore WordPress.NamingConventions.ValidHookName -- Intentional slash-separated hook namespace.
	'datamachine/image_generation/templates',
	static function ( array $templates ): array {
		$templates['flow_diagram'] ??= \DataMachine\Abilities\Media\Templates\FlowDiagramTemplate::class;
		return $templates;
	}
);

/*
|--------------------------------------------------------------------------
| Iteration budget registrations
|--------------------------------------------------------------------------
| Named bounded-iteration budgets shared across the engine. Each budget
| declares its ceiling-resolution rules (default, site-setting key,
| clamp bounds). Consumers instantiate a fresh WP_Agent_Iteration_Budget per run
| via IterationBudgetRegistry::create().
|
| Registration is side-effect free (static map mutation) and safe to
| run at file-load time — instance creation reads options lazily.
*/

IterationBudgetRegistry::register( 'conversation_turns', array(
	'default' => PluginSettings::DEFAULT_MAX_TURNS,
	'min'     => 1,
	'max'     => 200,
	'setting' => 'max_turns',
) );

// A2A chain depth — bounds how many cross-site agent hops a single
// chain can contain before being refused. Prevents runaway recursion
// when agents on different sites can call each other's /chat endpoints.
// Default 3 is deliberately low; raise via the `max_chain_depth` site
// setting if a real chain genuinely needs more hops.
IterationBudgetRegistry::register( 'chain_depth', array(
	'default' => 3,
	'min'     => 1,
	'max'     => 10,
	'setting' => 'max_chain_depth',
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
			'label'           => __( 'Chat Agent', 'data-machine' ),
			'description'     => __( 'Interactive chat conversations. Benefits from capable models for complex reasoning.', 'data-machine' ),
			'memory_contexts' => array( 'agent_identity', 'agent_memory', 'user_profile' ),
		) );
		AgentModeRegistry::register( 'pipeline', 20, array(
			'label'           => __( 'Pipeline Agent', 'data-machine' ),
			'description'     => __( 'Structured workflow execution. Operates within defined steps — efficient models work well.', 'data-machine' ),
			'memory_contexts' => array( 'agent_identity', 'agent_memory' ),
		) );
		AgentModeRegistry::register( 'pipeline_editor', 25, array(
			'label'           => __( 'Pipeline Editor Agent', 'data-machine' ),
			'description'     => __( 'Admin pipeline-editing surface. Composes on top of chat to add pipeline/handler/flow guidance, the pipelines inventory, and pipeline-editing tools.', 'data-machine' ),
			'memory_contexts' => array( 'agent_identity', 'agent_memory', 'user_profile' ),
		) );
		AgentModeRegistry::register( 'system', 30, array(
			'label'       => __( 'System Agent', 'data-machine' ),
			'description' => __( 'Background tasks like alt text generation and issue creation.', 'data-machine' ),
		) );
	},
	0
);

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

if ( did_action( 'plugins_loaded' ) ) {
	datamachine_register_default_memory_files();
} else {
	add_action( 'plugins_loaded', 'datamachine_register_default_memory_files', 0 );
}

// Composable file auto-regeneration — rebuilds AGENTS.md, SITE.md, NETWORK.md, and any other
// composable files on plugin (de)activation plus any hooks plugins register via
// datamachine_composable_invalidation_hooks (SITE.md and NETWORK.md core hooks live in
// inc/migrations/site-md.php). Runs on `init` so plugin filters registered during
// `plugins_loaded` are already in place.
add_action( 'init', array( \DataMachine\Engine\AI\ComposableFileInvalidation::class, 'register_hooks' ) );

require_once __DIR__ . '/Engine/AI/Directives/ClientContextDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/CrossSiteHandoffDirective.php';
require_once __DIR__ . '/Engine/AI/Directives/AgentDailyMemoryDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php';
require_once __DIR__ . '/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php';
require_once __DIR__ . '/Core/FilesRepository/FileCleanup.php';
require_once __DIR__ . '/Core/ActionScheduler/QueueTuning.php';
