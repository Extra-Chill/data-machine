<?php
/**
 * Handler Detail Ability
 *
 * Complete handler details for the admin settings UI: basic info, the
 * settings-display field state (with site-wide defaults applied), and the
 * handler's AI tool definition. Falls back to step-type settings for
 * step types that register settings without being handlers (webhook_gate).
 * Owns the behavior formerly exposed by the datamachine/v1
 * /handlers/{slug} wrapper route (see #3456).
 *
 * @package DataMachine\Abilities\Handler
 * @since 0.177.0
 */

namespace DataMachine\Abilities\Handler;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class HandlerDetailAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function (): void {
			wp_register_ability(
				'datamachine/get-handler-detail',
				array(
					'label'               => __( 'Get Handler Detail', 'data-machine' ),
					'description'         => __( 'Get complete handler details: info, settings field state with site defaults applied, and the AI tool definition.', 'data-machine' ),
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'handler_slug' ),
						'properties' => array(
							'handler_slug' => array(
								'type'        => 'string',
								'description' => __( 'Handler slug (e.g., twitter, rss, wordpress_publish) or step type slug', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'slug'     => array( 'type' => 'string' ),
							'info'     => array( 'type' => 'object' ),
							'settings' => array( 'type' => 'object' ),
							'ai_tool'  => array(
								'anyOf' => array(
									array( 'type' => 'object' ),
									array( 'type' => 'null' ),
								),
							),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Permission callback for the ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get-handler-detail ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Handler detail payload or a failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$handler_slug = $input['handler_slug'] ?? '';

		if ( ! is_string( $handler_slug ) || '' === $handler_slug ) {
			return new \WP_Error( 'handler_slug_required', 'handler_slug is required', array( 'status' => 400 ) );
		}

		$handler_abilities = new HandlerAbilities();
		$handler_info      = $handler_abilities->getHandler( $handler_slug );

		// Fall back to step type settings if not a handler.
		// Step types like webhook_gate register their own settings
		// via datamachine_handler_settings but are not in the handlers list.
		if ( ! $handler_info ) {
			$settings_display_service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
			$field_state              = $settings_display_service->getFieldState( $handler_slug, array() );

			if ( ! empty( $field_state ) ) {
				// Resolve label from step types registry.
				$step_types = apply_filters( 'datamachine_step_types', array() );
				$step_label = $step_types[ $handler_slug ]['label'] ?? $handler_slug;

				return array(
					'success'  => true,
					'slug'     => $handler_slug,
					'info'     => array(
						'label'       => $step_label,
						'description' => $step_types[ $handler_slug ]['description'] ?? '',
						'type'        => 'step_type',
					),
					'settings' => $field_state,
					'ai_tool'  => null,
				);
			}

			return new \WP_Error( 'handler_not_found', 'Handler not found', array( 'status' => 404 ) );
		}

		// Get site-wide handler defaults for this handler.
		$site_defaults    = $handler_abilities->getSiteDefaults();
		$handler_defaults = $site_defaults[ $handler_slug ] ?? array();

		// Get field state, using site-wide defaults as base settings.
		$settings_display_service = new \DataMachine\Core\Steps\Settings\SettingsDisplayService();
		$field_state              = $settings_display_service->getFieldState( $handler_slug, $handler_defaults );

		// Resolve handler tool definition from the unified registry using
		// empty handler_config/engine_data — the admin details endpoint wants
		// the shape-at-registration-time, not a pipeline-specific rendering.
		$ai_tool      = null;
		$tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
		$tools        = $tool_manager->resolveHandlerTools( $handler_slug, array(), array() );

		foreach ( $tools as $tool_name => $tool_def ) {
			if ( ( $tool_def['handler'] ?? '' ) !== $handler_slug ) {
				continue;
			}
			$ai_tool = array(
				'tool_name'   => $tool_name,
				'description' => $tool_def['description'] ?? '',
				'parameters'  => $tool_def['parameters'] ?? array(),
			);
			break;
		}

		return array(
			'success'  => true,
			'slug'     => $handler_slug,
			'info'     => $handler_info,
			'settings' => $field_state,
			'ai_tool'  => $ai_tool,
		);
	}
}
