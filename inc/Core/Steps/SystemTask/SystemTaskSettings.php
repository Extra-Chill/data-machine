<?php
/**
 * System Task Step Settings
 *
 * Defines configuration fields for the System Task step type.
 * Used by the admin UI to render configuration forms.
 *
 * @package DataMachine\Core\Steps\SystemTask
 * @since 0.34.0
 */

namespace DataMachine\Core\Steps\SystemTask;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SystemTaskSettings extends SettingsHandler {

	/**
	 * Get settings fields for System Task step.
	 *
	 * @return array Field definitions for the configuration UI.
	 */
	public static function get_fields(): array {
		return array(
			'task'   => array(
				'type'        => 'select',
				'label'       => __( 'Task Type', 'data-machine' ),
				'description' => __( 'The system task to execute. Available tasks are registered by Data Machine and plugins.', 'data-machine' ),
				'required'    => true,
				'options'     => self::getTaskOptions(),
			),
			'params' => array(
				'type'        => 'json',
				'label'       => __( 'Task Parameters', 'data-machine' ),
				'description' => __( 'JSON object of task-specific parameters. Pipeline context (post_id) is injected automatically.', 'data-machine' ),
				'default'     => '{}',
			),
		);
	}

	/**
	 * Get available task types as select options.
	 *
	 * Reads from the System Agent's registered task handlers.
	 *
	 * @return array<string, string> Task type slug => display label.
	 */
	private static function getTaskOptions(): array {
		$options = array();

		// SystemAgent may not be initialized during early bootstrap.
		if ( ! class_exists( '\DataMachine\Engine\AI\System\SystemAgent' ) ) {
			return $options;
		}

		$system_agent = \DataMachine\Engine\AI\System\SystemAgent::getInstance();
		$handlers     = $system_agent->getTaskHandlers();

		foreach ( $handlers as $task_type => $handler_class ) {
			$label = ucfirst( str_replace( '_', ' ', $task_type ) );

			// Use task metadata label if available.
			if ( method_exists( $handler_class, 'getTaskMeta' ) ) {
				$meta = $handler_class::getTaskMeta();
				if ( ! empty( $meta['label'] ) ) {
					$label = $meta['label'];
				}
			}

			$options[ $task_type ] = $label;
		}

		return $options;
	}
}
