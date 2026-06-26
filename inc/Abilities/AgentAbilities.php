<?php
/**
 * Agent Abilities
 *
 * WordPress 6.9 Abilities API primitives for agent identity operations.
 * Provides rename functionality for first-class agent identities.
 *
 * @package DataMachine\Abilities
 * @since 0.38.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Bundle\AgentBundleDirectory;
use DataMachine\Engine\Bundle\AgentBundleAbilityService;
use DataMachine\Engine\Bundle\AgentBundleArtifactRebase;
use DataMachine\Engine\Bundle\AgentBundleRunner;
use DataMachine\Engine\Bundle\BundleSource;
use DataMachine\Engine\Bundle\BundleSourceAuth;
use DataMachine\Engine\Bundle\BundleValidationException;

defined( 'ABSPATH' ) || exit;

class AgentAbilities {

	private static bool $registered     = false;
	private const ACTIVE_AGENT_META_KEY = 'datamachine_active_agent_slug';

	/**
	 * Register an ability, supporting late registration in headless runtimes.
	 *
	 * The public `wp_register_ability()` helper only works while
	 * `wp_abilities_api_init` is firing. That is the right path for normal
	 * requests, where this class hooks `registerAbilities()` onto the action
	 * before it fires.
	 *
	 * Some headless runtimes load Data Machine after WordPress has already
	 * booted through `wp-load.php` — which fires `init` and lazily initializes
	 * the abilities registry, completing the one-shot `wp_abilities_api_init`
	 * action — and only THEN include the plugin file. By the time this class is
	 * instantiated, the public registration window has already closed, so
	 * `wp_register_ability()` would `_doing_it_wrong()` and return null,
	 * leaving `datamachine/run-agent-bundle` unregistered for the runs that
	 * need it.
	 *
	 * WordPress core only enforces the lifecycle in the `wp_register_ability()`
	 * wrapper; `WP_Abilities_Registry::register()` itself has no such guard and
	 * is safe to call once the registry exists (after `init`). So when the
	 * action has already completed, register through the registry instance
	 * directly. The `is_registered()` guard keeps this idempotent.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability arguments.
	 * @return \WP_Ability|null Registered ability, or null when registration is not currently possible.
	 */
	private static function registerAbility( string $name, array $args ): ?\WP_Ability {
		$args = AbilityRegistration::with_lazy_runtime( array( $name => $args ) )[ $name ];

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			return \wp_register_ability( $name, $args );
		}

		// Late path: the init action has already fired (headless runtime
		// load order). Register through the registry instance directly, which
		// WordPress core permits any time after `init`.
		if ( ! did_action( 'wp_abilities_api_init' ) || ! class_exists( '\WP_Abilities_Registry' ) ) {
			return null;
		}

		$registry = \WP_Abilities_Registry::get_instance();
		if ( null === $registry || $registry->is_registered( $name ) ) {
			return null;
		}

		return $registry->register( $name, $args );
	}

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			// In-lifecycle: register now.
			$this->registerAbilities();
			self::$registered = true;
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			// Pre-lifecycle: hook the init action.
			add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
			self::$registered = true;
		} else {
			// Post-lifecycle (headless runtime load order): the
			// init action has already fired before this plugin file was
			// included, so neither the in-lifecycle nor the hook path will run.
			// Register immediately via the late path in registerAbility().
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	public function registerAbilities(): void {
		if ( class_exists( AbilityCategories::class ) ) {
			AbilityCategories::ensure_registered();
		}

		$register_callback = function () {
			self::registerAbility(
				'datamachine/export-agent',
				array(
					'label'               => 'Export Agent',
					'description'         => 'Export an agent as a portable bundle directory or ZIP archive.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent'       => array(
								'type'        => 'string',
								'description' => 'Agent slug or ID to export. Slugs are preferred.',
							),
							'agent_slug'  => array(
								'type'        => 'string',
								'description' => 'Agent slug to export. Prefer agent for new callers.',
							),
							'agent_id'    => array(
								'type'        => 'integer',
								'description' => 'Agent ID to export. Supported for compatibility; prefer agent slug.',
							),
							'profile'     => array(
								'type'        => 'string',
								'enum'        => array( 'share', 'backup', 'fork' ),
								'description' => 'Export profile. Defaults to share.',
							),
							'destination' => array(
								'type'        => 'string',
								'description' => 'Destination directory or ZIP file path. Defaults to <agent-slug>-bundle.',
							),
							'format'      => array(
								'type'        => 'string',
								'enum'        => array( 'directory', 'zip' ),
								'description' => 'Output format. Defaults to directory.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'path'       => array( 'type' => 'string' ),
							'format'     => array( 'type' => 'string' ),
							'profile'    => array( 'type' => 'string' ),
							'manifest'   => array( 'type' => 'object' ),
							'agent_id'   => array( 'type' => 'integer' ),
							'agent_slug' => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'exportAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/rename-agent',
				array(
					'label'               => 'Rename Agent',
					'description'         => 'Rename an agent slug — updates database and moves filesystem directory',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'old_slug', 'new_slug' ),
						'properties' => array(
							'old_slug' => array(
								'type'        => 'string',
								'description' => 'Current agent slug.',
							),
							'new_slug' => array(
								'type'        => 'string',
								'description' => 'New agent slug (sanitized automatically).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'message'  => array( 'type' => 'string' ),
							'old_slug' => array( 'type' => 'string' ),
							'new_slug' => array( 'type' => 'string' ),
							'old_path' => array( 'type' => 'string' ),
							'new_path' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'renameAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/list-agents',
				array(
					'label'               => 'List Agents',
					'description'         => 'List agents accessible to the caller. Defaults to the caller\'s own accessible agents; admins can escalate to all agents or query other users.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'        => array(
								'type'        => 'string',
								'enum'        => array( 'mine', 'all' ),
								'description' => '"mine" (default) returns agents the caller can access (owned + granted). "all" returns every agent on the site (admin-only).',
							),
							'user_id'      => array(
								'type'        => 'integer',
								'description' => 'Resolve accessible agents for this user instead of the caller. Non-admins are forced to themselves. Ignored when scope=all.',
							),
							'site_id'      => array(
								'type'        => 'integer',
								'description' => 'Filter by site_scope. Matches the exact site OR network-wide (NULL) agents. Defaults to current blog.',
							),
							'include_role' => array(
								'type'        => 'boolean',
								'description' => 'When true, enriches each row with the resolved user\'s role on that agent.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'active_agent_slug' => array( 'type' => array( 'string', 'null' ) ),
							'agents'            => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'agent_id'    => array( 'type' => 'integer' ),
										'agent_slug'  => array( 'type' => 'string' ),
										'agent_name'  => array( 'type' => 'string' ),
										'owner_id'    => array( 'type' => 'integer' ),
										'site_scope'  => array( 'type' => array( 'integer', 'null' ) ),
										'description' => array( 'type' => 'string' ),
										'is_owner'    => array( 'type' => 'boolean' ),
										'is_active'   => array( 'type' => 'boolean' ),
										'user_role'   => array( 'type' => array( 'string', 'null' ) ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'listAgents' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ) || PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/get-active-agent',
				array(
					'label'               => 'Get Active Agent',
					'description'         => 'Return the current user\'s persisted active Data Machine agent preference, falling back to an unambiguous accessible agent.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'Resolve active agent for this user. Non-admins are forced to themselves.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'agent'        => array( 'type' => array( 'object', 'null' ) ),
							'agent_slug'   => array( 'type' => array( 'string', 'null' ) ),
							'source'       => array( 'type' => 'string' ),
							'needs_choice' => array( 'type' => 'boolean' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getActiveAgent' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ) || PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/set-active-agent',
				array(
					'label'               => 'Set Active Agent',
					'description'         => 'Persist the active Data Machine agent preference for a user after validating access.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'agent' ),
						'properties' => array(
							'agent'   => array(
								'type'        => 'string',
								'description' => 'Agent slug or ID to make active.',
							),
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'Set active agent for this user. Non-admins are forced to themselves.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'agent'      => array( 'type' => 'object' ),
							'agent_slug' => array( 'type' => 'string' ),
							'user_id'    => array( 'type' => 'integer' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'setActiveAgent' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ) || PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/create-agent',
				array(
					'label'               => 'Create Agent',
					'description'         => 'Create a new agent identity with filesystem directory and owner access. Admins can create agents for any user. Non-admins with create_own_agent can create one agent for themselves.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'agent_slug' ),
						'properties' => array(
							'agent_slug' => array(
								'type'        => 'string',
								'description' => 'Unique agent slug (sanitized automatically).',
							),
							'agent_name' => array(
								'type'        => 'string',
								'description' => 'Display name (defaults to slug if omitted).',
							),
							'owner_id'   => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID of the agent owner. Non-admins can only create agents for themselves (this field is ignored).',
							),
							'config'     => array(
								'type'        => 'object',
								'description' => 'Agent configuration object.',
							),
							'site_scope' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Site scope for the agent. Omit or null for network-wide (resolves on every site); a blog ID scopes the agent to that single site. Default network-wide.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'agent_id'   => array( 'type' => 'integer' ),
							'agent_slug' => array( 'type' => 'string' ),
							'agent_name' => array( 'type' => 'string' ),
							'owner_id'   => array( 'type' => 'integer' ),
							'agent_dir'  => array( 'type' => 'string' ),
							'site_scope' => array( 'type' => array( 'integer', 'null' ) ),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'createAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage() || PermissionHelper::can( 'create_own_agent' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/import-agent',
				array(
					'label'               => 'Import Agent',
					'description'         => 'Materialize a portable agent bundle from a local bundle directory, JSON file, or ZIP archive.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'source'      => array(
								'type'        => 'string',
								'description' => 'Bundle source: local path (directory, .zip, .json) or remote URL (HTTPS to a .zip/.json or a GitHub blob/tree/archive URL).',
							),
							'bundle'      => array(
								'type'        => array( 'object', 'array' ),
								'description' => 'Already-parsed portable Data Machine agent bundle array.',
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => 'Optional target agent slug override.',
							),
							'on_conflict' => array(
								'type'        => 'string',
								'enum'        => array( 'error', 'skip', 'upgrade' ),
								'description' => 'How to handle an existing target agent slug.',
							),
							'owner_id'    => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID that should own the imported agent.',
							),
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'Validate and summarize without writing.',
							),
							'token'       => array(
								'type'        => 'string',
								'description' => 'Auth token for private archive downloads (e.g. GitHub PAT, GHE PAT). Used for this single resolve(); never persisted, never logged. Prefer token_env for repeated use.',
							),
							'token_env'   => array(
								'type'        => 'string',
								'description' => 'Environment variable (or PHP constant) name to read the auth token from. Used for this single resolve(); never persisted, never logged.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'skipped'       => array( 'type' => 'boolean' ),
							'agent_id'      => array( 'type' => 'integer' ),
							'agent_slug'    => array( 'type' => 'string' ),
							'imported'      => array( 'type' => 'object' ),
							'auth_warnings' => array( 'type' => 'array' ),
							'summary'       => array( 'type' => 'object' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'importAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage()
						|| ( PermissionHelper::in_agent_context() && PermissionHelper::can_use_ability( 'datamachine/import-agent', 'datamachine-agent' ) ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/list-agent-bundles',
				array(
					'label'               => 'List Agent Bundles',
					'description'         => 'List installed bundle-backed agents with template/source metadata.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'listAgentBundles' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/inspect-agent-bundle',
				array(
					'label'               => 'Inspect Agent Bundle',
					'description'         => 'Load an agent bundle without writing and return projected package metadata plus host compatibility.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema(),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'inspectAgentBundle' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/validate-agent-bundle',
				array(
					'label'               => 'Validate Agent Bundle',
					'description'         => 'Validate an agent bundle without writing and report unsupported host capabilities or artifact requirements.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema(),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'validateAgentBundle' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/get-agent-bundle-status',
				array(
					'label'               => 'Get Agent Bundle Status',
					'description'         => 'Return installed bundle source/version metadata and tracked artifact state for an agent or bundle slug.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'slug' ),
						'properties' => array(
							'slug' => array(
								'type'        => 'string',
								'description' => 'Agent slug or installed bundle slug.',
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'getAgentBundleStatus' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/plan-agent-bundle-upgrade',
				array(
					'label'               => 'Plan Agent Bundle Upgrade',
					'description'         => 'Build the canonical bundle upgrade plan, including clean updates, local overrides, warnings, and runtime drift.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema(),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'planAgentBundleUpgrade' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/rebase-agent-bundle-artifacts',
				array(
					'label'               => 'Rebase Agent Bundle Artifacts',
					'description'         => 'Preview 3-way rebases for locally modified bundle artifacts using a named policy.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema( true ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'rebaseAgentBundleArtifacts' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/apply-agent-bundle-upgrade',
				array(
					'label'               => 'Apply Agent Bundle Upgrade',
					'description'         => 'Apply clean bundle artifact updates and stage local overrides as PendingActions.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema( true ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'applyAgentBundleUpgrade' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/adopt-agent-bundle',
				array(
					'label'               => 'Adopt Agent Bundle',
					'description'         => 'Bind an already-live agent to a bundle without re-importing: backfill portable_slug, write the bundle header, and seed the artifact ledger from current live state so later upgrades diff cleanly instead of duplicating.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::bundleLifecycleInputSchema(),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'adoptAgentBundle' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/resolve-agent-bundle-upgrade-action',
				array(
					'label'               => 'Resolve Agent Bundle Upgrade Action',
					'description'         => 'Accept a staged bundle upgrade PendingAction through the canonical bundle ability surface.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pending_action_id' ),
						'properties' => array(
							'pending_action_id' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( self::class, 'resolveAgentBundleUpgradeAction' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/run-agent-bundle',
				array(
					'label'               => 'Run Agent Bundle',
					'description'         => 'Run a flow from a portable agent bundle as a headless ephemeral workflow without requiring callers to synthesize Data Machine internals.',
					'category'            => 'datamachine-agent',
					'input_schema'        => self::runAgentBundleInputSchema(),
					'output_schema'       => self::runAgentBundleOutputSchema(),
					'execute_callback'    => array( self::class, 'runAgentBundle' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/get-agent',
				array(
					'label'               => 'Get Agent',
					'description'         => 'Retrieve a single agent by slug or ID with access grants and directory info',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_slug' => array(
								'type'        => 'string',
								'description' => 'Agent slug (provide this or agent_id).',
							),
							'agent_id'   => array(
								'type'        => 'integer',
								'description' => 'Agent ID (provide this or agent_slug).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agent'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			self::registerAbility(
				'datamachine/update-agent',
				array(
					'label'               => 'Update Agent',
					'description'         => 'Update an agent\'s mutable fields (name, config)',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent'        => array(
								'type'        => 'string',
								'description' => 'Agent slug or ID to update. Slugs are preferred.',
							),
							'agent_slug'   => array(
								'type'        => 'string',
								'description' => 'Agent slug to update. Prefer agent for new callers.',
							),
							'agent_id'     => array(
								'type'        => 'integer',
								'description' => 'Agent ID to update. Supported for compatibility; prefer agent slug.',
							),
							'agent_name'   => array(
								'type'        => 'string',
								'description' => 'New display name.',
							),
							'agent_config' => array(
								'type'        => 'object',
								'description' => 'New agent configuration (replaces existing config).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'agent'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'updateAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/delete-agent',
				array(
					'label'               => 'Delete Agent',
					'description'         => 'Delete an agent record and access grants, optionally removing filesystem directory',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent'        => array(
								'type'        => 'string',
								'description' => 'Agent slug or ID to delete. Slugs are preferred.',
							),
							'agent_slug'   => array(
								'type'        => 'string',
								'description' => 'Agent slug to delete. Prefer agent for new callers.',
							),
							'agent_id'     => array(
								'type'        => 'integer',
								'description' => 'Agent ID to delete. Supported for compatibility; prefer agent slug.',
							),
							'delete_files' => array(
								'type'        => 'boolean',
								'description' => 'Also delete filesystem directory and contents.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'agent_id'      => array( 'type' => 'integer' ),
							'agent_slug'    => array( 'type' => 'string' ),
							'files_deleted' => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'deleteAgent' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			self::registerAbility(
				'datamachine/grant-agent-audience-access',
				array(
					'label'               => 'Grant Agent Audience Access',
					'description'         => 'Grant a selected agent to an explicit non-user audience principal such as audience:public.',
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'agent', 'principal_id' ),
						'properties' => array(
							'agent'          => array(
								'type'        => 'string',
								'description' => 'Agent slug or ID to grant.',
							),
							'principal_type' => array(
								'type'        => 'string',
								'description' => 'Principal type. Defaults to audience.',
							),
							'principal_id'   => array(
								'type'        => 'string',
								'description' => 'Principal identifier, such as public or automattician.',
							),
							'role'           => array(
								'type'        => 'string',
								'enum'        => array( 'admin', 'operator', 'viewer' ),
								'description' => 'Access role. Defaults to operator.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'grant'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'grantAgentAudienceAccess' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		} else {
			$register_callback();
		}
	}

	/**
	 * Rename an agent — update DB slug and move filesystem directory.
	 *
	 * @param array $input Input parameters with old_slug and new_slug.
	 * @return array Result.
	 */
	public static function renameAgent( array $input ): array {
		$old_slug = sanitize_title( $input['old_slug'] );
		$new_slug = sanitize_title( $input['new_slug'] );

		if ( $old_slug === $new_slug ) {
			return array(
				'success' => false,
				'message' => 'Old and new slugs are identical.',
			);
		}

		if ( empty( $new_slug ) ) {
			return array(
				'success' => false,
				'message' => 'New slug cannot be empty.',
			);
		}

		$agents_repo = new Agents();

		// Validate source exists.
		$existing = $agents_repo->get_by_slug( $old_slug );

		if ( ! $existing ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Agent with slug "%s" not found.', $old_slug ),
			);
		}

		// Validate target is free.
		$conflict = $agents_repo->get_by_slug( $new_slug );

		if ( $conflict ) {
			return array(
				'success' => false,
				'message' => sprintf( 'An agent with slug "%s" already exists.', $new_slug ),
			);
		}

		$agent_id          = (int) $existing['agent_id'];
		$directory_manager = new DirectoryManager();
		$old_path          = $directory_manager->get_agent_identity_directory( $old_slug );
		$new_path          = $directory_manager->get_agent_identity_directory( $new_slug );

		// Move directory first — easier to roll back than a DB change.
		$dir_moved = false;

		if ( is_dir( $old_path ) ) {
			if ( is_dir( $new_path ) ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Target directory "%s" already exists.', $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$dir_moved = rename( $old_path, $new_path );

			if ( ! $dir_moved ) {
				return array(
					'success'  => false,
					'message'  => sprintf( 'Failed to move directory from "%s" to "%s".', $old_path, $new_path ),
					'old_slug' => $old_slug,
					'new_slug' => $new_slug,
					'old_path' => $old_path,
					'new_path' => $new_path,
				);
			}
		}

		// Update database.
		$db_ok = $agents_repo->update_slug( $agent_id, $new_slug );

		if ( ! $db_ok ) {
			// Roll back directory move.
			if ( $dir_moved ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $new_path, $old_path );
			}

			return array(
				'success'  => false,
				'message'  => 'Database update failed. Directory change reverted.',
				'old_slug' => $old_slug,
				'new_slug' => $new_slug,
				'old_path' => $old_path,
				'new_path' => $new_path,
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf(
				'Agent renamed from "%s" to "%s".%s',
				$old_slug,
				$new_slug,
				$dir_moved ? ' Directory moved.' : ' No directory to move.'
			),
			'old_slug' => $old_slug,
			'new_slug' => $new_slug,
			'old_path' => $old_path,
			'new_path' => $new_path,
		);
	}

	/**
	 * List registered agents, scoped to the current site.
	 *
	 * On multisite, returns agents with site_scope matching the current blog_id
	 * OR site_scope IS NULL (network-wide). This mirrors WordPress core's default
	 * of scoping user queries to the current site via wp_N_capabilities meta.
	 *
	 * @since 0.38.0
	 * @since 0.57.0 Added site_scope filtering and site_scope in output.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function listAgents( array $input ): array {
		// ---- Parameter resolution ----------------------------------------
		$scope             = isset( $input['scope'] ) ? (string) $input['scope'] : 'mine';
		$requested_user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$site_id           = isset( $input['site_id'] ) ? (int) $input['site_id'] : get_current_blog_id();
		$include_role      = ! empty( $input['include_role'] );

		if ( ! in_array( $scope, array( 'mine', 'all' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid scope. Allowed values: "mine", "all".',
			);
		}

		$is_admin  = PermissionHelper::can_manage();
		$caller_id = PermissionHelper::acting_user_id();

		// ---- Escalation checks -------------------------------------------
		if ( 'all' === $scope && ! $is_admin ) {
			return array(
				'success' => false,
				'error'   => 'scope=all requires admin privileges.',
			);
		}

		// Non-admins are always forced to self. Admin omitting user_id also
		// defaults to self (the intuitive "show me MY accessible agents").
		if ( $requested_user_id > 0 && $requested_user_id !== $caller_id && ! $is_admin ) {
			return array(
				'success' => false,
				'error'   => 'Querying another user\'s agents requires admin privileges.',
			);
		}

		$target_user_id = $requested_user_id > 0 ? $requested_user_id : $caller_id;

		$agents_repo = new Agents();
		$access_repo = new AgentAccess();

		// ---- Resolve candidate rows --------------------------------------
		if ( 'all' === $scope ) {
			// Admin firehose: all agents on the requested site.
			$candidates = $agents_repo->get_all( array( 'site_id' => $site_id ) );
		} else {
			if ( $target_user_id <= 0 ) {
				return array(
					'success'           => true,
					'active_agent_slug' => null,
					'agents'            => array(),
				);
			}

			// Union of agents the target user OWNS plus agents they have
			// ACCESS GRANTS to. The owner relationship lives on
			// datamachine_agents.owner_id, not on agent_access, so merging
			// both sides is the only way to get the complete picture.
			$owned        = $agents_repo->get_all_by_owner_id( $target_user_id );
			$owned_ids    = array_map( static fn( $a ) => (int) $a['agent_id'], $owned );
			$granted_ids  = array_map( 'intval', $access_repo->get_agent_ids_for_user( $target_user_id ) );
			$extra_ids    = array_values( array_diff( $granted_ids, $owned_ids ) );
			$granted_rows = ! empty( $extra_ids ) ? $agents_repo->get_agents_by_ids( $extra_ids ) : array();

			$candidates = array_merge( $owned, $granted_rows );

			// Site scoping: match the requested site OR network-wide (NULL).
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $row ) use ( $site_id ) {
						$scope_value = $row['site_scope'] ?? null;
						return null === $scope_value || (int) $scope_value === $site_id;
					}
				)
			);
		}

		// ---- Final access gate (mine only) -------------------------------
		//
		// When listing the caller's OWN agents, defence-in-depth: run each
		// row through can_access_agent() so the filter `datamachine_can_access_agent`
		// still has the final say. When the admin queries another user via
		// `user_id`, this gate is skipped — the admin permission check above
		// is authoritative and we must not accidentally filter out agents
		// the target user can actually access.
		if ( 'mine' === $scope && $target_user_id === $caller_id ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static fn( $row ) => PermissionHelper::can_access_agent( (int) $row['agent_id'] )
				)
			);
		}

		$active            = self::resolve_active_agent_for_user( $target_user_id, $candidates, 'all' !== $scope );
		$active_agent_slug = $active['agent'] ? (string) $active['agent']['agent_slug'] : null;

		// ---- Role enrichment (optional) ----------------------------------
		// Computed against $target_user_id so `include_role=true` reflects
		// the resolved user's role even when an admin queries on their behalf.
		$agents = array();

		foreach ( $candidates as $row ) {
			$agent_id    = (int) $row['agent_id'];
			$owner_id    = (int) $row['owner_id'];
			$config      = is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array();
			$description = isset( $config['description'] ) ? (string) $config['description'] : '';

			$item = array(
				'agent_id'    => $agent_id,
				'agent_slug'  => (string) $row['agent_slug'],
				'agent_name'  => (string) $row['agent_name'],
				'owner_id'    => $owner_id,
				'site_scope'  => isset( $row['site_scope'] ) ? (int) $row['site_scope'] : null,
				'description' => $description,
				'is_owner'    => $target_user_id > 0 && $owner_id === $target_user_id,
				'is_active'   => null !== $active_agent_slug && (string) $row['agent_slug'] === $active_agent_slug,
			);

			if ( $include_role ) {
				if ( $target_user_id > 0 && $owner_id === $target_user_id ) {
					$item['user_role'] = 'admin';
				} elseif ( $target_user_id > 0 ) {
					$grant             = $access_repo->get_access( (string) $agent_id, $target_user_id );
					$item['user_role'] = $grant instanceof \WP_Agent_Access_Grant ? $grant->role : null;
				} else {
					$item['user_role'] = null;
				}
			}

			$agents[] = $item;
		}

		return array(
			'success'           => true,
			'active_agent_slug' => $active_agent_slug,
			'agents'            => $agents,
		);
	}

	/**
	 * Return a user's active agent preference with safe fallback metadata.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function getActiveAgent( array $input ): array {
		$target_user_id = self::resolve_active_agent_user_id( isset( $input['user_id'] ) ? (int) $input['user_id'] : 0 );
		if ( is_wp_error( $target_user_id ) ) {
			return array(
				'success' => false,
				'error'   => $target_user_id->get_error_message(),
			);
		}

		$active = self::resolve_active_agent_for_user( $target_user_id );

		return array(
			'success'      => true,
			'agent'        => $active['agent'] ? self::format_active_agent_row( $active['agent'], $target_user_id ) : null,
			'agent_slug'   => $active['agent'] ? (string) $active['agent']['agent_slug'] : null,
			'source'       => $active['source'],
			'needs_choice' => (bool) $active['needs_choice'],
		);
	}

	/**
	 * Persist a user's active agent preference after validating access.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function setActiveAgent( array $input ): array {
		$target_user_id = self::resolve_active_agent_user_id( isset( $input['user_id'] ) ? (int) $input['user_id'] : 0 );
		if ( is_wp_error( $target_user_id ) ) {
			return array(
				'success' => false,
				'error'   => $target_user_id->get_error_message(),
			);
		}

		$agent_id = self::resolve_agent_input_id( array( 'agent' => (string) ( $input['agent'] ?? '' ) ) );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );
		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		if ( ! self::user_can_access_agent_row( $target_user_id, $agent ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'User %d cannot access agent "%s".', $target_user_id, (string) $agent['agent_slug'] ),
			);
		}

		update_user_meta( $target_user_id, self::ACTIVE_AGENT_META_KEY, (string) $agent['agent_slug'] );

		return array(
			'success'    => true,
			'agent'      => self::format_active_agent_row( $agent, $target_user_id ),
			'agent_slug' => (string) $agent['agent_slug'],
			'user_id'    => $target_user_id,
		);
	}

	/**
	 * Resolve which user an active-agent ability may act on.
	 *
	 * @param int $requested_user_id Requested user ID, or zero for caller.
	 * @return int|\WP_Error
	 */
	private static function resolve_active_agent_user_id( int $requested_user_id ): int|\WP_Error {
		$caller_id = PermissionHelper::acting_user_id();
		$is_admin  = PermissionHelper::can_manage();

		if ( $requested_user_id > 0 && $requested_user_id !== $caller_id && ! $is_admin ) {
			return new \WP_Error( 'forbidden_user', 'Changing another user\'s active agent requires admin privileges.' );
		}

		$target_user_id = $requested_user_id > 0 ? $requested_user_id : $caller_id;
		if ( $target_user_id <= 0 ) {
			return new \WP_Error( 'missing_user', 'Could not determine acting user.' );
		}

		return $target_user_id;
	}

	/**
	 * Resolve active agent row from persisted preference or safe fallback.
	 *
	 * @param int        $user_id               User ID.
	 * @param array|null $candidates            Optional accessible agent rows.
	 * @param bool       $allow_single_fallback Whether a single candidate should become active by default.
	 * @return array{agent: array|null, source: string, needs_choice: bool}
	 */
	private static function resolve_active_agent_for_user( int $user_id, ?array $candidates = null, bool $allow_single_fallback = true ): array {
		$candidates = null === $candidates ? self::get_accessible_agent_rows_for_user( $user_id ) : array_values( $candidates );
		$stored     = self::get_active_agent_slug_for_user( $user_id );

		if ( '' !== $stored ) {
			foreach ( $candidates as $row ) {
				if ( (string) ( $row['agent_slug'] ?? '' ) === $stored && self::user_can_access_agent_row( $user_id, $row ) ) {
					return array(
						'agent'        => $row,
						'source'       => 'preference',
						'needs_choice' => false,
					);
				}
			}
		}

		if ( $allow_single_fallback && 1 === count( $candidates ) ) {
			return array(
				'agent'        => $candidates[0],
				'source'       => 'single_accessible_agent',
				'needs_choice' => false,
			);
		}

		return array(
			'agent'        => null,
			'source'       => '' !== $stored ? 'invalid_preference' : 'none',
			'needs_choice' => count( $candidates ) > 1,
		);
	}

	/**
	 * Read persisted active agent slug for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function get_active_agent_slug_for_user( int $user_id ): string {
		$stored = get_user_meta( $user_id, self::ACTIVE_AGENT_META_KEY, true );
		return is_string( $stored ) ? sanitize_title( $stored ) : '';
	}

	/**
	 * Build accessible agent rows for a user from ownership plus grants.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_accessible_agent_rows_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$agents_repo  = new Agents();
		$access_repo  = new AgentAccess();
		$owned        = $agents_repo->get_all_by_owner_id( $user_id );
		$owned_ids    = array_map( static fn( $agent ) => (int) $agent['agent_id'], $owned );
		$granted_ids  = array_map( 'intval', $access_repo->get_agent_ids_for_user( $user_id ) );
		$extra_ids    = array_values( array_diff( $granted_ids, $owned_ids ) );
		$granted_rows = ! empty( $extra_ids ) ? $agents_repo->get_agents_by_ids( $extra_ids ) : array();

		return array_values( array_filter( array_merge( $owned, $granted_rows ), static fn( $row ) => is_array( $row ) ) );
	}

	/**
	 * Check whether a user can access an agent row without changing acting context.
	 *
	 * @param int   $user_id User ID.
	 * @param array $agent   Agent row.
	 * @return bool
	 */
	private static function user_can_access_agent_row( int $user_id, array $agent ): bool {
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		if ( $user_id <= 0 || $agent_id <= 0 ) {
			return false;
		}

		if ( (int) ( $agent['owner_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		$grant      = ( new AgentAccess() )->get_access( (string) $agent_id, $user_id );
		$can_access = $grant instanceof \WP_Agent_Access_Grant && $grant->role_meets( 'viewer' );

		return (bool) apply_filters( 'datamachine_can_access_agent', $can_access, $agent_id, $user_id, 'viewer' );
	}

	/**
	 * Format active agent payload for ability responses.
	 *
	 * @param array $agent   Agent row.
	 * @param int   $user_id User ID.
	 * @return array<string,mixed>
	 */
	private static function format_active_agent_row( array $agent, int $user_id ): array {
		$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();

		return array(
			'agent_id'    => (int) $agent['agent_id'],
			'agent_slug'  => (string) $agent['agent_slug'],
			'agent_name'  => (string) $agent['agent_name'],
			'owner_id'    => (int) $agent['owner_id'],
			'site_scope'  => isset( $agent['site_scope'] ) ? (int) $agent['site_scope'] : null,
			'description' => isset( $config['description'] ) ? (string) $config['description'] : '',
			'is_owner'    => (int) $agent['owner_id'] === $user_id,
			'is_active'   => true,
		);
	}

	/**
	 * Import an agent bundle through the generic ability surface.
	 *
	 * @param array $input Import parameters.
	 * @return array<string,mixed>
	 */
	public static function importAgent( array $input ): array {
		$has_inline_bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] );
		$source            = trim( (string) ( $input['source'] ?? '' ) );
		if ( ! $has_inline_bundle && '' === $source ) {
			return array(
				'success' => false,
				'error'   => 'Bundle source or inline bundle is required.',
			);
		}

		$resolved = null;
		$revision = null;
		$bundle   = $has_inline_bundle ? $input['bundle'] : null;
		if ( ! $has_inline_bundle ) {
			$context  = self::build_resolve_context( $input );
			$resolved = BundleSource::resolve( $source, $context );
			if ( is_wp_error( $resolved ) ) {
				return array(
					'success' => false,
					'error'   => $resolved->get_error_message(),
				);
			}

			// Snapshot the revision before any nested resolve() call could
			// reset it.
			$revision = BundleSource::is_remote( $source ) ? BundleSource::last_resolved_revision() : null;
		}

		$on_conflict = (string) ( $input['on_conflict'] ?? 'error' );
		if ( ! in_array( $on_conflict, array( 'error', 'skip', 'upgrade' ), true ) ) {
			if ( null !== $resolved ) {
				BundleSource::cleanup( $resolved, $source );
			}
			return array(
				'success' => false,
				'error'   => 'on_conflict must be one of: error, skip, upgrade.',
			);
		}

		$owner_id = self::resolve_import_owner_id( isset( $input['owner_id'] ) ? (int) $input['owner_id'] : 0 );
		if ( $owner_id <= 0 ) {
			if ( null !== $resolved ) {
				BundleSource::cleanup( $resolved, $source );
			}
			return array(
				'success' => false,
				'error'   => 'Unable to resolve import owner. Pass owner_id, authenticate as a user, or set datamachine_default_owner_id.',
			);
		}

		$bundler = new AgentBundler();
		if ( null !== $resolved ) {
			$bundle = self::load_import_bundle( $bundler, $resolved );

			// from_zip() extracts to its own tempdir and from_json() reads
			// the file into memory, so the resolver's temp file is safe to
			// remove now (success or failure of parse).
			BundleSource::cleanup( $resolved, $source );
		}

		if ( ! is_array( $bundle ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse bundle. Use a bundle directory, .json file, or .zip archive.',
			);
		}

		// Stamp the original remote source so installed bundle metadata
		// records where this came from. source_revision is best-effort
		// from the response ETag for GitHub archives (#1830).
		if ( ! $has_inline_bundle && BundleSource::is_remote( $source ) && empty( $bundle['source_ref'] ) ) {
			$bundle['source_ref'] = $source;
		}
		if ( null !== $revision && empty( $bundle['source_revision'] ) ) {
			$bundle['source_revision'] = $revision;
		}

		$slug = sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		if ( isset( $input['slug'] ) && '' !== trim( (string) $input['slug'] ) ) {
			$slug = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['slug'] ) && '' !== $slug ) {
			$bundle['agent']['agent_slug'] = $slug;
		}
		$existing = $slug ? ( new Agents() )->get_by_slug( $slug ) : null;

		$auth_resolution = self::resolve_import_auth_refs( $bundle );
		$bundle          = $auth_resolution['bundle'];
		$auth_warnings   = $auth_resolution['warnings'];

		if ( $existing && 'skip' === $on_conflict ) {
			return array(
				'success'       => true,
				'skipped'       => true,
				'agent_id'      => (int) $existing['agent_id'],
				'agent_slug'    => $slug,
				'auth_warnings' => $auth_warnings,
				'message'       => sprintf( 'Agent "%s" already exists; import skipped.', $slug ),
			);
		}

		if ( $existing && 'upgrade' !== $on_conflict ) {
			return array(
				'success'    => false,
				'agent_id'   => (int) $existing['agent_id'],
				'agent_slug' => $slug,
				'error'      => sprintf( 'Agent slug "%s" already exists. Use on_conflict=skip to no-op, on_conflict=upgrade to reconcile bundle artifacts, or import with a new slug.', $slug ),
			);
		}

		$result = $bundler->import(
			$bundle,
			null,
			$owner_id,
			! empty( $input['dry_run'] ),
			array(
				'is_upgrade'        => 'upgrade' === $on_conflict,
				'reconcile_runtime' => 'upgrade' === $on_conflict,
			)
		);
		if ( empty( $result['success'] ) ) {
			$result['auth_warnings'] = $auth_warnings;
			return $result;
		}

		$summary  = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$imported = array(
			'pipelines' => (int) ( $summary['pipelines_imported'] ?? 0 ),
			'flows'     => (int) ( $summary['flows_imported'] ?? 0 ),
			'files'     => (int) ( $summary['files'] ?? 0 ),
		);

		$result['agent_id']      = (int) ( $summary['agent_id'] ?? 0 );
		$result['agent_slug']    = (string) ( $summary['agent_slug'] ?? $slug );
		$result['imported']      = $imported;
		$result['auth_warnings'] = $auth_warnings;

		return $result;
	}

	/**
	 * List installed bundle-backed agents through the canonical bundle ability contract.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function listAgentBundles( array $input = array() ): array {
		unset( $input );
		return self::bundleLifecycleService()->list_installed();
	}

	/**
	 * Return installed bundle status for an agent or bundle slug.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function getAgentBundleStatus( array $input ): array {
		return self::bundleLifecycleService()->status( (string) ( $input['slug'] ?? '' ) );
	}

	/**
	 * Inspect a bundle without writing runtime state.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function inspectAgentBundle( array $input ): array {
		return self::bundleLifecycleService()->inspect( $input );
	}

	/**
	 * Validate a bundle without writing runtime state.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function validateAgentBundle( array $input ): array {
		return self::bundleLifecycleService()->validate( $input );
	}

	/**
	 * Build a canonical bundle upgrade plan.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function planAgentBundleUpgrade( array $input ): array {
		return self::bundleLifecycleService()->plan( $input );
	}

	/**
	 * Preview 3-way rebases for locally modified bundle artifacts.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function rebaseAgentBundleArtifacts( array $input ): array {
		return self::bundleLifecycleService()->rebase( $input );
	}

	/**
	 * Apply clean bundle upgrades and stage approval-required changes.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function applyAgentBundleUpgrade( array $input ): array {
		return self::bundleLifecycleService()->upgrade( $input );
	}

	/**
	 * Bind an already-live agent to a bundle (one-time, idempotent adopt).
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function adoptAgentBundle( array $input ): array {
		return self::bundleLifecycleService()->adopt( $input );
	}

	/**
	 * Resolve a bundle upgrade PendingAction through the bundle ability surface.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function resolveAgentBundleUpgradeAction( array $input ): array {
		return self::bundleLifecycleService()->apply_pending_action( (string) ( $input['pending_action_id'] ?? $input['action_id'] ?? '' ) );
	}

	/**
	 * Run a bundle flow through Data Machine's headless workflow contract.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function runAgentBundle( array $input ): array {
		return ( new AgentBundleRunner() )->run( $input );
	}

	/**
	 * Run a Data Machine runtime bundle through the generic agent runtime seam.
	 *
	 * @param mixed $result         Previous runner result, or null when unhandled.
	 * @param array $task_config    Generic task/bundle execution config.
	 * @param array $runtime_config Generic runtime config supplied by the runtime host.
	 * @param int   $index          Bundle/task index in the runtime request.
	 * @return mixed Runtime bundle run result.
	 */
	public static function runRuntimeAgentBundle( $result, array $task_config, array $runtime_config = array(), int $index = 0 ) {
		unset( $index );

		if ( null !== $result ) {
			return $result;
		}

		$input = array_merge( $runtime_config, $task_config );
		if ( isset( $task_config['bundle'] ) && is_array( $task_config['bundle'] ) && ! isset( $input['runtime_bundle'] ) ) {
			$input['runtime_bundle'] = $task_config['bundle'];
		}
		if ( isset( $task_config['bundles'] ) && is_array( $task_config['bundles'] ) && ! isset( $input['runtime_bundles'] ) ) {
			$input['runtime_bundles'] = $task_config['bundles'];
		}
		if ( isset( $runtime_config['import'] ) && is_array( $runtime_config['import'] ) && ! isset( $input['runtime_import'] ) ) {
			$input['runtime_import'] = $runtime_config['import'];
		}

		return self::runAgentBundle( $input );
	}

	/**
	 * Provide the Agents API canonical runtime-package handler for Data Machine bundles.
	 *
	 * @param callable|null $handler Existing runtime package handler.
	 * @param object        $request Agents API runtime package request value object.
	 * @param array         $raw_input Raw ability input.
	 * @return callable|null Runtime package handler.
	 */
	public static function runtimePackageRunHandler( $handler, object $request, array $raw_input = array() ) {
		if ( null !== $handler ) {
			return $handler;
		}

		return static function () use ( $request, $raw_input ): array {
			$package  = method_exists( $request, 'get_package' ) ? $request->get_package() : array();
			$workflow = method_exists( $request, 'get_workflow' ) ? $request->get_workflow() : array();
			$input    = method_exists( $request, 'get_input' ) ? $request->get_input() : array();
			$options  = method_exists( $request, 'get_options' ) ? $request->get_options() : array();
			if ( is_array( $raw_input['options'] ?? null ) ) {
				$options = array_replace( $raw_input['options'], is_array( $options ) ? $options : array() );
			}
			$metadata = method_exists( $request, 'get_metadata' ) ? $request->get_metadata() : array();
			$replay   = method_exists( $request, 'get_replay' ) ? $request->get_replay() : array();

			$bundle_input = array(
				'initial_data' => $input,
				'metadata'     => $metadata,
				'replay'       => $replay,
				'job_source'   => 'runtime_package',
			);

			if ( isset( $package['source'] ) && is_string( $package['source'] ) && '' !== trim( $package['source'] ) ) {
				$bundle_input['source'] = trim( $package['source'] );
			}

			if ( isset( $workflow['id'] ) && is_string( $workflow['id'] ) && '' !== trim( $workflow['id'] ) ) {
				$bundle_input['flow'] = trim( $workflow['id'] );
			}

			if ( isset( $workflow['spec'] ) && is_array( $workflow['spec'] ) ) {
				$bundle_input['workflow'] = $workflow['spec'];
			}

			foreach ( array( 'provider', 'model', 'wait_for_completion', 'wait', 'step_budget', 'time_budget_ms', 'required_outputs', 'required_artifacts', 'engine_data_outputs', 'runtime_tools', 'ability_tools', 'tools', 'disable_directives' ) as $key ) {
				if ( array_key_exists( $key, $options ) ) {
					$bundle_input[ $key ] = $options[ $key ];
				} elseif ( array_key_exists( $key, $input ) ) {
					$bundle_input[ $key ] = $input[ $key ];
				}
			}

			if ( isset( $package['slug'] ) && is_string( $package['slug'] ) && '' !== trim( $package['slug'] ) ) {
				$bundle_input['runtime_bundle'] = array_merge( $package, array( 'slug' => trim( $package['slug'] ) ) );
			}

			if ( isset( $raw_input['runtime_import'] ) && is_array( $raw_input['runtime_import'] ) ) {
				$bundle_input['runtime_import'] = $raw_input['runtime_import'];
			}

			$result = self::runAgentBundle( $bundle_input );
			$status = (bool) ( $result['success'] ?? false ) ? 'succeeded' : 'failed';

			return array(
				'status'        => $status,
				'result'        => $result,
				'artifact_refs' => array_values( array_filter( array_merge( $result['export_refs'] ?? array(), $result['transcript_refs'] ?? array() ), 'is_array' ) ),
				'metadata'      => array(
					'handler' => 'datamachine/run-agent-bundle',
					'package' => $package,
				),
				'replay'        => $replay,
			);
		};
	}

	private static function bundleLifecycleService(): AgentBundleAbilityService {
		return new AgentBundleAbilityService();
	}

	/** @return array<string,mixed> */
	private static function runAgentBundleInputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array(),
			'properties' => array(
				'source'              => array(
					'type'        => 'string',
					'description' => 'Bundle source: local path (directory, .zip, .json) or remote URL.',
				),
				'flow'                => array(
					'type'        => 'string',
					'description' => 'Optional bundle flow slug. Defaults to the first flow in the bundle.',
				),
				'flow_slug'           => array(
					'type'        => 'string',
					'description' => 'Alias for flow.',
				),
				'initial_data'        => array(
					'type'        => 'object',
					'description' => 'Initial engine data merged into the ephemeral workflow job.',
				),
				'required_outputs'    => array(
					'type'        => array( 'array', 'object' ),
					'description' => 'Semantic output keys that must be present and non-empty when a completed bundle run is returned.',
				),
				'required_artifacts'  => array(
					'type'        => array( 'array', 'object' ),
					'description' => 'Typed artifact output keys that must be present and non-empty when a completed bundle run is returned.',
				),
				'engine_data_outputs' => array(
					'type'        => 'object',
					'description' => 'Map semantic output keys to engine_data/response paths for stable caller-facing outputs.',
				),
				'provider'            => array(
					'type'        => 'string',
					'description' => 'Optional wp-ai-client provider slug to use as the run-scoped pipeline model default.',
				),
				'model'               => array(
					'type'        => 'string',
					'description' => 'Optional wp-ai-client model slug to use as the run-scoped pipeline model default.',
				),
				'timestamp'           => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Future Unix timestamp for delayed execution. Omit for immediate execution.',
				),
				'dry_run'             => array(
					'type'        => 'boolean',
					'description' => 'Return the projected workflow and initial_data without creating a job.',
				),
				'wait_for_completion' => array(
					'type'        => 'boolean',
					'description' => 'Synchronously drain the created job and include terminal job_status plus engine_data in the response.',
				),
				'wait'                => array(
					'type'        => 'boolean',
					'description' => 'Alias for wait_for_completion.',
				),
				'step_budget'         => array(
					'type'        => 'integer',
					'description' => 'Maximum number of scheduled job actions to drain when wait_for_completion is true.',
				),
				'time_budget_ms'      => array(
					'type'        => 'integer',
					'description' => 'Maximum wall-clock milliseconds to drain when wait_for_completion is true.',
				),
				'token'               => array(
					'type'        => 'string',
					'description' => 'Auth token for private archive downloads. Used for this single resolve(); never persisted or logged.',
				),
				'token_env'           => array(
					'type'        => 'string',
					'description' => 'Environment variable or PHP constant name to read the auth token from.',
				),
				'runtime_bundle'      => array(
					'type'        => 'object',
					'description' => 'Single generic runtime bundle spec to import before running. Uses wp_agent_runtime_import_bundle internally.',
				),
				'runtime_bundles'     => array(
					'type'        => 'array',
					'description' => 'Generic runtime bundle specs to import before running. Uses wp_agent_import_runtime_bundles/wp_agent_runtime_import_bundle internally.',
					'items'       => array( 'type' => 'object' ),
				),
				'agent_bundles'       => array(
					'type'        => 'array',
					'description' => 'Alias for runtime_bundles used by runtime host task payloads.',
					'items'       => array( 'type' => 'object' ),
				),
				'runtime_import'      => array(
					'type'        => 'object',
					'description' => 'Defaults passed to the generic runtime bundle import seam, such as owner_id.',
				),
				'runtime_tools'       => array(
					'type'        => 'object',
					'description' => 'Runtime-scoped Data Machine tool definitions merged through datamachine_resolved_tools only for this run.',
				),
				'ability_tools'       => array(
					'type'        => array( 'object', 'array' ),
					'description' => 'Runtime-scoped generic ability-backed tools. Accepts a keyed map or list entries with name and ability, e.g. [{"name":"my_tool","ability":"plugin/ability"}].',
				),
				'tools'               => array(
					'type'        => array( 'object', 'array' ),
					'description' => 'Alias for runtime_tools; accepts keyed tool definitions or a list with name/tool fields.',
				),
				'disable_directives'  => array(
					'type'        => 'boolean',
					'description' => 'Disable Data Machine directives only while this runtime bundle run executes.',
				),
				'job_source'          => array(
					'type'        => 'string',
					'description' => 'Optional job source label. Defaults to agent_bundle.',
				),
				'job_label'           => array(
					'type'        => 'string',
					'description' => 'Optional job label. Defaults to the selected flow name.',
				),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function runAgentBundleOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'            => array( 'type' => 'boolean' ),
				'status'             => array( 'type' => 'string' ),
				'job_id'             => array( 'type' => 'integer' ),
				'outputs'            => array(
					'type'        => 'object',
					'description' => 'Semantic output map projected from declared or common task outputs in engine data.',
				),
				'output_diagnostics' => array(
					'type'        => 'object',
					'description' => 'Declared, present, and missing semantic output keys for downstream diagnostics.',
				),
				'engine_data'        => array(
					'type'        => 'object',
					'description' => 'Raw final engine data retained for diagnostics when wait_for_completion is used.',
				),
				'diagnostics'        => array( 'type' => 'object' ),
			),
		);
	}

	private static function bundleLifecycleInputSchema( bool $include_rebase = false ): array {
		$properties = array(
			'source'            => array(
				'type'        => 'string',
				'description' => 'Bundle source: local path (directory, .zip, .json) or remote URL.',
			),
			'slug'              => array(
				'type'        => 'string',
				'description' => 'Optional target agent slug override.',
			),
			'owner_id'          => array(
				'type'        => 'integer',
				'description' => 'WordPress user ID that owns imported or upgraded artifacts.',
			),
			'dry_run'           => array(
				'type'        => 'boolean',
				'description' => 'Preview without writing.',
			),
			'reconcile_runtime' => array(
				'type'        => 'boolean',
				'description' => 'Replace preserved flow runtime queues and scheduling with the bundle seed.',
			),
			'token'             => array(
				'type'        => 'string',
				'description' => 'One-shot auth token for private archive downloads; never persisted.',
			),
			'token_env'         => array(
				'type'        => 'string',
				'description' => 'Environment variable or PHP constant name that contains a one-shot auth token.',
			),
		);

		if ( $include_rebase ) {
			$properties['rebase_local'] = array(
				'type'        => 'boolean',
				'description' => 'Attach a policy-driven 3-way rebase preview for locally modified artifacts.',
			);
			$properties['policy']       = array(
				'type'        => 'string',
				'enum'        => array( AgentBundleArtifactRebase::POLICY_CONSERVATIVE, AgentBundleArtifactRebase::POLICY_BURN_IN_SAFE ),
				'description' => 'Rebase policy name.',
			);
			$properties['artifact']     = array(
				'type'        => 'string',
				'description' => 'Optional artifact_key filter for rebase previews.',
			);
		}

		return array(
			'type'       => 'object',
			'required'   => array( 'source' ),
			'properties' => $properties,
		);
	}

	/**
	 * Build the {@see BundleSource::resolve()} context array from the
	 * import-agent ability inputs. Honors `token` (literal) and
	 * `token_env` (env-var or PHP constant name) — both short-circuit
	 * the env/constant/option/filter chain in
	 * {@see \DataMachine\Engine\Bundle\BundleSourceAuth::token_for()}.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function build_resolve_context( array $input ): array {
		return BundleSourceAuth::build_resolve_context(
			isset( $input['token'] ) ? (string) $input['token'] : null,
			isset( $input['token_env'] ) ? (string) $input['token_env'] : null
		);
	}

	private static function load_import_bundle( AgentBundler $bundler, string $source ): ?array {
		if ( is_dir( $source ) ) {
			return $bundler->from_directory( $source );
		}

		if ( preg_match( '/\.zip$/i', $source ) ) {
			return $bundler->from_zip( $source );
		}

		if ( preg_match( '/\.json$/i', $source ) ) {
			return $bundler->from_json( (string) file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return null;
	}

	private static function resolve_import_owner_id( int $explicit_owner_id ): int {
		if ( $explicit_owner_id > 0 ) {
			return $explicit_owner_id;
		}

		$current_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $current_user_id > 0 ) {
			return $current_user_id;
		}

		$default_owner_id = function_exists( 'get_option' ) ? (int) get_option( 'datamachine_default_owner_id', 0 ) : 0;
		return $default_owner_id > 0 ? $default_owner_id : 0;
	}

	/**
	 * Resolve auth_ref markers into local handler configs before import.
	 *
	 * @param array $bundle Legacy bundle array.
	 * @return array{bundle: array, warnings: array<int,array<string,string>>}
	 */
	private static function resolve_import_auth_refs( array $bundle ): array {
		$warnings = array();
		if ( ! is_array( $bundle['flows'] ?? null ) ) {
			return array(
				'bundle'   => $bundle,
				'warnings' => $warnings,
			);
		}

		foreach ( $bundle['flows'] as $flow_index => &$flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$disable_flow = false;
			if ( ! is_array( $flow['flow_config'] ?? null ) ) {
				continue;
			}

			foreach ( $flow['flow_config'] as $flow_step_id => &$step ) {
				if ( ! is_array( $step ) || ! is_array( $step['handler_configs'] ?? null ) ) {
					continue;
				}
				foreach ( $step['handler_configs'] as $handler_slug => &$handler_config ) {
					if ( ! is_array( $handler_config ) || empty( $handler_config['auth_ref'] ) ) {
						continue;
					}

					$resolved = apply_filters( 'datamachine_auth_ref_to_handler_config', $handler_config, (string) $handler_slug, array( 'import' => true ) );
					if ( is_wp_error( $resolved ) ) {
						$disable_flow = true;

						$warnings[] = array(
							'flow'         => (string) ( $flow['portable_slug'] ?? ( $flow['flow_name'] ?? '' ) ),
							'flow_step_id' => (string) $flow_step_id,
							'handler_slug' => (string) $handler_slug,
							'auth_ref'     => (string) $handler_config['auth_ref'],
							'code'         => $resolved->get_error_code(),
							'message'      => $resolved->get_error_message(),
						);
						continue;
					}

					if ( is_array( $resolved ) ) {
						$handler_config = $resolved;
					}
				}
				unset( $handler_config );
			}
			unset( $step );

			if ( $disable_flow ) {
				$scheduling_config                    = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
				$scheduling_config['enabled']         = false;
				$scheduling_config['interval']        = 'manual';
				$scheduling_config['disabled_reason'] = 'unresolved_auth_ref';
				$flow['scheduling_config']            = $scheduling_config;
			}
		}
		unset( $flow );

		return array(
			'bundle'   => $bundle,
			'warnings' => $warnings,
		);
	}

	/**
	 * Create a new agent.
	 *
	 * @param array $input { agent_slug, agent_name, owner_id, config? }.
	 * @return array Result with agent_id on success.
	 */
	public static function createAgent( array $input ): array {
		$slug     = sanitize_title( $input['agent_slug'] ?? '' );
		$name     = sanitize_text_field( $input['agent_name'] ?? '' );
		$owner_id = (int) ( $input['owner_id'] ?? 0 );
		$config   = $input['config'] ?? array();

		// Scope is first-class on create. Default (key absent) is network-wide
		// via the DB column default. An explicit null also means network-wide;
		// a positive integer scopes the agent to that single blog.
		$site_scope = false;
		if ( array_key_exists( 'site_scope', $input ) ) {
			$scope_input = $input['site_scope'];
			if ( null === $scope_input || '' === $scope_input ) {
				$site_scope = null;
			} elseif ( is_numeric( $scope_input ) && (int) $scope_input > 0 ) {
				$site_scope = (int) $scope_input;
			} else {
				$site_scope = null;
			}
		}

		if ( empty( $slug ) ) {
			return array(
				'success' => false,
				'error'   => 'Agent slug is required.',
			);
		}

		if ( empty( $name ) ) {
			$name = $slug;
		}

		// Self-service creation: non-admins can only create agents for themselves.
		$is_admin = PermissionHelper::can_manage();

		if ( ! $is_admin ) {
			// Force owner to the acting user — non-admins cannot create agents for others.
			$owner_id = PermissionHelper::acting_user_id();

			if ( $owner_id <= 0 ) {
				return array(
					'success' => false,
					'error'   => 'Could not determine acting user for self-service agent creation.',
				);
			}

			// Enforce per-user agent limit for non-admins.
			$agents_repo = new Agents();
			$existing    = $agents_repo->get_by_owner_id( $owner_id );

			/**
			 * Filter the maximum number of agents a non-admin user can create.
			 *
			 * @since 0.52.0
			 *
			 * @param int $limit    Maximum agents per user. Default 1.
			 * @param int $owner_id The user creating the agent.
			 */
			$max_agents = (int) apply_filters( 'datamachine_max_agents_per_user', 1, $owner_id );

			if ( $existing && $max_agents <= 1 ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						'You already have an agent ("%s"). Non-admin users are limited to %d agent.',
						$existing['agent_name'],
						$max_agents
					),
				);
			}

			// For limits > 1, count all agents owned by this user.
			if ( $max_agents > 1 ) {
				$owned = $agents_repo->get_all_by_owner_id( $owner_id );

				if ( count( $owned ) >= $max_agents ) {
					return array(
						'success' => false,
						'error'   => sprintf(
							'You already have %d agent(s). Non-admin users are limited to %d.',
							count( $owned ),
							$max_agents
						),
					);
				}
			}
		}

		if ( $owner_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Owner user ID is required (--owner=<user_id>).',
			);
		}

		$user = get_user_by( 'id', $owner_id );
		if ( ! $user ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Owner user ID %d not found.', $owner_id ),
			);
		}

		$agents_repo = isset( $agents_repo ) ? $agents_repo : new Agents();

		// Check for conflict.
		$existing = $agents_repo->get_by_slug( $slug );
		if ( $existing ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent with slug "%s" already exists (ID: %d).', $slug, $existing['agent_id'] ),
			);
		}

		$agent_id = $agents_repo->create_if_missing( $slug, $name, $owner_id, $config, $site_scope );

		if ( ! $agent_id ) {
			return array(
				'success' => false,
				'error'   => 'Failed to create agent in database.',
			);
		}

		// Bootstrap owner access.
		$access_repo = new \DataMachine\Core\Database\Agents\AgentAccess();
		$access_repo->bootstrap_owner_access( $agent_id, $owner_id );

		// Ensure agent directory exists.
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );
		$directory_manager->ensure_directory_exists( $agent_dir );

		// Scaffold agent-layer memory files (SOUL.md, MEMORY.md) with identity context.
		$scaffold_ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $scaffold_ability ) {
			$scaffold_ability->execute( array(
				'layer'      => 'agent',
				'agent_slug' => $slug,
				'agent_id'   => $agent_id,
			) );
		}

		/**
		 * Fires after a new agent has been created.
		 *
		 * @since 0.65.0
		 *
		 * @param int    $agent_id Agent ID.
		 * @param string $slug     Agent slug.
		 * @param string $name     Agent display name.
		 */
		do_action( 'datamachine_agent_created', $agent_id, $slug, $name );

		return array(
			'success'    => true,
			'agent_id'   => $agent_id,
			'agent_slug' => $slug,
			'agent_name' => $name,
			'owner_id'   => $owner_id,
			'agent_dir'  => $agent_dir,
			'site_scope' => ( false === $site_scope ) ? null : $site_scope,
			'message'    => sprintf( 'Agent "%s" created (ID: %d).', $slug, $agent_id ),
		);
	}

	/**
	 * Resolve public agent ability input to the internal agent ID.
	 *
	 * Accepts the slug-first `agent`/`agent_slug` inputs and keeps `agent_id`
	 * working for callers that still pass the storage key.
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error Agent ID or error.
	 */
	private static function resolve_agent_input_id( array $input ): int|\WP_Error {
		$context = array();

		if ( isset( $input['agent'] ) && '' !== (string) $input['agent'] ) {
			$context['agent'] = (string) $input['agent'];
		}

		if ( isset( $input['agent_slug'] ) && '' !== (string) $input['agent_slug'] ) {
			$context['agent_slug'] = (string) $input['agent_slug'];
		}

		if ( isset( $input['agent_id'] ) && (int) $input['agent_id'] > 0 ) {
			$context['agent_id'] = (int) $input['agent_id'];
		}

		if ( isset( $context['agent'] ) ) {
			try {
				$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $context['agent'] );
				unset( $context['agent'] );
				$context['agent_id']   = $context['agent_id'] ?? $identity->agent_id;
				$context['agent_slug'] = $context['agent_slug'] ?? $identity->agent_slug;
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'agent_not_found', $e->getMessage() );
			}
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_identity( $context )->agent_id;
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'agent_not_found', $e->getMessage() );
		}
	}

	/**
	 * Get a single agent by slug or ID.
	 *
	 * @param array $input { agent_slug or agent_id }.
	 * @return array Agent data or error.
	 */
	public static function getAgent( array $input ): array {
		$agent_id = self::resolve_agent_input_id( $input );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		// Enrich with access grants.
		$access_repo      = new \DataMachine\Core\Database\Agents\AgentAccess();
		$access           = array_map(
			static fn( \WP_Agent_Access_Grant $grant ): array => $grant->to_array(),
			$access_repo->get_users_for_agent( (string) (int) $agent['agent_id'] )
		);
		$principal_access = $access_repo->get_principals_for_agent( (string) (int) $agent['agent_id'] );

		// Check for agent directory.
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $agent['agent_slug'] );

		return array(
			'success' => true,
			'agent'   => array(
				'agent_id'         => (int) $agent['agent_id'],
				'agent_slug'       => (string) $agent['agent_slug'],
				'agent_name'       => (string) $agent['agent_name'],
				'owner_id'         => (int) $agent['owner_id'],
				'agent_config'     => is_array( $agent['agent_config'] ?? null )
					? $agent['agent_config']
					: ( json_decode( $agent['agent_config'] ?? '{}', true ) ? json_decode( $agent['agent_config'] ?? '{}', true ) : array() ),
				'created_at'       => $agent['created_at'] ?? '',
				'updated_at'       => $agent['updated_at'] ?? '',
				'agent_dir'        => $agent_dir,
				'has_files'        => is_dir( $agent_dir ),
				'access'           => $access,
				'principal_access' => $principal_access,
			),
		);
	}

	/**
	 * Grant an agent to an explicit audience/non-user principal.
	 *
	 * @param array $input Ability input.
	 * @return array Result.
	 */
	public static function grantAgentAudienceAccess( array $input ): array {
		$agent_id = self::resolve_agent_input_id( $input );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$principal_type = sanitize_key( (string) ( $input['principal_type'] ?? 'audience' ) );
		$principal_id   = sanitize_title( (string) ( $input['principal_id'] ?? '' ) );
		$role           = (string) ( $input['role'] ?? \WP_Agent_Access_Grant::ROLE_OPERATOR );

		if ( '' === $principal_type || '' === $principal_id || 'user' === $principal_type ) {
			return array(
				'success' => false,
				'error'   => 'A non-user principal_type and principal_id are required.',
			);
		}

		try {
			$grant = ( new AgentAccess() )->grant_principal_access( (string) $agent_id, $principal_type, $principal_id, $role );
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		return array(
			'success' => true,
			'grant'   => $grant,
		);
	}

	/**
	 * Update an agent's mutable fields.
	 *
	 * @param array $input { agent_id, agent_name?, agent_config? }.
	 * @return array Result with updated agent data.
	 */
	public static function updateAgent( array $input ): array {
		$agent_id = self::resolve_agent_input_id( $input );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent ID %d not found.', $agent_id ),
			);
		}

		// Build update payload from allowed mutable fields.
		$update = array();

		if ( isset( $input['agent_name'] ) ) {
			$name = sanitize_text_field( $input['agent_name'] );
			if ( empty( $name ) ) {
				return array(
					'success' => false,
					'error'   => 'Agent name cannot be empty.',
				);
			}
			$update['agent_name'] = $name;
		}

		if ( array_key_exists( 'agent_config', $input ) ) {
			$update['agent_config'] = is_array( $input['agent_config'] ) ? $input['agent_config'] : array();
		}

		if ( empty( $update ) ) {
			return array(
				'success' => false,
				'error'   => 'No fields to update. Provide agent_name or agent_config.',
			);
		}

		// Capture old name before update for propagation.
		$old_name = (string) $agent['agent_name'];

		$ok = $agents_repo->update_agent( $agent_id, $update );

		if ( ! $ok ) {
			return array(
				'success' => false,
				'error'   => 'Database update failed.',
			);
		}

		// Propagate name change to agent memory files.
		if ( isset( $update['agent_name'] ) && $update['agent_name'] !== $old_name ) {
			self::propagateNameChange(
				(string) $agent['agent_slug'],
				$old_name,
				$update['agent_name']
			);
		}

		/**
		 * Fires after an agent has been updated.
		 *
		 * @since 0.65.0
		 *
		 * @param int $agent_id Agent ID.
		 */
		do_action( 'datamachine_agent_updated', $agent_id );

		// Return the updated agent.
		return self::getAgent( array( 'agent_id' => $agent_id ) );
	}

	/**
	 * Propagate an agent name change across memory files.
	 *
	 * Performs a find-and-replace of the old name with the new name in
	 * SOUL.md and MEMORY.md. Only touches files that exist and contain
	 * the old name. Uses whole-word matching to avoid partial replacements.
	 *
	 * @since 0.51.0
	 *
	 * @param string $agent_slug Agent slug (for directory resolution).
	 * @param string $old_name   Previous agent display name.
	 * @param string $new_name   New agent display name.
	 * @return array { files_updated: string[], files_skipped: string[] }
	 */
	private static function propagateNameChange( string $agent_slug, string $old_name, string $new_name ): array {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $agent_slug );

		if ( ! is_dir( $agent_dir ) ) {
			return array(
				'files_updated' => array(),
				'files_skipped' => array(),
			);
		}

		$target_files  = array( 'SOUL.md', 'MEMORY.md' );
		$files_updated = array();
		$files_skipped = array();

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		foreach ( $target_files as $filename ) {
			$filepath = trailingslashit( $agent_dir ) . $filename;

			if ( ! file_exists( $filepath ) ) {
				$files_skipped[] = $filename;
				continue;
			}

			$content = $wp_filesystem->get_contents( $filepath );

			if ( false === $content || false === strpos( $content, $old_name ) ) {
				$files_skipped[] = $filename;
				continue;
			}

			$updated_content = str_replace( $old_name, $new_name, $content );

			if ( $updated_content === $content ) {
				$files_skipped[] = $filename;
				continue;
			}

			$wp_filesystem->put_contents( $filepath, $updated_content, FS_CHMOD_FILE );
			$files_updated[] = $filename;
		}

		if ( ! empty( $files_updated ) ) {
			do_action(
				'datamachine_log',
				'info',
				sprintf(
					'Agent name changed from "%s" to "%s". Updated: %s.',
					$old_name,
					$new_name,
					implode( ', ', $files_updated )
				),
				array(
					'agent_slug'    => $agent_slug,
					'old_name'      => $old_name,
					'new_name'      => $new_name,
					'files_updated' => $files_updated,
					'files_skipped' => $files_skipped,
				)
			);
		}

		return array(
			'files_updated' => $files_updated,
			'files_skipped' => $files_skipped,
		);
	}

	/**
	 * Export an agent to a portable bundle directory or ZIP file.
	 *
	 * @param array $input Export input.
	 * @return array Result.
	 */
	public static function exportAgent( array $input ): array {
		$agent_id = self::resolve_agent_input_id( $input );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );
		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Agent ID %d not found.', $agent_id ),
			);
		}

		$format = (string) ( $input['format'] ?? 'directory' );
		if ( 'dir' === $format ) {
			$format = 'directory';
		}
		if ( ! in_array( $format, array( 'directory', 'zip' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'format must be directory or zip.',
			);
		}

		$profile = (string) ( $input['profile'] ?? 'share' );
		if ( ! in_array( $profile, array( 'share', 'backup', 'fork' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'profile must be share, backup, or fork.',
			);
		}

		$agent_slug   = (string) $agent['agent_slug'];
		$destination  = trim( (string) ( $input['destination'] ?? '' ) );
		$destination  = '' !== $destination ? $destination : $agent_slug . '-bundle' . ( 'zip' === $format ? '.zip' : '' );
		$bundler      = new AgentBundler();
		$export       = $bundler->export_directory_object( $agent_slug, array( 'profile' => $profile ) );
		$directory    = $export['directory'] ?? null;
		$manifest     = $directory instanceof AgentBundleDirectory ? $directory->manifest()->to_array() : array();
		$written_path = $destination;

		if ( empty( $export['success'] ) || ! $directory instanceof AgentBundleDirectory ) {
			return array(
				'success' => false,
				'error'   => (string) ( $export['error'] ?? 'Failed to build export bundle.' ),
			);
		}

		try {
			if ( 'directory' === $format ) {
				$directory->write( $destination );
			} else {
				$written_path = self::writeBundleZip( $directory, $destination, $agent_slug );
			}
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		return array(
			'success'    => true,
			'agent_id'   => $agent_id,
			'agent_slug' => $agent_slug,
			'profile'    => $profile,
			'format'     => $format,
			'path'       => $written_path,
			'manifest'   => $manifest,
		);
	}

	private static function writeBundleZip( AgentBundleDirectory $directory, string $zip_path, string $agent_slug ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new BundleValidationException( 'ZipArchive is not available.' );
		}

		$temp_dir   = sys_get_temp_dir() . '/datamachine-agent-export-' . uniqid( '', true );
		$bundle_dir = $temp_dir . '/' . sanitize_title( $agent_slug );
		wp_mkdir_p( $bundle_dir );
		$directory->write( $bundle_dir );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			self::removeDirectory( $temp_dir );
			throw new BundleValidationException( sprintf( 'Unable to create ZIP archive: %s', esc_html( $zip_path ) ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $bundle_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$relative_path = sanitize_title( $agent_slug ) . '/' . substr( $item->getPathname(), strlen( $bundle_dir ) + 1 );
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative_path );
			} else {
				$zip->addFile( $item->getPathname(), $relative_path );
			}
		}
		$zip->close();
		self::removeDirectory( $temp_dir );

		return $zip_path;
	}

	private static function removeDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Delete an agent.
	 *
	 * Removes the agent record and access grants. Does NOT delete
	 * the filesystem directory (use --delete-files for that).
	 *
	 * @param array $input { agent_slug or agent_id, delete_files? }.
	 * @return array Result.
	 */
	public static function deleteAgent( array $input ): array {
		$agent_id = self::resolve_agent_input_id( $input );
		if ( is_wp_error( $agent_id ) ) {
			return array(
				'success' => false,
				'error'   => $agent_id->get_error_message(),
			);
		}

		$agents_repo = new Agents();
		$agent       = $agents_repo->get_agent( $agent_id );

		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		$agent_id = (int) $agent['agent_id'];
		$slug     = (string) $agent['agent_slug'];

		// Delete access grants.
		global $wpdb;
		$access_table = $wpdb->base_prefix . 'datamachine_agent_access';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $access_table, array( 'agent_id' => $agent_id ) );

		// Delete agent record.
		$agents_table = $wpdb->base_prefix . 'datamachine_agents';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $agents_table, array( 'agent_id' => $agent_id ) );

		if ( false === $deleted ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete agent from database.',
			);
		}

		// Optionally delete files.
		$files_deleted = false;
		if ( ! empty( $input['delete_files'] ) ) {
			$directory_manager = new DirectoryManager();
			$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );
			if ( is_dir( $agent_dir ) ) {
				// Recursive delete.
				$iterator = new \RecursiveDirectoryIterator( $agent_dir, \RecursiveDirectoryIterator::SKIP_DOTS );
				$files    = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $files as $file ) {
					if ( $file->isDir() ) {
						rmdir( $file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- DirectoryManager owns agent filesystem paths.
					} else {
						wp_delete_file( $file->getRealPath() );
					}
				}
				rmdir( $agent_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- DirectoryManager owns agent filesystem paths.
				$files_deleted = true;
			}
		}

		/**
		 * Fires after an agent has been deleted.
		 *
		 * @since 0.65.0
		 *
		 * @param int    $agent_id Agent ID (no longer exists in DB).
		 * @param string $slug     Agent slug.
		 */
		do_action( 'datamachine_agent_deleted', $agent_id, $slug );

		return array(
			'success'       => true,
			'agent_id'      => $agent_id,
			'agent_slug'    => $slug,
			'files_deleted' => $files_deleted,
			'message'       => sprintf( 'Agent "%s" (ID: %d) deleted.%s', $slug, $agent_id, $files_deleted ? ' Files removed.' : '' ),
		);
	}

	/**
	 * Import an agent bundle staged by a disposable agent runtime.
	 *
	 * @param mixed $result Previous importer result, or null when unhandled.
	 * @param array $spec   Original bundle spec.
	 * @param array $input  Staged datamachine/import-agent input.
	 * @param int   $index  Bundle index in the runtime request.
	 * @return mixed Import result or WP_Error.
	 */
	public static function importRuntimeAgentBundle( $result, array $spec, array $input, int $index ) {
		unset( $index );

		if ( null !== $result ) {
			return $result;
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/import-agent' ) : null;
		if ( ! $ability instanceof \WP_Ability ) {
			return new \WP_Error(
				'datamachine_import_agent_unavailable',
				__( 'The Data Machine import-agent ability is not available inside the runtime site.', 'data-machine' )
			);
		}

		$input     = self::runtimeAgentBundleImportInput( $spec, $input );
		$principal = self::runtimeAgentBundleImportPrincipal( $spec, $input );
		if ( is_wp_error( $principal ) ) {
			return $principal;
		}

		PermissionHelper::set_agent_context( (int) $principal['agent_id'], (int) $principal['owner_id'], $principal['scope'], $principal['token_id'] );
		try {
			return $ability->execute( $input );
		} finally {
			PermissionHelper::clear_agent_context();
		}
	}

	/**
	 * Normalize a runtime bundle spec into canonical datamachine/import-agent input.
	 *
	 * @param array $spec  Original bundle spec.
	 * @param array $input Runtime import defaults supplied by the caller.
	 * @return array<string,mixed>
	 */
	private static function runtimeAgentBundleImportInput( array $spec, array $input ): array {
		$canonical = array();

		foreach ( array( 'bundle', 'source', 'slug', 'owner_id', 'on_conflict', 'dry_run', 'token', 'token_env' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$canonical[ $field ] = $input[ $field ];
			}
		}

		foreach ( array( 'bundle', 'source', 'slug', 'owner_id', 'on_conflict', 'dry_run', 'token', 'token_env' ) as $field ) {
			if ( array_key_exists( $field, $spec ) ) {
				$canonical[ $field ] = $spec[ $field ];
			}
		}

		foreach ( array( 'source', 'slug', 'token', 'token_env' ) as $field ) {
			if ( isset( $canonical[ $field ] ) ) {
				$canonical[ $field ] = trim( (string) $canonical[ $field ] );
				if ( '' === $canonical[ $field ] ) {
					unset( $canonical[ $field ] );
				}
			}
		}

		foreach ( array( 'owner_id' ) as $field ) {
			if ( isset( $canonical[ $field ] ) ) {
				$canonical[ $field ] = (int) $canonical[ $field ];
				if ( $canonical[ $field ] <= 0 ) {
					unset( $canonical[ $field ] );
				}
			}
		}

		if ( isset( $canonical['dry_run'] ) ) {
			$canonical['dry_run'] = (bool) $canonical['dry_run'];
		}

		if ( isset( $canonical['bundle'] ) && is_array( $canonical['bundle'] ) ) {
			unset( $canonical['source'] );
		}

		return $canonical;
	}

	/**
	 * Resolve the Data Machine principal used for browser runtime bundle imports.
	 *
	 * @param array $spec  Original bundle spec.
	 * @param array $input Staged datamachine/import-agent input.
	 * @return array|\WP_Error
	 */
	private static function runtimeAgentBundleImportPrincipal( array $spec, array $input ) {
		$principal       = is_array( $spec['import_principal'] ?? null ) ? $spec['import_principal'] : array();
		$agent_id        = (int) ( $principal['agent_id'] ?? 1 );
		$current_user_id = get_current_user_id();
		if ( $agent_id <= 0 ) {
			return new \WP_Error( 'datamachine_browser_bundle_import_principal_missing_agent', __( 'Agent bundle import principals require a positive agent_id.', 'data-machine' ) );
		}

		$capabilities = array_values( array_filter( array_map( 'strval', is_array( $principal['capabilities'] ?? null ) ? $principal['capabilities'] : array() ) ) );
		if ( empty( $capabilities ) ) {
			$capabilities = array( 'datamachine_manage_agents' );
		}

		$scope = is_array( $principal['scope'] ?? null ) ? $principal['scope'] : array();

		$scope = array_merge(
			array(
				'scope'              => 'agent_bundle_import',
				'label'              => 'Agent bundle import',
				'ability_categories' => array(),
				'ability_allow'      => array( 'datamachine/import-agent' ),
				'ability_deny'       => array(),
				'capabilities'       => $capabilities,
			),
			$scope
		);

		$scope['capabilities'] = array_values( array_filter( array_map( 'strval', is_array( $scope['capabilities'] ?? null ) ? $scope['capabilities'] : $capabilities ) ) );
		if ( empty( $scope['capabilities'] ) ) {
			$scope['capabilities'] = $capabilities;
		}

		return array(
			'agent_id' => $agent_id,
			'owner_id' => (int) ( $principal['owner_id'] ?? $input['owner_id'] ?? ( $current_user_id ? $current_user_id : 1 ) ),
			'scope'    => $scope,
			'token_id' => isset( $principal['token_id'] ) && (int) $principal['token_id'] > 0 ? (int) $principal['token_id'] : null,
		);
	}
}
