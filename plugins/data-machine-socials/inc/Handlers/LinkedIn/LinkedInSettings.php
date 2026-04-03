<?php
/**
 * LinkedIn Publish Handler Settings
 *
 * Defines settings fields and sanitization for LinkedIn publish handler.
 * Extends base publish handler settings with LinkedIn-specific options.
 *
 * @package DataMachineSocials
 * @since 0.5.0
 */

namespace DataMachineSocials\Handlers\LinkedIn;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class LinkedInSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for LinkedIn publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'visibility' => array(
					'type'        => 'select',
					'label'       => __( 'Post Visibility', 'data-machine-socials' ),
					'description' => __( 'Who can see posts published to LinkedIn.', 'data-machine-socials' ),
					'options'     => array(
						'PUBLIC'      => __( 'Public - Anyone on or off LinkedIn', 'data-machine-socials' ),
						'CONNECTIONS' => __( 'Connections only - Only your connections', 'data-machine-socials' ),
					),
					'default'     => 'PUBLIC',
				),
			)
		);
	}

	public function __construct() {
		parent::__construct( 'linkedin' );
	}
}
