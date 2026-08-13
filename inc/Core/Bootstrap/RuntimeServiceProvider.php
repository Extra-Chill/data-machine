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

		self::register_step_types();
		self::register_handlers();
		\DataMachine\Engine\Bundle\AuthRefHandlerConfig::register();
		\DataMachine\Engine\Bundle\BundleSourceAuth::register();
		\DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts::register();
		\DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::init();

		// Tools depend on step types and handlers.
		\DataMachine\Engine\AI\Tools\ToolServiceProvider::register();
		RestServiceProvider::register();

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

	/**
	 * Register core step types in their established order.
	 */
	public static function register_step_types(): void {
		new \DataMachine\Core\Steps\Fetch\FetchStep();
		new \DataMachine\Core\Steps\Publish\PublishStep();
		new \DataMachine\Core\Steps\Upsert\UpsertStep();
		new \DataMachine\Core\Steps\AI\AIStep();
		new \DataMachine\Core\Steps\WebhookGate\WebhookGateStep();
		new \DataMachine\Core\Steps\SystemTask\SystemTaskStep();
	}

	/**
	 * Register core handlers in their established order.
	 */
	public static function register_handlers(): void {
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
	}
}
