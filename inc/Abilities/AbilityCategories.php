<?php
/**
 * Ability Categories
 *
 * Centralized registration of all Data Machine ability categories.
 * Each category groups related abilities for discoverability, tool
 * scoping (pipeline tool filtering), permission boundaries, and
 * MCP/REST API organization.
 *
 * @package DataMachine\Abilities
 * @since 0.55.0
 */

namespace DataMachine\Abilities;

defined( 'ABSPATH' ) || exit;

class AbilityCategories {

	/**
	 * Category slug constants for use in ability registrations.
	 *
	 * Extension plugins should define their own constants or use string
	 * literals following the same naming convention.
	 */
	public const CONTENT    = 'datamachine-content';
	public const MEDIA      = 'datamachine-media';
	public const ANALYTICS  = 'datamachine-analytics';
	public const SEO        = 'datamachine-seo';
	public const MEMORY     = 'datamachine-memory';
	public const TAXONOMY   = 'datamachine-taxonomy';
	public const PUBLISHING = 'datamachine-publishing';
	public const FETCH      = 'datamachine-fetch';
	public const EMAIL      = 'datamachine-email';
	public const PIPELINE   = 'datamachine-pipeline';
	public const FLOW       = 'datamachine-flow';
	public const JOBS       = 'datamachine-jobs';
	public const AGENT      = 'datamachine-agent';
	public const SETTINGS   = 'datamachine-settings';
	public const AUTH       = 'datamachine-auth';
	public const LOGGING    = 'datamachine-logging';
	public const SYSTEM     = 'datamachine-system';
	public const CHAT       = 'datamachine-chat';
	public const ACTIONS    = 'datamachine-actions';

	private static bool $registered = false;

