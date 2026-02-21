<?php
/**
 * Twitter Publish Handler Settings
 *
 * Defines settings fields and sanitization for Twitter publish handler.
 * Extends base publish handler settings with Twitter-specific options.
 *
 * @package DataMachineSocials
 * @since 0.1.0
 */

namespace DataMachineSocials\Handlers\Twitter;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class TwitterSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for Twitter publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'link_handling' => array(
					'type' => 'select',
					'label' => __( 'Source URL Handling', 'data-machine-socials' ),
					'description' => __( 'Choose how to handle source URLs when posting to Twitter.', 'data-machine-socials' ),
					'options' => array(
						'none' => __( 'No URL - exclude source link entirely', 'data-machine-socials' ),
						'append' => __( 'Append to tweet - add URL to tweet content (if it fits in 280 chars)', 'data-machine-socials' ),
						'reply' => __( 'Post as reply - create separate reply tweet with URL', 'data-machine-socials' ),
					),
					'default' => 'append',
				),
			)
		);
	}
}
