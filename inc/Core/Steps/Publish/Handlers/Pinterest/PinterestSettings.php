<?php
/**
 * Pinterest Publish Handler Settings
 *
 * Defines settings fields for Pinterest publish handler.
 * Extends base publish handler settings with Pinterest-specific board configuration.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Pinterest
 * @since 0.3.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Pinterest;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest Settings Handler
 *
 * Provides settings fields for the Pinterest publish handler,
 * including default board ID configuration.
 */
class PinterestSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for Pinterest publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'board_id'             => array(
					'type'        => 'text',
					'label'       => __( 'Default Board ID', 'data-machine' ),
					'description' => __( 'Pinterest board ID to pin to. Find in board URL.', 'data-machine' ),
					'default'     => '',
				),
				'board_selection_mode' => array(
					'type'        => 'select',
					'label'       => __( 'Board Selection Mode', 'data-machine' ),
					'description' => __( 'How to choose which Pinterest board to pin to.', 'data-machine' ),
					'default'     => 'pre_selected',
					'options'     => array(
						'pre_selected'     => __( 'Pre-selected (use default board)', 'data-machine' ),
						'ai_decides'       => __( 'AI Decides (agent picks from available boards)', 'data-machine' ),
						'category_mapping' => __( 'Category Mapping (route by WordPress category)', 'data-machine' ),
					),
				),
				'board_mapping'        => array(
					'type'        => 'textarea',
					'label'       => __( 'Category → Board Mapping', 'data-machine' ),
					'description' => __( 'One mapping per line: category_slug=board_id (e.g., spirituality=123456789). Used when mode is Category Mapping.', 'data-machine' ),
					'default'     => '',
				),
			)
		);
	}
}
