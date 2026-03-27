//! datamachine_load — extracted from data-machine.php.


function datamachine_run_datamachine_plugin() {

	// Set Action Scheduler timeout to 10 minutes (600 seconds) for large tasks
	add_filter(
		'action_scheduler_timeout_period',
		function () {
			return 600;
		}
	);

	// Initialize translation readiness tracking for lazy tool resolution
	\DataMachine\Engine\AI\Tools\ToolManager::init();

	// Cache invalidation hooks for dynamic registration
	add_action(
		'datamachine_handler_registered',
		function () {
			\DataMachine\Abilities\HandlerAbilities::clearCache();
		}
	);
	add_action(
		'datamachine_step_type_registered',
		function () {
			\DataMachine\Abilities\StepTypeAbilities::clearCache();
		}
	);

	datamachine_register_utility_filters();
	datamachine_register_admin_filters();
	datamachine_register_oauth_system();
	datamachine_register_core_actions();

	// Load step types - they self-register via constructors
	datamachine_load_step_types();

	// Load and instantiate all handlers - they self-register via constructors
	datamachine_load_handlers();

	// Initialize FetchHandler to register skip_item tool for all fetch-type handlers
	\DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::init();

	// Register all tools - must happen AFTER step types and handlers are registered.
	\DataMachine\Engine\AI\Tools\ToolServiceProvider::register();

	\DataMachine\Api\Execute::register();
	\DataMachine\Api\WebhookTrigger::register();
	\DataMachine\Api\Pipelines\Pipelines::register();
	\DataMachine\Api\Pipelines\PipelineSteps::register();
	\DataMachine\Api\Pipelines\PipelineFlows::register();
	\DataMachine\Api\Flows\Flows::register();
	\DataMachine\Api\Flows\FlowSteps::register();
	\DataMachine\Api\Flows\FlowQueue::register();
	\DataMachine\Api\AgentPing::register();
	\DataMachine\Api\AgentFiles::register();
	\DataMachine\Api\FlowFiles::register();
	\DataMachine\Api\Users::register();
	\DataMachine\Api\Agents::register();
	\DataMachine\Api\Logs::register();
	\DataMachine\Api\ProcessedItems::register();
	\DataMachine\Api\Jobs::register();
	\DataMachine\Api\Settings::register();
	\DataMachine\Api\Auth::register();
	\DataMachine\Api\Chat\Chat::register();
	\DataMachine\Api\System\System::register();
	\DataMachine\Api\Handlers::register();
	\DataMachine\Api\StepTypes::register();
	\DataMachine\Api\Tools::register();
	\DataMachine\Api\Providers::register();
	\DataMachine\Api\Analytics::register();
	\DataMachine\Api\InternalLinks::register();
	\DataMachine\Api\Email::register();

	// Agent runtime authentication middleware.
	new \DataMachine\Core\Auth\AgentAuthMiddleware();

	// Agent browser-based authorization flow.
	new \DataMachine\Core\Auth\AgentAuthorize();

	// Agent auth callback handler (receives tokens from external DM instances).
	new \DataMachine\Core\Auth\AgentAuthCallback();

	// Load abilities
	require_once __DIR__ . '/inc/Abilities/AuthAbilities.php';
	require_once __DIR__ . '/inc/Abilities/File/FileConstants.php';
	require_once __DIR__ . '/inc/Abilities/File/AgentFileAbilities.php';
	require_once __DIR__ . '/inc/Abilities/File/FlowFileAbilities.php';
	require_once __DIR__ . '/inc/Abilities/File/ScaffoldAbilities.php';
	require_once __DIR__ . '/inc/Abilities/FlowAbilities.php';
	require_once __DIR__ . '/inc/Abilities/FlowStepAbilities.php';
	require_once __DIR__ . '/inc/Abilities/JobAbilities.php';
	require_once __DIR__ . '/inc/Abilities/LogAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PostQueryAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PipelineAbilities.php';
	require_once __DIR__ . '/inc/Abilities/PipelineStepAbilities.php';
	require_once __DIR__ . '/inc/Core/Similarity/SimilarityResult.php';
	require_once __DIR__ . '/inc/Core/Similarity/SimilarityEngine.php';
	require_once __DIR__ . '/inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php';
	require_once __DIR__ . '/inc/Abilities/ProcessedItemsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SettingsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/HandlerAbilities.php';
	require_once __DIR__ . '/inc/Abilities/StepTypeAbilities.php';
	require_once __DIR__ . '/inc/Abilities/LocalSearchAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SystemAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/AltTextAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageGenerationAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/MediaAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/MetaDescriptionAbilities.php';
	require_once __DIR__ . '/inc/Abilities/SEO/IndexNowAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Media/ImageTemplateAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/BingWebmasterAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Analytics/PageSpeedAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentPingAbilities.php';
	require_once __DIR__ . '/inc/Abilities/TaxonomyAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentAbilities.php';
	require_once __DIR__ . '/inc/Abilities/AgentMemoryAbilities.php';
	require_once __DIR__ . '/inc/Abilities/DailyMemoryAbilities.php';
	// WorkspaceAbilities moved to data-machine-code extension.
	require_once __DIR__ . '/inc/Abilities/ChatAbilities.php';
	require_once __DIR__ . '/inc/Abilities/InternalLinkingAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Content/BlockSanitizer.php';
	require_once __DIR__ . '/inc/Abilities/Content/PendingDiffStore.php';
	require_once __DIR__ . '/inc/Abilities/Content/CanonicalDiffPreview.php';
	require_once __DIR__ . '/inc/Abilities/Content/GetPostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Content/EditPostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Content/ReplacePostBlocksAbility.php';
	require_once __DIR__ . '/inc/Abilities/Content/ResolveDiffAbility.php';
	// GitHubAbilities moved to data-machine-code extension.
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchFilesAbility.php';
	require_once __DIR__ . '/inc/Abilities/Email/EmailAbilities.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchEmailAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchRssAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchWordPressApiAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/FetchWordPressMediaAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/GetWordPressPostAbility.php';
	require_once __DIR__ . '/inc/Abilities/Fetch/QueryWordPressPostsAbility.php';
	require_once __DIR__ . '/inc/Abilities/Publish/PublishWordPressAbility.php';
	require_once __DIR__ . '/inc/Abilities/Publish/SendEmailAbility.php';
	require_once __DIR__ . '/inc/Abilities/Update/UpdateWordPressAbility.php';
	require_once __DIR__ . '/inc/Abilities/Handler/TestHandlerAbility.php';
	// Defer ability instantiation to init so translations are loaded.
	add_action( 'init', function () {
		new \DataMachine\Abilities\AuthAbilities();
		new \DataMachine\Abilities\File\AgentFileAbilities();
		new \DataMachine\Abilities\File\FlowFileAbilities();
		new \DataMachine\Abilities\File\ScaffoldAbilities();
		new \DataMachine\Abilities\FlowAbilities();
		new \DataMachine\Abilities\FlowStepAbilities();
		new \DataMachine\Abilities\JobAbilities();
		new \DataMachine\Abilities\LogAbilities();
		new \DataMachine\Abilities\PostQueryAbilities();
		new \DataMachine\Abilities\PipelineAbilities();
		new \DataMachine\Abilities\PipelineStepAbilities();
		new \DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility();
		new \DataMachine\Abilities\ProcessedItemsAbilities();
		new \DataMachine\Abilities\SettingsAbilities();
		new \DataMachine\Abilities\HandlerAbilities();
		new \DataMachine\Abilities\StepTypeAbilities();
		new \DataMachine\Abilities\LocalSearchAbilities();
		new \DataMachine\Abilities\SystemAbilities();
		new \DataMachine\Engine\AI\System\SystemAgentServiceProvider();
		new \DataMachine\Abilities\Media\AltTextAbilities();
		new \DataMachine\Abilities\Media\ImageGenerationAbilities();
		new \DataMachine\Abilities\Media\MediaAbilities();
		new \DataMachine\Abilities\SEO\MetaDescriptionAbilities();
		new \DataMachine\Abilities\SEO\IndexNowAbilities();
		new \DataMachine\Abilities\Media\ImageTemplateAbilities();
		new \DataMachine\Abilities\Analytics\BingWebmasterAbilities();
		new \DataMachine\Abilities\Analytics\GoogleAnalyticsAbilities();
		new \DataMachine\Abilities\Analytics\GoogleSearchConsoleAbilities();
		new \DataMachine\Abilities\Analytics\PageSpeedAbilities();
		new \DataMachine\Abilities\AgentPingAbilities();
		new \DataMachine\Abilities\TaxonomyAbilities();
		new \DataMachine\Abilities\AgentAbilities();
		new \DataMachine\Abilities\AgentTokenAbilities();
		new \DataMachine\Abilities\AgentMemoryAbilities();
		new \DataMachine\Abilities\DailyMemoryAbilities();
		// WorkspaceAbilities moved to data-machine-code extension.
		new \DataMachine\Abilities\ChatAbilities();
		new \DataMachine\Abilities\InternalLinkingAbilities();
		new \DataMachine\Abilities\Content\GetPostBlocksAbility();
		new \DataMachine\Abilities\Content\EditPostBlocksAbility();
		new \DataMachine\Abilities\Content\ReplacePostBlocksAbility();
		new \DataMachine\Abilities\Content\InsertContentAbility();
		new \DataMachine\Abilities\Content\ResolveDiffAbility();
		// GitHubAbilities moved to data-machine-code extension.
		new \DataMachine\Abilities\Fetch\FetchFilesAbility();
		new \DataMachine\Abilities\Email\EmailAbilities();
		new \DataMachine\Abilities\Fetch\FetchEmailAbility();
		new \DataMachine\Abilities\Fetch\FetchRssAbility();
		new \DataMachine\Abilities\Fetch\FetchWordPressApiAbility();
		new \DataMachine\Abilities\Fetch\FetchWordPressMediaAbility();
		new \DataMachine\Abilities\Fetch\GetWordPressPostAbility();
		new \DataMachine\Abilities\Fetch\QueryWordPressPostsAbility();
		new \DataMachine\Abilities\Publish\PublishWordPressAbility();
		new \DataMachine\Abilities\Publish\SendEmailAbility();
		new \DataMachine\Abilities\Update\UpdateWordPressAbility();
		new \DataMachine\Abilities\Handler\TestHandlerAbility();
	} );

	// Clean up identity index rows when posts are permanently deleted.
	add_action(
		'before_delete_post',
		function ( $post_id ) {
			$index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
			$index->delete( (int) $post_id );
		}
	);
}

