<?php
/**
 * WP-CLI Settings Command
 *
 * Provides CLI access to Data Machine Events plugin settings.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.6
 */

namespace DataMachineEvents\Cli;

use WP_CLI;
use WP_CLI_Command;
use DataMachineEvents\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Data Machine Events plugin settings.
 *
 * ## EXAMPLES
 *
 *     # Get a setting
 *     wp datamachine-events settings get next_day_cutoff
 *
 *     # Set a setting
 *     wp datamachine-events settings set next_day_cutoff 05:00
 *
 *     # List all settings
 *     wp datamachine-events settings list
 */
class SettingsCommand extends WP_CLI_Command {

	private const OPTION_NAME = 'datamachine_events_settings';

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
	 *     wp datamachine-events settings get next_day_cutoff
	 *     wp datamachine-events settings get map_display_type --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$key    = $args[0];
		$format = $assoc_args['format'] ?? 'value';

		$value = Settings_Page::get_setting( $key );

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
	 * : The new value. Use 'true'/'false' for booleans.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-events settings set next_day_cutoff 05:00
	 *     wp datamachine-events settings set map_display_type carto-positron
	 *     wp datamachine-events settings set include_in_search true
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( array $args, array $assoc_args ): void {
		$key   = $args[0];
		$value = $args[1];

		if ( 'true' === $value ) {
			$value = true;
		} elseif ( 'false' === $value ) {
			$value = false;
		}

		$settings         = get_option( self::OPTION_NAME, array() );
		$old_value        = $settings[ $key ] ?? null;
		$settings[ $key ] = $value;

		$result = update_option( self::OPTION_NAME, $settings );

		if ( $result ) {
			WP_CLI::success( "Updated '{$key}': " . $this->format_value( $old_value ) . ' → ' . $this->format_value( $value ) );
		} elseif ( $old_value === $value ) {
				WP_CLI::warning( "Setting '{$key}' already has value: " . $this->format_value( $value ) );
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
	 *     wp datamachine-events settings list
	 *     wp datamachine-events settings list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$settings = get_option( self::OPTION_NAME, array() );

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
