<?php
/**
 * Settings Abilities
 *
 * WordPress 6.9 Abilities API primitives for settings operations.
 * Centralizes plugin settings, scheduling intervals, tool config, and handler defaults.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class SettingsAbilities {

	/**
	 * Option name for handler defaults storage.
	 */
	const HANDLER_DEFAULTS_OPTION = 'datamachine_handler_defaults';

	private static bool $registered = false;

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

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetSettings();
			$this->registerUpdateSettings();
			$this->registerGetSchedulingIntervals();
			$this->registerGetToolConfig();
			$this->registerSaveToolConfig();
			$this->registerGetHandlerDefaults();
			$this->registerUpdateHandlerDefaults();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerGetSettings(): void {
		wp_register_ability(
			'datamachine/get-settings',
			array(
				'label'               => __( 'Get Settings', 'data-machine' ),
				'description'         => __( 'Get all plugin settings including AI settings, enabled tools, and masked API keys.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'settings'     => array( 'type' => 'object' ),
						'global_tools' => array( 'type' => 'object' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetSettings' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdateSettings(): void {
		wp_register_ability(
			'datamachine/update-settings',
			array(
				'label'               => __( 'Update Settings', 'data-machine' ),
				'description'         => __( 'Partial update of plugin settings. Only provided fields are updated.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'cleanup_job_data_on_failure'     => array( 'type' => 'boolean' ),
						'file_retention_days'             => array( 'type' => 'integer' ),
						'chat_retention_days'             => array( 'type' => 'integer' ),
						'chat_ai_titles_enabled'          => array( 'type' => 'boolean' ),
						'alt_text_auto_generate_enabled'  => array( 'type' => 'boolean' ),
						'problem_flow_threshold'          => array( 'type' => 'integer' ),
						'flows_per_page'              => array( 'type' => 'integer' ),
						'jobs_per_page'               => array( 'type' => 'integer' ),
						'global_system_prompt'        => array( 'type' => 'string' ),
						'site_context_enabled'        => array( 'type' => 'boolean' ),
						'default_provider'            => array( 'type' => 'string' ),
						'default_model'               => array( 'type' => 'string' ),
						'max_turns'                   => array( 'type' => 'integer' ),
						'enabled_tools'               => array( 'type' => 'object' ),
						'ai_provider_keys'            => array( 'type' => 'object' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdateSettings' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetSchedulingIntervals(): void {
		wp_register_ability(
			'datamachine/get-scheduling-intervals',
			array(
				'label'               => __( 'Get Scheduling Intervals', 'data-machine' ),
				'description'         => __( 'Get available scheduling intervals for flow scheduling.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'intervals' => array( 'type' => 'array' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetSchedulingIntervals' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetToolConfig(): void {
		wp_register_ability(
			'datamachine/get-tool-config',
			array(
				'label'               => __( 'Get Tool Config', 'data-machine' ),
				'description'         => __( 'Get configuration for a specific tool including fields and current config values.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'tool_id' ),
					'properties' => array(
						'tool_id' => array(
							'type'        => 'string',
							'description' => __( 'Tool identifier', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                => array( 'type' => 'boolean' ),
						'tool_id'                => array( 'type' => 'string' ),
						'label'                  => array( 'type' => 'string' ),
						'description'            => array( 'type' => 'string' ),
						'requires_configuration' => array( 'type' => 'boolean' ),
						'is_configured'          => array( 'type' => 'boolean' ),
						'fields'                 => array( 'type' => 'object' ),
						'config'                 => array( 'type' => 'object' ),
						'error'                  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetToolConfig' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerSaveToolConfig(): void {
		wp_register_ability(
			'datamachine/save-tool-config',
			array(
				'label'               => __( 'Save Tool Config', 'data-machine' ),
				'description'         => __( 'Save configuration for a specific tool. Fires tool-specific handler via action hook.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'tool_id', 'config_data' ),
					'properties' => array(
						'tool_id'     => array(
							'type'        => 'string',
							'description' => __( 'Tool identifier', 'data-machine' ),
						),
						'config_data' => array(
							'type'        => 'object',
							'description' => __( 'Tool configuration data', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'tool_id' => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeSaveToolConfig' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetHandlerDefaults(): void {
		wp_register_ability(
			'datamachine/get-handler-defaults',
			array(
				'label'               => __( 'Get Handler Defaults', 'data-machine' ),
				'description'         => __( 'Get all handler defaults grouped by step type.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'defaults' => array( 'type' => 'object' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetHandlerDefaults' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdateHandlerDefaults(): void {
		wp_register_ability(
			'datamachine/update-handler-defaults',
			array(
				'label'               => __( 'Update Handler Defaults', 'data-machine' ),
				'description'         => __( 'Update defaults for a specific handler.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug', 'defaults' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler slug', 'data-machine' ),
						),
						'defaults'     => array(
							'type'        => 'object',
							'description' => __( 'Default configuration values', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'handler_slug' => array( 'type' => 'string' ),
						'defaults'     => array( 'type' => 'object' ),
						'message'      => array( 'type' => 'string' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdateHandlerDefaults' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function checkPermission(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	public function executeGetSettings( array $input ): array {
		$settings = PluginSettings::all();

		$tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
		$global_tools = $tool_manager->get_global_tools();
		$tools_keyed  = array();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			$tools_keyed[ $tool_name ] = array(
				'label'                  => $tool_config['label'] ?? ucfirst( str_replace( '_', ' ', $tool_name ) ),
				'description'            => $tool_config['description'] ?? '',
				'is_configured'          => $tool_manager->is_tool_configured( $tool_name ),
				'requires_configuration' => $tool_manager->requires_configuration( $tool_name ),
				'is_enabled'             => isset( $settings['enabled_tools'][ $tool_name ] ),
			);
		}

		$raw_keys    = apply_filters( 'chubes_ai_provider_api_keys', null ) ?? array();
		$masked_keys = array();
		foreach ( $raw_keys as $provider => $key ) {
			if ( ! empty( $key ) ) {
				if ( strlen( $key ) > 12 ) {
					$masked_keys[ $provider ] = substr( $key, 0, 4 ) . '****************' . substr( $key, -4 );
				} else {
					$masked_keys[ $provider ] = '****************';
				}
			} else {
				$masked_keys[ $provider ] = '';
			}
		}

		return array(
			'success'      => true,
			'settings'     => array(
				'cleanup_job_data_on_failure'    => $settings['cleanup_job_data_on_failure'] ?? true,
				'file_retention_days'            => $settings['file_retention_days'] ?? 7,
				'chat_retention_days'            => $settings['chat_retention_days'] ?? 90,
				'chat_ai_titles_enabled'         => $settings['chat_ai_titles_enabled'] ?? true,
				'alt_text_auto_generate_enabled' => $settings['alt_text_auto_generate_enabled'] ?? true,
				'problem_flow_threshold'         => $settings['problem_flow_threshold'] ?? 3,
				'flows_per_page'              => $settings['flows_per_page'] ?? 20,
				'jobs_per_page'               => $settings['jobs_per_page'] ?? 50,
				'global_system_prompt'        => $settings['global_system_prompt'] ?? '',
				'site_context_enabled'        => $settings['site_context_enabled'] ?? false,
				'default_provider'            => $settings['default_provider'] ?? '',
				'default_model'               => $settings['default_model'] ?? '',
				'max_turns'                   => $settings['max_turns'] ?? 12,
				'enabled_tools'               => $settings['enabled_tools'] ?? array(),
				'ai_provider_keys'            => $masked_keys,
			),
			'global_tools' => $tools_keyed,
		);
	}

	public function executeUpdateSettings( array $input ): array {
		$all_settings = get_option( 'datamachine_settings', array() );

		if ( isset( $input['cleanup_job_data_on_failure'] ) ) {
			$all_settings['cleanup_job_data_on_failure'] = (bool) $input['cleanup_job_data_on_failure'];
		}

		if ( isset( $input['file_retention_days'] ) ) {
			$days                                = absint( $input['file_retention_days'] );
			$all_settings['file_retention_days'] = max( 1, min( 90, $days ) );
		}

		if ( isset( $input['chat_retention_days'] ) ) {
			$days                                = absint( $input['chat_retention_days'] );
			$all_settings['chat_retention_days'] = max( 1, min( 365, $days ) );
		}

		if ( isset( $input['chat_ai_titles_enabled'] ) ) {
			$all_settings['chat_ai_titles_enabled'] = (bool) $input['chat_ai_titles_enabled'];
		}

		if ( isset( $input['alt_text_auto_generate_enabled'] ) ) {
			$all_settings['alt_text_auto_generate_enabled'] = (bool) $input['alt_text_auto_generate_enabled'];
		}

		if ( isset( $input['problem_flow_threshold'] ) ) {
			$threshold                              = absint( $input['problem_flow_threshold'] );
			$all_settings['problem_flow_threshold'] = max( 1, min( 10, $threshold ) );
		}

		if ( isset( $input['flows_per_page'] ) ) {
			$flows_per_page                 = absint( $input['flows_per_page'] );
			$all_settings['flows_per_page'] = max( 5, min( 100, $flows_per_page ) );
		}

		if ( isset( $input['jobs_per_page'] ) ) {
			$jobs_per_page                 = absint( $input['jobs_per_page'] );
			$all_settings['jobs_per_page'] = max( 5, min( 100, $jobs_per_page ) );
		}

		if ( isset( $input['global_system_prompt'] ) ) {
			$all_settings['global_system_prompt'] = wp_kses_post( $input['global_system_prompt'] );
		}

		if ( isset( $input['site_context_enabled'] ) ) {
			$all_settings['site_context_enabled'] = (bool) $input['site_context_enabled'];
		}

		if ( isset( $input['default_provider'] ) ) {
			$all_settings['default_provider'] = sanitize_text_field( $input['default_provider'] );
		}

		if ( isset( $input['default_model'] ) ) {
			$all_settings['default_model'] = sanitize_text_field( $input['default_model'] );
		}

		if ( isset( $input['max_turns'] ) ) {
			$turns                     = absint( $input['max_turns'] );
			$all_settings['max_turns'] = max( 1, min( 50, $turns ) );
		}

		if ( isset( $input['enabled_tools'] ) ) {
			$all_settings['enabled_tools'] = array();
			foreach ( $input['enabled_tools'] as $tool_id => $enabled ) {
				if ( $enabled ) {
					$all_settings['enabled_tools'][ sanitize_key( $tool_id ) ] = true;
				}
			}
		}

		if ( isset( $input['ai_provider_keys'] ) && is_array( $input['ai_provider_keys'] ) ) {
			$current_keys = apply_filters( 'chubes_ai_provider_api_keys', null );
			if ( ! is_array( $current_keys ) ) {
				$current_keys = array();
			}
			foreach ( $input['ai_provider_keys'] as $provider => $key ) {
				$provider_key = sanitize_key( $provider );
				$new_key      = sanitize_text_field( $key );

				if ( strpos( $new_key, '****' ) === false ) {
					$current_keys[ $provider_key ] = $new_key;
				}
			}
			apply_filters( 'chubes_ai_provider_api_keys', $current_keys );
		}

		update_option( 'datamachine_settings', $all_settings );
		PluginSettings::clearCache();

		return array(
			'success' => true,
			'message' => __( 'Settings saved successfully.', 'data-machine' ),
		);
	}

	public function executeGetSchedulingIntervals( array $input ): array {
		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );

		$frontend_intervals = array();

		$frontend_intervals[] = array(
			'value' => 'manual',
			'label' => __( 'Manual only', 'data-machine' ),
		);

		foreach ( $intervals as $key => $interval_data ) {
			$frontend_intervals[] = array(
				'value' => $key,
				'label' => $interval_data['label'],
			);
		}

		return array(
			'success'   => true,
			'intervals' => $frontend_intervals,
		);
	}

	public function executeGetToolConfig( array $input ): array {
		$tool_id = $input['tool_id'] ?? '';

		if ( empty( $tool_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Tool ID is required.', 'data-machine' ),
			);
		}

		$tool_manager    = new \DataMachine\Engine\AI\Tools\ToolManager();
		$global_tools    = $tool_manager->get_global_tools();
		$tool_definition = $global_tools[ $tool_id ] ?? null;

		if ( empty( $tool_definition ) || ! is_array( $tool_definition ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: tool ID */
					__( 'Unknown tool: %s', 'data-machine' ),
					$tool_id
				),
			);
		}

		$requires_configuration = $tool_manager->requires_configuration( $tool_id );
		$is_configured          = $tool_manager->is_tool_configured( $tool_id );

		$fields = array();
		$config = array();

		if ( $requires_configuration ) {
			$fields = apply_filters( 'datamachine_get_tool_config_fields', array(), $tool_id );
			$config = apply_filters( 'datamachine_get_tool_config', array(), $tool_id );

			if ( ! is_array( $fields ) ) {
				$fields = array();
			}
			if ( ! is_array( $config ) ) {
				$config = array();
			}

			foreach ( $fields as $field_key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$field_type = $field['type'] ?? '';
				if ( in_array( $field_type, array( 'password', 'secret' ), true ) && ! empty( $config[ $field_key ] ) ) {
					$value = (string) $config[ $field_key ];
					if ( strlen( $value ) > 12 ) {
						$config[ $field_key ] = substr( $value, 0, 4 ) . '****************' . substr( $value, -4 );
					} else {
						$config[ $field_key ] = '****************';
					}
				}
			}
		}

		return array(
			'success'                => true,
			'tool_id'                => $tool_id,
			'label'                  => $tool_definition['label'] ?? ucfirst( str_replace( '_', ' ', $tool_id ) ),
			'description'            => $tool_definition['description'] ?? '',
			'requires_configuration' => $requires_configuration,
			'is_configured'          => $is_configured,
			'fields'                 => $fields,
			'config'                 => $config,
		);
	}

	public function executeSaveToolConfig( array $input ): array {
		$tool_id     = $input['tool_id'] ?? '';
		$config_data = $input['config_data'] ?? array();

		if ( empty( $tool_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Tool ID is required.', 'data-machine' ),
			);
		}

		if ( empty( $config_data ) || ! is_array( $config_data ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Valid configuration data is required.', 'data-machine' ),
			);
		}

		$tool_manager    = new \DataMachine\Engine\AI\Tools\ToolManager();
		$global_tools    = $tool_manager->get_global_tools();
		$tool_definition = $global_tools[ $tool_id ] ?? null;

		if ( empty( $tool_definition ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: tool ID */
					__( 'Unknown tool: %s', 'data-machine' ),
					$tool_id
				),
			);
		}

		$sanitized_config = array();
		foreach ( $config_data as $key => $value ) {
			$sanitized_key                      = sanitize_text_field( $key );
			$sanitized_config[ $sanitized_key ] = is_array( $value )
				? array_map( 'sanitize_text_field', $value )
				: sanitize_text_field( $value );
		}

		$result = array(
			'success' => false,
			'error'   => sprintf(
				/* translators: %s: tool ID */
				__( 'No configuration handler found for tool: %s', 'data-machine' ),
				$tool_id
			),
		);

		$handler_fired = apply_filters( 'datamachine_save_tool_config_ability', false, $tool_id, $sanitized_config );

		if ( $handler_fired ) {
			return array(
				'success' => true,
				'tool_id' => $tool_id,
				'message' => sprintf(
					/* translators: %s: tool ID */
					__( 'Configuration saved for tool: %s', 'data-machine' ),
					$tool_id
				),
			);
		}

		do_action( 'datamachine_save_tool_config', $tool_id, $sanitized_config );

		return $result;
	}

	public function executeGetHandlerDefaults( array $input ): array {
		$defaults = get_option( self::HANDLER_DEFAULTS_OPTION, null );

		if ( null === $defaults ) {
			$defaults = $this->buildInitialHandlerDefaults();
			update_option( self::HANDLER_DEFAULTS_OPTION, $defaults );
		}

		$handler_abilities   = new HandlerAbilities();
		$step_type_abilities = new StepTypeAbilities();
		$step_types          = $step_type_abilities->getAllStepTypes();

		$grouped = array();
		foreach ( $step_types as $step_type_slug => $step_type_config ) {
			$uses_handler = $step_type_config['uses_handler'] ?? true;
			if ( ! $uses_handler ) {
				$grouped[ $step_type_slug ] = array(
					'label'        => $step_type_config['label'] ?? $step_type_slug,
					'uses_handler' => false,
					'handlers'     => array(),
				);
				continue;
			}

			$handlers         = $handler_abilities->getAllHandlers( $step_type_slug );
			$handler_defaults = array();

			foreach ( $handlers as $handler_slug => $handler_info ) {
				$handler_defaults[ $handler_slug ] = array(
					'label'       => $handler_info['label'] ?? $handler_slug,
					'description' => $handler_info['description'] ?? '',
					'defaults'    => $defaults[ $handler_slug ] ?? array(),
					'fields'      => $handler_abilities->getConfigFields( $handler_slug ),
				);
			}

			$grouped[ $step_type_slug ] = array(
				'label'        => $step_type_config['label'] ?? $step_type_slug,
				'uses_handler' => true,
				'handlers'     => $handler_defaults,
			);
		}

		return array(
			'success'  => true,
			'defaults' => $grouped,
		);
	}

	public function executeUpdateHandlerDefaults( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? '';
		$new_defaults = $input['defaults'] ?? array();

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required.', 'data-machine' ),
			);
		}

		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_slug );

		if ( ! $handler_info ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: handler slug */
					__( 'Handler "%s" not found.', 'data-machine' ),
					$handler_slug
				),
			);
		}

		$all_defaults = get_option( self::HANDLER_DEFAULTS_OPTION, array() );

		$sanitized_defaults            = $this->sanitizeSettingsArray( $new_defaults );
		$all_defaults[ $handler_slug ] = $sanitized_defaults;

		$updated = update_option( self::HANDLER_DEFAULTS_OPTION, $all_defaults );

		if ( ! $updated && get_option( self::HANDLER_DEFAULTS_OPTION ) !== $all_defaults ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to update handler defaults.', 'data-machine' ),
			);
		}

		return array(
			'success'      => true,
			'handler_slug' => $handler_slug,
			'defaults'     => $sanitized_defaults,
			'message'      => sprintf(
				/* translators: %s: handler slug */
				__( 'Defaults updated for handler "%s".', 'data-machine' ),
				$handler_slug
			),
		);
	}

	private function buildInitialHandlerDefaults(): array {
		$handler_abilities   = new HandlerAbilities();
		$step_type_abilities = new StepTypeAbilities();

		$defaults   = array();
		$step_types = $step_type_abilities->getAllStepTypes();

		foreach ( $step_types as $step_type_slug => $step_type_config ) {
			$uses_handler = $step_type_config['uses_handler'] ?? true;
			if ( ! $uses_handler ) {
				continue;
			}

			$handlers = $handler_abilities->getAllHandlers( $step_type_slug );

			foreach ( $handlers as $handler_slug => $handler_info ) {
				$fields           = $handler_abilities->getConfigFields( $handler_slug );
				$handler_defaults = array();

				foreach ( $fields as $field_key => $field_config ) {
					if ( isset( $field_config['default'] ) ) {
						$handler_defaults[ $field_key ] = $field_config['default'];
					}
				}

				if ( ! empty( $handler_defaults ) ) {
					$defaults[ $handler_slug ] = $handler_defaults;
				}
			}
		}

		return $defaults;
	}

	private function sanitizeSettingsArray( array $settings ): array {
		$sanitized = array();

		foreach ( $settings as $key => $value ) {
			$sanitized_key = sanitize_text_field( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitizeSettingsArray( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $sanitized_key ] = (bool) $value;
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $sanitized_key ] = is_float( $value ) ? (float) $value : (int) $value;
			} else {
				$sanitized[ $sanitized_key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}
}
