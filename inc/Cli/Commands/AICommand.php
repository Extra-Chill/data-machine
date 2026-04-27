<?php
/**
 * WP-CLI AI debugging commands.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Engine\AI\RequestInspector;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class AICommand extends BaseCommand {

	/**
	 * Inspect the final provider request for a pipeline AI job without dispatching it.
	 *
	 * ## OPTIONS
	 *
	 * --job=<job_id>
	 * : Job ID to inspect.
	 *
	 * [--step=<flow_step_id>]
	 * : Flow step ID to inspect. Required when the job snapshot has multiple AI steps.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine ai inspect-request --job=123
	 *     wp datamachine ai inspect-request --job=123 --step=flow_step_abc --format=json
	 *
	 * @subcommand inspect-request
	 */
	public function inspect_request( array $args, array $assoc_args ): void {
		$job_id = isset( $assoc_args['job'] ) ? (int) $assoc_args['job'] : 0;
		if ( $job_id <= 0 ) {
			WP_CLI::error( 'Missing or invalid --job=<job_id>.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( 'Invalid --format. Use table or json.' );
			return;
		}

		$result = ( new RequestInspector() )->inspectPipelineJob(
			$job_id,
			isset( $assoc_args['step'] ) ? (string) $assoc_args['step'] : null
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Request inspection failed.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$this->renderTableSummary( $result );
	}

	private function renderTableSummary( array $result ): void {
		WP_CLI::log( sprintf( 'Job:        %d', (int) $result['job_id'] ) );
		WP_CLI::log( sprintf( 'Flow step:  %s', $result['flow_step_id'] ) );
		WP_CLI::log( sprintf( 'Provider:   %s', $result['provider'] ) );
		WP_CLI::log( sprintf( 'Model:      %s', $result['model'] ) );
		WP_CLI::log( sprintf( 'Mode:       %s', $result['mode'] ) );
		WP_CLI::log( '' );

		$summary = array(
			array(
				'metric' => 'message_count',
				'value'  => (int) $result['message_count'],
			),
			array(
				'metric' => 'total_request_json_bytes',
				'value'  => (int) $result['total_request_json_bytes'],
			),
			array(
				'metric' => 'messages_json_bytes',
				'value'  => (int) $result['messages_json_bytes'],
			),
			array(
				'metric' => 'tools_json_bytes',
				'value'  => (int) $result['tools_json_bytes'],
			),
			array(
				'metric' => 'conversation_user_message_bytes',
				'value'  => (int) $result['conversation_user_message_bytes'],
			),
			array(
				'metric' => 'conversation_packet_json_bytes',
				'value'  => (int) $result['conversation_packet_json_bytes'],
			),
			array(
				'metric' => 'tool_count',
				'value'  => (int) $result['tool_count'],
			),
		);
		\WP_CLI\Utils\format_items( 'table', $summary, array( 'metric', 'value' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'Directives' );
		$directives = array_map(
			fn( $row ) => array(
				'class'    => $row['class'] ?? '',
				'priority' => $row['priority'] ?? 0,
				'outputs'  => $row['output_count'] ?? 0,
				'messages' => $row['rendered_message_count'] ?? 0,
				'content'  => $row['content_bytes'] ?? 0,
				'json'     => $row['json_bytes'] ?? 0,
			),
			$result['directives'] ?? array()
		);
		if ( ! empty( $directives ) ) {
			\WP_CLI\Utils\format_items( 'table', $directives, array( 'class', 'priority', 'outputs', 'messages', 'content', 'json' ) );
		} else {
			WP_CLI::log( 'No directives rendered.' );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Largest tools' );
		$tools = $result['largest_tools'] ?? array();
		if ( ! empty( $tools ) ) {
			\WP_CLI\Utils\format_items( 'table', $tools, array( 'name', 'json_bytes' ) );
		} else {
			WP_CLI::log( 'No tools available.' );
		}
	}
}
