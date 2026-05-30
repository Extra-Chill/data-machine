<?php
/**
 * Month Grid Builder
 *
 * Pure date/event arithmetic for the month-grid display mode (#318).
 * Given a `YYYY-MM` month and the events grouped by date, produces the
 * structured grid the template renders:
 *
 *   - 6 rows × 7 columns of cells (always a complete grid; leading/
 *     trailing cells from adjacent months render as "other-month").
 *   - Per cell: date, single-day events, multi-day ribbons that start
 *     on that cell within its row, and metadata flags (is_today,
 *     is_past, is_other_month, day_of_week).
 *
 * The ribbon model:
 *   - One ribbon per (event × row) intersection. A multi-day event
 *     spanning two rows produces two ribbons; CSS marks the boundary
 *     ends with continuation indicators.
 *   - Ribbons are placed on the row's start cell with a column span.
 *   - Ribbons in the same row are vertically stacked by assigning each
 *     a `lane` index — non-overlapping ribbons reuse the lowest free
 *     lane.
 *
 * This class is intentionally framework-free: pure arrays in, pure
 * arrays out. The template handles all escaping / markup.
 *
 * @package DataMachineEvents\Blocks\Calendar\Grid
 * @since   0.40.0
 */

namespace DataMachineEvents\Blocks\Calendar\Grid;

