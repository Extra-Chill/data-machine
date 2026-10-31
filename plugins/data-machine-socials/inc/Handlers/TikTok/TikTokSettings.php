<?php
/**
 * Settings for TikTok publish handler.
 *
 * @package DataMachineSocials
 * @subpackage Handlers\TikTok
 */

namespace DataMachineSocials\Handlers\TikTok;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TikTokSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for TikTok handler.
	 *
	 * @return array Settings field definitions.
	 */
	public static function get_fields(): array {
		$fields = array(
			'default_privacy_level' => array(
				'type'        => 'select',
				'label'       => __( 'Default Privacy Level', 'data-machine-socials' ),
				'description' => __( 'Default visibility for TikTok posts. PUBLIC_TO_EVERYONE requires the Content Posting Audit. Pre-audit posts are forced to SELF_ONLY.', 'data-machine-socials' ),
				'options'     => array(
					'PUBLIC_TO_EVERYONE'    => __( 'Public (requires audit)', 'data-machine-socials' ),
					'SELF_ONLY'             => __( 'Private / self only', 'data-machine-socials' ),
					'MUTUAL_FOLLOW_FRIENDS' => __( 'Mutual follow friends', 'data-machine-socials' ),
					'FOLLOWER_OF_CREATOR'   => __( 'Followers of creator', 'data-machine-socials' ),
				),
				'default'     => 'PUBLIC_TO_EVERYONE',
			),
			'caption_source'        => array(
				'type'        => 'select',
				'label'       => __( 'Caption Source', 'data-machine-socials' ),
				'description' => __( 'Where to get the caption text from (maps to TikTok title field)', 'data-machine-socials' ),
				'options'     => array(
					'content'      => __( 'Content field', 'data-machine-socials' ),
					'post_excerpt' => __( 'Post excerpt', 'data-machine-socials' ),
					'post_title'   => __( 'Post title', 'data-machine-socials' ),
				),
				'default'     => 'content',
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
