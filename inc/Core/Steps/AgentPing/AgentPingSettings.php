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
			'webhook_url' => array(
				'type'        => 'text',
				'label'       => __( 'Webhook URL', 'data-machine' ),
				'description' => __( 'URL to POST data to (Discord, Slack, custom endpoint)', 'data-machine' ),
				'required'    => true,
			),
			'prompt'      => array(
				'type'        => 'textarea',
				'label'       => __( 'Instructions', 'data-machine' ),
				'description' => __( 'Optional instructions for the receiving agent', 'data-machine' ),
				'default'     => '',
			),
		);
	}
}
