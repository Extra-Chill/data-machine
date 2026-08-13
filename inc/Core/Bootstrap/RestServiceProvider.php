<?php
/**
 * REST service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the complete Data Machine REST surface in deterministic order.
 */
final class RestServiceProvider {

	/**
	 * Register REST controllers.
	 */
	public static function register(): void {
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
	}
}
