<?php
/**
 * Bluesky Publish Handler Settings
 *
 * Defines settings fields and sanitization for Bluesky publish handler.
 * Extends base publish handler settings with Bluesky-specific options.
 *
 * @package    DataMachineSocials
 * @subpackage Handlers\Bluesky
 * @since      0.8.2
 */

namespace DataMachineSocials\Handlers\Bluesky;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class BlueskySettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for Bluesky publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'link_handling' => array(
					'type'        => 'select',
					'label'       => __( 'Source URL Handling', 'data-machine-socials' ),
					'description' => __( 'Choose how to handle source URLs when posting to Bluesky.', 'data-machine-socials' ),
					'options'     => array(
						'none'   => __( 'No URL - exclude source link entirely', 'data-machine-socials' ),
						'append' => __( 'Append to post - add URL to post content (if it fits in 300 chars)', 'data-machine-socials' ),
						'card'   => __( 'Link card - attach as rich link card embed', 'data-machine-socials' ),
					),
					'default'     => 'card',
				),
			)
		);
	}
}
