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

defined( 'ABSPATH' ) || exit;

/**
 * Processed items (deduplication) management.
 *
 * @since 0.41.0
 */
class ProcessedItemsCommand extends BaseCommand {

	/**
	 * Clear processed items for a pipeline or flow.
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
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine processed-items clear --pipeline=12
	 *     wp datamachine processed-items clear --flow=42
	 *     wp datamachine processed-items clear --pipeline=12 --yes
	 *
	 * @subcommand clear
	 */
	public function clear( array $args, array $assoc_args ): void {
		$pipeline_id  = $assoc_args['pipeline'] ?? null;
		$flow_id      = $assoc_args['flow'] ?? null;
		$skip_confirm = isset( $assoc_args['yes'] );

		if ( $pipeline_id && $flow_id ) {
			WP_CLI::error( 'Specify either --pipeline or --flow, not both.' );
			return;
		}

		if ( ! $pipeline_id && ! $flow_id ) {
			WP_CLI::error( 'Either --pipeline=<id> or --flow=<id> is required.' );
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
