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

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\NetworkSettings;
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
						'defaults'     => array( 'type' => 'object' ),
						'network_settings' => array( 'type' => 'object' ),
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
						'cleanup_job_data_on_failure'    => array( 'type' => 'boolean' ),
						'file_retention_days'            => array( 'type' => 'integer' ),
						'chat_retention_days'            => array( 'type' => 'integer' ),
						'chat_ai_titles_enabled'         => array( 'type' => 'boolean' ),
						'alt_text_auto_generate_enabled' => array( 'type' => 'boolean' ),
						'problem_flow_threshold'         => array( 'type' => 'integer' ),
						'flows_per_page'                 => array( 'type' => 'integer' ),
						'jobs_per_page'                  => array( 'type' => 'integer' ),
						'site_context_enabled'           => array( 'type' => 'boolean' ),
						'daily_memory_enabled'           => array( 'type' => 'boolean' ),
						'default_provider'               => array( 'type' => 'string' ),
						'default_model'                  => array( 'type' => 'string' ),
						'agent_models'                   => array(
							'type'        => 'object',
							'description' => 'Per-agent-type provider/model overrides keyed by agent type id',
						),
						'max_turns'                      => array( 'type' => 'integer' ),
						'disabled_tools'                 => array( 'type' => 'object' ),
						'ai_provider_keys'               => array( 'type' => 'object' ),
						'queue_tuning'                   => array(
							'type'        => 'object',
							'description' => 'Action Scheduler queue tuning settings',
							'properties'  => array(
								'concurrent_batches' => array( 'type' => 'integer' ),
								'batch_size'         => array( 'type' => 'integer' ),
								'time_limit'         => array( 'type' => 'integer' ),
							),
						),
						'github_pat'                     => array(
							'type'        => 'string',
							'description' => 'GitHub Personal Access Token for GitHub integration.',
						),
						'github_default_repo'            => array(
							'type'        => 'string',
							'description' => 'Default GitHub repository in owner/repo format.',
						),
						'network_settings'               => array(
							'type'        => 'object',
							'description' => 'Network-wide defaults (multisite). Keys: default_provider, default_model, agent_models.',
						),
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
		return PermissionHelper::can_manage();
	}

	public function executeGetSettings( array $input ): array {
		$input;
		$settings = PluginSettings::all();
		$defaults = PluginSettings::getDefaults();

		$tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
		$global_tools = $tool_manager->get_global_tools();
		$tools_keyed  = array();
		foreach ( $global_tools as $tool_name => $tool_config ) {
			$tools_keyed[ $tool_name ] = array(
				'label'                  => $tool_config['label'] ?? ucfirst( str_replace( '_', ' ', $tool_name ) ),
				'description'            => $tool_config['description'] ?? '',
				'is_configured'          => $tool_manager->is_tool_configured( $tool_name ),
				'requires_configuration' => $tool_manager->requires_configuration( $tool_name ),
				'is_enabled'             => ! isset( $settings['disabled_tools'][ $tool_name ] ),
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

		$network_defaults = NetworkSettings::all();

		return array(
			'success'          => true,
			'settings'         => array(
				'cleanup_job_data_on_failure'    => $settings['cleanup_job_data_on_failure'] ?? true,
				'file_retention_days'            => $settings['file_retention_days'] ?? 7,
				'chat_retention_days'            => $settings['chat_retention_days'] ?? 90,
				'chat_ai_titles_enabled'         => $settings['chat_ai_titles_enabled'] ?? true,
				'alt_text_auto_generate_enabled' => $settings['alt_text_auto_generate_enabled'] ?? true,
				'problem_flow_threshold'         => $settings['problem_flow_threshold'] ?? 3,
				'flows_per_page'                 => $settings['flows_per_page'] ?? 20,
				'jobs_per_page'                  => $settings['jobs_per_page'] ?? 50,
				'site_context_enabled'           => $settings['site_context_enabled'] ?? false,
				'daily_memory_enabled'           => $settings['daily_memory_enabled'] ?? false,
				'default_provider'               => $settings['default_provider'] ?? '',
				'default_model'                  => $settings['default_model'] ?? '',
				'agent_models'                   => $settings['agent_models'] ?? array(),
				'max_turns'                      => $settings['max_turns'] ?? $defaults['max_turns'],
				'disabled_tools'                 => $settings['disabled_tools'] ?? array(),
				'ai_provider_keys'               => $masked_keys,
				'queue_tuning'                   => wp_parse_args( $settings['queue_tuning'] ?? array(), $defaults['queue_tuning'] ),
			),
			'defaults'         => $defaults,
			'network_settings' => array(
				'default_provider' => $network_defaults['default_provider'] ?? '',
				'default_model'    => $network_defaults['default_model'] ?? '',
				'agent_models'     => $network_defaults['agent_models'] ?? array(),
			),
			'global_tools'     => $tools_keyed,
		);
	}

	public function executeUpdateSettings( array $input ): array {
		$all_settings = get_option( 'datamachine_settings', array() );
		$handled_keys = array();

		if ( isset( $input['cleanup_job_data_on_failure'] ) ) {
			$all_settings['cleanup_job_data_on_failure'] = (bool) $input['cleanup_job_data_on_failure'];
			$handled_keys[]                              = 'cleanup_job_data_on_failure';
		}

		if ( isset( $input['file_retention_days'] ) ) {
			$days                                = absint( $input['file_retention_days'] );
			$all_settings['file_retention_days'] = max( 1, min( 90, $days ) );
			$handled_keys[]                      = 'file_retention_days';
		}

		if ( isset( $input['chat_retention_days'] ) ) {
			$days                                = absint( $input['chat_retention_days'] );
			$all_settings['chat_retention_days'] = max( 1, min( 365, $days ) );
			$handled_keys[]                      = 'chat_retention_days';
		}

		if ( isset( $input['chat_ai_titles_enabled'] ) ) {
			$all_settings['chat_ai_titles_enabled'] = (bool) $input['chat_ai_titles_enabled'];
			$handled_keys[]                         = 'chat_ai_titles_enabled';
		}

		if ( isset( $input['alt_text_auto_generate_enabled'] ) ) {
			$all_settings['alt_text_auto_generate_enabled'] = (bool) $input['alt_text_auto_generate_enabled'];
			$handled_keys[]                                 = 'alt_text_auto_generate_enabled';
		}

		if ( isset( $input['problem_flow_threshold'] ) ) {
			$threshold                              = absint( $input['problem_flow_threshold'] );
			$all_settings['problem_flow_threshold'] = max( 1, min( 10, $threshold ) );
			$handled_keys[]                         = 'problem_flow_threshold';
		}

		if ( isset( $input['flows_per_page'] ) ) {
			$flows_per_page                 = absint( $input['flows_per_page'] );
			$all_settings['flows_per_page'] = max( 5, min( 100, $flows_per_page ) );
			$handled_keys[]                 = 'flows_per_page';
		}

		if ( isset( $input['jobs_per_page'] ) ) {
			$jobs_per_page                 = absint( $input['jobs_per_page'] );
			$all_settings['jobs_per_page'] = max( 5, min( 100, $jobs_per_page ) );
			$handled_keys[]                = 'jobs_per_page';
		}

		if ( isset( $input['site_context_enabled'] ) ) {
			$all_settings['site_context_enabled'] = (bool) $input['site_context_enabled'];
			$handled_keys[]                       = 'site_context_enabled';
		}

		if ( isset( $input['daily_memory_enabled'] ) ) {
			$all_settings['daily_memory_enabled'] = (bool) $input['daily_memory_enabled'];
			$handled_keys[]                       = 'daily_memory_enabled';
		}

		if ( isset( $input['default_provider'] ) ) {
			$all_settings['default_provider'] = sanitize_text_field( $input['default_provider'] );
			$handled_keys[]                   = 'default_provider';
		}

		if ( isset( $input['default_model'] ) ) {
			$all_settings['default_model'] = sanitize_text_field( $input['default_model'] );
			$handled_keys[]                = 'default_model';
		}

		if ( isset( $input['agent_models'] ) && is_array( $input['agent_models'] ) ) {
			$valid_agent_ids = array_column( PluginSettings::getAgentTypes(), 'id' );
			$agent_models    = array();
			foreach ( $input['agent_models'] as $agent_type => $config ) {
				if ( in_array( $agent_type, $valid_agent_ids, true ) && is_array( $config ) ) {
					$agent_models[ $agent_type ] = array(
						'provider' => sanitize_text_field( $config['provider'] ?? '' ),
						'model'    => sanitize_text_field( $config['model'] ?? '' ),
					);
				}
			}
			$all_settings['agent_models'] = $agent_models;
			$handled_keys[]               = 'agent_models';
		}

		if ( isset( $input['max_turns'] ) ) {
			$turns                     = absint( $input['max_turns'] );
			$all_settings['max_turns'] = max( 1, min( 50, $turns ) );
			$handled_keys[]            = 'max_turns';
		}

		if ( isset( $input['disabled_tools'] ) ) {
			$all_settings['disabled_tools'] = array();
			foreach ( $input['disabled_tools'] as $tool_id => $disabled ) {
				if ( $disabled ) {
					$all_settings['disabled_tools'][ sanitize_key( $tool_id ) ] = true;
				}
			}
			$handled_keys[] = 'disabled_tools';
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
			$handled_keys[] = 'ai_provider_keys';
		}

		// Queue tuning settings for Action Scheduler
		if ( isset( $input['queue_tuning'] ) && is_array( $input['queue_tuning'] ) ) {
			$tuning = wp_parse_args( $all_settings['queue_tuning'] ?? array(), PluginSettings::getDefaultQueueTuning() );

			if ( isset( $input['queue_tuning']['concurrent_batches'] ) ) {
				$batches                      = absint( $input['queue_tuning']['concurrent_batches'] );
				$tuning['concurrent_batches'] = max( 1, min( 10, $batches ) ); // 1-10 range
			}

			if ( isset( $input['queue_tuning']['batch_size'] ) ) {
				$size                 = absint( $input['queue_tuning']['batch_size'] );
				$tuning['batch_size'] = max( 10, min( 200, $size ) ); // 10-200 range
			}

			if ( isset( $input['queue_tuning']['time_limit'] ) ) {
				$limit                = absint( $input['queue_tuning']['time_limit'] );
				$tuning['time_limit'] = max( 15, min( 300, $limit ) ); // 15-300 seconds range
			}

			$all_settings['queue_tuning'] = $tuning;
			$handled_keys[]               = 'queue_tuning';
		}

		// GitHub integration settings.
		if ( isset( $input['github_pat'] ) ) {
			$all_settings['github_pat'] = sanitize_text_field( $input['github_pat'] );
			$handled_keys[]             = 'github_pat';
		}

		if ( isset( $input['github_default_repo'] ) ) {
			$all_settings['github_default_repo'] = sanitize_text_field( $input['github_default_repo'] );
			$handled_keys[]                      = 'github_default_repo';
		}

		// Network-wide defaults (requires super admin on multisite).
		if ( isset( $input['network_settings'] ) && is_array( $input['network_settings'] ) ) {
			if ( ! is_multisite() || is_super_admin() ) {
				$network_values = array();

				if ( isset( $input['network_settings']['default_provider'] ) ) {
					$network_values['default_provider'] = sanitize_text_field( $input['network_settings']['default_provider'] );
				}

				if ( isset( $input['network_settings']['default_model'] ) ) {
					$network_values['default_model'] = sanitize_text_field( $input['network_settings']['default_model'] );
				}

				if ( isset( $input['network_settings']['agent_models'] ) && is_array( $input['network_settings']['agent_models'] ) ) {
					$valid_agent_ids      = array_column( PluginSettings::getAgentTypes(), 'id' );
					$network_agent_models = array();
					foreach ( $input['network_settings']['agent_models'] as $agent_type => $config ) {
						if ( in_array( $agent_type, $valid_agent_ids, true ) && is_array( $config ) ) {
							$network_agent_models[ $agent_type ] = array(
								'provider' => sanitize_text_field( $config['provider'] ?? '' ),
								'model'    => sanitize_text_field( $config['model'] ?? '' ),
							);
						}
					}
					$network_values['agent_models'] = $network_agent_models;
				}

				if ( ! empty( $network_values ) ) {
					NetworkSettings::update( $network_values );
				}
			}
			$handled_keys[] = 'network_settings';
		}

		/**
		 * Filter to allow extensions to handle their own settings keys.
		 *
		 * Extensions can modify $all_settings for keys they own and add
		 * those keys to $handled_keys so the CLI knows they were processed.
		 *
		 * @since 0.40.0
		 *
		 * @param array $all_settings  Current settings array (will be saved to DB).
		 * @param array $input         Raw input from the settings update request.
		 * @param array $handled_keys  Keys already handled by core.
		 * @return array Associative array with 'settings' and 'handled_keys'.
		 */
		$filtered = apply_filters(
			'datamachine_update_settings',
			array(
				'settings'     => $all_settings,
				'handled_keys' => $handled_keys,
			),
			$input
		);

		$all_settings = $filtered['settings'] ?? $all_settings;
		$handled_keys = $filtered['handled_keys'] ?? $handled_keys;

		update_option( 'datamachine_settings', $all_settings );
		PluginSettings::clearCache();

		// Identify unhandled keys so callers know if something was ignored.
		$input_keys    = array_keys( $input );
		$unhandled     = array_diff( $input_keys, $handled_keys );

		$result = array(
			'success' => true,
			'message' => __( 'Settings saved successfully.', 'data-machine' ),
		);

		if ( ! empty( $unhandled ) ) {
			$result['unhandled_keys'] = array_values( $unhandled );
		}

		return $result;
	}

	public function executeGetSchedulingIntervals( array $input ): array {
		$input;
		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );

		$frontend_intervals = array();

		$frontend_intervals[] = array(
			'value' => 'manual',
			'label' => __( 'Manual only', 'data-machine' ),
		);

		$frontend_intervals[] = array(
			'value' => 'one_time',
			'label' => __( 'One Time (scheduled)', 'data-machine' ),
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

		/**
		 * Save tool configuration via registered handlers.
		 *
		 * Tools hook into this filter via BaseTool::registerConfigurationHandlers().
		 * Each handler checks if it owns the tool_id and returns a result array
		 * with 'success' and 'message' or 'error' keys. Handlers that don't own
		 * the tool_id pass through the $result unchanged.
		 *
		 * @since 0.36.0
		 *
		 * @param array|null $result         Result from a previous handler, or null if none handled it yet.
		 * @param string     $tool_id        Tool identifier.
		 * @param array      $sanitized_config Sanitized configuration data.
		 */
		$result = apply_filters( 'datamachine_save_tool_config', null, $tool_id, $sanitized_config );

		if ( is_array( $result ) && isset( $result['success'] ) ) {
			if ( $result['success'] ) {
				$result['tool_id'] = $tool_id;
			}
			return $result;
		}

		return array(
			'success' => false,
			'error'   => sprintf(
				/* translators: %s: tool ID */
				__( 'No configuration handler found for tool: %s', 'data-machine' ),
				$tool_id
			),
		);
	}

	public function executeGetHandlerDefaults( array $input ): array {
		$input;
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
