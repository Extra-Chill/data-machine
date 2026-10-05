<?php
/**
 * Base WP-CLI Command
 *
 * Provides standardized formatting methods using WP-CLI's native Formatter.
 * All Data Machine CLI commands extend this class.
 *
 * @package DataMachine\Cli
 * @since 0.15.1
 */

namespace DataMachine\Cli;

use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

/**
 * Base WP-CLI command class with standardized formatting methods.
 *
 * All Data Machine CLI commands extend this class to access shared
 * formatting utilities using WP-CLI's native Formatter.
 *
 * @since 0.15.1
 */
class BaseCommand extends WP_CLI_Command {

	/**
	 * Build bounded sync-runner options shared by flow and pipeline commands.
	 *
	 * @param array $assoc_args WP-CLI associative arguments.
	 * @return array Runner options.
	 */
	protected function build_sync_runner_options( array $assoc_args ): array {
		$options = array(
			'max_steps'       => (int) ( $assoc_args['max-steps'] ?? $assoc_args['max_steps'] ?? 20 ),
			'max_items'       => (int) ( $assoc_args['max-items'] ?? $assoc_args['max_items'] ?? 50 ),
			'timeout_seconds' => (int) ( $assoc_args['timeout'] ?? $assoc_args['timeout-seconds'] ?? 60 ),
			'show_packets'    => isset( $assoc_args['show-packets'] ) || isset( $assoc_args['show_packets'] ),
		);

		$input_file = $assoc_args['input-file'] ?? $assoc_args['input_file'] ?? null;
		if ( is_string( $input_file ) && '' !== $input_file ) {
			$options['input_packets'] = $this->read_sync_input_packets( $input_file );
		}

		return $options;
	}

	/**
	 * Output sync-runner diagnostics.
	 *
	 * @param array  $packet Diagnostics packet.
	 * @param string $format Output format.
	 */
	protected function output_sync_runner_packet( array $packet, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( 'yaml', array( $packet ), array_keys( $packet ) );
			return;
		}

		WP_CLI::log( sprintf( 'Success: %s', ! empty( $packet['success'] ) ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Stopped: %s', $packet['stopped_reason'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( 'Job ID:  %s', $packet['job_id'] ?? 'none' ) );
		WP_CLI::log( sprintf( 'Steps:   %d', (int) ( $packet['counts']['steps_executed'] ?? 0 ) ) );
		WP_CLI::log( sprintf( 'Packets: %d', (int) ( $packet['counts']['packets_seen'] ?? 0 ) ) );

		$steps = is_array( $packet['steps'] ?? null ) ? $packet['steps'] : array();
		if ( ! empty( $steps ) ) {
			$rows = array_map(
				static function ( array $step ): array {
					return array(
						'flow_step_id' => $step['flow_step_id'] ?? '',
						'step_type'    => $step['step_type'] ?? '',
						'input'        => $step['input_count'] ?? 0,
						'output'       => $step['output_count'] ?? 0,
						'next_step_id' => $step['next_step_id'] ?? '',
					);
				},
				$steps
			);
			$this->format_items( $rows, array( 'flow_step_id', 'step_type', 'input', 'output', 'next_step_id' ), array( 'format' => 'table' ) );
		}
	}

	/**
	 * Read JSON input packets for run-sync.
	 *
	 * @param string $input_file JSON file path.
	 * @return array<int, array> Packet list.
	 */
	private function read_sync_input_packets( string $input_file ): array {
		$contents = file_get_contents( $input_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads a local JSON path supplied by --input-file.
		if ( false === $contents ) {
			WP_CLI::error( sprintf( 'Unable to read input file: %s', $input_file ) );
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( sprintf( 'Input file must contain JSON object or array: %s', $input_file ) );
		}

		if ( isset( $decoded['data_packets'] ) && is_array( $decoded['data_packets'] ) ) {
			$decoded = $decoded['data_packets'];
		} elseif ( isset( $decoded['packets'] ) && is_array( $decoded['packets'] ) ) {
			$decoded = $decoded['packets'];
		}

		if ( array_is_list( $decoded ) ) {
			return array_values( array_filter( $decoded, 'is_array' ) );
		}

		return array( $decoded );
	}

	/**
	 * Format and display items using WP-CLI's native Formatter.
	 *
	 * @param array  $items      Items to display (array of associative arrays).
	 * @param array  $fields     Default fields/columns to display.
	 * @param array  $assoc_args Command arguments (format, fields).
	 * @param string $id_field   Field name to use for --format=ids.
	 */
	protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
		if ( empty( $items ) ) {
			WP_CLI::warning( 'No items found.' );
			return;
		}

		// Set ID field for --format=ids.
		if ( $id_field && ! isset( $assoc_args['field'] ) ) {
			$format = $assoc_args['format'] ?? 'table';
			if ( 'ids' === $format ) {
				$assoc_args['field'] = $id_field;
			}
		}

		$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
		$formatter->display_items( $items );
	}

	/**
	 * Output pagination info (table format only).
	 *
	 * @param int    $offset      Current offset.
	 * @param int    $count       Items returned.
	 * @param int    $total       Total items available.
	 * @param string $format      Current output format.
	 * @param string $item_label  Label for items (e.g., 'flows', 'pipelines').
	 */
	protected function output_pagination( int $offset, int $count, int $total, string $format = 'table', string $item_label = 'items' ): void {
		if ( 'table' !== $format ) {
			return;
		}

		$end = $offset + $count;
		if ( $end < $total ) {
			WP_CLI::log( "Showing {$offset} - {$end} of {$total} {$item_label}. Use --offset to see more." );
		} else {
			WP_CLI::log( "Showing {$offset} - {$end} of {$total} {$item_label}." );
		}
	}
}
