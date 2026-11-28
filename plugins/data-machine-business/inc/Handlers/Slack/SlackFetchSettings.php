<?php
/**
 * Slack Fetch Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Handlers\Slack;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlackFetchSettings extends SettingsHandler {

	public static function get_fields(): array {
		return array(
			'slack_fetch_channel' => array(
				'type'        => 'text',
				'label'       => __( 'Channel ID', 'data-machine-business' ),
				'description' => __( 'Slack channel ID to fetch messages from (e.g., C12345678). The bot must be a member of the channel.', 'data-machine-business' ),
				'placeholder' => 'C12345678',
				'required'    => true,
			),
			'slack_fetch_limit' => array(
				'type'        => 'number',
				'label'       => __( 'Message Limit', 'data-machine-business' ),
				'description' => __( 'Maximum number of messages to fetch per run (1-1000).', 'data-machine-business' ),
				'default'     => 20,
				'min'         => 1,
				'max'         => 1000,
			),
			'slack_fetch_oldest' => array(
				'type'        => 'text',
				'label'       => __( 'Oldest Message (optional)', 'data-machine-business' ),
				'description' => __( 'Only fetch messages after this Unix timestamp.', 'data-machine-business' ),
				'placeholder' => '1234567890.123456',
			),
			'slack_fetch_latest' => array(
				'type'        => 'text',
				'label'       => __( 'Latest Message (optional)', 'data-machine-business' ),
				'description' => __( 'Only fetch messages before this Unix timestamp.', 'data-machine-business' ),
				'placeholder' => '1234567890.123456',
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );

		// Channel ID: allow alphanumeric, hyphens, underscores
		if ( ! empty( $sanitized['slack_fetch_channel'] ) ) {
			$sanitized['slack_fetch_channel'] = preg_replace( '/[^a-zA-Z0-9_-]/', '', $sanitized['slack_fetch_channel'] );
		}

		// Limit: clamp to valid range
		$sanitized['slack_fetch_limit'] = min( max( intval( $sanitized['slack_fetch_limit'] ?? 20 ), 1 ), 1000 );

		// Timestamps: numeric with optional dot
		foreach ( array( 'slack_fetch_oldest', 'slack_fetch_latest' ) as $field ) {
			if ( ! empty( $sanitized[ $field ] ) ) {
				$sanitized[ $field ] = preg_replace( '/[^0-9.]/', '', $sanitized[ $field ] );
			} else {
				unset( $sanitized[ $field ] );
			}
		}

		return $sanitized;
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new AuthAbilities();
		$auth_provider  = $auth_abilities->getProvider( 'slack' );

		if ( ! $auth_provider ) {
			return new \WP_Error( 'slack_auth_unavailable', __( 'Slack authentication service not available.', 'data-machine-business' ) );
		}

		if ( ! $auth_abilities->isHandlerAuthenticated( 'slack' ) ) {
			return new \WP_Error( 'slack_not_authenticated', __( 'Slack authentication required. Configure your bot token in Data Machine settings.', 'data-machine-business' ) );
		}

		return true;
	}
}
