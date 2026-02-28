<?php
/**
 * WP-CLI Settings Command
 *
 * Thin wrapper around SettingsAbilities for CLI access.
 * All business logic delegated to SettingsAbilities.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.6
 */

namespace DataMachineEvents\Cli;

use WP_CLI;
use WP_CLI_Command;
use DataMachineEvents\Abilities\SettingsAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Data Machine Events plugin settings.
 *
 * ## EXAMPLES
 *
 *     # Get a setting
 *     wp data-machine-events settings get next_day_cutoff
 *
 *     # Set a setting
 *     wp data-machine-events settings set next_day_cutoff 05:00
 *
 *     # List all settings
 *     wp data-machine-events settings list
 */
class SettingsCommand extends WP_CLI_Command {

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
	 *     wp data-machine-events settings get next_day_cutoff
	 *     wp data-machine-events settings get map_display_type --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$key    = $args[0];
		$format = $assoc_args['format'] ?? 'value';

		$abilities = new SettingsAbilities();
		$result    = $abilities->executeGetSettings( array( 'key' => $key ) );

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		$value = $result['value'];

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
	 *     wp data-machine-events settings set next_day_cutoff 05:00
	 *     wp data-machine-events settings set map_display_type carto-positron
	 *     wp data-machine-events settings set include_in_search true
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( array $args, array $assoc_args ): void {
		$key   = $args[0];
		$value = $args[1];

		$abilities = new SettingsAbilities();
		$result    = $abilities->executeUpdateSetting(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		if ( $result['success'] ) {
			WP_CLI::success(
				"Updated '{$key}': " . $this->format_value( $result['old_value'] ) . ' → ' . $this->format_value( $result['new_value'] )
			);
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
	 *     wp data-machine-events settings list
	 *     wp data-machine-events settings list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$abilities = new SettingsAbilities();
		$result    = $abilities->executeGetSettings( array() );

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( $result['error'] );
		}

		$settings = $result['settings'];

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
