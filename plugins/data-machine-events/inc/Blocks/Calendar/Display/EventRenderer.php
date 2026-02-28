<?php
/**
 * Event Renderer
 *
 * Renders date groups and individual events as HTML using templates.
 * Handles lazy-loading placeholders for events beyond the fold.
 *
 * @package DataMachineEvents\Blocks\Calendar\Display
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Display;

use DataMachineEvents\Blocks\Calendar\Template_Loader;
use DataMachineEvents\Blocks\Calendar\Taxonomy_Badges;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRenderer {

	/**
	 * Number of events to fully render before switching to placeholders.
	 */
	const LAZY_RENDER_THRESHOLD = 5;

	/**
	 * Render date groups as HTML.
	 *
	 * Iterates through date groups, rendering time-gap separators
	 * and event items using templates.
	 *
	 * @param array $paged_date_groups Date-grouped events from DateGrouper.
	 * @param array $gaps_detected     Time gaps from DateGrouper::detect_time_gaps().
	 * @param bool  $include_gaps      Whether to render time-gap separators.
	 * @return string Rendered HTML.
	 */
	public static function render_date_groups(
		array $paged_date_groups,
		array $gaps_detected = array(),
		bool $include_gaps = true
	): string {
		if ( empty( $paged_date_groups ) ) {
			ob_start();
			Template_Loader::include_template( 'no-events' );
			return ob_get_clean();
		}

		ob_start();

		foreach ( $paged_date_groups as $date_key => $date_group ) {
			$date_obj        = $date_group['date_obj'];
			$events_for_date = $date_group['events'];

			if ( $include_gaps && isset( $gaps_detected[ $date_key ] ) ) {
				Template_Loader::include_template(
					'time-gap-separator',
					array(
						'gap_days' => $gaps_detected[ $date_key ],
					)
				);
			}

			$day_of_week          = strtolower( $date_obj->format( 'l' ) );
			$formatted_date_label = $date_obj->format( 'l, F jS' );

			Template_Loader::include_template(
				'date-group',
				array(
					'date_obj'             => $date_obj,
					'day_of_week'          => $day_of_week,
					'formatted_date_label' => $formatted_date_label,
					'events_count'         => count( $events_for_date ),
				)
			);
			?>

			<div class="datamachine-events-wrapper">
				<?php
				$event_index = 0;
				foreach ( $events_for_date as $event_item ) {
					$event_post      = $event_item['post'];
					$event_data      = $event_item['event_data'];
					$display_context = $event_item['display_context'] ?? array();

					global $post;
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required for setup_postdata()
					$post = $event_post;
					setup_postdata( $post );

					$display_vars = DisplayVars::build( $event_data, $display_context );

					if ( $event_index < self::LAZY_RENDER_THRESHOLD ) {
						Template_Loader::include_template(
							'event-item',
							array(
								'event_post'   => $event_post,
								'event_data'   => $event_data,
								'display_vars' => $display_vars,
							)
						);
					} else {
						self::render_event_placeholder( $event_post, $event_data, $display_vars, $display_context );
					}
					++$event_index;
				}
				?>
			</div><!-- .datamachine-events-wrapper -->
			<?php
			echo '</div><!-- .datamachine-date-group -->';
		}

		return ob_get_clean();
	}

	/**
	 * Render an event placeholder for lazy loading.
	 *
	 * Outputs a skeleton placeholder with JSON data for client-side hydration.
	 *
	 * @param \WP_Post $event_post     Event post object.
	 * @param array    $event_data     Event data from block attributes.
	 * @param array    $display_vars   Processed display variables.
	 * @param array    $display_context Display context for multi-day events.
	 */
	private static function render_event_placeholder(
		\WP_Post $event_post,
		array $event_data,
		array $display_vars,
		array $display_context
	): void {
		$placeholder_data = array(
			'id'              => $event_post->ID,
			'title'           => get_the_title( $event_post ),
			'permalink'       => get_the_permalink( $event_post ),
			'event_data'      => $event_data,
			'display_vars'    => $display_vars,
			'display_context' => $display_context,
			'badges_html'     => Taxonomy_Badges::render_taxonomy_badges( $event_post->ID ),
			'button_classes'  => implode( ' ', apply_filters( 'datamachine_events_more_info_button_classes', array( 'datamachine-more-info-button' ) ) ),
		);

		$item_classes = array( 'datamachine-event-item', 'datamachine-event-placeholder' );
		if ( ! empty( $display_vars['is_continuation'] ) ) {
			$item_classes[] = 'datamachine-event-continuation';
		}
		if ( ! empty( $display_vars['is_multi_day'] ) ) {
			$item_classes[] = 'datamachine-event-multi-day';
		}

		printf(
			'<div class="%s" data-event-json="%s">
				<div class="datamachine-placeholder-skeleton">
					<div class="datamachine-skeleton-badges"></div>
					<div class="datamachine-skeleton-title"></div>
					<div class="datamachine-skeleton-meta"></div>
					<div class="datamachine-skeleton-button"></div>
				</div>
			</div>',
			esc_attr( implode( ' ', $item_classes ) ),
			esc_attr( wp_json_encode( $placeholder_data ) )
		);
	}
}
