<?php
/**
 * Settings Abilities
 *
 * Provides settings CRUD via WordPress Abilities API.
 * Single source of truth for plugin settings access.
 *
 * Abilities:
 * - datamachine-events/get-settings    — Read all settings or a specific key
 * - datamachine-events/update-setting  — Update a single setting value
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsAbilities {

	private const OPTION_KEY = 'datamachine_events_settings';

	private const DEFAULTS = array(
		'include_in_archives'  => false,
		'include_in_search'    => true,
		'main_events_page_url' => '',
		'map_display_type'     => 'osm-standard',
		'geonames_username'    => '',
		'next_day_cutoff'      => '05:00',
	);

	private const ALLOWED_MAP_TYPES = array(
		'osm-standard',
		'carto-positron',
		'carto-voyager',
		'carto-dark',
		'humanitarian',
	);

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetSettingsAbility();
			$this->registerUpdateSettingAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -------------------------------------------------------------------------
	// Ability: get-settings
	// -------------------------------------------------------------------------

	private function registerGetSettingsAbility(): void {
		wp_register_ability(
			'datamachine-events/get-settings',
			array(
				'label'               => __( 'Get Settings', 'datamachine-events' ),
				'description'         => __( 'Read plugin settings. Returns all settings or a specific key.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'key' => array(
							'type'        => 'string',
							'description' => 'Specific setting key to read. Omit for all settings.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array(
							'type'        => 'object',
							'description' => 'All settings as key-value pairs (when no key specified)',
						),
						'key'      => array( 'type' => 'string' ),
						'value'    => array( 'description' => 'Setting value (when key specified)' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetSettings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute get-settings ability.
	 *
	 * @param array $input Input with optional 'key'.
	 * @return array Settings data.
	 */
	public function executeGetSettings( array $input ): array {
		$settings = get_option( self::OPTION_KEY, self::DEFAULTS );
		$settings = wp_parse_args( $settings, self::DEFAULTS );

		$key = $input['key'] ?? '';

		if ( ! empty( $key ) ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				return array( 'error' => "Unknown setting key: {$key}" );
			}
			return array(
				'key'   => $key,
				'value' => $settings[ $key ],
			);
		}

		return array( 'settings' => $settings );
	}

	// -------------------------------------------------------------------------
	// Ability: update-setting
	// -------------------------------------------------------------------------

	private function registerUpdateSettingAbility(): void {
		wp_register_ability(
			'datamachine-events/update-setting',
			array(
				'label'               => __( 'Update Setting', 'datamachine-events' ),
				'description'         => __( 'Update a single plugin setting. Validates and sanitizes the value.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'key', 'value' ),
					'properties' => array(
						'key'   => array(
							'type'        => 'string',
							'description' => 'Setting key to update',
						),
						'value' => array(
							'description' => 'New value (type depends on the setting)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'key'       => array( 'type' => 'string' ),
						'old_value' => array( 'description' => 'Previous value' ),
						'new_value' => array( 'description' => 'Updated value' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdateSetting' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute update-setting ability.
	 *
	 * @param array $input Input with 'key' and 'value'.
	 * @return array Result with old and new values.
	 */
	public function executeUpdateSetting( array $input ): array {
		$key   = $input['key'] ?? '';
		$value = $input['value'] ?? null;

		if ( empty( $key ) ) {
			return array(
				'success' => false,
				'error'   => 'Setting key is required.',
			);
		}

		if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
			return array(
				'success' => false,
				'error'   => "Unknown setting key: {$key}. Valid keys: " . implode( ', ', array_keys( self::DEFAULTS ) ),
			);
		}

		// Sanitize value based on the setting type.
		$value = $this->sanitizeValue( $key, $value );

		if ( is_wp_error( $value ) ) {
			return array(
				'success' => false,
				'error'   => $value->get_error_message(),
			);
		}

		$settings  = get_option( self::OPTION_KEY, self::DEFAULTS );
		$old_value = $settings[ $key ] ?? self::DEFAULTS[ $key ];

		$settings[ $key ] = $value;
		$updated          = update_option( self::OPTION_KEY, $settings );

		if ( ! $updated && $old_value !== $value ) {
			return array(
				'success' => false,
				'error'   => "Failed to update setting '{$key}'.",
			);
		}

		return array(
			'success'   => true,
			'key'       => $key,
			'old_value' => $old_value,
			'new_value' => $value,
		);
	}

	/**
	 * Sanitize a setting value based on its key.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed|\WP_Error Sanitized value or error.
	 */
	private function sanitizeValue( string $key, $value ) {
		switch ( $key ) {
			case 'include_in_archives':
			case 'include_in_search':
				if ( is_string( $value ) ) {
					$value = in_array( strtolower( $value ), array( 'true', '1', 'yes' ), true );
				}
				return (bool) $value;

			case 'main_events_page_url':
				return ! empty( $value ) ? esc_url_raw( (string) $value ) : '';

			case 'map_display_type':
				if ( ! in_array( $value, self::ALLOWED_MAP_TYPES, true ) ) {
					return new \WP_Error(
						'invalid_value',
						"Invalid map type: {$value}. Allowed: " . implode( ', ', self::ALLOWED_MAP_TYPES )
					);
				}
				return $value;

			case 'geonames_username':
				return sanitize_text_field( (string) $value );

			case 'next_day_cutoff':
				$value = sanitize_text_field( (string) $value );
				if ( ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
					return new \WP_Error( 'invalid_value', "Invalid time format: {$value}. Expected HH:MM." );
				}
				return $value;

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
