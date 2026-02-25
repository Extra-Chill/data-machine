<?php
/**
 * Tool Service Provider.
 *
 * Centralizes registration of all global and chat tools.
 * Global tools are registered first, then chat tools (which depend
 * on step types and handlers already being registered).
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.27.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

// Global tools.
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
use DataMachine\Engine\AI\Tools\Global\WordPressPostReader;

// Chat tools.
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
 * Registers all global and chat tools.
 */
class ToolServiceProvider {

	/**
	 * Register all tools.
	 *
	 * Global tools are registered first because chat tools may depend
	 * on handlers and step types that global tools provide.
	 */
	public static function register(): void {
		self::registerGlobalTools();
		self::registerChatTools();
	}

	/**
	 * Register global tools.
	 *
	 * These tools are available to all agent types (pipeline, system, chat).
	 */
	private static function registerGlobalTools(): void {
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
		new WordPressPostReader();
	}

	/**
	 * Register chat tools.
	 *
	 * These tools are only available to the chat agent and depend on
	 * step types and handlers being already registered.
	 */
	private static function registerChatTools(): void {
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
