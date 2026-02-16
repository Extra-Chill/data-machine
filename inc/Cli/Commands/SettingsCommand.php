<?php
/**
 * WP-CLI Settings Command
 *
 * Provides CLI access to Data Machine plugin settings.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.11.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Data Machine plugin settings.
 *
 * ## EXAMPLES
 *
 *     # Get a setting
 *     wp datamachine settings get default_model
 *
 *     # Set a setting
 *     wp datamachine settings set default_model gpt-4o-mini
 *
 *     # List all settings
 *     wp datamachine settings list
 */
class SettingsCommand extends BaseCommand {

	/**
	 * Get a setting value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key to retrieve.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: value
	 * options:
	 *   - value
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine settings get default_model
	 *     wp datamachine settings get default_provider --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$key    = $args[0];
		$format = $assoc_args['format'] ?? 'value';

		$value = PluginSettings::get( $key );

		if ( null === $value ) {
			WP_CLI::error( "Setting '{$key}' is not set." );
		}

		if ( 'json' === $format ) {
			WP_CLI::log(
				wp_json_encode(
					array(
						'key'   => $key,
						'value' => $value,
					),
					JSON_PRETTY_PRINT
				)
			);
		} elseif ( is_array( $value ) || is_object( $value ) ) {
				WP_CLI::log( wp_json_encode( $value, JSON_PRETTY_PRINT ) );
		} elseif ( is_bool( $value ) ) {
			WP_CLI::log( $value ? 'true' : 'false' );
		} else {
			WP_CLI::log( (string) $value );
		}
	}

	/**
	 * Set a setting value.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key to update.
	 *
	 * <value>
	 * : The new value. Use 'true'/'false' for booleans, integers for numbers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine settings set default_model gpt-4o-mini
	 *     wp datamachine settings set default_provider openai
	 *     wp datamachine settings set site_context_enabled true
	 *     wp datamachine settings set problem_flow_threshold 5
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( array $args, array $assoc_args ): void {
		$key   = $args[0];
		$value = $args[1];

		// Type coercion
		if ( 'true' === $value ) {
			$value = true;
		} elseif ( 'false' === $value ) {
			$value = false;
		} elseif ( is_numeric( $value ) && strpos( $value, '.' ) === false ) {
			$value = (int) $value;
		} elseif ( is_string( $value ) ) {
			$trimmed_value = trim( $value );

			// Allow JSON input for array/object settings.
			if ( '' !== $trimmed_value && ( str_starts_with( $trimmed_value, '{' ) || str_starts_with( $trimmed_value, '[' ) ) ) {
				$decoded = json_decode( $trimmed_value, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$value = $decoded;
				}
			}

			// Convenience format for enabled_tools: comma-separated list of tool IDs.
			if ( 'enabled_tools' === $key && is_string( $value ) && '' !== trim( $value ) && ! str_contains( $value, '{' ) ) {
				$tool_ids = array_filter( array_map( 'trim', explode( ',', $value ) ) );
				if ( ! empty( $tool_ids ) ) {
					$value = array_fill_keys( $tool_ids, true );
				}
			}
		}

		$old_value = PluginSettings::get( $key );

		$ability = wp_get_ability( 'datamachine/update-settings' );
		if ( ! $ability ) {
			WP_CLI::error( 'Settings ability not available.' );
		}

		$result = $ability->execute( array( $key => $value ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( $result['success'] ?? false ) {
			WP_CLI::success( "Updated '{$key}': " . $this->format_value( $old_value ) . ' → ' . $this->format_value( $value ) );
		} elseif ( $old_value === $value ) {
			WP_CLI::warning( "Setting '{$key}' already has value: " . $this->format_value( $value ) );
		} elseif ( ! empty( $result['error'] ) ) {
			WP_CLI::error( (string) $result['error'] );
		} else {
			WP_CLI::error( "Failed to update setting '{$key}'." );
		}
	}

	/**
	 * List all settings.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine settings list
	 *     wp datamachine settings list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$settings = PluginSettings::all();

		if ( empty( $settings ) ) {
			WP_CLI::warning( 'No settings configured.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$rows = array();
			foreach ( $settings as $key => $value ) {
				$rows[] = array(
					'key'   => $key,
					'value' => $this->format_value( $value ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
		}
	}

	/**
	 * Format a value for display.
	 *
	 * @param mixed $value Value to format.
	 * @return string Formatted value.
	 */
	private function format_value( mixed $value ): string {
		if ( null === $value ) {
			return '(null)';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
