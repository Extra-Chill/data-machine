<?php
/**
 * Webhook payload fetch handler settings.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

defined( 'ABSPATH' ) || exit;

class WebhookPayloadSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for Webhook Payload fetch handler.
	 *
	 * @return array Settings fields.
	 */
	public static function get_fields(): array {
		return array_merge(
			array(
				'source_type'              => array(
					'type'        => 'text',
					'label'       => __( 'Source Type', 'data-machine' ),
					'description' => __( 'Packet source_type metadata value, e.g. github_webhook.', 'data-machine' ),
					'default'     => 'webhook_payload',
				),
				'title_path'               => array(
					'type'        => 'text',
					'label'       => __( 'Title Path', 'data-machine' ),
					'description' => __( 'Dot path inside webhook payload for packet title, e.g. pull_request.title.', 'data-machine' ),
				),
				'content_path'             => array(
					'type'        => 'text',
					'label'       => __( 'Content Path', 'data-machine' ),
					'description' => __( 'Dot path inside webhook payload for packet body, e.g. pull_request.body.', 'data-machine' ),
				),
				'metadata'                 => array(
					'type'        => 'textarea',
					'label'       => __( 'Metadata Mapping', 'data-machine' ),
					'description' => __( 'JSON object mapping metadata keys to webhook payload dot paths.', 'data-machine' ),
				),
				'item_identifier_template' => array(
					'type'        => 'text',
					'label'       => __( 'Item Identifier Template', 'data-machine' ),
					'description' => __( 'Template for processed-items dedupe, e.g. {repository.full_name}#{pull_request.number}@{pull_request.head.sha}.', 'data-machine' ),
				),
				'ignore_missing_paths'     => array(
					'type'        => 'checkbox',
					'label'       => __( 'Ignore Missing Paths', 'data-machine' ),
					'description' => __( 'Skip the tick when a mapped path is missing. Leave off to fail loudly for bad config.', 'data-machine' ),
				),
			),
			parent::get_common_fields()
		);
	}

	/**
	 * Sanitize webhook payload settings.
	 *
	 * @param array $raw_settings Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $raw_settings ): array {
		$sanitized = parent::sanitize( $raw_settings );
		$metadata  = $raw_settings['metadata'] ?? '';
		$decoded   = is_array( $metadata ) ? $metadata : json_decode( (string) $metadata, true );

		$sanitized['metadata'] = is_array( $decoded ) ? $decoded : array();

		return $sanitized;
	}
}
