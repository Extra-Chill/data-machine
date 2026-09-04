<?php
/**
 * Ability service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\AbilityCategories;
use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\Media\ImageTemplateAbilities;
use DataMachine\Abilities\Publish\SendEmailAbility;
use DataMachine\Abilities\Publish\SendEmailQueuedAbility;

/**
 * Registers lightweight and full-runtime ability surfaces.
 */
final class AbilityServiceProvider {

	/**
	 * Register abilities required on lightweight requests.
	 */
	public static function register_lightweight(): void {
		AbilityCategories::ensure_registered();
		new AgentAbilities();
		ImageTemplateAbilities::ensure_registered();
		SendEmailAbility::ensure_registered();
		SendEmailQueuedAbility::ensure_registered();
	}

	/**
	 * Register the complete ability runtime in load order.
	 */
	public static function register_full_runtime(): void {
		$plugin_root = dirname( __DIR__, 3 );

		// Register ability categories first — must happen before any ability registration.
		AbilityCategories::ensure_registered();

		// This file registers pending-action handlers at load time.
		require_once $plugin_root . '/inc/Abilities/Content/ContentActionHandlers.php';

		new \DataMachine\Abilities\AuthAbilities();
		new \DataMachine\Abilities\AI\InspectRequestAbility();
		new \DataMachine\Abilities\File\AgentFileAbilities();
		new \DataMachine\Abilities\File\FlowFileAbilities();
		new \DataMachine\Abilities\File\ScaffoldAbilities();
		new \DataMachine\Abilities\Flow\GetFlowsAbility();
		new \DataMachine\Abilities\Flow\CreateFlowAbility();
		new \DataMachine\Abilities\Flow\UpdateFlowAbility();
		new \DataMachine\Abilities\Flow\DeleteFlowAbility();
		new \DataMachine\Abilities\Flow\DuplicateFlowAbility();
		new \DataMachine\Abilities\Flow\PauseFlowAbility();
		new \DataMachine\Abilities\Flow\ResumeFlowAbility();
		new \DataMachine\Abilities\Flow\ReconcileFlowSchedulesAbility();
		new \DataMachine\Abilities\Flow\QueueAbility();
		new \DataMachine\Abilities\Flow\WebhookTriggerAbility();
		new \DataMachine\Abilities\FlowStep\GetFlowStepsAbility();
		new \DataMachine\Abilities\FlowStep\UpdateFlowStepAbility();
		new \DataMachine\Abilities\FlowStep\ConfigureFlowStepsAbility();
		new \DataMachine\Abilities\FlowStep\ValidateFlowStepsConfigAbility();
		new \DataMachine\Abilities\Job\GetJobsAbility();
		new \DataMachine\Abilities\Job\GetRunArtifactsAbility();
		new \DataMachine\Abilities\Job\HydrateJobArtifactAbility();
		new \DataMachine\Abilities\Job\DeleteJobsAbility();
		new \DataMachine\Abilities\Job\ExecuteWorkflowAbility();
		new \DataMachine\Abilities\DelegatedOperationAbilities();
		new \DataMachine\Abilities\Job\ExecuteAgentWorkflowAbility();
		new \DataMachine\Abilities\Job\FlowHealthAbility();
		new \DataMachine\Abilities\Job\ProblemFlowsAbility();
		new \DataMachine\Abilities\Job\RecoverStuckJobsAbility();
		new \DataMachine\Abilities\Job\JobsSummaryAbility();
		new \DataMachine\Abilities\Job\RunMetricsAbility();
		new \DataMachine\Abilities\Job\FailJobAbility();
		new \DataMachine\Abilities\Job\RetryJobAbility();
		new \DataMachine\Abilities\LogAbilities();
		new \DataMachine\Abilities\PostQueryAbilities();
		new \DataMachine\Abilities\Pipeline\GetPipelinesAbility();
		new \DataMachine\Abilities\Pipeline\CreatePipelineAbility();
		new \DataMachine\Abilities\Pipeline\UpdatePipelineAbility();
		new \DataMachine\Abilities\Pipeline\DeletePipelineAbility();
		new \DataMachine\Abilities\Pipeline\DuplicatePipelineAbility();
		new \DataMachine\Abilities\Pipeline\ImportExportAbility();
		new \DataMachine\Abilities\Pipeline\PipelineConfigurationAbilities();
		new \DataMachine\Abilities\PipelineStepAbilities();
		new \DataMachine\Abilities\DuplicateCheck\DuplicateCheckAbility();
		new \DataMachine\Abilities\ProcessedItemsAbilities();
		new \DataMachine\Abilities\TrackedItemsAbilities();
		new \DataMachine\Abilities\SettingsAbilities();
		new \DataMachine\Abilities\HandlerAbilities();
		new \DataMachine\Abilities\StepTypeAbilities();
		new \DataMachine\Abilities\LocalSearchAbilities();
		new \DataMachine\Abilities\SourceAggregateAbility();
		new \DataMachine\Abilities\SourceInventoryAbility();
		new \DataMachine\Abilities\SystemAbilities();
		new \DataMachine\Abilities\Media\AltTextAbilities();
		new \DataMachine\Abilities\Media\ImageGenerationAbilities();
		new \DataMachine\Abilities\Media\MediaAbilities();
		new \DataMachine\Abilities\SEO\MetaDescriptionAbilities();
		new \DataMachine\Abilities\Media\ImageTemplateAbilities();
		new \DataMachine\Abilities\AgentCallAbilities();
		new \DataMachine\Abilities\AgentRemoteCallAbilities();
		new \DataMachine\Abilities\Runtime\RuntimeTaskAbility();
		new \DataMachine\Abilities\Taxonomy\ResolveTermAbility();
		new \DataMachine\Abilities\Taxonomy\MergeTermMetaAbility();
		new \DataMachine\Abilities\Taxonomy\GetTaxonomyTermsAbility();
		new \DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility();
		new \DataMachine\Abilities\Taxonomy\UpdateTaxonomyTermAbility();
		new \DataMachine\Abilities\Taxonomy\DeleteTaxonomyTermAbility();
		new \DataMachine\Abilities\AgentAbilities();
		new \DataMachine\Abilities\AgentTokenAbilities();
		new \DataMachine\Abilities\AgentMemoryAbilities();
		new \DataMachine\Abilities\InjectableMemoryFilesAbility();
		new \DataMachine\Abilities\DailyMemoryAbilities();
		new \DataMachine\Abilities\InternalLinkingAbilities();
		new \DataMachine\Abilities\Content\GetPostBlocksAbility();
		new \DataMachine\Abilities\Content\EditPostBlocksAbility();
		new \DataMachine\Abilities\Content\ReplacePostBlocksAbility();
		new \DataMachine\Abilities\Content\InsertContentAbility();
		new \DataMachine\Abilities\Content\UpsertPostAbility();

		\DataMachine\Engine\AI\Actions\PendingActionObservers::register( new \DataMachine\Engine\AI\Actions\WordPressActionDispatchObserver() );
		new \DataMachine\Engine\AI\Actions\PendingActionInspectionAbility();
		new \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility();
		new \DataMachine\Engine\AI\Actions\ResolvePendingActionAbility();
		new \DataMachine\Engine\AI\Actions\ResolvePendingAction();

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
		new \DataMachine\Abilities\Publish\SendEmailQueuedAbility();
		new \DataMachine\Abilities\Update\UpdateWordPressAbility();
		new \DataMachine\Abilities\Handler\TestHandlerAbility();

		// Task registry initialization can resolve abilities, so register it last.
		new \DataMachine\Engine\AI\System\SystemAgentServiceProvider();
	}
}
