<?php
/**
 * Event Post Type Registration
 *
 * Handles registration of the data_machine_events custom post type with selective taxonomy menu control
 * and custom admin columns for event date display and sorting.
 *
 * @package DataMachineEvents
 * @subpackage Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Post Type registration and configuration
 */
class Event_Post_Type {

	const POST_TYPE           = 'data_machine_events';
	const EVENT_DATE_META_KEY = '_datamachine_event_datetime';

	public static function register() {
		$labels = array(
			'name'                  => _x( 'Events', 'Post type general name', 'data-machine-events' ),
			'singular_name'         => _x( 'Event', 'Post type singular name', 'data-machine-events' ),
			'menu_name'             => _x( 'Events', 'Admin Menu text', 'data-machine-events' ),
			'name_admin_bar'        => _x( 'Event', 'Add New on Toolbar', 'data-machine-events' ),
			'add_new'               => __( 'Add New', 'data-machine-events' ),
			'add_new_item'          => __( 'Add New Event', 'data-machine-events' ),
			'new_item'              => __( 'New Event', 'data-machine-events' ),
			'edit_item'             => __( 'Edit Event', 'data-machine-events' ),
			'view_item'             => __( 'View Event', 'data-machine-events' ),
			'all_items'             => __( 'All Events', 'data-machine-events' ),
			'search_items'          => __( 'Search Events', 'data-machine-events' ),
			'parent_item_colon'     => __( 'Parent Events:', 'data-machine-events' ),
			'not_found'             => __( 'No events found.', 'data-machine-events' ),
			'not_found_in_trash'    => __( 'No events found in Trash.', 'data-machine-events' ),
			'featured_image'        => _x( 'Event Image', 'Overrides the "Featured Image" phrase', 'data-machine-events' ),
			'set_featured_image'    => _x( 'Set event image', 'Overrides the "Set featured image" phrase', 'data-machine-events' ),
			'remove_featured_image' => _x( 'Remove event image', 'Overrides the "Remove featured image" phrase', 'data-machine-events' ),
			'use_featured_image'    => _x( 'Use as event image', 'Overrides the "Use as featured image" phrase', 'data-machine-events' ),
			'archives'              => _x( 'Event archives', 'The post type archive label', 'data-machine-events' ),
			'insert_into_item'      => _x( 'Insert into event', 'Overrides the "Insert into post" phrase', 'data-machine-events' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this event', 'Overrides the "Uploaded to this post" phrase', 'data-machine-events' ),
			'filter_items_list'     => _x( 'Filter events list', 'Screen reader text for the filter links', 'data-machine-events' ),
			'items_list_navigation' => _x( 'Events list navigation', 'Screen reader text for the pagination', 'data-machine-events' ),
			'items_list'            => _x( 'Events list', 'Screen reader text for the items list', 'data-machine-events' ),
		);

		$args = array(
			'labels'                => $labels,
			'public'                => true,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'show_in_admin_bar'     => true,
			'query_var'             => true,
			'rewrite'               => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'capability_type'       => 'post',
			'has_archive'           => true,
			'hierarchical'          => false,
			'menu_position'         => 5,
			'menu_icon'             => 'dashicons-calendar-alt',
			'supports'              => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'revisions',
				'author',
				'page-attributes',
				'editor-styles',
				'wp-block-styles',
				'align-wide',
			),
			'show_in_rest'          => true,
			'rest_base'             => 'data_machine_events',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'taxonomies'            => array(),
		);

		register_post_type( self::POST_TYPE, $args );

		self::setup_admin_menu_control();
	}

	private static function setup_admin_menu_control() {
		add_action( 'admin_menu', array( __CLASS__, 'control_taxonomy_menus' ), 999 );

		add_filter( 'parent_file', array( __CLASS__, 'filter_parent_file' ) );

		add_filter( 'submenu_file', array( __CLASS__, 'filter_submenu_file' ) );

		self::setup_admin_columns();
	}

