<?php
/**
 * Ability service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\AbilityCategories;
use DataMachine\Abilities\AbilityManifest;

/**
 * Registers lightweight and full-runtime ability surfaces.
 */
final class AbilityServiceProvider {

	/**
	 * Register abilities required on lightweight requests.
	 */
	public static function register_lightweight(): void {
		$plugin_root = dirname( __DIR__, 3 );

		require_once $plugin_root . '/inc/Abilities/AbilityCategories.php';
		AbilityCategories::ensure_registered();

		require_once $plugin_root . '/inc/Abilities/AbilityManifest.php';
		AbilityManifest::register( self::lightweight_ability_manifest() );
	}

	/**
	 * Register the complete ability runtime in load order.
	 */
	public static function register_full_runtime(): void {
		$plugin_root = dirname( __DIR__, 3 );

		// Register ability categories first — must happen before any ability registration.
		require_once $plugin_root . '/inc/Abilities/AbilityCategories.php';
		\DataMachine\Abilities\AbilityCategories::ensure_registered();

		// Load abilities.
		require_once $plugin_root . '/inc/Abilities/AuthAbilities.php';
		require_once $plugin_root . '/inc/Abilities/AI/InspectRequestAbility.php';
		require_once $plugin_root . '/inc/Abilities/File/FileConstants.php';
		require_once $plugin_root . '/inc/Abilities/File/AgentFileAbilities.php';
		require_once $plugin_root . '/inc/Abilities/File/FlowFileAbilities.php';
		require_once $plugin_root . '/inc/Abilities/File/ScaffoldAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Job/JobHelpers.php';
		require_once $plugin_root . '/inc/Abilities/LogAbilities.php';
		require_once $plugin_root . '/inc/Abilities/PostQueryAbilities.php';
		require_once $plugin_root . '/inc/Abilities/PipelineStepAbilities.php';
		require_once $plugin_root . '/inc/Core/Similarity/SimilarityResult.php';
		require_once $plugin_root . '/inc/Core/Similarity/SimilarityEngine.php';
		require_once $plugin_root . '/inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php';
		require_once $plugin_root . '/inc/Abilities/ProcessedItemsAbilities.php';
		require_once $plugin_root . '/inc/Abilities/TrackedItemsAbilities.php';
		require_once $plugin_root . '/inc/Abilities/SettingsAbilities.php';
		require_once $plugin_root . '/inc/Abilities/HandlerAbilities.php';
		require_once $plugin_root . '/inc/Abilities/StepTypeAbilities.php';
		require_once $plugin_root . '/inc/Abilities/LocalSearchAbilities.php';
		require_once $plugin_root . '/inc/Abilities/SourceAggregateAbility.php';
		require_once $plugin_root . '/inc/Core/SourceAggregation/SourceInventoryProfiler.php';
		require_once $plugin_root . '/inc/Abilities/SourceInventoryAbility.php';
		require_once $plugin_root . '/inc/Abilities/SystemAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Media/AltTextAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Media/ImageGenerationAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Media/MediaAbilities.php';
		require_once $plugin_root . '/inc/Abilities/SEO/MetaDescriptionAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Media/ImageTemplateAbilities.php';
		require_once $plugin_root . '/inc/Abilities/AgentCallAbilities.php';
		require_once $plugin_root . '/inc/Abilities/AgentRemoteCallAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Runtime/RuntimeTaskAbility.php';
		require_once $plugin_root . '/inc/Abilities/AgentAbilities.php';
		require_once $plugin_root . '/inc/Abilities/AgentMemoryAbilities.php';
		require_once $plugin_root . '/inc/Abilities/InjectableMemoryFilesAbility.php';
		require_once $plugin_root . '/inc/Abilities/DailyMemoryAbilities.php';
		require_once $plugin_root . '/inc/Abilities/InternalLinkingAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Content/BlockSanitizer.php';
		require_once $plugin_root . '/inc/Abilities/Content/CanonicalDiffPreview.php';
		require_once $plugin_root . '/inc/Abilities/Content/GetPostBlocksAbility.php';
		require_once $plugin_root . '/inc/Abilities/Content/EditPostBlocksAbility.php';
		require_once $plugin_root . '/inc/Abilities/Content/ReplacePostBlocksAbility.php';
		require_once $plugin_root . '/inc/Abilities/Content/UpsertPostAbility.php';
		require_once $plugin_root . '/inc/Abilities/Content/ContentActionHandlers.php';
		// GitHubAbilities moved to data-machine-code extension.
		require_once $plugin_root . '/inc/Abilities/Fetch/FetchFilesAbility.php';
		require_once $plugin_root . '/inc/Abilities/Email/EmailAbilities.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/FetchEmailAbility.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/FetchRssAbility.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/FetchWordPressApiAbility.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/FetchWordPressMediaAbility.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/GetWordPressPostAbility.php';
		require_once $plugin_root . '/inc/Abilities/Fetch/QueryWordPressPostsAbility.php';
		require_once $plugin_root . '/inc/Abilities/Publish/PublishWordPressAbility.php';
		require_once $plugin_root . '/inc/Abilities/Publish/SendEmailAbility.php';
		require_once $plugin_root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php';
		require_once $plugin_root . '/inc/Abilities/Update/UpdateWordPressAbility.php';
		require_once $plugin_root . '/inc/Abilities/Handler/TestHandlerAbility.php';

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

	/**
	 * Declare abilities whose schemas are cheap enough for lite requests.
	 *
	 * @return array<int, array{file:string,class:string,method?:string}>
	 */
	private static function lightweight_ability_manifest(): array {
		$plugin_root = dirname( __DIR__, 3 );

		return array(
			array(
				'file'  => $plugin_root . '/inc/Abilities/AgentAbilities.php',
				'class' => \DataMachine\Abilities\AgentAbilities::class,
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Media/ImageTemplateAbilities.php',
				'class'  => \DataMachine\Abilities\Media\ImageTemplateAbilities::class,
				'method' => 'ensure_registered',
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Publish/SendEmailAbility.php',
				'class'  => \DataMachine\Abilities\Publish\SendEmailAbility::class,
				'method' => 'ensure_registered',
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php',
				'class'  => \DataMachine\Abilities\Publish\SendEmailQueuedAbility::class,
				'method' => 'ensure_registered',
			),
		);
	}
}
