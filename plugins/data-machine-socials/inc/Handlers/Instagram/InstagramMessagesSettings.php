<?php
/** Instagram direct-message fetch settings. @package DataMachineSocials */
namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

defined( 'ABSPATH' ) || exit;

class InstagramMessagesSettings extends FetchHandlerSettings {
	public static function get_fields(): array {
		return array(
			'max_items'          => array(
				'type'    => 'number',
				'label'   => __( 'Maximum items', 'data-machine-socials' ),
				'min'     => 1,
				'max'     => 500,
				'default' => 10,
			),
			'days_back'          => array(
				'type'    => 'number',
				'label'   => __( 'Days back (0 = unlimited)', 'data-machine-socials' ),
				'min'     => 0,
				'default' => 7,
			),
			'require_attachment' => array(
				'type'    => 'checkbox',
				'label'   => __( 'Require an image attachment', 'data-machine-socials' ),
				'default' => true,
			),
			'conversation_limit' => array(
				'type'    => 'number',
				'label'   => __( 'Maximum conversations', 'data-machine-socials' ),
				'min'     => 1,
				'max'     => 100,
				'default' => 20,
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		$settings                       = parent::sanitize( $raw_settings );
		$settings['max_items']          = max( 1, min( 500, (int) ( $raw_settings['max_items'] ?? 10 ) ) );
		$settings['days_back']          = max( 0, (int) ( $raw_settings['days_back'] ?? 7 ) );
		$settings['conversation_limit'] = max( 1, min( 100, (int) ( $raw_settings['conversation_limit'] ?? 20 ) ) );
		$settings['require_attachment'] = filter_var( $raw_settings['require_attachment'] ?? true, FILTER_VALIDATE_BOOLEAN );
		return $settings;
	}
}
