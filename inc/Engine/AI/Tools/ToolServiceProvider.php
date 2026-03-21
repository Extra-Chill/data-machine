<?php
/**
 * Tool Service Provider.
 *
 * Centralizes registration of all tools via the unified `datamachine_tools` filter.
 * Each tool declares a `contexts` array specifying where it's available
 * (e.g. 'chat', 'pipeline').
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.27.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

// Tools available in chat and pipeline contexts.
use DataMachine\Engine\AI\Tools\Global\AgentDailyMemory;
use DataMachine\Engine\AI\Tools\Global\AgentMemory;
use DataMachine\Engine\AI\Tools\Global\AmazonAffiliateLink;
use DataMachine\Engine\AI\Tools\Global\BingWebmaster;
use DataMachine\Engine\AI\Tools\Global\GoogleAnalytics;
use DataMachine\Engine\AI\Tools\Global\GoogleSearch;
use DataMachine\Engine\AI\Tools\Global\GoogleSearchConsole;
use DataMachine\Engine\AI\Tools\Global\PageSpeed;
use DataMachine\Engine\AI\Tools\Global\ImageGeneration;
use DataMachine\Engine\AI\Tools\Global\InternalLinkAudit;
use DataMachine\Engine\AI\Tools\Global\LocalSearch;
use DataMachine\Engine\AI\Tools\Global\QueueValidator;
use DataMachine\Engine\AI\Tools\Global\WebFetch;
// WorkspaceTools moved to data-machine-code extension.
use DataMachine\Engine\AI\Tools\Global\WordPressPostReader;

// Chat-only tools.
use DataMachine\Api\Chat\Tools\AddPipelineStep;
use DataMachine\Api\Chat\Tools\ApiQuery;
use DataMachine\Api\Chat\Tools\AssignTaxonomyTerm;
use DataMachine\Api\Chat\Tools\AuthenticateHandler;
use DataMachine\Api\Chat\Tools\ConfigureFlowSteps;
use DataMachine\Api\Chat\Tools\ConfigurePipelineStep;
use DataMachine\Api\Chat\Tools\CopyFlow;
use DataMachine\Api\Chat\Tools\CreateFlow;
use DataMachine\Api\Chat\Tools\CreatePipeline;
use DataMachine\Api\Chat\Tools\CreateTaxonomyTerm;
use DataMachine\Api\Chat\Tools\DeleteFile;
use DataMachine\Api\Chat\Tools\DeleteFlow;
use DataMachine\Api\Chat\Tools\DeletePipeline;
use DataMachine\Api\Chat\Tools\DeletePipelineStep;
use DataMachine\Api\Chat\Tools\ExecuteWorkflowTool;
use DataMachine\Api\Chat\Tools\GetHandlerDefaults;
use DataMachine\Api\Chat\Tools\ListFlows;
use DataMachine\Api\Chat\Tools\ManageJobs;
use DataMachine\Api\Chat\Tools\ManageLogs;
use DataMachine\Api\Chat\Tools\ManageQueue;
use DataMachine\Api\Chat\Tools\MergeTaxonomyTerms;
use DataMachine\Api\Chat\Tools\ReadLogs;
use DataMachine\Api\Chat\Tools\ReorderPipelineSteps;
use DataMachine\Api\Chat\Tools\RunFlow;
use DataMachine\Api\Chat\Tools\SearchTaxonomyTerms;
use DataMachine\Api\Chat\Tools\SendPing;
use DataMachine\Api\Chat\Tools\SetHandlerDefaults;
use DataMachine\Api\Chat\Tools\SystemHealthCheck;
use DataMachine\Api\Chat\Tools\UpdateFlow;
use DataMachine\Api\Chat\Tools\UpdateTaxonomyTerm;

/**
 * Registers all tools via the unified datamachine_tools filter.
 */
class ToolServiceProvider {

	/**
	 * Register all tools.
	 *
	 * Tools with contexts ['chat', 'pipeline'] are registered
	 * first because chat-only tools may depend on handlers and step types
	 * that they provide.
	 */
	public static function register(): void {
		self::registerTools();
	}

	/**
	 * Register all tools with their context declarations.
	 */
	private static function registerTools(): void {
		// Tools available in chat and pipeline contexts.
		new AgentDailyMemory();
		new AgentMemory();
		new AmazonAffiliateLink();
		new BingWebmaster();
		new GoogleAnalytics();
		new GoogleSearch();
		new GoogleSearchConsole();
		new PageSpeed();
		new ImageGeneration();
		new InternalLinkAudit();
		new LocalSearch();
		new QueueValidator();
		new WebFetch();
		// WorkspaceTools moved to data-machine-code extension.
		new WordPressPostReader();

		// Chat-only tools.
		new ApiQuery();
		new CreatePipeline();
		new AddPipelineStep();
		new CreateFlow();
		new ConfigureFlowSteps();
		new RunFlow();
		new UpdateFlow();
		new ConfigurePipelineStep();
		new ExecuteWorkflowTool();
		new CopyFlow();
		new AuthenticateHandler();
		new ReadLogs();
		new ManageLogs();
		new CreateTaxonomyTerm();
		new SearchTaxonomyTerms();
		new UpdateTaxonomyTerm();
		new MergeTaxonomyTerms();
		new AssignTaxonomyTerm();
		new GetHandlerDefaults();
		new SetHandlerDefaults();
		new DeleteFile();
		new DeleteFlow();
		new DeletePipeline();
		new DeletePipelineStep();
		new ReorderPipelineSteps();
		new ListFlows();
		new ManageQueue();
		new ManageJobs();
		new SendPing();
		new SystemHealthCheck();
	}
}
