<?php
/**
 * Agents API and WordPress host integration service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\AbilityScopePermissionFilter;
use DataMachine\Abilities\AgentAbilities;
use DataMachine\Abilities\Chat\AgentsChatHandler;
use DataMachine\Abilities\Chat\CreateChatSessionAbility;
use DataMachine\Abilities\Chat\DeleteChatSessionAbility;
use DataMachine\Abilities\Chat\GetChatSessionAbility;
use DataMachine\Abilities\Chat\ListChatSessionsAbility;
use DataMachine\Abilities\Chat\MarkSessionReadAbility;
use DataMachine\Core\Auth\AgentAccessFilterBridge;
use DataMachine\Core\Auth\AgentAccessStoreAdapter;
use DataMachine\Core\Content\ContentFormat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Identity\AgentIdentityStoreAdapter;
use DataMachine\Core\OAuth\HttpBasicAuthProvider;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;
use DataMachine\Engine\AI\AgentModeRegistry;
use DataMachine\Engine\AI\IterationBudgetRegistry;
use DataMachine\Engine\AI\WpAiClientCache;

/**
 * Registers always-on integrations with WordPress and the Agents API.
 */
final class HostIntegrationServiceProvider {

	/**
	 * Register integrations in the historical bootstrap order.
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( WpAiClientCache::class, 'install' ), 20 );
		AbilityScopePermissionFilter::register();
		ContentFormat::register();
		new ListChatSessionsAbility();
		new GetChatSessionAbility();
		new DeleteChatSessionAbility();
		new CreateChatSessionAbility();
		new MarkSessionReadAbility();
		new AgentsChatHandler();

		add_filter( 'wp_agent_runtime_import_bundle', array( AgentAbilities::class, 'importRuntimeAgentBundle' ), 5, 4 );
		add_filter( 'wp_agent_runtime_run_bundle', array( AgentAbilities::class, 'runRuntimeAgentBundle' ), 10, 4 );
		add_filter( 'wp_agent_runtime_package_run_handler', array( AgentAbilities::class, 'runtimePackageRunHandler' ), 10, 3 );
		add_filter( 'datamachine_auth_providers', array( self::class, 'register_auth_provider' ) );
		add_filter( 'datamachine_auth_encrypted_fields', array( self::class, 'register_auth_encrypted_fields' ), 10, 2 );

		AgentAccessStoreAdapter::register();
		AgentAccessFilterBridge::register();
		AgentIdentityStoreAdapter::register();

		add_filter( 'wp_agent_conversation_store', array( self::class, 'conversation_store' ) );
		add_filter( 'wp_agent_pending_action_store', array( self::class, 'pending_action_store' ) );
		add_filter( 'wp_agent_pending_action_resolver', array( self::class, 'pending_action_resolver' ) );
		add_filter( 'agents_pending_action_permission', array( self::class, 'pending_action_permission' ) );
		add_filter( 'datamachine/image_generation/templates', array( self::class, 'register_image_templates' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName

		IterationBudgetRegistry::register(
			'conversation_turns',
			array(
				'default' => PluginSettings::DEFAULT_MAX_TURNS,
				'min'     => 1,
				'max'     => 200,
				'setting' => 'max_turns',
			)
		);
		IterationBudgetRegistry::register(
			'chain_depth',
			array(
				'default' => 3,
				'min'     => 1,
				'max'     => 10,
				'setting' => 'max_chain_depth',
			)
		);

		add_action( 'init', array( self::class, 'register_agent_modes' ), 0 );

		if ( did_action( 'plugins_loaded' ) ) {
			datamachine_register_default_memory_files();
		} else {
			add_action( 'plugins_loaded', 'datamachine_register_default_memory_files', 0 );
		}

		add_action( 'init', array( \DataMachine\Engine\AI\ComposableFileInvalidation::class, 'register_hooks' ) );
	}

	/** @param array<string, object> $providers Authentication providers. */
	public static function register_auth_provider( array $providers ): array {
		$providers[ HttpBasicAuthProvider::PROVIDER_SLUG ] ??= new HttpBasicAuthProvider();
		return $providers;
	}

	/** @param array<int, string> $fields Encrypted fields. */
	public static function register_auth_encrypted_fields( array $fields, string $provider_slug ): array {
		if ( HttpBasicAuthProvider::PROVIDER_SLUG === $provider_slug ) {
			$fields[] = 'password';
		}
		return $fields;
	}

	/** Return the Agents API conversation store adapter. */
	public static function conversation_store() {
		return ConversationStoreFactory::get_transcript_store();
	}

	/** Return the Agents API pending-action store adapter. */
	public static function pending_action_store() {
		return PendingActionStore::adapter();
	}

	/** Return the Agents API pending-action resolver adapter. */
	public static function pending_action_resolver() {
		return ResolvePendingActionAbility::adapter();
	}

	/** Check pending-action permission. */
	public static function pending_action_permission(): bool {
		return \DataMachine\Abilities\PermissionHelper::can( 'chat' );
	}

	/** @param array<string, class-string> $templates Image template classes. */
	public static function register_image_templates( array $templates ): array {
		$templates['flow_diagram'] ??= \DataMachine\Abilities\Media\Templates\FlowDiagramTemplate::class;
		return $templates;
	}

	/** Register core agent modes. */
	public static function register_agent_modes(): void {
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
	}
}
