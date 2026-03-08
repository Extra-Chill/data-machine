<?php
/**
 * Handler Registration Trait
 *
 * Provides standardized handler registration functionality to eliminate
 * boilerplate code across all handler filter registration files.
 *
 * @package DataMachine\Core\Steps
 * @since 0.2.2
 */

namespace DataMachine\Core\Steps;

if ( ! defined('ABSPATH') ) {
	exit;
}

trait HandlerRegistrationTrait {
	/**
	 * Register a handler with all required filters.
	 *
	 * Provides a standardized way to register handlers, eliminating
	 * repetitive filter registration code across all handlers.
	 *
	 * @param string $slug Handler slug identifier
	 * @param string $type Handler type (publish, fetch, update)
	 * @param string $class_name Handler class name
	 * @param string $label Display label
	 * @param string $description Handler description
	 * @param bool $requiresAuth Whether handler requires authentication
	 * @param string|null $authClass Authentication class name
	 * @param string|null $settingsClass Settings class name
	 * @param callable|null $aiToolCallback AI tool registration callback
	 * @param string|null $authProviderKey Optional custom auth provider key for shared authentication
	 */
	protected static function registerHandler(
		string $slug,
		string $type,
		string $class_name,
		string $label,
		string $description,
		bool $requiresAuth = false,
		?string $authClass = null,
		?string $settingsClass = null,
		?callable $aiToolCallback = null,
		?string $authProviderKey = null
	): void {
		// Compute auth provider key for both handler metadata and auth registration
		$provider_key = $authProviderKey ?? $slug;

		// Handler registration
		add_filter('datamachine_handlers', function($handlers, $step_type = null)
			use ($slug, $type, $class_name, $label, $description, $requiresAuth, $provider_key) {
			if ( null === $step_type || $step_type === $type ) {
				$handlers[ $slug ] = array(
					'type'              => $type,
					'class'             => $class_name,
					'label'             => $label,
					'description'       => $description,
					'requires_auth'     => $requiresAuth,
					'auth_provider_key' => $requiresAuth ? $provider_key : null,
				);
			}
			return $handlers;
		}, 10, 2);

		// Auth provider registration
		if ( $authClass && $requiresAuth ) {
			add_filter('datamachine_auth_providers', function($providers, $step_type = null)
				use ($provider_key, $authClass, $type) {
				if ( null === $step_type || $step_type === $type ) {
					// Singleton pattern: only create instance if key doesn't already exist
					if ( ! isset($providers[ $provider_key ]) ) {
						$providers[ $provider_key ] = new $authClass();
					}
				}
				return $providers;
			}, 10, 2);
		}

		// Settings registration
		if ( $settingsClass ) {
			add_filter('datamachine_handler_settings', function($all_settings, $handler_slug = null)
				use ($slug, $settingsClass) {
				if ( null === $handler_slug || $handler_slug === $slug ) {
					$all_settings[ $slug ] = new $settingsClass();
				}
				return $all_settings;
			}, 10, 2);
		}

		// AI tools registration (4 params: tools, handler_slug, handler_config, engine_data)
		if ( $aiToolCallback ) {
			add_filter('chubes_ai_tools', $aiToolCallback, 10, 4);
		}

		// Fire action for cache invalidation
		do_action('datamachine_handler_registered', $slug, $type);
	}
}