	/**
	 * Register all Data Machine ability categories.
	 *
	 * Safe to call multiple times — uses a static guard.
	 * Must be called during `wp_abilities_api_categories_init` action —
	 * WordPress core enforces this via `doing_action()` check.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		$late     = ! doing_action( 'wp_abilities_api_categories_init' )
			&& did_action( 'wp_abilities_api_categories_init' )
			&& class_exists( '\WP_Ability_Categories_Registry' );
		$registry = $late ? \WP_Ability_Categories_Registry::get_instance() : null;

		foreach ( self::get_category_definitions() as $slug => $args ) {
			if ( $late ) {
				// Late path: the categories-init action has already completed
				// (headless runtime load order; see
				// ensure_registered()). The public helper would
				// `_doing_it_wrong()`, so register through the registry
				// instance directly, which core permits any time after `init`.
				if ( null !== $registry && ! $registry->is_registered( $slug ) ) {
					$registry->register( $slug, $args );
				}
				continue;
			}

			wp_register_ability_category( $slug, $args );
		}

		self::$registered = true;
	}

	/**
	 * Ensure categories are registered.
	 *
	 * Handles three timing states so registration is robust regardless of
	 * which plugin instantiates the abilities registry first:
	 *
	 *   1. `doing_action( wp_abilities_api_categories_init )` — the action
	 *      is currently firing. Register immediately so the categories
	 *      land in this same dispatch pass.
	 *   2. `! did_action( wp_abilities_api_categories_init )` — the action
	 *      has not fired yet. Attach a hook for the lazy fire.
	 *   3. otherwise — the action has already fired and completed. This can
	 *      happen in headless runtimes where WordPress boots before including
	 *      the plugin file, firing the one-shot
	 *      `wp_abilities_api_categories_init` action first. Register through the
	 *      registry instance directly via `register()`'s late path so
	 *      category-bound abilities (e.g.
	 *      `datamachine/run-agent-bundle`) are not silently dropped. Core only
	 *      enforces the lifecycle in the `wp_register_ability_category()`
	 *      wrapper, not in `WP_Ability_Categories_Registry::register()`.
	 *
	 * @return void
	 */
	public static function ensure_registered(): void {
		if ( self::$registered ) {
			return;
		}

		if ( doing_action( 'wp_abilities_api_categories_init' ) ) {
			self::register();
			return;
		}

		if ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( self::class, 'register' ) );
			return;
		}

		// Post-lifecycle: register categories late via the registry instance so
		// abilities included after the categories-init fire still resolve their
		// category. register() detects this state and uses the registry directly.
		self::register();
	}

	/**
	 * Category definitions used by both `register()` and lifecycle-safe
	 * registration checks in `ensure_registered()`.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_category_definitions(): array {
		return array(
			self::CONTENT    => array(
				'label'       => __( 'Content', 'data-machine' ),
				'description' => __( 'Content querying, editing, searching, and block operations.', 'data-machine' ),
			),
			self::MEDIA      => array(
				'label'       => __( 'Media', 'data-machine' ),
				'description' => __( 'Image generation, alt text, media upload, validation, and optimization.', 'data-machine' ),
			),
			self::ANALYTICS  => array(
				'label'       => __( 'Analytics', 'data-machine' ),
				'description' => __( 'Analytics integrations and reporting tools.', 'data-machine' ),
			),
			self::SEO        => array(
				'label'       => __( 'SEO', 'data-machine' ),
				'description' => __( 'Internal linking, meta descriptions, and IndexNow.', 'data-machine' ),
			),
			self::MEMORY     => array(
				'label'       => __( 'Memory', 'data-machine' ),
				'description' => __( 'Agent memory, daily journals, and agent file management.', 'data-machine' ),
			),
			self::TAXONOMY   => array(
				'label'       => __( 'Taxonomy', 'data-machine' ),
				'description' => __( 'Taxonomy term CRUD and resolution.', 'data-machine' ),
			),
			self::PUBLISHING => array(
				'label'       => __( 'Publishing', 'data-machine' ),
				'description' => __( 'WordPress publishing, email sending, and post updates.', 'data-machine' ),
			),
			self::FETCH      => array(
				'label'       => __( 'Fetch', 'data-machine' ),
				'description' => __( 'RSS, WordPress API, media, files, and email fetching.', 'data-machine' ),
			),
			self::EMAIL      => array(
				'label'       => __( 'Email', 'data-machine' ),
				'description' => __( 'Email management — reply, delete, move, flag, unsubscribe.', 'data-machine' ),
			),
			self::PIPELINE   => array(
				'label'       => __( 'Pipeline', 'data-machine' ),
				'description' => __( 'Pipeline CRUD, step management, handler and step-type discovery.', 'data-machine' ),
			),
			self::FLOW       => array(
				'label'       => __( 'Flow', 'data-machine' ),
				'description' => __( 'Flow CRUD, scheduling, queue management, and webhook triggers.', 'data-machine' ),
			),
			self::JOBS       => array(
				'label'       => __( 'Jobs', 'data-machine' ),
				'description' => __( 'Job management, health monitoring, recovery, and workflow execution.', 'data-machine' ),
			),
			self::AGENT      => array(
				'label'       => __( 'Agent', 'data-machine' ),
				'description' => __( 'Agent CRUD, tokens, pings, duplicate checking, and processed items.', 'data-machine' ),
			),
			self::SETTINGS   => array(
				'label'       => __( 'Settings', 'data-machine' ),
				'description' => __( 'Plugin settings, tool configuration, and handler defaults.', 'data-machine' ),
			),
			self::AUTH       => array(
				'label'       => __( 'Auth', 'data-machine' ),
				'description' => __( 'OAuth provider management and authentication.', 'data-machine' ),
			),
			self::LOGGING    => array(
				'label'       => __( 'Logging', 'data-machine' ),
				'description' => __( 'Log reading, writing, clearing, and metadata.', 'data-machine' ),
			),
			self::SYSTEM     => array(
				'label'       => __( 'System', 'data-machine' ),
				'description' => __( 'System health, session titles, and background task execution.', 'data-machine' ),
			),
			self::CHAT       => array(
				'label'       => __( 'Chat', 'data-machine' ),
				'description' => __( 'Chat session management and messaging.', 'data-machine' ),
			),
			self::ACTIONS    => array(
				'label'       => __( 'Actions', 'data-machine' ),
				'description' => __( 'Pending action staging and resolution (user approval of tool invocations).', 'data-machine' ),
			),
		);
	}
}
