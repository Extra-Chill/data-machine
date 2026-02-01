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
			'trigger_mode'   => array(
				'type'        => 'select',
				'label'       => __( 'Trigger Mode', 'data-machine' ),
				'description' => __( 'How to notify the receiving agent', 'data-machine' ),
				'default'     => 'webhook',
				'options'     => array(
					'webhook'  => __( 'Webhook (Discord, Slack, custom)', 'data-machine' ),
					'openclaw' => __( 'OpenClaw Agent (CLI)', 'data-machine' ),
				),
			),
			'webhook_url'    => array(
				'type'        => 'text',
				'label'       => __( 'Webhook URL', 'data-machine' ),
				'description' => __( 'URL to POST data to (Discord, Slack, custom endpoint)', 'data-machine' ),
				'required'    => false,
				'conditions'  => array(
					'trigger_mode' => 'webhook',
				),
			),
			'openclaw_path'  => array(
				'type'        => 'text',
				'label'       => __( 'OpenClaw Path', 'data-machine' ),
				'description' => __( 'Path to openclaw CLI binary (default: openclaw)', 'data-machine' ),
				'default'     => 'openclaw',
				'conditions'  => array(
					'trigger_mode' => 'openclaw',
				),
			),
			'channel'        => array(
				'type'        => 'text',
				'label'       => __( 'Channel', 'data-machine' ),
				'description' => __( 'Delivery channel for agent response (e.g., discord)', 'data-machine' ),
				'default'     => 'discord',
				'conditions'  => array(
					'trigger_mode' => 'openclaw',
				),
			),
			'deliver'        => array(
				'type'        => 'checkbox',
				'label'       => __( 'Deliver Response', 'data-machine' ),
				'description' => __( 'Whether the agent should deliver its response to the channel', 'data-machine' ),
				'default'     => true,
				'conditions'  => array(
					'trigger_mode' => 'openclaw',
				),
			),
			'prompt'         => array(
				'type'        => 'textarea',
				'label'       => __( 'Instructions', 'data-machine' ),
				'description' => __( 'Optional instructions for the receiving agent', 'data-machine' ),
				'default'     => '',
			),
		);
	}
}
