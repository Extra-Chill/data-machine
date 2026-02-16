<?php
/**
 * Webhook Gate Settings - Configuration fields for webhook gate steps.
 *
 * @package DataMachine\Core\Steps\WebhookGate
 * @since 0.25.0
 */

namespace DataMachine\Core\Steps\WebhookGate;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookGateSettings extends SettingsHandler {

	/**
	 * Get configuration fields for the webhook gate step.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		return [
			'timeout_hours' => [
				'type'        => 'number',
				'label'       => 'Timeout (hours)',
				'description' => 'How long to wait for the webhook before failing the job. 0 = no timeout (defaults to 7-day token expiry).',
				'default'     => 0,
				'min'         => 0,
				'max'         => 8760,
			],
			'description' => [
				'type'        => 'text',
				'label'       => 'Description',
				'description' => 'Human-readable description of what this gate is waiting for.',
				'default'     => '',
			],
		];
	}
}
