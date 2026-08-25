<?php
/**
 * Full runtime service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the gated Data Machine runtime.
 */
final class RuntimeServiceProvider {

	/**
	 * Register the full runtime once when the request requires it.
	 */
	public static function register(): void {
		if ( ! RuntimeEnvironment::should_load_full_runtime() ) {
			return;
		}

		static $runtime_loaded = false;
		if ( $runtime_loaded ) {
			return;
		}
		$runtime_loaded = true;

		add_filter(
			'action_scheduler_timeout_period',
			static function (): int {
				return 600;
			}
		);

		\DataMachine\Engine\AI\Tools\ToolManager::init();

		add_action(
			'datamachine_handler_registered',
			static function (): void {
				\DataMachine\Abilities\HandlerAbilities::clearCache();
			}
		);
		add_action(
			'datamachine_step_type_registered',
			static function (): void {
				\DataMachine\Abilities\StepTypeAbilities::clearCache();
			}
		);

		datamachine_register_utility_filters();
		datamachine_register_admin_filters();
		datamachine_register_oauth_system();
		datamachine_register_core_actions();
		datamachine_log_agents_api_load_warning();

		new \DataMachine\Core\Steps\Fetch\FetchStep();
		new \DataMachine\Core\Steps\Publish\PublishStep();
		new \DataMachine\Core\Steps\Upsert\UpsertStep();
		new \DataMachine\Core\Steps\AI\AIStep();
		new \DataMachine\Core\Steps\WebhookGate\WebhookGateStep();
		new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();

		new \DataMachine\Core\Steps\Publish\Handlers\WordPress\WordPress();
		new \DataMachine\Core\Steps\Publish\Handlers\Email\Email();
		new \DataMachine\Core\Steps\Publish\Handlers\TypedArtifact\TypedArtifact();
		new \DataMachine\Core\Steps\Fetch\Handlers\WordPress\WordPress();
		new \DataMachine\Core\Steps\Fetch\Handlers\WordPressAPI\WordPressAPI();
		new \DataMachine\Core\Steps\Fetch\Handlers\WordPressMedia\WordPressMedia();
		new \DataMachine\Core\Steps\Fetch\Handlers\Rss\Rss();
		new \DataMachine\Core\Steps\Fetch\Handlers\Email\Email();
		new \DataMachine\Core\Steps\Fetch\Handlers\Files\Files();
		new \DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload\WebhookPayload();
		new \DataMachine\Core\Steps\Upsert\Handlers\WordPress\WordPress();
		\DataMachine\Engine\Bundle\AuthRefHandlerConfig::register();
		\DataMachine\Engine\Bundle\BundleSourceAuth::register();
		\DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts::register();
		\DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::init();

		// Tools depend on step types and handlers.
		new \DataMachine\Engine\AI\Tools\Global\AgentDailyMemory();
		new \DataMachine\Engine\AI\Tools\Global\AgentMemory();
		new \DataMachine\Engine\AI\Configuration\ImageGenerationSettings();
		\datamachine_register_global_ability_tools();
		new \DataMachine\Engine\AI\Tools\Global\QueueValidator();
		new \DataMachine\Engine\AI\Tools\Global\WebFetch();
		new \DataMachine\Api\Chat\Tools\ConsultAgent();
		new \DataMachine\Api\Chat\Tools\ApiQuery();
		new \DataMachine\Api\Chat\Tools\CreatePipeline();
		new \DataMachine\Api\Chat\Tools\AddPipelineStep();
		new \DataMachine\Api\Chat\Tools\CreateFlow();
		new \DataMachine\Api\Chat\Tools\ConfigureFlowSteps();
		new \DataMachine\Api\Chat\Tools\RunFlow();
		new \DataMachine\Api\Chat\Tools\UpdateFlow();
		new \DataMachine\Api\Chat\Tools\ConfigurePipelineStep();
		new \DataMachine\Api\Chat\Tools\ExecuteWorkflowTool();
		new \DataMachine\Api\Chat\Tools\CopyFlow();
		new \DataMachine\Api\Chat\Tools\AuthenticateHandler();
		new \DataMachine\Api\Chat\Tools\ReadLogs();
		new \DataMachine\Api\Chat\Tools\ManageLogs();
		new \DataMachine\Api\Chat\Tools\CreateTaxonomyTerm();
		new \DataMachine\Api\Chat\Tools\SearchTaxonomyTerms();
		new \DataMachine\Api\Chat\Tools\UpdateTaxonomyTerm();
		new \DataMachine\Api\Chat\Tools\MergeTaxonomyTerms();
		new \DataMachine\Api\Chat\Tools\AssignTaxonomyTerm();
		new \DataMachine\Api\Chat\Tools\GetHandlerDefaults();
		new \DataMachine\Api\Chat\Tools\SetHandlerDefaults();
		new \DataMachine\Api\Chat\Tools\DeleteFile();
		new \DataMachine\Api\Chat\Tools\DeleteFlow();
		new \DataMachine\Api\Chat\Tools\DeletePipeline();
		new \DataMachine\Api\Chat\Tools\DeletePipelineStep();
		new \DataMachine\Api\Chat\Tools\ReorderPipelineSteps();
		new \DataMachine\Api\Chat\Tools\ListFlows();
		new \DataMachine\Api\Chat\Tools\ManageQueue();
		new \DataMachine\Api\Chat\Tools\ManageJobs();
		new \DataMachine\Api\Chat\Tools\SendPing();
		new \DataMachine\Api\Chat\Tools\SystemHealthCheck();

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

		new \DataMachine\Core\Auth\AgentAuthMiddleware();
		new \DataMachine\Core\Auth\AgentAuthorize();
		new \DataMachine\Core\Auth\AgentAuthCallback();
		\DataMachine\Core\Auth\ExternalLoginRouter::register();

		AbilityServiceProvider::register_full_runtime();

		// @phpstan-ignore-next-line WordPress stubs in CI omit the optional priority argument.
		add_action(
			'init',
			static function (): void {
				if ( get_transient( 'datamachine_needs_scaffold' ) ) {
					delete_transient( 'datamachine_needs_scaffold' );
					datamachine_ensure_default_memory_files();
				}
			},
			20
		);

		add_action(
			'before_delete_post',
			static function ( $post_id ): void {
				$index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
				$index->delete( (int) $post_id );
			}
		);
	}
}
