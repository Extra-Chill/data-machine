<?php
/**
 * WP-CLI commands for durable tracked items.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Abilities\TrackedItemsAbilities;
use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class TrackedItemsCommand extends BaseCommand {

	/**
	 * Upsert one tracked item.
	 *
	 * ## OPTIONS
	 *
	 * --namespace=<namespace>
	 * --item-id=<item_id>
	 * [--item-type=<item_type>]
	 * [--state=<state>]
	 * [--source-ref=<source_ref>]
	 * [--source-revision=<source_revision>]
	 * [--source-path=<source_path>]
	 * [--source-line=<source_line>]
	 * [--output-ref=<output_ref>]
	 * [--metadata=<json>]
	 * [--last-job-id=<job_id>]
	 * [--format=<format>]
	 */
	public function upsert( array $args, array $assoc_args ): void {
		$input  = $this->input_from_assoc_args( $assoc_args );
		$result = ( new TrackedItemsAbilities() )->executeUpsertTrackedItem( $input );
		$this->output_result( $result, (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * Get one tracked item.
	 *
	 * ## OPTIONS
	 *
	 * --namespace=<namespace>
	 * --item-id=<item_id>
	 * [--format=<format>]
	 */
	public function get( array $args, array $assoc_args ): void {
		$result = ( new TrackedItemsAbilities() )->executeGetTrackedItem(
			array(
				'namespace' => (string) ( $assoc_args['namespace'] ?? '' ),
				'item_id'   => (string) ( $assoc_args['item-id'] ?? $assoc_args['item_id'] ?? '' ),
			)
		);
		$this->output_result( $result, (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * List tracked items.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * [--item-type=<item_type>]
	 * [--state=<state>]
	 * [--source-ref=<source_ref>]
	 * [--output-ref=<output_ref>]
	 * [--limit=<limit>]
	 * [--offset=<offset>]
	 * [--format=<format>]
	 */
	public function list( array $args, array $assoc_args ): void {
		$result = ( new TrackedItemsAbilities() )->executeListTrackedItems( $this->input_from_assoc_args( $assoc_args ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		$items = (array) ( $result['items'] ?? array() );
		if ( empty( $items ) ) {
			WP_CLI::log( 'No tracked items found.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'namespace', 'item_id', 'item_type', 'state', 'source_ref', 'source_path', 'output_ref', 'updated_at' ) );
	}

	/**
	 * Summarize tracked items by type and state.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * [--item-type=<item_type>]
	 * [--state=<state>]
	 * [--source-ref=<source_ref>]
	 * [--output-ref=<output_ref>]
	 * [--format=<format>]
	 */
	public function summary( array $args, array $assoc_args ): void {
		$result = ( new TrackedItemsAbilities() )->executeTrackedItemsSummary( $this->input_from_assoc_args( $assoc_args ) );
		WP_CLI::print_value( $result, array( 'format' => (string) ( $assoc_args['format'] ?? 'json' ) ) );
	}

	/** @return array<string,mixed> */
	private function input_from_assoc_args( array $assoc_args ): array {
		$map   = array(
			'item-id'         => 'item_id',
			'item-type'       => 'item_type',
			'source-ref'      => 'source_ref',
			'source-revision' => 'source_revision',
			'source-path'     => 'source_path',
			'source-line'     => 'source_line',
			'output-ref'      => 'output_ref',
			'last-job-id'     => 'last_job_id',
		);
		$input = array();
		foreach ( $assoc_args as $key => $value ) {
			if ( 'format' === $key ) {
				continue;
			}
			$input[ $map[ $key ] ?? $key ] = $value;
		}

		if ( isset( $input['metadata'] ) && is_string( $input['metadata'] ) ) {
			$decoded           = json_decode( $input['metadata'], true );
			$input['metadata'] = is_array( $decoded ) ? $decoded : array();
		}

		return $input;
	}

	private function output_result( array $result, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::print_value( $result, array( 'format' => 'json' ) );
			return;
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Tracked item command failed.' ) );
		}

		$item = (array) ( $result['item'] ?? array() );
		if ( empty( $item ) ) {
			WP_CLI::log( 'Tracked item not found.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, array( $item ), array( 'id', 'namespace', 'item_id', 'item_type', 'state', 'source_ref', 'source_path', 'output_ref', 'updated_at' ) );
	}
}
