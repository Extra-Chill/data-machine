<?php
/**
 * WP-CLI Processed Items Command
 *
 * Wraps ProcessedItemsAbilities for deduplication tracking management.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\ProcessedItemsAbilities;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

defined( 'ABSPATH' ) || exit;

/**
 * Processed items (deduplication) management.
 *
 * @since 0.41.0
 */
class ProcessedItemsCommand extends BaseCommand {

	/**
	 * Audit processed items vs actual published posts per flow.
	 *
	 * Shows how many items were marked as "processed" in dedup tracking
	 * versus how many posts were actually published. A large gap indicates
	 * items that were fetched and deduped but never imported (the max_items
	 * burn bug).
	 *
	 * ## OPTIONS
	 *
	 * [--handler=<handler_type>]
	 * : Filter to a specific handler type (e.g., "ticketmaster", "dice_fm", "universal_web_scraper").
	 *
	 * [--pipeline=<pipeline_id>]
	 * : Filter to a specific pipeline.
	 *
	 * [--min-waste=<count>]
	 * : Only show flows with at least this many wasted items. Default: 10.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine processed-items audit
	 *     wp datamachine processed-items audit --handler=ticketmaster
	 *     wp datamachine processed-items audit --pipeline=3 --min-waste=0
	 *
	 * @subcommand audit
	 */
	public function audit( array $args, array $assoc_args ): void {
		global $wpdb;

		$handler_filter  = $assoc_args['handler'] ?? null;
		$pipeline_filter = $assoc_args['pipeline'] ?? null;
		$min_waste       = (int) ( $assoc_args['min-waste'] ?? 10 );
		$format          = $assoc_args['format'] ?? 'table';

		$db    = new ProcessedItems();
		$table = $db->get_table_name();

		// Get processed items grouped by flow_step_id and source_type.
		$where_clauses = array();
		$prepare_args  = array( $table );

		if ( $handler_filter ) {
			$where_clauses[] = 'source_type = %s';
			$prepare_args[]  = $handler_filter;
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					flow_step_id,
					source_type,
					COUNT(*) as processed_count,
					MIN(processed_timestamp) as first_processed,
					MAX(processed_timestamp) as last_processed,
					CAST(SUBSTRING_INDEX(flow_step_id, '_', -1) AS UNSIGNED) as flow_id
				FROM %i
				{$where_sql}
				GROUP BY flow_step_id, source_type
				ORDER BY processed_count DESC",
				...$prepare_args
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			WP_CLI::log( 'No processed items found.' );
			return;
		}

		// Enrich with flow names and filter by pipeline.
		$db_flows = new \DataMachine\Core\Database\Flows\Flows();
		$rows     = array();

		foreach ( $results as $row ) {
			$flow_id = (int) $row['flow_id'];
			$flow    = $db_flows->get_flow( $flow_id );

			if ( ! $flow ) {
				continue;
			}

			if ( $pipeline_filter && (int) $flow['pipeline_id'] !== (int) $pipeline_filter ) {
				continue;
			}

			$processed = (int) $row['processed_count'];
			$waste     = $processed; // Conservative: all processed items are "waste" until proven otherwise.

			if ( $waste < $min_waste ) {
				continue;
			}

			$rows[] = array(
				'flow_id'     => $flow_id,
				'flow_name'   => $flow['flow_name'] ?? '?',
				'pipeline_id' => $flow['pipeline_id'] ?? '?',
				'handler'     => $row['source_type'],
				'processed'   => $processed,
				'first_seen'  => $row['first_processed'],
				'last_seen'   => $row['last_processed'],
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No flows match the criteria.' );
			return;
		}

		// Summary.
		$total_processed = array_sum( array_column( $rows, 'processed' ) );
		WP_CLI::log( sprintf( 'Total processed items across %d flows: %s', count( $rows ), number_format( $total_processed ) ) );
		WP_CLI::log( '' );

		WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Clear processed items for a pipeline, flow, handler, or date range.
	 *
	 * Resets deduplication tracking so items can be re-processed.
	 *
	 * ## OPTIONS
	 *
	 * [--pipeline=<pipeline_id>]
	 * : Clear all processed items for this pipeline ID.
	 *
	 * [--flow=<flow_id>]
	 * : Clear all processed items for this flow ID.
	 *
	 * [--handler=<handler_type>]
	 * : Clear all processed items for this handler type (e.g., "ticketmaster", "dice_fm").
	 *
	 * [--after=<date>]
	 * : Only clear items processed after this date (YYYY-MM-DD or datetime).
	 *
	 * [--before=<date>]
	 * : Only clear items processed before this date (YYYY-MM-DD or datetime).
	 *
	 * [--all]
	 * : Clear ALL processed items. Requires --yes.
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine processed-items clear --pipeline=12
	 *     wp datamachine processed-items clear --flow=42
	 *     wp datamachine processed-items clear --handler=ticketmaster --yes
	 *     wp datamachine processed-items clear --handler=ticketmaster --after=2025-01-01
	 *     wp datamachine processed-items clear --all --yes
	 *     wp datamachine processed-items clear --handler=dice_fm --dry-run
	 *
	 * @subcommand clear
	 */
	public function clear( array $args, array $assoc_args ): void {
		global $wpdb;

		$pipeline_id  = $assoc_args['pipeline'] ?? null;
		$flow_id      = $assoc_args['flow'] ?? null;
		$handler      = $assoc_args['handler'] ?? null;
		$after        = $assoc_args['after'] ?? null;
		$before       = $assoc_args['before'] ?? null;
		$clear_all    = isset( $assoc_args['all'] );
		$dry_run      = isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		// Validate: need at least one filter.
		$has_filter = $pipeline_id || $flow_id || $handler || $after || $before || $clear_all;
		if ( ! $has_filter ) {
			WP_CLI::error( 'Specify at least one of: --pipeline, --flow, --handler, --after, --before, or --all.' );
			return;
		}

		// If --handler or date filters are used, go direct to DB.
		// Otherwise use the abilities API for pipeline/flow clearing.
		if ( $handler || $after || $before || $clear_all ) {
			$this->clear_with_filters( $assoc_args );
			return;
		}

		// Legacy path: pipeline or flow via abilities.
		if ( $pipeline_id && $flow_id ) {
			WP_CLI::error( 'Specify either --pipeline or --flow, not both.' );
			return;
		}

		$clear_type = $pipeline_id ? 'pipeline' : 'flow';
		$target_id  = (int) ( $pipeline_id ?? $flow_id );

		if ( ! $skip_confirm ) {
			WP_CLI::confirm(
				sprintf( 'Clear all processed items for %s %d? This resets deduplication.', $clear_type, $target_id )
			);
		}

		$ability = new ProcessedItemsAbilities();
		$result  = $ability->executeClearProcessedItems(
			array(
				'clear_type' => $clear_type,
				'target_id'  => $target_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to clear processed items.' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Clear processed items using handler/date filters via direct DB queries.
	 *
	 * @param array $assoc_args CLI associative args.
	 */
	private function clear_with_filters( array $assoc_args ): void {
		global $wpdb;

		$handler      = $assoc_args['handler'] ?? null;
		$pipeline_id  = $assoc_args['pipeline'] ?? null;
		$flow_id      = $assoc_args['flow'] ?? null;
		$after        = $assoc_args['after'] ?? null;
		$before       = $assoc_args['before'] ?? null;
		$clear_all    = isset( $assoc_args['all'] );
		$dry_run      = isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		$db    = new ProcessedItems();
		$table = $db->get_table_name();

		$where_parts = array();
		$values      = array( $table );

		if ( $handler ) {
			$where_parts[] = 'source_type = %s';
			$values[]      = $handler;
		}

		if ( $flow_id ) {
			$where_parts[] = 'flow_step_id LIKE %s';
			$values[]      = '%_' . $flow_id;
		}

		if ( $pipeline_id ) {
			// Get flows for this pipeline and build OR condition.
			$db_flows = new \DataMachine\Core\Database\Flows\Flows();
			$flows    = $db_flows->get_flows_for_pipeline( (int) $pipeline_id );

			if ( empty( $flows ) ) {
				WP_CLI::error( sprintf( 'No flows found for pipeline %s.', $pipeline_id ) );
				return;
			}

			$flow_patterns = array();
			foreach ( $flows as $flow ) {
				$flow_patterns[] = 'flow_step_id LIKE %s';
				$values[]        = '%_' . $flow['flow_id'];
			}
			$where_parts[] = '(' . implode( ' OR ', $flow_patterns ) . ')';
		}

		if ( $after ) {
			$where_parts[] = 'processed_timestamp >= %s';
			$values[]      = $after;
		}

		if ( $before ) {
			$where_parts[] = 'processed_timestamp <= %s';
			$values[]      = $before;
		}

		if ( ! $clear_all && empty( $where_parts ) ) {
			WP_CLI::error( 'No valid filters provided.' );
			return;
		}

		$where_sql = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

		// Count first.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_sql}", ...$values )
		);

		if ( 0 === $count ) {
			WP_CLI::log( 'No processed items match the criteria.' );
			return;
		}

		// Build description for confirmation.
		$desc_parts = array();
		if ( $handler ) {
			$desc_parts[] = "handler={$handler}";
		}
		if ( $pipeline_id ) {
			$desc_parts[] = "pipeline={$pipeline_id}";
		}
		if ( $flow_id ) {
			$desc_parts[] = "flow={$flow_id}";
		}
		if ( $after ) {
			$desc_parts[] = "after={$after}";
		}
		if ( $before ) {
			$desc_parts[] = "before={$before}";
		}
		if ( $clear_all ) {
			$desc_parts[] = 'ALL';
		}

		$desc = implode( ', ', $desc_parts );

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'DRY RUN: Would delete %s processed items matching: %s', number_format( $count ), $desc ) );
			return;
		}

		if ( ! $skip_confirm ) {
			WP_CLI::confirm(
				sprintf( 'Delete %s processed items matching: %s?', number_format( $count ), $desc )
			);
		}

		// Delete.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM %i {$where_sql}", ...$values )
		);

		if ( false === $deleted ) {
			WP_CLI::error( 'Database error during deletion: ' . $wpdb->last_error );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %s processed items (%s).', number_format( $deleted ), $desc ) );
	}

	/**
	 * Check if a specific item has been processed.
	 *
	 * ## OPTIONS
	 *
	 * --flow-step=<flow_step_id>
	 * : Flow step ID in format "{pipeline_step_id}_{flow_id}".
	 *
	 * --source=<source_type>
	 * : Source type (e.g., "rss", "reddit", "WordPress").
	 *
	 * --item=<item_identifier>
	 * : Item identifier (GUID, URL, or post ID).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine processed-items check --flow-step=3_12 --source=rss --item="https://example.com/post-1"
	 *
	 * @subcommand check
	 */
	public function check( array $args, array $assoc_args ): void {
		$flow_step_id    = $assoc_args['flow-step'] ?? '';
		$source_type     = $assoc_args['source'] ?? '';
		$item_identifier = $assoc_args['item'] ?? '';

		if ( empty( $flow_step_id ) ) {
			WP_CLI::error( 'Flow step ID is required (--flow-step=<id>).' );
			return;
		}

		if ( empty( $source_type ) ) {
			WP_CLI::error( 'Source type is required (--source=<type>).' );
			return;
		}

		if ( empty( $item_identifier ) ) {
			WP_CLI::error( 'Item identifier is required (--item=<identifier>).' );
			return;
		}

		$ability = new ProcessedItemsAbilities();
		$result  = $ability->executeCheckProcessedItem(
			array(
				'flow_step_id'    => $flow_step_id,
				'source_type'     => $source_type,
				'item_identifier' => $item_identifier,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to check processed item.' );
			return;
		}

		if ( $result['is_processed'] ) {
			WP_CLI::log( 'Status: PROCESSED' );
			WP_CLI::log( sprintf( 'Item "%s" has already been processed for flow step %s.', $item_identifier, $flow_step_id ) );
		} else {
			WP_CLI::log( 'Status: NOT PROCESSED' );
			WP_CLI::log( sprintf( 'Item "%s" has not been processed for flow step %s.', $item_identifier, $flow_step_id ) );
		}
	}

	/**
	 * Check if a flow step has any processing history.
	 *
	 * ## OPTIONS
	 *
	 * --flow-step=<flow_step_id>
	 * : Flow step ID in format "{pipeline_step_id}_{flow_id}".
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine processed-items history --flow-step=3_12
	 *
	 * @subcommand history
	 */
	public function history( array $args, array $assoc_args ): void {
		$flow_step_id = $assoc_args['flow-step'] ?? '';

		if ( empty( $flow_step_id ) ) {
			WP_CLI::error( 'Flow step ID is required (--flow-step=<id>).' );
			return;
		}

		$ability = new ProcessedItemsAbilities();
		$result  = $ability->executeHasProcessedHistory(
			array(
				'flow_step_id' => $flow_step_id,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to check processing history.' );
			return;
		}

		if ( $result['has_history'] ) {
			WP_CLI::success( sprintf( 'Flow step %s has processing history.', $flow_step_id ) );
		} else {
			WP_CLI::log( sprintf( 'Flow step %s has no processing history (first run or cleared).', $flow_step_id ) );
		}
	}
}
