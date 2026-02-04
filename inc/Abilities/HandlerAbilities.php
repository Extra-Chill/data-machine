<?php
/**
 * Handler Abilities
 *
 * Abilities API primitives for handler discovery and configuration.
 * Replaces HandlerService for handler data access throughout the codebase.
 *
 * @package DataMachine\Abilities
 * @since 0.12.0
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class HandlerAbilities {

	/**
	 * Option name for handler defaults storage.
	 */
	private const HANDLER_DEFAULTS_OPTION = 'datamachine_handler_defaults';

	private static bool $registered = false;

	/**
	 * Cached handlers by step type.
	 *
	 * @var array<string, array>
	 */
	private static array $handlers_cache = array();

	/**
	 * Cached handler settings classes.
	 *
	 * @var array<string, object|null>
	 */
	private static array $settings_cache = array();

	/**
	 * Cached config fields by handler slug.
	 *
	 * @var array<string, array>
	 */
	private static array $config_fields_cache = array();

	/**
	 * Cached site-wide handler defaults.
	 *
	 * @var array|null
	 */
	private static ?array $site_defaults_cache = null;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Clear all cached data.
	 * Call when handlers are dynamically registered.
	 */
	public static function clearCache(): void {
		self::$handlers_cache      = array();
		self::$settings_cache      = array();
		self::$config_fields_cache = array();
		self::$site_defaults_cache = null;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetHandlersAbility();
			$this->registerValidateHandlerAbility();
			$this->registerGetHandlerConfigFieldsAbility();
			$this->registerApplyHandlerDefaultsAbility();
			$this->registerGetHandlerSiteDefaultsAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerGetHandlersAbility(): void {
		wp_register_ability(
			'datamachine/get-handlers',
			array(
				'label'               => __( 'Get Handlers', 'data-machine' ),
				'description'         => __( 'Get all registered handlers, optionally filtered by step type, or a single handler by slug.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'handler_slug' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Get a specific handler by slug (ignores step_type filter when provided)', 'data-machine' ),
						),
						'step_type'    => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Step type filter (fetch, publish, update, etc.)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'handlers'  => array( 'type' => 'object' ),
						'count'     => array( 'type' => 'integer' ),
						'step_type' => array( 'type' => 'string' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetHandlers' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerValidateHandlerAbility(): void {
		wp_register_ability(
			'datamachine/validate-handler',
			array(
				'label'               => __( 'Validate Handler', 'data-machine' ),
				'description'         => __( 'Validate that a handler slug exists.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler slug to validate', 'data-machine' ),
						),
						'step_type'    => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Optional step type constraint', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'valid'   => array( 'type' => 'boolean' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeValidateHandler' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetHandlerConfigFieldsAbility(): void {
		wp_register_ability(
			'datamachine/get-handler-config-fields',
			array(
				'label'               => __( 'Get Handler Config Fields', 'data-machine' ),
				'description'         => __( 'Get configuration field definitions for a handler.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler slug to get fields for', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'fields'       => array( 'type' => 'object' ),
						'handler_slug' => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetHandlerConfigFields' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerApplyHandlerDefaultsAbility(): void {
		wp_register_ability(
			'datamachine/apply-handler-defaults',
			array(
				'label'               => __( 'Apply Handler Defaults', 'data-machine' ),
				'description'         => __( 'Apply default values to handler configuration.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug', 'config' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler slug', 'data-machine' ),
						),
						'config'       => array(
							'type'        => 'object',
							'description' => __( 'Configuration values to merge with defaults', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'config'  => array( 'type' => 'object' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeApplyHandlerDefaults' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetHandlerSiteDefaultsAbility(): void {
		wp_register_ability(
			'datamachine/get-handler-site-defaults',
			array(
				'label'               => __( 'Get Handler Site Defaults', 'data-machine' ),
				'description'         => __( 'Get site-wide handler default configurations.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'handler_slug' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Optional handler slug to filter defaults', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'defaults' => array( 'type' => 'object' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetHandlerSiteDefaults' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get handlers ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with handlers data.
	 */
	public function executeGetHandlers( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;
		$step_type    = $input['step_type'] ?? null;

		// Direct handler lookup by slug - bypasses step_type filter.
		if ( $handler_slug ) {
			if ( ! is_string( $handler_slug ) || empty( $handler_slug ) ) {
				return array(
					'success' => false,
					'error'   => 'handler_slug must be a non-empty string',
				);
			}

			$handler = $this->getHandler( $handler_slug );

			if ( ! $handler ) {
				return array(
					'success'   => true,
					'handlers'  => array(),
					'count'     => 0,
					'step_type' => null,
				);
			}

			return array(
				'success'   => true,
				'handlers'  => array( $handler_slug => $handler ),
				'count'     => 1,
				'step_type' => null,
			);
		}

		$handlers = $this->getAllHandlers( $step_type );

		return array(
			'success'   => true,
			'handlers'  => $handlers,
			'count'     => count( $handlers ),
			'step_type' => $step_type,
		);
	}

	/**
	 * Execute validate handler ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with validation status.
	 */
	public function executeValidateHandler( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;
		$step_type    = $input['step_type'] ?? null;

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => true,
				'valid'   => false,
				'error'   => 'handler_slug is required',
			);
		}

		if ( $step_type ) {
			$handlers = $this->getAllHandlers( $step_type );
			if ( ! isset( $handlers[ $handler_slug ] ) ) {
				return array(
					'success' => true,
					'valid'   => false,
					'error'   => "Handler '{$handler_slug}' not found for step type '{$step_type}'",
				);
			}
			return array(
				'success' => true,
				'valid'   => true,
			);
		}

		$all_handlers = $this->getAllHandlers();
		if ( ! isset( $all_handlers[ $handler_slug ] ) ) {
			return array(
				'success' => true,
				'valid'   => false,
				'error'   => "Handler '{$handler_slug}' not found",
			);
		}

		return array(
			'success' => true,
			'valid'   => true,
		);
	}

	/**
	 * Execute get handler config fields ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with field definitions.
	 */
	public function executeGetHandlerConfigFields( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => 'handler_slug is required',
			);
		}

		$fields = $this->getConfigFields( $handler_slug );

		return array(
			'success'      => true,
			'fields'       => $fields,
			'handler_slug' => $handler_slug,
		);
	}

	/**
	 * Execute apply handler defaults ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with complete configuration.
	 */
	public function executeApplyHandlerDefaults( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;
		$config       = $input['config'] ?? array();

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => 'handler_slug is required',
			);
		}

		$complete_config = $this->applyDefaults( $handler_slug, $config );

		return array(
			'success' => true,
			'config'  => $complete_config,
		);
	}

	/**
	 * Execute get handler site defaults ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with site defaults.
	 */
	public function executeGetHandlerSiteDefaults( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;
		$all_defaults = $this->getSiteDefaults();

		if ( $handler_slug ) {
			$defaults = $all_defaults[ $handler_slug ] ?? array();
			return array(
				'success'  => true,
				'defaults' => $defaults,
			);
		}

		return array(
			'success'  => true,
			'defaults' => $all_defaults,
		);
	}

	/**
	 * Get all registered handlers (cached).
	 *
	 * @param string|null $step_type Step type filter.
	 * @return array Handlers array keyed by slug.
	 */
	public function getAllHandlers( ?string $step_type = null ): array {
		$cache_key = $step_type ?? '__all__';

		if ( ! isset( self::$handlers_cache[ $cache_key ] ) ) {
			self::$handlers_cache[ $cache_key ] = apply_filters( 'datamachine_handlers', array(), $step_type );
		}

		return self::$handlers_cache[ $cache_key ];
	}

	/**
	 * Get handler definition by slug.
	 *
	 * @param string      $handler_slug Handler slug.
	 * @param string|null $step_type Optional step type filter.
	 * @return array|null Handler definition or null.
	 */
	public function getHandler( string $handler_slug, ?string $step_type = null ): ?array {
		$handlers = $this->getAllHandlers( $step_type );
		return $handlers[ $handler_slug ] ?? null;
	}

	/**
	 * Check if a handler slug exists.
	 *
	 * @param string      $handler_slug Handler slug to check.
	 * @param string|null $step_type Optional step type constraint.
	 * @return bool True if handler exists.
	 */
	public function handlerExists( string $handler_slug, ?string $step_type = null ): bool {
		$handlers = $this->getAllHandlers( $step_type );
		return isset( $handlers[ $handler_slug ] );
	}

	/**
	 * Validate handler slug.
	 *
	 * @param string      $handler_slug Handler slug to validate.
	 * @param string|null $step_type Optional step type constraint.
	 * @return array{valid: bool, error?: string}
	 */
	public function validateHandler( string $handler_slug, ?string $step_type = null ): array {
		if ( empty( $handler_slug ) ) {
			return array(
				'valid' => false,
				'error' => 'handler_slug is required',
			);
		}

		if ( $step_type ) {
			$handlers = $this->getAllHandlers( $step_type );
			if ( ! isset( $handlers[ $handler_slug ] ) ) {
				return array(
					'valid' => false,
					'error' => "Handler '{$handler_slug}' not found for step type '{$step_type}'",
				);
			}
			return array( 'valid' => true );
		}

		$all_handlers = $this->getAllHandlers();
		if ( ! isset( $all_handlers[ $handler_slug ] ) ) {
			return array(
				'valid' => false,
				'error' => "Handler '{$handler_slug}' not found",
			);
		}
		return array( 'valid' => true );
	}

	/**
	 * Get settings class instance for a handler (cached).
	 *
	 * @param string $handler_slug Handler slug.
	 * @return object|null Settings class instance or null.
	 */
	public function getSettingsClass( string $handler_slug ): ?object {
		if ( ! array_key_exists( $handler_slug, self::$settings_cache ) ) {
			$all_settings                          = apply_filters( 'datamachine_handler_settings', array(), $handler_slug );
			self::$settings_cache[ $handler_slug ] = $all_settings[ $handler_slug ] ?? null;
		}

		return self::$settings_cache[ $handler_slug ];
	}

	/**
	 * Get configuration fields for a handler (cached).
	 *
	 * @param string $handler_slug Handler slug.
	 * @return array Field definitions from the handler's settings class.
	 */
	public function getConfigFields( string $handler_slug ): array {
		if ( isset( self::$config_fields_cache[ $handler_slug ] ) ) {
			return self::$config_fields_cache[ $handler_slug ];
		}

		$settings_class = $this->getSettingsClass( $handler_slug );

		if ( ! $settings_class || ! method_exists( $settings_class, 'get_fields' ) ) {
			self::$config_fields_cache[ $handler_slug ] = array();
			return array();
		}

		$fields                                     = $settings_class::get_fields();
		self::$config_fields_cache[ $handler_slug ] = $fields;

		return $fields;
	}

	/**
	 * Get site-wide handler defaults.
	 *
	 * @return array Defaults keyed by handler slug.
	 */
	public function getSiteDefaults(): array {
		if ( null === self::$site_defaults_cache ) {
			self::$site_defaults_cache = get_option( self::HANDLER_DEFAULTS_OPTION, array() );
		}
		return self::$site_defaults_cache;
	}

	/**
	 * Clear site defaults cache.
	 * Call when defaults are updated.
	 */
	public static function clearSiteDefaultsCache(): void {
		self::$site_defaults_cache = null;
	}

	/**
	 * Apply handler defaults to configuration.
	 *
	 * Priority order (highest to lowest):
	 * 1. Explicitly provided config values
	 * 2. Site-wide handler defaults (from Settings -> Handler Defaults)
	 * 3. Schema defaults (from handler field definitions)
	 *
	 * Keys not in schema are preserved for forward compatibility.
	 *
	 * @param string $handler_slug Handler identifier.
	 * @param array  $config Provided configuration values.
	 * @return array Complete configuration with defaults applied.
	 */
	public function applyDefaults( string $handler_slug, array $config ): array {
		$fields = $this->getConfigFields( $handler_slug );

		if ( empty( $fields ) ) {
			return $config;
		}

		$site_defaults         = $this->getSiteDefaults();
		$handler_site_defaults = $site_defaults[ $handler_slug ] ?? array();

		$complete_config = array();

		foreach ( $fields as $key => $field_config ) {
			if ( array_key_exists( $key, $config ) ) {
				$complete_config[ $key ] = $config[ $key ];
			} elseif ( array_key_exists( $key, $handler_site_defaults ) ) {
				$complete_config[ $key ] = $handler_site_defaults[ $key ];
			} elseif ( isset( $field_config['default'] ) ) {
				$complete_config[ $key ] = $field_config['default'];
			}
		}

		return $complete_config;
	}
}
