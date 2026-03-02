<?php
/**
 * Base Settings Handler for Fetch Handlers
 *
 * Provides common settings fields shared across all fetch handlers.
 * Individual fetch handlers can extend this class and override get_fields()
 * to add handler-specific customizations.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers
 * @since 0.2.1
 */

namespace DataMachine\Core\Steps\Fetch\Handlers;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

abstract class FetchHandlerSettings extends SettingsHandler {

	/**
	 * Get common fields shared across all fetch handlers.
	 *
	 * @return array Common field definitions.
	 */
	protected static function get_common_fields(): array {
		return array(
			'timeframe_limit'  => array(
				'type'        => 'select',
				'label'       => __( 'Process Items Within', 'data-machine' ),
				'description' => __( 'Only consider items published within this timeframe.', 'data-machine' ),
				'options'     => apply_filters( 'datamachine_timeframe_limit', array(), null ),
			),
			'search'           => array(
				'type'        => 'text',
				'label'       => __( 'Include Keywords', 'data-machine' ),
				'description' => __( 'Only process items containing any of these keywords (comma-separated).', 'data-machine' ),
			),
			'exclude_keywords' => array(
				'type'        => 'text',
				'label'       => __( 'Exclude Keywords', 'data-machine' ),
				'description' => __( 'Skip items containing any of these keywords (comma-separated).', 'data-machine' ),
			),
			'max_items'        => array(
				'type'        => 'number',
				'label'       => __( 'Max Items Per Run', 'data-machine' ),
				'description' => __( 'Maximum number of items to process per execution. 0 = unlimited.', 'data-machine' ),
				'default'     => 1,
				'min'         => 0,
				'max'         => 100,
			),
		);
	}
}
