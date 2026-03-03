<?php
/**
 * Base Settings Handler for Publish Handlers
 *
 * Provides common settings fields shared across all publish handlers.
 * Individual publish handlers can extend this class and override get_fields()
 * to add platform-specific customizations.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers
 * @since 0.2.1
 */

namespace DataMachine\Core\Steps\Publish\Handlers;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

abstract class PublishHandlerSettings extends SettingsHandler {

	/**
	 * Get common fields shared across all publish handlers.
	 *
	 * @return array Common field definitions.
	 */
	public static function get_common_fields(): array {
		return array(
			'link_handling'  => array(
				'type'        => 'select',
				'label'       => __( 'Source URL Handling', 'data-machine' ),
				'description' => __( 'Choose how to handle source URLs when publishing.', 'data-machine' ),
				'options'     => array(
					'none'   => __( 'No URL - exclude source link entirely', 'data-machine' ),
					'append' => __( 'Append to content - add URL to post content', 'data-machine' ),
				),
				'default'     => 'append',
			),
			'include_images' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Image Posting', 'data-machine' ),
				'description' => __( 'Include images when available in source data.', 'data-machine' ),
				'default'     => false,
			),
		);
	}
}
