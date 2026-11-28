<?php
/**
 * Discord Publish Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Discord
 * @since 0.3.0
 */

namespace DataMachineBusiness\Handlers\Discord;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscordSettings extends PublishHandlerSettings {

	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'discord_channel_id' => array(
					'type'        => 'text',
					'label'       => __( 'Channel ID', 'data-machine-business' ),
					'description' => __( 'Discord channel ID (e.g., 123456789012345678). The bot must have access to the channel.', 'data-machine-business' ),
					'placeholder' => '123456789012345678',
					'required'    => true,
				),
			)
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );

		// Channel ID: numeric only
		if ( ! empty( $sanitized['discord_channel_id'] ) ) {
			$sanitized['discord_channel_id'] = preg_replace( '/[^0-9]/', '', $sanitized['discord_channel_id'] );
		}

		return $sanitized;
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
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