/**
 * Load and instantiate all step types - they self-register via constructors.
 * Uses StepTypeRegistrationTrait for standardized registration.
 */
function datamachine_load_step_types() {
	new \DataMachine\Core\Steps\Fetch\FetchStep();
	new \DataMachine\Core\Steps\Publish\PublishStep();
	new \DataMachine\Core\Steps\Update\UpdateStep();
	new \DataMachine\Core\Steps\AI\AIStep();
	new \DataMachine\Core\Steps\AgentPing\AgentPingStep();
	new \DataMachine\Core\Steps\WebhookGate\WebhookGateStep();
	new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();
}

/**
 * Load and instantiate all handlers - they self-register via constructors.
 * Clean, explicit approach using composer PSR-4 autoloading.
 */
function datamachine_load_handlers() {
	// Publish Handlers (core only - social handlers moved to data-machine-socials plugin)
	new \DataMachine\Core\Steps\Publish\Handlers\WordPress\WordPress();
	new \DataMachine\Core\Steps\Publish\Handlers\Email\Email();

	// Fetch Handlers
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPress\WordPress();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI\WordPressAPI();
	new \DataMachine\Core\Steps\Fetch\Handlers\WordPressMedia\WordPressMedia();
	new \DataMachine\Core\Steps\Fetch\Handlers\Rss\Rss();
	new \DataMachine\Core\Steps\Fetch\Handlers\Email\Email();
	new \DataMachine\Core\Steps\Fetch\Handlers\Files\Files();
	// GitHub handler moved to data-machine-code extension.
	// Workspace fetch handler moved to data-machine-code extension.

	// Update Handlers
	new \DataMachine\Core\Steps\Update\Handlers\WordPress\WordPress();

	// Workspace publish handler moved to data-machine-code extension.
}
