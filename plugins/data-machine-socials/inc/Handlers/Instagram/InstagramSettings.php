<?php
/**
 * Settings for Instagram publish handler.
 *
 * @package DataMachineSocials\Handlers\Instagram
 */

namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InstagramSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for Instagram handler.
	 *
	 * @return array Settings field definitions
	 */
	public static function get_fields(): array {
		$fields = array(
			'default_aspect_ratio' => array(
				'type' => 'select',
				'label' => __( 'Default Aspect Ratio', 'data-machine-socials' ),
				'description' => __( 'Default aspect ratio for images when posting to Instagram', 'data-machine-socials' ),
				'options' => array(
					'1:1' => __( 'Square (1:1)', 'data-machine-socials' ),
					'4:5' => __( 'Portrait (4:5)', 'data-machine-socials' ),
					'3:4' => __( 'Classic Portrait (3:4)', 'data-machine-socials' ),
					'1.91:1' => __( 'Landscape (1.91:1)', 'data-machine-socials' ),
				),
				'default' => '4:5',
			),
			'caption_source' => array(
				'type' => 'select',
				'label' => __( 'Caption Source', 'data-machine-socials' ),
				'description' => __( 'Where to get the caption from', 'data-machine-socials' ),
				'options' => array(
					'content' => __( 'Content field', 'data-machine-socials' ),
					'post_excerpt' => __( 'Post excerpt', 'data-machine-socials' ),
					'post_title' => __( 'Post title', 'data-machine-socials' ),
				),
				'default' => 'content',
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
