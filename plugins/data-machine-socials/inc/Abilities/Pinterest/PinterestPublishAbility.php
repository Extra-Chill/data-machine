<?php
/**
 * Pinterest Publish Ability
 *
 * Primitive ability for publishing content to Pinterest as pins.
 *
 * @package DataMachineSocials\Abilities\Pinterest
 * @since 0.1.0
 */

namespace DataMachineSocials\Abilities\Pinterest;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest Publish Ability
 */
class PinterestPublishAbility {

	/**
	 * Whether the ability has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Register Pinterest publish ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/pinterest-publish',
				array(
					'label' => __( 'Publish to Pinterest', 'data-machine-socials' ),
					'description' => __( 'Create a pin on Pinterest with image, title, description, and link', 'data-machine-socials' ),
					'category' => 'datamachine',
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'title', 'description', 'image_url' ),
						'properties' => array(
							'title' => array(
								'type' => 'string',
								'description' => 'Pin title (max 100 characters)',
								'maxLength' => 100,
							),
							'description' => array(
								'type' => 'string',
								'description' => 'Pin description (max 500 characters)',
								'maxLength' => 500,
							),
							'image_url' => array(
								'type' => 'string',
								'description' => 'URL to the image for the pin',
								'format' => 'uri',
							),
							'link' => array(
								'type' => 'string',
								'description' => 'URL the pin will link to',
								'format' => 'uri',
							),
							'board_id' => array(
								'type' => 'string',
								'description' => 'Pinterest board ID (uses default if omitted)',
							),
						),
					),
					'output_schema' => array(
						'type' => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pin_id' => array( 'type' => 'string' ),
							'pin_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'error' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta' => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute Pinterest publish.
	 *
	 * @param array $input Ability input with publish parameters.
	 * @return array Response with pin details or error.
	 */
	public static function execute_publish( array $input ): array {
		$auth = new AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return array(
				'success' => false,
				'error' => 'Pinterest not authenticated',
			);
		}

		$config = $provider->get_config();
		$token = $config['access_token'] ?? '';

		$title = sanitize_text_field( $input['title'] ?? '' );
		$description = sanitize_textarea_field( $input['description'] ?? '' );
		$image_url = esc_url_raw( $input['image_url'] ?? '' );
		$link = esc_url_raw( $input['link'] ?? '' );
		$board_id = sanitize_text_field( $input['board_id'] ?? '' );

		if ( empty( $title ) || empty( $description ) || empty( $image_url ) ) {
			return array(
				'success' => false,
				'error' => 'Missing required fields: title, description, image_url',
			);
		}

		// Get default board if no board_id provided
		if ( empty( $board_id ) ) {
			$board_id = $config['default_board_id'] ?? '';
		}

		if ( empty( $board_id ) ) {
			return array(
				'success' => false,
				'error' => 'No board ID provided and no default board configured',
			);
		}

		$post_data = array(
			'title' => $title,
			'description' => $description,
			'link' => $link,
			'media_source' => array(
				'source_type' => 'image_url',
				'url' => $image_url,
			),
		);

		$url = 'https://api.pinterest.com/v5/pins';
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type' => 'application/json',
		);

		$result = HttpClient::post( $url, array(
			'headers' => $headers,
			'body' => wp_json_encode( $post_data ),
			'timeout' => 30,
			'context' => 'Pinterest Pin Creation',
		) );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error' => $result['error'] ?? 'Failed to create pin',
			);
		}

		$data = json_decode( $result['data'], true );

		if ( ! isset( $data['id'] ) ) {
			return array(
				'success' => false,
				'error' => 'Invalid response from Pinterest API',
			);
		}

		$pin_id = $data['id'];
		$pin_url = 'https://pinterest.com/pin/' . $pin_id;

		return array(
			'success' => true,
			'pin_id' => $pin_id,
			'pin_url' => $pin_url,
		);
	}
}
