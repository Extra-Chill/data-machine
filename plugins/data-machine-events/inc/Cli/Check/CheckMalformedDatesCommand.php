<?php
/**
 * Check for events with malformed/zero dates in the event_dates table.
 *
 * Under non-strict MySQL sql_mode, malformed date strings (e.g. "2026-07-??")
 * are silently coerced to "0000-00-00 00:00:00" in the
 * datamachine_event_dates table. This command finds those rows so they can
 * be cleaned up, and also scans event-details block content for placeholder
 * date patterns (the upstream source of the problem). See #395.
 *
 * Usage:
 *   wp data-machine-events check malformed-dates
 *   wp data-machine-events check malformed-dates --format=json
 *   wp data-machine-events check malformed-dates --delete-zero-rows --yes
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.47.4
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckMalformedDatesCommand {

	/**
	 * Check for events with malformed/zero dates.
	 *
	 * Scans the datamachine_event_dates table for rows coerced to
	 * "0000-00-00 00:00:00" and event-details block attributes for
	 * placeholder date patterns (e.g. "2026-07-??"). Optionally deletes
	 * the zero rows so the event no longer appears in date-based queries.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--delete-zero-rows]
	 * : Delete zero-date rows from the event_dates table. The post itself
	 * is not modified — only the denormalized date row is removed. The row
	 * will be re-created on the next save_post if the block has a valid date.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt when using --delete-zero-rows.
	 *
	 * ## EXAMPLES
	 *
	 *     # Audit only (read-only)
	 *     wp data-machine-events check malformed-dates
	 *
	 *     # Output as JSON
	 *     wp data-machine-events check malformed-dates --format=json
	 *
	 *     # Delete zero-date rows without prompting
	 *     wp data-machine-events check malformed-dates --delete-zero-rows --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format           = $assoc_args['format'] ?? 'table';
		$delete_zero_rows = isset( $assoc_args['delete-zero-rows'] );
		$skip_confirm     = isset( $assoc_args['yes'] );

		$zero_rows          = EventDatesTable::find_zero_date_rows();
		$block_placeholders = $this->find_placeholder_block_dates();

		$total = count( $zero_rows ) + count( $block_placeholders );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode(
				array(
					'zero_date_rows'     => $zero_rows,
					'block_placeholders' => $block_placeholders,
					'total_issues'       => $total,
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d malformed-date issue(s).', $total ) );
		\WP_CLI::log( '' );

		// --- Zero-date rows in the event_dates table ---
		\WP_CLI::log( sprintf( '--- Zero-date rows in event_dates table (%d) ---', count( $zero_rows ) ) );

		if ( empty( $zero_rows ) ) {
			\WP_CLI::log( 'None.' );
		} else {
			$table_data = array();
			foreach ( $zero_rows as $row ) {
				$post         = get_post( $row['post_id'] );
				$table_data[] = array(
					'ID'            => $row['post_id'],
					'Title'         => $post ? mb_substr( $post->post_title, 0, 45 ) : '(deleted)',
					'Status'        => $post ? $post->post_status : '—',
					'Start (table)' => $row['start_datetime'],
				);
			}

			if ( 'csv' === $format ) {
				\WP_CLI\Utils\format_items( 'csv', $table_data, array_keys( $table_data[0] ) );
			} else {
				\WP_CLI\Utils\format_items( 'table', $table_data, array_keys( $table_data[0] ) );
			}

			if ( $delete_zero_rows ) {
				if ( ! $skip_confirm ) {
					\WP_CLI::confirm( sprintf( 'Delete %d zero-date row(s) from the event_dates table?', count( $zero_rows ) ) );
				}
				$deleted = 0;
				foreach ( $zero_rows as $row ) {
					EventDatesTable::delete( $row['post_id'] );
					++$deleted;
					\WP_CLI::log( sprintf( 'Deleted zero-date row: post_id %d', $row['post_id'] ) );
				}
				\WP_CLI::success( sprintf( 'Deleted %d zero-date row(s).', $deleted ) );
			} else {
				\WP_CLI::log( 'Tip: run with --delete-zero-rows to remove these rows.' );
			}
		}

		\WP_CLI::log( '' );

		// --- Placeholder dates in block content ---
		\WP_CLI::log( sprintf( '--- Placeholder dates in block content (%d) ---', count( $block_placeholders ) ) );

		if ( empty( $block_placeholders ) ) {
			\WP_CLI::log( 'None.' );
		} else {
			$table_data = array();
			foreach ( $block_placeholders as $item ) {
				$table_data[] = array(
					'ID'        => $item['id'],
					'Title'     => mb_substr( $item['title'], 0, 45 ),
					'Status'    => $item['status'],
					'StartDate' => $item['start_date'],
					'EndDate'   => $item['end_date'],
				);
			}

			if ( 'csv' === $format ) {
				\WP_CLI\Utils\format_items( 'csv', $table_data, array_keys( $table_data[0] ) );
			} else {
				\WP_CLI\Utils\format_items( 'table', $table_data, array_keys( $table_data[0] ) );
			}

			\WP_CLI::log( 'These events have placeholder dates (e.g. "2026-07-??") in the' );
			\WP_CLI::log( 'event-details block. Fix the date in the editor or delete the event.' );
		}

		\WP_CLI::log( '' );

		if ( 0 === $total ) {
			\WP_CLI::success( 'No malformed dates found.' );
		} else {
			\WP_CLI::warning( sprintf( '%d malformed-date issue(s) found.', $total ) );
		}
	}

	/**
	 * Find events whose event-details block has a placeholder date pattern.
	 *
	 * Scans all event posts for startDate/endDate attributes containing
	 * placeholder characters or an incomplete Y-m-d shape.
	 *
	 * @return array<array{id: int, title: string, status: string, start_date: string, end_date: string}>
	 */
	private function find_placeholder_block_dates(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status, post_content
				FROM {$wpdb->posts}
				WHERE post_type = %s
					AND post_content REGEXP %s",
				Event_Post_Type::POST_TYPE,
				'startDate":"20[0-9][0-9]-[0-9?][0-9?]-[?]'
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$blocks = parse_blocks( $post->post_content );
			foreach ( $blocks as $block ) {
				if ( 'data-machine-events/event-details' !== $block['blockName'] ) {
					continue;
				}

				$start_date = $block['attrs']['startDate'] ?? '';
				$end_date   = $block['attrs']['endDate'] ?? '';

				$has_placeholder = $this->is_placeholder_date( $start_date ) || $this->is_placeholder_date( $end_date );
				if ( ! $has_placeholder ) {
					continue;
				}

				$results[] = array(
					'id'         => (int) $post->ID,
					'title'      => $post->post_title,
					'status'     => $post->post_status,
					'start_date' => $start_date,
					'end_date'   => $end_date,
				);
				break;
			}
		}

		return $results;
	}

	/**
	 * Check whether a date string is a placeholder/TBD value.
	 *
	 * @param string $date Date string from block attributes.
	 * @return bool True if the date contains placeholder characters.
	 */
	private function is_placeholder_date( string $date ): bool {
		if ( '' === $date ) {
			return false;
		}

		return false !== strpos( $date, '?' );
	}
}
