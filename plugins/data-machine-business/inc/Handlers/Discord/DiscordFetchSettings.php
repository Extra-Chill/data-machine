<?php
/**
 * Discord Fetch Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\Discord;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscordFetchSettings extends SettingsHandler {

	public static function get_fields(): array {
		return array(
			'discord_fetch_channel_id' => array(
				'type'        => 'text',
				'label'       => __( 'Channel ID', 'data-machine-business' ),
				'description' => __( 'Discord channel ID to fetch messages from (e.g., 123456789012345678). The bot must have access to the channel.', 'data-machine-business' ),
				'placeholder' => '123456789012345678',
				'required'    => true,
			),
			'discord_fetch_limit' => array(
				'type'        => 'number',
				'label'       => __( 'Message Limit', 'data-machine-business' ),
				'description' => __( 'Maximum number of messages to fetch per run (1-100).', 'data-machine-business' ),
				'default'     => 50,
				'min'         => 1,
				'max'         => 100,
			),
			'discord_fetch_before' => array(
				'type'        => 'text',
				'label'       => __( 'Before Message ID (optional)', 'data-machine-business' ),
				'description' => __( 'Only fetch messages before this message ID (for pagination).', 'data-machine-business' ),
				'placeholder' => '123456789012345678',
			),
			'discord_fetch_after' => array(
				'type'        => 'text',
				'label'       => __( 'After Message ID (optional)', 'data-machine-business' ),
				'description' => __( 'Only fetch messages after this message ID (for pagination).', 'data-machine-business' ),
				'placeholder' => '123456789012345678',
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );

		// Channel ID: numeric only
		if ( ! empty( $sanitized['discord_fetch_channel_id'] ) ) {
			$sanitized['discord_fetch_channel_id'] = preg_replace( '/[^0-9]/', '', $sanitized['discord_fetch_channel_id'] );
		}

		// Limit: clamp to valid range
		$sanitized['discord_fetch_limit'] = min( max( intval( $sanitized['discord_fetch_limit'] ?? 50 ), 1 ), 100 );

		// Message IDs: numeric only
		foreach ( array( 'discord_fetch_before', 'discord_fetch_after' ) as $field ) {
			if ( ! empty( $sanitized[ $field ] ) ) {
				$sanitized[ $field ] = preg_replace( '/[^0-9]/', '', $sanitized[ $field ] );
			} else {
				unset( $sanitized[ $field ] );
			}
		}

		return $sanitized;
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new AuthAbilities();
		$auth_provider  = $auth_abilities->getProvider( 'discord' );

		if ( ! $auth_provider ) {
			return new \WP_Error( 'discord_auth_unavailable', __( 'Discord authentication service not available.', 'data-machine-business' ) );
		}

		if ( ! $auth_abilities->isHandlerAuthenticated( 'discord' ) ) {
			return new \WP_Error( 'discord_not_authenticated', __( 'Discord authentication required. Configure your bot token in Data Machine settings.', 'data-machine-business' ) );
		}

		return true;
	}
}
