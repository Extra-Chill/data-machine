<?php
/**
 * Tumblr Publish Handler Settings
 *
 * Defines settings fields for the Tumblr publish handler.
 * Extends base publish handler settings with Tumblr-specific blog targeting.
 *
 * @package DataMachineSocials\Handlers\Tumblr
 * @since 0.17.0
 */

namespace DataMachineSocials\Handlers\Tumblr;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Tumblr Settings Handler
 *
 * Provides settings fields for the Tumblr publish handler, including the
 * target blog identifier (blog name or hostname) and optional default tags.
 */
class TumblrSettings extends PublishHandlerSettings {

	/**
	* Get settings fields for Tumblr publish handler.
	*
	* @return array Associative array defining the settings fields.
	*/
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'blog_identifier' => array(
					'type'        => 'text',
					'label'       => __( 'Default Blog Identifier', 'data-machine-socials' ),
					'description' => __( 'The Tumblr blog to post to: blog name (e.g. extrachill) or hostname (e.g. extrachill.tumblr.com). Can be overridden per publish.', 'data-machine-socials' ),
					'default'     => '',
				),
				'default_tags'    => array(
					'type'        => 'text',
					'label'       => __( 'Default Tags', 'data-machine-socials' ),
					'description' => __( 'Comma-separated tags added to every Tumblr post by default (e.g. live music, charleston).', 'data-machine-socials' ),
					'default'     => '',
				),
			)
		);
	}
}