	private static function setup_admin_columns() {
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_event_date_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_event_date_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_event_date_column' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_event_date' ) );
	}

	public static function add_event_date_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'title' === $key ) {
				$new_columns['event_date'] = __( 'Event Date', 'data-machine-events' );
			}
		}

		return $new_columns;
	}

	public static function render_event_date_column( $column, $post_id ) {
		if ( 'event_date' !== $column ) {
			return;
		}

		$event_datetime = get_post_meta( $post_id, self::EVENT_DATE_META_KEY, true );

		if ( ! $event_datetime ) {
			echo '<span class="datamachine-no-date">' . esc_html__( 'No date set', 'data-machine-events' ) . '</span>';
			return;
		}

		try {
			$date           = new \DateTime( $event_datetime );
			$formatted_date = $date->format( 'M j, Y' );
			$formatted_time = $date->format( 'g:i a' );

			printf(
				'<span class="datamachine-event-date"><strong>%s</strong><br>%s</span>',
				esc_html( $formatted_date ),
				esc_html( $formatted_time )
			);
		} catch ( \Exception $e ) {
			echo '<span class="datamachine-invalid-date">' . esc_html__( 'Invalid date', 'data-machine-events' ) . '</span>';
		}
	}

	public static function sortable_event_date_column( $columns ) {
		$columns['event_date'] = 'event_date';
		return $columns;
	}

	public static function sort_by_event_date( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'event_date' === $orderby ) {
			$query->set( 'meta_key', self::EVENT_DATE_META_KEY );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	public static function control_taxonomy_menus() {
		global $submenu;

		$post_type_menu = 'edit.php?post_type=' . self::POST_TYPE;

		$allowed_items = apply_filters(
			'data_machine_events_post_type_menu_items',
			array(
				'venue'    => true,
				'promoter' => true,
				'settings' => true,
			)
		);

		if ( isset( $submenu[ $post_type_menu ] ) ) {
			foreach ( $submenu[ $post_type_menu ] as $key => $menu_item ) {
				if ( strpos( $menu_item[2], 'taxonomy=' ) !== false ) {
					parse_str( parse_url( $menu_item[2], PHP_URL_QUERY ), $query_vars );
					$taxonomy = $query_vars['taxonomy'] ?? '';

					if ( $taxonomy && ! isset( $allowed_items[ $taxonomy ] ) ) {
						unset( $submenu[ $post_type_menu ][ $key ] );
					}
				}
			}
		}

		foreach ( $allowed_items as $item_key => $item_config ) {
			if ( is_array( $item_config ) && isset( $item_config['type'] ) && 'submenu' === $item_config['type'] ) {
				if ( isset( $item_config['callback'] ) && is_callable( $item_config['callback'] ) ) {
					call_user_func( $item_config['callback'] );
				}
			}
		}
	}

	/**
	 * Ensures proper menu highlighting by filtering parent file for disallowed taxonomies
	 */
	public static function filter_parent_file( $parent_file ) {
		global $current_screen;

		if ( ! $current_screen || self::POST_TYPE !== $current_screen->post_type ) {
			return $parent_file;
		}

		$allowed_items = apply_filters(
			'data_machine_events_post_type_menu_items',
			array(
				'venue'    => true,
				'promoter' => true,
				'settings' => true,
			)
		);

		if ( $current_screen->taxonomy && ! isset( $allowed_items[ $current_screen->taxonomy ] ) ) {
			return 'edit.php?post_type=' . self::POST_TYPE;
		}

		return $parent_file;
	}

	/**
	 * Ensures proper submenu highlighting for allowed taxonomies
	 */
	public static function filter_submenu_file( $submenu_file ) {
		global $current_screen;

		if ( ! $current_screen || self::POST_TYPE !== $current_screen->post_type ) {
			return $submenu_file;
		}

		if ( $current_screen->taxonomy ) {
			$allowed_items = apply_filters(
				'data_machine_events_post_type_menu_items',
				array(
					'venue'    => true,
					'promoter' => true,
					'settings' => true,
				)
			);

			if ( isset( $allowed_items[ $current_screen->taxonomy ] ) ) {
				return "edit-tags.php?taxonomy={$current_screen->taxonomy}&post_type=" . self::POST_TYPE;
			}
		}

		return $submenu_file;
	}
}
