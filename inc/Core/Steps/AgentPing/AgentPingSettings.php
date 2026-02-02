<?php
/**
 * Agent Ping Step Settings
 *
 * Defines configuration fields for the Agent Ping step type.
 * Used by the admin UI to render configuration forms.
 *
 * @package DataMachine\Core\Steps\AgentPing
 * @since 0.18.0
 */

namespace DataMachine\Core\Steps\AgentPing;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentPingSettings extends SettingsHandler {

	/**
	 * Get settings fields for Agent Ping step.
	 *
	 * @return array Field definitions for the configuration UI.
	 */
	public static function get_fields(): array {
		return array(
			'webhook_url'      => array(
				'type'        => 'url_list',
				'label'       => __( 'Webhook URL(s)', 'data-machine' ),
				'description' => __( 'URL(s) to POST data to (Discord, Slack, or custom endpoints).', 'data-machine' ),
				'required'    => true,
			),
			'prompt'           => array(
				'type'        => 'textarea',
				'label'       => __( 'Instructions', 'data-machine' ),
				'description' => __( 'Optional instructions for the receiving agent', 'data-machine' ),
				'default'     => '',
			),
			'auth_header_name' => array(
				'type'        => 'text',
				'label'       => __( 'Auth Header Name', 'data-machine' ),
				'description' => __( 'Optional header name for authentication (e.g., X-Agent-Token, Authorization).', 'data-machine' ),
				'default'     => '',
			),
			'auth_token'       => array(
				'type'        => 'text',
				'label'       => __( 'Auth Token', 'data-machine' ),
				'description' => __( 'Optional token/key to send in the auth header.', 'data-machine' ),
				'default'     => '',
			),
		);
	}
}
