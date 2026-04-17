<?php
/**
 * WP-CLI Test Command
 *
 * Universal handler dry-run. Tests any fetch handler with a config
 * and displays packet summaries.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.55.3
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\Handler\TestHandlerAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Test any fetch handler with a config (dry-run).
 *
 * ## EXAMPLES
 *
 *     # List available handlers
 *     wp datamachine test --list
 *
 *     # Show config schema for a handler
 *     wp datamachine test ticketmaster --describe
 *
 *     # Test a handler with config
 *     wp datamachine test ticketmaster --config='{"lat":32.7,"lng":-79.9,"radius":50}'
 *
 *     # Test using an existing flow's config
 *     wp datamachine test --flow=42
 *
 *     # Limit output and get JSON
 *     wp datamachine test ticketmaster --config='...' --limit=3 --format=json
 *
 * @since 0.55.3
 */
class TestCommand extends BaseCommand {

	/**
	 * Test a fetch handler or list available handlers.
	 *
	 * ## OPTIONS
	 *
	 * [<handler>]
	 * : Handler slug to test.
	 *
	 * [--config=<json>]
	 * : Handler config as JSON string.
	 *
	 * [--flow=<id>]
	 * : Pull handler and config from an existing flow ID.
	 *
	 * [--limit=<number>]
	 * : Max packets to return.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--list]
	 * : List all available fetch handlers.
	 *
	 * [--describe]
	 * : Show config fields for the given handler.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine test --list
	 *     wp datamachine test ticketmaster --describe
	 *     wp datamachine test ticketmaster --config='{"lat":32.7,"lng":-79.9,"radius":50}'
	 *     wp datamachine test rss --config='{"feed_url":"https://example.com/feed"}'
	 *     wp datamachine test --flow=42
	 *     wp datamachine test ticketmaster --config='...' --limit=3 --format=json
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$handler_slug = $args[0] ?? null;
		$format       = $assoc_args['format'] ?? 'table';

		// --list: show available handlers.
		if ( isset( $assoc_args['list'] ) || ( ! $handler_slug && ! isset( $assoc_args['flow'] ) ) ) {
			$this->listHandlers( $assoc_args );
			return;
		}

		// --describe: show config fields for a handler.
		if ( isset( $assoc_args['describe'] ) ) {
			if ( ! $handler_slug ) {
				WP_CLI::error( 'Handler slug is required with --describe.' );
				return;
			}
			$this->describeHandler( $handler_slug, $assoc_args );
			return;
		}

		// Run the test.
		$this->runTest( $handler_slug, $assoc_args );
	}

	/**
	 * List all available fetch handlers.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function listHandlers( array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$ability  = new HandlerAbilities();
		$handlers = $ability->getAllHandlers();

		if ( empty( $handlers ) ) {
			WP_CLI::warning( 'No handlers registered.' );
			return;
		}

		// Filter to fetch-type handlers (fetch + event_import).
		$fetch_types = array( 'fetch', 'event_import' );
		$items       = array();

		foreach ( $handlers as $slug => $handler ) {
			$handler_type = $handler['type'] ?? $handler['step_type'] ?? '';
			if ( ! in_array( $handler_type, $fetch_types, true ) ) {
				continue;
			}

			$items[] = array(
				'slug'  => $slug,
				'label' => $handler['label'] ?? '',
				'type'  => $handler_type,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::warning( 'No fetch handlers registered.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$fields = array( 'slug', 'label', 'type' );
		$this->format_items( $items, $fields, $assoc_args, 'slug' );

		WP_CLI::log( sprintf( 'Total: %d fetch handler(s).', count( $items ) ) );
	}

	/**
	 * Describe config fields for a handler.
	 *
	 * @param string $handler_slug Handler slug.
	 * @param array  $assoc_args   Command arguments.
	 */
	private function describeHandler( string $handler_slug, array $assoc_args ): void {
		$format  = $assoc_args['format'] ?? 'table';
		$ability = new HandlerAbilities();
		$info    = $ability->getHandler( $handler_slug );

		if ( ! $info ) {
			WP_CLI::error( sprintf( 'Handler "%s" not found.', $handler_slug ) );
			return;
		}

		$fields = $ability->getConfigFields( $handler_slug );

		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					array(
						'handler_slug' => $handler_slug,
						'label'        => $info['label'] ?? $handler_slug,
						'type'         => $info['type'] ?? $info['step_type'] ?? '',
						'fields'       => $fields,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Handler:     %s', $handler_slug ) );
		WP_CLI::log( sprintf( 'Label:       %s', $info['label'] ?? $handler_slug ) );
		WP_CLI::log( sprintf( 'Type:        %s', $info['type'] ?? $info['step_type'] ?? '' ) );
		WP_CLI::log( '' );

		if ( empty( $fields ) ) {
			WP_CLI::warning( 'No config fields defined for this handler.' );
			return;
		}

		$items = array();
		foreach ( $fields as $key => $field ) {
			$default_val = isset( $field['default'] ) ? wp_json_encode( $field['default'] ) : '';
			$items[]     = array(
				'key'      => $key,
				'type'     => $field['type'] ?? 'text',
				'label'    => $field['label'] ?? $key,
				'required' => ! empty( $field['required'] ) ? 'yes' : 'no',
				'default'  => $default_val,
			);
		}

		$field_columns = array( 'key', 'type', 'label', 'required', 'default' );
		$this->format_items( $items, $field_columns, $assoc_args, 'key' );
	}

	/**
	 * Run the handler test.
	 *
	 * @param string|null $handler_slug Handler slug (null if using --flow).
	 * @param array       $assoc_args   Command arguments.
	 */
	private function runTest( ?string $handler_slug, array $assoc_args ): void {
		$format      = $assoc_args['format'] ?? 'table';
		$config_json = $assoc_args['config'] ?? null;
		$flow_id     = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$limit       = (int) ( $assoc_args['limit'] ?? 5 );

		$config = array();
		if ( $config_json ) {
			$config = json_decode( $config_json, true );
			if ( ! is_array( $config ) ) {
				WP_CLI::error( 'Invalid JSON in --config. Provide a valid JSON object.' );
				return;
			}
		}

		// Build ability input.
		$input = array(
			'limit' => $limit,
		);

		if ( $handler_slug ) {
			$input['handler_slug'] = $handler_slug;
		}

		if ( ! empty( $config ) ) {
			$input['config'] = $config;
		}

		if ( $flow_id ) {
			$input['flow_id'] = $flow_id;
		}

		$ability = new TestHandlerAbility();
		$result  = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Test failed.' );
			return;
		}

		// JSON output — dump everything.
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table output — rich formatting.
		$this->renderTableOutput( $result );
	}