use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MonthGridBuilder {

	private const DAYS_OF_WEEK = array(
		0 => 'sunday',
		1 => 'monday',
		2 => 'tuesday',
		3 => 'wednesday',
		4 => 'thursday',
		5 => 'friday',
		6 => 'saturday',
	);

	/**
	 * Build the structured grid for a visible month.
	 *
	 * @param string $month             `YYYY-MM` visible month.
	 * @param array  $paged_date_groups Output of `DateGrouper::group_events_by_date`.
	 *                                   Shape: `[ date_key => [ 'events' => [ event_item, ... ], ... ], ... ]`.
	 *                                   Each event_item carries `post`, `event_data`, `display_context`.
	 * @param string $today_date        Today in Y-m-d, in the site timezone.
	 * @return array<string,mixed>      Structured grid (see code).
	 */
	public static function build( string $month, array $paged_date_groups, string $today_date ): array {
		try {
			$first = new DateTimeImmutable( $month . '-01' );
		} catch ( \Exception $e ) {
			return self::empty_grid( $month, $today_date );
		}

		$year      = (int) $first->format( 'Y' );
		$month_num = (int) $first->format( 'n' );

		// Start of grid: first day of the week containing the 1st.
		// Week starts on Sunday (locked decision in #318 v1).
		$first_dow  = (int) $first->format( 'w' ); // 0 = Sunday.
		$grid_start = $first->modify( '-' . $first_dow . ' days' );

		// Build a flat date-indexed map for fast event lookup.
		$by_date = array();
		foreach ( $paged_date_groups as $date_key => $group ) {
			$by_date[ $date_key ] = $group['events'] ?? array();
		}

		// 6 rows × 7 columns = 42 cells. Always render 6 rows so the
		// grid is a fixed shape; months with 4/5 visible weeks just
		// trail more other-month cells.
		$rows = array();
		for ( $row_index = 0; $row_index < 6; $row_index++ ) {
			$row_start = $grid_start->modify( '+' . ( $row_index * 7 ) . ' days' );
			$row_end   = $row_start->modify( '+6 days' );

			$cells = array();
			for ( $col_index = 0; $col_index < 7; $col_index++ ) {
				$cell_date = $row_start->modify( '+' . $col_index . ' days' );
				$date_key  = $cell_date->format( 'Y-m-d' );
				$cells[]   = self::build_cell(
					$cell_date,
					$date_key,
					$by_date[ $date_key ] ?? array(),
					$today_date,
					$month_num,
					$year
				);
			}

			$ribbons = self::build_row_ribbons( $row_start, $row_end, $by_date );

			$rows[] = array(
				'start_date' => $row_start->format( 'Y-m-d' ),
				'end_date'   => $row_end->format( 'Y-m-d' ),
				'cells'      => $cells,
				'ribbons'    => $ribbons,
			);
		}

		// Trim trailing all-other-month rows so months that fit in five
		// weeks don't add an empty sixth row. We always KEEP at least
		// five rows for layout stability.
		while ( count( $rows ) > 5 ) {
			$last      = end( $rows );
			$all_other = true;
			foreach ( $last['cells'] as $cell ) {
				if ( ! $cell['is_other_month'] ) {
					$all_other = false;
					break;
				}
			}
			if ( $all_other ) {
				array_pop( $rows );
			} else {
				break;
			}
		}

		return array(
			'month'        => $month,
			'year'         => $year,
			'month_label'  => date_i18n( 'F Y', $first->getTimestamp() ),
			'prev_month'   => $first->modify( '-1 month' )->format( 'Y-m' ),
			'next_month'   => $first->modify( '+1 month' )->format( 'Y-m' ),
			'today_month'  => self::today_month( $today_date ),
			'rows'         => $rows,
			'weekday_keys' => array_values( self::DAYS_OF_WEEK ),
		);
	}

	/**
	 * Build a single cell.
	 */
	private static function build_cell(
		DateTimeImmutable $cell_date,
		string $date_key,
		array $events_for_date,
		string $today_date,
		int $visible_month_num,
		int $visible_year
	): array {
		$dow_index   = (int) $cell_date->format( 'w' );
		$day_of_week = self::DAYS_OF_WEEK[ $dow_index ];

		$is_other_month = (int) $cell_date->format( 'n' ) !== $visible_month_num
			|| (int) $cell_date->format( 'Y' ) !== $visible_year;

		$is_today = ( $date_key === $today_date );
		$is_past  = ( $date_key < $today_date );

		// Split into single-day events (rendered as strips inside the
		// cell) and multi-day events (deferred to the row-level ribbon
		// builder so the same event renders once per row, not once
		// per spanned cell). Per-occurrence we look at the event's
		// own display_context to decide.
		$single_day_events = array();
		foreach ( $events_for_date as $event_item ) {
			$display_context = $event_item['display_context'] ?? array();
			$is_multi_day    = ! empty( $display_context['is_multi_day'] );
			if ( $is_multi_day ) {
				continue;
			}
			$single_day_events[] = self::serialize_event_for_cell( $event_item );
		}

		return array(
			'date'              => $date_key,
			'day_number'        => (int) $cell_date->format( 'j' ),
			'day_of_week'       => $day_of_week,
			'is_today'          => $is_today,
			'is_past'           => $is_past,
			'is_other_month'    => $is_other_month,
			'single_day_events' => $single_day_events,
		);
	}

	/**
	 * Build the multi-day ribbons that intersect a single row.
	 *
	 * Iterates every date in the row, collects the unique multi-day
	 * events that touch any cell in the row, then computes each
	 * ribbon's row-relative start column and span. Assigns lanes
	 * (vertical stacking index) greedily — non-overlapping ribbons
	 * reuse the lowest free lane.
	 */
	private static function build_row_ribbons(
		DateTimeImmutable $row_start,
		DateTimeImmutable $row_end,
		array $by_date
	): array {
		$row_start_key = $row_start->format( 'Y-m-d' );
		$row_end_key   = $row_end->format( 'Y-m-d' );

		// Collect unique multi-day occurrences hitting this row.
		// Keyed by post ID so we render each event once per row even
		// when its display_context appears under multiple dates in the
		// row.
		$ribbons_by_post = array();
		$cursor          = $row_start;
		for ( $i = 0; $i < 7; $i++ ) {
			$date_key        = $cursor->format( 'Y-m-d' );
			$events_for_date = $by_date[ $date_key ] ?? array();
			foreach ( $events_for_date as $event_item ) {
				$display_context = $event_item['display_context'] ?? array();
				if ( empty( $display_context['is_multi_day'] ) ) {
					continue;
				}
				$post = $event_item['post'] ?? null;
				if ( ! $post || ! isset( $post->ID ) ) {
					continue;
				}
				$post_id = (int) $post->ID;
				if ( isset( $ribbons_by_post[ $post_id ] ) ) {
					continue;
				}

				$ribbons_by_post[ $post_id ] = self::compute_ribbon_span(
					$event_item,
					$row_start_key,
					$row_end_key
				);
			}
			$cursor = $cursor->modify( '+1 day' );
		}

		// Sort by start col asc, then by span desc (longest first
		// claims its lane first) for visually stable stacking.
		usort(
			$ribbons_by_post,
			static function ( $a, $b ) {
				if ( $a['start_col'] !== $b['start_col'] ) {
					return $a['start_col'] <=> $b['start_col'];
				}
				return $b['span'] <=> $a['span'];
			}
		);

		// Assign lanes greedily.
		$lane_ends = array(); // lane_index => last occupied column (inclusive).
		foreach ( $ribbons_by_post as &$ribbon ) {
			$assigned = null;
			foreach ( $lane_ends as $lane_index => $end_col ) {
				if ( $ribbon['start_col'] > $end_col ) {
					$assigned = $lane_index;
					break;
				}
			}
			if ( null === $assigned ) {
				$assigned               = count( $lane_ends );
				$lane_ends[ $assigned ] = -1;
			}
			$ribbon['lane']         = $assigned;
			$lane_ends[ $assigned ] = $ribbon['start_col'] + $ribbon['span'] - 1;
		}
		unset( $ribbon );

		return array_values( $ribbons_by_post );
	}

	/**
	 * Compute a single ribbon's row-relative geometry.
	 *
	 * The event's full date range (from event_data) is clipped to the
	 * row's [row_start_key, row_end_key] window. `continues_left` /
	 * `continues_right` flags tell the template whether to draw the
	 * cut-off ribbon ends as continuation arrows.
	 */
	private static function compute_ribbon_span(
		array $event_item,
		string $row_start_key,
		string $row_end_key
	): array {
		$event_data  = $event_item['event_data'] ?? array();
		$event_start = (string) ( $event_data['startDate'] ?? '' );
		$event_end   = (string) ( $event_data['endDate'] ?? $event_start );
		if ( '' === $event_end ) {
			$event_end = $event_start;
		}

		$clip_start = $event_start < $row_start_key ? $row_start_key : $event_start;
		$clip_end   = $event_end > $row_end_key ? $row_end_key : $event_end;

		// Defensive clamp: if clipping inverts the range, collapse to
		// the row start (the event doesn't actually intersect the row
		// after clipping — should never happen given the call site,
		// but cheap to be safe).
		if ( $clip_start > $clip_end ) {
			$clip_start = $clip_end = $row_start_key;
		}

		$start_col = self::days_between( $row_start_key, $clip_start );
		$end_col   = self::days_between( $row_start_key, $clip_end );
		$span      = max( 1, $end_col - $start_col + 1 );

		$continues_left  = $event_start < $row_start_key;
		$continues_right = $event_end > $row_end_key;

		$post      = $event_item['post'];
		$title     = isset( $post->post_title ) ? (string) $post->post_title : '';
		$post_id   = isset( $post->ID ) ? (int) $post->ID : 0;
		$permalink = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
		$dow_index = (int) ( new DateTimeImmutable( $clip_start ) )->format( 'w' );

		return array(
			'post_id'         => $post_id,
			'title'           => $title,
			'permalink'       => $permalink,
			'start_col'       => $start_col,
			'span'            => $span,
			'continues_left'  => $continues_left,
			'continues_right' => $continues_right,
			'day_of_week'     => self::DAYS_OF_WEEK[ $dow_index ] ?? '',
		);
	}

	/**
	 * Serialize a single-day event item into the shape the template
	 * consumes for cell strips.
	 */
	private static function serialize_event_for_cell( array $event_item ): array {
		$post      = $event_item['post'];
		$post_id   = isset( $post->ID ) ? (int) $post->ID : 0;
		$title     = isset( $post->post_title ) ? (string) $post->post_title : '';
		$permalink = $post_id > 0 ? (string) get_permalink( $post_id ) : '';

		return array(
			'post_id'   => $post_id,
			'title'     => $title,
			'permalink' => $permalink,
		);
	}

	/**
	 * Whole-day delta between two `Y-m-d` strings (assumed UTC, since
	 * date-only strings are timezone-neutral).
	 */
	private static function days_between( string $a, string $b ): int {
		$da   = new DateTimeImmutable( $a );
		$db   = new DateTimeImmutable( $b );
		$diff = $da->diff( $db );
		return (int) ( $diff->invert ? -$diff->days : $diff->days );
	}

	/**
	 * Empty grid fallback for invalid month input.
	 */
	private static function empty_grid( string $month, string $today_date ): array {
		return array(
			'month'        => $month,
			'year'         => 0,
			'month_label'  => '',
			'prev_month'   => '',
			'next_month'   => '',
			'today_month'  => self::today_month( $today_date ),
			'rows'         => array(),
			'weekday_keys' => array_values( self::DAYS_OF_WEEK ),
		);
	}

	private static function today_month( string $today_date ): string {
		if ( strlen( $today_date ) >= 7 && preg_match( '/^\d{4}-\d{2}/', $today_date ) ) {
			return substr( $today_date, 0, 7 );
		}
		return gmdate( 'Y-m' );
	}
}
