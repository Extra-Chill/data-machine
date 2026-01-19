<?php
/**
 * Admin Bar Events Menu
 *
 * @package DataMachineEvents
 * @subpackage Admin
 */

namespace DataMachineEvents\Admin;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Events navigation to WordPress admin bar
 */
class Admin_Bar {

	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_events_menu' ), 999 );
	}

	/**
	 * Add Events menu to admin bar
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_events_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Get events page URL from settings
		$events_url = class_exists( 'DataMachineEvents\Admin\Settings_Page' )
			? Settings_Page::get_main_events_page_url()
			: '';

		// Fallback to post type archive if no custom URL set
		if ( empty( $events_url ) ) {
			$events_url = get_post_type_archive_link( Event_Post_Type::POST_TYPE );
		}

		// Don't add menu if no URL available
		if ( empty( $events_url ) ) {
			return;
		}

		$wp_admin_bar->add_menu(
			array(
				'id'    => 'datamachine-events',
				'title' => __( 'View Events', 'datamachine-events' ),
				'href'  => $events_url,
				'meta'  => array(
					'title'  => __( 'View Events Calendar', 'datamachine-events' ),
					'target' => '_blank',
				),
			)
		);
	}
}
