<?php
/** Public Instagram account fetch settings. @package DataMachineSocials */
namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

defined( 'ABSPATH' ) || exit;

class InstagramAccountSettings extends FetchHandlerSettings {
	public static function get_fields(): array {
		return array(
			'username'  => array(
				'type'     => 'text',
				'label'    => __( 'Instagram username', 'data-machine-socials' ),
				'required' => true,
			),
			'max_items' => array(
				'type'    => 'number',
				'label'   => __( 'Maximum items', 'data-machine-socials' ),
				'min'     => 1,
				'max'     => 500,
				'default' => 10,
			),
			'days_back' => array(
				'type'  => 'number',
				'label' => __( 'Days back (0 = unlimited)', 'data-machine-socials' ),
				'min'   => 0,
			),
			'search'    => array(
				'type'        => 'text',
				'label'       => __( 'Caption keywords', 'data-machine-socials' ),
				'description' => __( 'Include captions matching any comma-separated keyword (case-insensitive).', 'data-machine-socials' ),
			),
		);
	}

	public static function normalizeUsername( string $username ): string {
		$username = trim( $username );
		$username = preg_replace( '~^(?:https?://)?(?:www\.)?instagram\.com/~i', '', $username );
		$username = ltrim( $username, '@' );
		$token    = strtok( $username, '/?#' );
		$username = strtolower( false === $token ? '' : $token );
		return preg_match( '/^[a-z0-9_.]{1,30}$/', $username ) ? $username : '';
	}

	public static function sanitize( array $raw_settings ): array {
		$settings             = parent::sanitize( $raw_settings );
		$settings['username'] = self::normalizeUsername( (string) ( $raw_settings['username'] ?? '' ) );
		return $settings;
	}
}
