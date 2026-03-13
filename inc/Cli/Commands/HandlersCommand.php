<?php
/**
 * WP-CLI Handlers Command
 *
 * Wraps HandlerAbilities for handler discovery and configuration.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\HandlerAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handler discovery and configuration.
 *
 * @since 0.41.0
 */
class HandlersCommand extends BaseCommand {

	/**
	 * List registered handlers.
	 *
	 * ## OPTIONS
	 *
	 * [--step-type=<step_type>]
	 * : Filter by step type (fetch, publish, update, etc.).
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
	 *     wp datamachine handlers list
	 *     wp datamachine handlers list --step-type=fetch
	 *     wp datamachine handlers list --format=json
	 *
	 * @subcommand list
	 */
	public function list_handlers( array $args, array $assoc_args ): void {
		$step_type = $assoc_args['step-type'] ?? null;
		$format    = $assoc_args['format'] ?? 'table';

		$ability = new HandlerAbilities();
		$result  = $ability->executeGetHandlers(
			array(
				'step_type' => $step_type,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get handlers.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $result['handlers'] ) ) {
			$msg = $step_type
				? sprintf( 'No handlers found for step type "%s".', $step_type )
				: 'No handlers registered.';
			WP_CLI::warning( $msg );
			return;
		}

		$items = array();
		foreach ( $result['handlers'] as $slug => $handler ) {
			$items[] = array(
				'slug'      => $slug,
				'label'     => $handler['label'] ?? '',
				'step_type' => $handler['step_type'] ?? '',
			);
		}

		$fields = array( 'slug', 'label', 'step_type' );
		$this->format_items( $items, $fields, $assoc_args, 'slug' );

		WP_CLI::log( sprintf( 'Total: %d handler(s).', $result['count'] ) );
	}

	/**
	 * Validate a handler slug.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Handler slug to validate.
	 *
	 * [--step-type=<step_type>]
	 * : Optional step type constraint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine handlers validate rss-feed
	 *     wp datamachine handlers validate rss-feed --step-type=fetch
	 *
	 * @subcommand validate
	 */
	public function validate( array $args, array $assoc_args ): void {
		$slug      = $args[0] ?? '';
		$step_type = $assoc_args['step-type'] ?? null;

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$ability = new HandlerAbilities();
		$result  = $ability->executeValidateHandler(
			array(
				'handler_slug' => $slug,
				'step_type'    => $step_type,
			)
		);

		if ( $result['valid'] ) {
			WP_CLI::success( sprintf( 'Handler "%s" is valid.', $slug ) );
		} else {
			WP_CLI::error( $result['error'] ?? sprintf( 'Handler "%s" is not valid.', $slug ) );
		}
	}

	/**
	 * Get configuration fields for a handler.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Handler slug.
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
	 *     wp datamachine handlers fields rss-feed
	 *     wp datamachine handlers fields rss-feed --format=json
	 *
	 * @subcommand fields
	 */
	public function fields( array $args, array $assoc_args ): void {
		$slug   = $args[0] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Handler slug is required.' );
			return;
		}

		$ability = new HandlerAbilities();
		$result  = $ability->executeGetHandlerConfigFields(
			array(
				'handler_slug' => $slug,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get config fields.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $result['fields'] ) ) {
			WP_CLI::warning( sprintf( 'No config fields defined for handler "%s".', $slug ) );
			return;
		}

		$items = array();
		foreach ( $result['fields'] as $key => $field ) {
			$default_val = isset( $field['default'] ) ? wp_json_encode( $field['default'] ) : '';
			$items[]     = array(
				'key'      => $key,
				'type'     => $field['type'] ?? 'text',
				'label'    => $field['label'] ?? $key,
				'required' => ! empty( $field['required'] ) ? 'yes' : 'no',
				'default'  => $default_val,
			);
		}

		$fields = array( 'key', 'type', 'label', 'required', 'default' );
		$this->format_items( $items, $fields, $assoc_args, 'key' );
	}

	/**
	 * Get site-wide handler defaults.
	 *
	 * ## OPTIONS
	 *
	 * [<slug>]
	 * : Optional handler slug to filter defaults.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine handlers defaults
	 *     wp datamachine handlers defaults rss-feed
	 *
	 * @subcommand defaults
	 */
	public function defaults( array $args, array $assoc_args ): void {
		$slug   = $args[0] ?? null;
		$format = $assoc_args['format'] ?? 'json';

		$ability = new HandlerAbilities();
		$result  = $ability->executeGetHandlerSiteDefaults(
			array(
				'handler_slug' => $slug,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get handler defaults.' );
			return;
		}

		if ( empty( $result['defaults'] ) ) {
			$msg = $slug
				? sprintf( 'No defaults configured for handler "%s".', $slug )
				: 'No handler defaults configured.';
			WP_CLI::warning( $msg );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result['defaults'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Table format: flatten to key/value pairs.
		$items = array();
		foreach ( $result['defaults'] as $key => $value ) {
			$items[] = array(
				'key'   => $key,
				'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
			);
		}

		$this->format_items( $items, array( 'key', 'value' ), $assoc_args, 'key' );
	}
}