	/**
	 * Render rich table output for test results.
	 *
	 * @param array $result Ability result.
	 */
	private function renderTableOutput( array $result ): void {
		$handler_slug  = $result['handler_slug'];
		$handler_label = $result['handler_label'];
		$config_used   = $result['config_used'] ?? array();
		$packets       = $result['packets'] ?? array();
		$packet_count  = $result['packet_count'] ?? 0;
		$elapsed_ms    = $result['execution_time_ms'] ?? 0;
		$warnings      = $result['warnings'] ?? array();

		// Header.
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Handler:     %s', $handler_slug ) );
		WP_CLI::log( sprintf( 'Label:       %s', $handler_label ) );

		// Config summary (key=value pairs).
		$config_parts = array();
		foreach ( $config_used as $key => $value ) {
			// Skip internal keys.
			if ( in_array( $key, array( 'flow_step_id', 'flow_id' ), true ) ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$config_parts[] = $key . '=' . wp_json_encode( $value );
			} else {
				$config_parts[] = $key . '=' . $value;
			}
		}
		if ( ! empty( $config_parts ) ) {
			WP_CLI::log( sprintf( 'Config:      %s', implode( ', ', $config_parts ) ) );
		}

		WP_CLI::log( '' );

		if ( empty( $packets ) ) {
			WP_CLI::warning( 'No packets returned.' );
			WP_CLI::log( sprintf( 'Execution time: %ss', round( $elapsed_ms / 1000, 1 ) ) );
			return;
		}

		$count_label = count( $packets );
		if ( $packet_count > $count_label ) {
			$count_label .= ' of ' . $packet_count;
		}

		WP_CLI::log( sprintf( '── Results: %s items ──', $count_label ) );
		WP_CLI::log( '' );

		// Build table rows.
		$items             = array();
		$all_metadata_keys = array();

		foreach ( $packets as $index => $packet ) {
			$title      = $packet['title'] ?? '(untitled)';
			$source_url = $packet['source_url'] ?? '';
			$metadata   = $packet['metadata'] ?? array();

			// Extract domain from source_url.
			$source_display = '';
			if ( $source_url ) {
				$parsed         = wp_parse_url( $source_url );
				$source_display = $parsed['host'] ?? $source_url;
			}

			$items[] = array(
				'#'      => $index + 1,
				'title'  => mb_substr( $title, 0, 60 ),
				'source' => $source_display,
			);

			// Collect metadata keys (excluding internal ones).
			$internal_keys = array( 'source_type', 'pipeline_id', 'flow_id', 'handler', 'item_identifier' );
			foreach ( array_keys( $metadata ) as $mk ) {
				if ( ! in_array( $mk, $internal_keys, true ) ) {
					$all_metadata_keys[ $mk ] = true;
				}
			}
		}

		$fields = array( '#', 'title', 'source' );
		$this->format_items( $items, $fields, array( 'format' => 'table' ) );

		WP_CLI::log( '' );

		// Metadata keys summary.
		if ( ! empty( $all_metadata_keys ) ) {
			WP_CLI::log( sprintf( 'Metadata keys: %s', implode( ', ', array_keys( $all_metadata_keys ) ) ) );
		}

		// Warnings.
		foreach ( $warnings as $warning ) {
			WP_CLI::warning( $warning );
		}

		WP_CLI::log( sprintf( 'Execution time: %ss', round( $elapsed_ms / 1000, 1 ) ) );
	}
}
