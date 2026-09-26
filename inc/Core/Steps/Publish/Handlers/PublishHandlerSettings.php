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
			'link_handling'       => array(
				'type'        => 'select',
				'label'       => __( 'Source URL Handling', 'data-machine' ),
				'description' => __( 'Choose how to handle source URLs when publishing.', 'data-machine' ),
				'options'     => array(
					'none'   => __( 'No URL - exclude source link entirely', 'data-machine' ),
					'append' => __( 'Append to content - add URL to post content', 'data-machine' ),
				),
				'default'     => 'append',
			),
			'include_images'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Image Posting', 'data-machine' ),
				'description' => __( 'Include images when available in source data.', 'data-machine' ),
				'default'     => false,
			),
			'dedup_enabled'       => array(
				'type'        => 'checkbox',
				'label'       => __( 'Duplicate Detection', 'data-machine' ),
				'description' => __( 'Skip publishing if a post with a similar title or source URL already exists. Prevents duplicate posts from parallel jobs, cross-flow overlap, and retry scenarios. Enabled by default.', 'data-machine' ),
				'default'     => true,
			),
			'dedup_lookback_days' => array(
				'type'        => 'number',
				'label'       => __( 'Duplicate Lookback (days)', 'data-machine' ),
				'description' => __( 'How many days back to search for duplicate titles. Only used when duplicate detection is enabled.', 'data-machine' ),
				'default'     => 14,
				'min'         => 1,
				'max'         => 90,
			),
		);
	}
}
