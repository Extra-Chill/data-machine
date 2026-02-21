<?php
/**
* Facebook Publish Handler Settings
*
* Defines settings fields and sanitization for Facebook publish handler.
* Extends base publish handler settings with Facebook-specific options.
*
* @package Data_Machine_Socials
* @subpackage Handlers\Facebook
* @since 0.1.0
*/

namespace DataMachineSocials\Handlers\Facebook;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class FacebookSettings extends PublishHandlerSettings {

	/**
	* Get settings fields for Facebook publish handler.
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
					'description' => __( 'Choose how to handle source URLs when posting to Facebook.', 'data-machine-socials' ),
					'options' => array(
						'none' => __( 'No URL - exclude source link entirely', 'data-machine-socials' ),
						'append' => __( 'Append to post - add URL to post content', 'data-machine-socials' ),
						'comment' => __( 'Post as comment - add URL as separate comment', 'data-machine-socials' ),
					),
					'default' => 'append',
				),
			)
		);
	}
}
