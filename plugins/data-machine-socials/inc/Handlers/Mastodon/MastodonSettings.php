<?php
/**
 * Mastodon Publish Handler Settings
 *
 * Defines settings fields and sanitization for the Mastodon publish handler.
 * Extends base publish handler settings with Mastodon-specific options.
 *
 * @package    DataMachineSocials
 * @subpackage Handlers\Mastodon
 * @since      0.17.0
 */

namespace DataMachineSocials\Handlers\Mastodon;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

defined( 'ABSPATH' ) || exit;

class MastodonSettings extends PublishHandlerSettings {

	/**
	 * Get settings fields for the Mastodon publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'visibility'    => array(
					'type'        => 'select',
					'label'       => __( 'Default Visibility', 'data-machine-socials' ),
					'description' => __( 'Default post visibility for Mastodon statuses.', 'data-machine-socials' ),
					'options'     => array(
						'public'   => __( 'Public — visible on local and federated timelines', 'data-machine-socials' ),
						'unlisted' => __( 'Unlisted — visible to followers, not on public timelines', 'data-machine-socials' ),
						'private'  => __( 'Followers only', 'data-machine-socials' ),
					),
					'default'     => 'public',
				),
				'link_handling' => array(
					'type'        => 'select',
					'label'       => __( 'Source URL Handling', 'data-machine-socials' ),
					'description' => __( 'Choose how to handle source URLs when posting to Mastodon.', 'data-machine-socials' ),
					'options'     => array(
						'append' => __( 'Append to post — add URL to status text', 'data-machine-socials' ),
						'none'   => __( 'No URL — exclude source link entirely', 'data-machine-socials' ),
					),
					'default'     => 'append',
				),
			)
		);
	}
}
