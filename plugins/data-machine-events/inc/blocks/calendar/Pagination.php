<?php
/**
 * Calendar Block Pagination System
 *
 * Self-contained pagination rendering for Calendar block. Provides extensibility
 * filters for themes/plugins (datamachine_events_pagination_wrapper_classes,
 * datamachine_events_pagination_args) while keeping pagination logic within Calendar block.
 * Allows complete customization of pagination styling and behavior through WordPress filters.
 *
 * Available Filters:
 * - datamachine_events_pagination_wrapper_classes: Modify CSS classes on <nav> wrapper
 * - datamachine_events_pagination_args: Customize paginate_links() arguments
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pagination {

	/**
	 * Render pagination controls with extensibility filters
	 *
	 * @param int $current_page Current page number
	 * @param int $max_pages Total number of pages
	 * @param bool $show_past Whether currently showing past events
	 * @return string Pagination HTML or empty string if not needed
	 */
	public static function render_pagination( $current_page, $max_pages, $show_past = false ) {
		if ( $max_pages <= 1 ) {
			return '';
		}

		// Preserve all GET parameters except 'paged'
		$get_params = isset( $_GET ) ? self::sanitize_query_params( wp_unslash( $_GET ) ) : array();
		unset( $get_params['paged'] );
		// Build default pagination arguments
		$pagination_args = array(
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'current'   => $current_page,
			'total'     => $max_pages,
			'prev_text' => __( '« Previous', 'datamachine-events' ),
			'next_text' => __( 'Next »', 'datamachine-events' ),
			'type'      => 'list',
			'end_size'  => 1,
			'mid_size'  => 2,
			'add_args'  => $get_params,
		);

		/**
		 * Filter pagination arguments before generating links
		 *
		 * Allows complete customization of paginate_links() behavior including:
		 * - prev_text/next_text: Change button labels
		 * - mid_size/end_size: Control number of page links shown
		 * - type: Change output format (list, plain, array)
		 * - show_all: Display all page numbers
		 *
		 * @param array $pagination_args Arguments passed to paginate_links()
		 * @param int $current_page Current page number
		 * @param int $max_pages Total number of pages
		 * @param bool $show_past Whether showing past events
		 */
		$pagination_args = apply_filters(
			'datamachine_events_pagination_args',
			$pagination_args,
			$current_page,
			$max_pages,
			$show_past
		);

		$pagination_links = paginate_links( $pagination_args );

		// Only render if pagination links were generated
		if ( empty( $pagination_links ) || trim( $pagination_links ) === '' ) {
			return '';
		}

		// Build wrapper classes with filter
		$wrapper_classes = apply_filters(
			'datamachine_events_pagination_wrapper_classes',
			array( 'datamachine-events-pagination' ),
			$current_page,
			$max_pages,
			$show_past
		);

		$output = sprintf(
			'<nav class="%s" aria-label="%s">%s</nav>',
			esc_attr( implode( ' ', $wrapper_classes ) ),
			esc_attr__( 'Events pagination', 'datamachine-events' ),
			wp_kses_post( $pagination_links )
		);

		return $output;
	}

	/**
	 * Recursively sanitize query parameters to preserve nested arrays
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	protected static function sanitize_query_params( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'sanitize_query_params' ), $value );
		}

		return is_scalar( $value ) ? sanitize_text_field( $value ) : $value;
	}
}
