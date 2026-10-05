<?php
/**
 * RSS Fetch Handler Settings
 *
 * Defines settings fields and sanitization for RSS fetch handler.
 * Part of the modular handler architecture.
 *
 * @package    Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers\Rss
 * @since      0.1.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Rss;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class RssSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for RSS fetch handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		// Handler-specific fields
		$fields = array(
			'feed_url' => array(
				'type'        => 'url',
				'label'       => __( 'RSS Feed URL', 'data-machine' ),
				'description' => __( 'Enter the full URL of the RSS or Atom feed (e.g., https://example.com/feed).', 'data-machine' ),
				'required'    => true,
			),
		);

		// Merge with common fetch handler fields
		return array_merge( $fields, parent::get_common_fields() );
	}
}
