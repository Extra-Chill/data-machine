<?php
/**
 * Email Publish Handler Settings
 *
 * Defines settings fields and sanitization for the email publish handler.
 * These are default values configurable in the admin UI — the AI can
 * override recipients, subject, and body per execution.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Email
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Email;

use DataMachine\Core\Steps\Settings\SettingsHandler;

defined( 'ABSPATH' ) || exit;

class EmailSettings extends SettingsHandler {

	/**
	 * Get settings fields for email publish handler.
	 *
	 * @return array Associative array defining the settings fields.
	 */
	public static function get_fields(): array {
		return array(
			// Recipients.
			'default_to'      => array(
				'type'        => 'text',
				'label'       => __( 'Default To', 'data-machine' ),
				'description' => __( 'Comma-separated default recipient(s). The AI can override per execution.', 'data-machine' ),
			),
			'default_cc'      => array(
				'type'        => 'text',
				'label'       => __( 'CC', 'data-machine' ),
				'description' => __( 'Comma-separated CC addresses.', 'data-machine' ),
			),
			'default_bcc'     => array(
				'type'        => 'text',
				'label'       => __( 'BCC', 'data-machine' ),
				'description' => __( 'Comma-separated BCC addresses.', 'data-machine' ),
			),

			// Sender.
			'from_name'       => array(
				'type'        => 'text',
				'label'       => __( 'From Name', 'data-machine' ),
				'description' => __( 'Sender name. Defaults to site name. May be overridden by your SMTP plugin.', 'data-machine' ),
			),
			'from_email'      => array(
				'type'        => 'text',
				'label'       => __( 'From Email', 'data-machine' ),
				'description' => __( 'Sender email. Defaults to admin email. May be overridden by your SMTP plugin.', 'data-machine' ),
			),
			'reply_to'        => array(
				'type'        => 'text',
				'label'       => __( 'Reply-To', 'data-machine' ),
				'description' => __( 'Reply-to address if different from sender.', 'data-machine' ),
			),

			// Content.
			'default_subject' => array(
				'type'        => 'text',
				'label'       => __( 'Default Subject', 'data-machine' ),
				'description' => __( 'Default subject template. Supports {month}, {year}, {site_name} placeholders.', 'data-machine' ),
			),
			'content_type'    => array(
				'type'        => 'select',
				'label'       => __( 'Content Type', 'data-machine' ),
				'options'     => array(
					'text/html'  => __( 'HTML', 'data-machine' ),
					'text/plain' => __( 'Plain Text', 'data-machine' ),
				),
				'default'     => 'text/html',
				'description' => __( 'Email body format.', 'data-machine' ),
			),
		);
	}

	/**
	 * Determine if authentication is required.
	 *
	 * Email uses wp_mail() which handles transport — no auth needed at handler level.
	 *
	 * @param array $current_config Current configuration values.
	 * @return bool Always false.
	 */
	public static function requires_authentication( array $current_config = array() ): bool {
		return false;
	}
}
