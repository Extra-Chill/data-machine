<?php
/**
 * Slack Publish Handler Settings
 *
 * @package DataMachineBusiness
 * @subpackage Handlers\Slack
 * @since 0.2.0
 */

namespace DataMachineBusiness\Handlers\Slack;

use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlackSettings extends PublishHandlerSettings {

	public static function get_fields(): array {
		return array_merge(
			parent::get_common_fields(),
			array(
				'slack_channel' => array(
					'type'        => 'text',
					'label'       => __( 'Channel ID', 'data-machine-business' ),
					'description' => __( 'Slack channel ID (e.g., C12345678) or channel name (e.g., #general). The bot must be a member of the channel.', 'data-machine-business' ),
					'placeholder' => 'C12345678',
					'required'    => true,
				),
				'slack_thread_ts' => array(
					'type'        => 'text',
					'label'       => __( 'Thread Parent (optional)', 'data-machine-business' ),
					'description' => __( 'Timestamp of a parent message to reply in a thread. Leave empty to post as a new message.', 'data-machine-business' ),
					'placeholder' => '1234567890.123456',
				),
				'slack_unfurl_links' => array(
					'type'        => 'checkbox',
					'label'       => __( 'Unfurl Links', 'data-machine-business' ),
					'description' => __( 'Enable rich link previews for URLs in messages.', 'data-machine-business' ),
					'default'     => false,
				),
			)
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );

		// Channel ID: allow alphanumeric, hyphens, underscores, and # prefix
		if ( ! empty( $sanitized['slack_channel'] ) ) {
			$sanitized['slack_channel'] = preg_replace( '/[^a-zA-Z0-9_#-]/', '', $sanitized['slack_channel'] );
		}

		// Thread timestamp: numeric with optional dot
		if ( ! empty( $sanitized['slack_thread_ts'] ) ) {
			$sanitized['slack_thread_ts'] = preg_replace( '/[^0-9.]/', '', $sanitized['slack_thread_ts'] );
		}

		return $sanitized;
	}

	public static function validate_authentication( int $user_id ) {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
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
