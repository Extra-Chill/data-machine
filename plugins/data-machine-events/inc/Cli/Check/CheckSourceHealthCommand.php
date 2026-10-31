<?php
/** @package DataMachineEvents */
namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\SourceHealth;

class CheckSourceHealthCommand {
	public const FIELDS = array( 'flow_id', 'flow_name', 'pipeline_name', 'handlers', 'source_url', 'status', 'needs_attention', 'last_published', 'lifetime_count', 'upcoming_count', 'total', 'with_items', 'no_items', 'failed', 'failure_class', 'source_materialized', 'rejection_reason' );

	/**
	 * Report event-import source health. No writes or live source requests.
	 *
	 * ## OPTIONS
	 *
	 * [--pipeline=<id>]
	 * : Limit to a pipeline.
	 *
	 * [--status=<buckets>]
	 * : Comma-separated status buckets.
	 *
	 * [--format=<format>]
	 * : Output format: table, json or csv. JSON includes summary and flows.
	 * ---
	 * default: table
	 * ---
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$statuses = isset( $assoc_args['status'] ) ? explode( ',', $assoc_args['status'] ) : array();
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) || array_diff( $statuses, SourceHealth::STATUSES ) || ( isset( $assoc_args['pipeline'] ) && ! preg_match( '/^[1-9][0-9]*$/', (string) $assoc_args['pipeline'] ) ) || isset( $assoc_args['apply'] ) ) {
			\WP_CLI::error( 'Invalid source-health options. Use a positive pipeline, known statuses, and table|json|csv; this command is read-only.' );
			return;
		}
		$result = $this->health()->report( (int) ( $assoc_args['pipeline'] ?? 0 ), $statuses );
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $result ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $result['flows'], self::FIELDS );
		// Keep CSV machine-readable: summary goes to stderr rather than CSV rows.
		$summary = 'Source health (all selected pipeline flows): ' . wp_json_encode( $result['summary'] );
		if ( 'csv' === $format ) {
			\WP_CLI::warning( $summary );
		} else {
			\WP_CLI::line( $summary );
		}
	}

	protected function health(): SourceHealth {
		return new SourceHealth();
	}
}
