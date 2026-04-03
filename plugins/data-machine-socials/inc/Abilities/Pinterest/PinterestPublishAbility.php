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
					'label'               => __( 'Publish to Pinterest', 'data-machine-socials' ),
					'description'         => __( 'Create a pin on Pinterest with image, title, description, and link', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'title', 'description', 'image_url' ),
						'properties' => array(
							'title'       => array(
								'type'        => 'string',
								'description' => 'Pin title (max 100 characters)',
								'maxLength'   => 100,
							),
							'description' => array(
								'type'        => 'string',
								'description' => 'Pin description (max 500 characters)',
								'maxLength'   => 500,
							),
							'image_url'   => array(
								'type'        => 'string',
								'description' => 'URL to the image for the pin',
								'format'      => 'uri',
							),
							'link'        => array(
								'type'        => 'string',
								'description' => 'URL the pin will link to',
								'format'      => 'uri',
							),
							'board_id'    => array(
								'type'        => 'string',
								'description' => 'Pinterest board ID (uses default if omitted)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pin_id'  => array( 'type' => 'string' ),
							'pin_url' => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_publish' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
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
	public static function execute_publish( array $input ): array|\WP_Error {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'Pinterest not authenticated', array( 'status' => 401 ) );
		}

		$config = $provider->get_config();
		$token  = $provider->get_valid_access_token();

		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		$title       = sanitize_text_field( $input['title'] ?? '' );
		$description = sanitize_textarea_field( $input['description'] ?? '' );
		$image_url   = esc_url_raw( $input['image_url'] ?? '' );
		$link        = esc_url_raw( $input['link'] ?? '' );
		$board_id    = sanitize_text_field( $input['board_id'] ?? '' );

		if ( empty( $title ) || empty( $description ) || empty( $image_url ) ) {
			return new \WP_Error( 'api_error', 'Missing required fields: title, description, image_url', array( 'status' => 500 ) );
		}

		// Get default board if no board_id provided
		if ( empty( $board_id ) ) {
			$board_id = $config['default_board_id'] ?? '';
		}

		if ( empty( $board_id ) ) {
			return new \WP_Error( 'api_error', 'No board ID provided and no default board configured', array( 'status' => 500 ) );
		}

		$post_data = array(
			'title'        => $title,
			'description'  => $description,
			'link'         => $link,
			'media_source' => array(
				'source_type' => 'image_url',
				'url'         => $image_url,
			),
		);

		$url     = 'https://api.pinterest.com/v5/pins';
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);

		$result = HttpClient::post( $url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $post_data ),
			'timeout' => 30,
			'context' => 'Pinterest Pin Creation',
		) );

		if ( ! $result['success'] ) {
			return new \WP_Error( 'api_error', $result['error'] ?? 'Failed to create pin', array( 'status' => 500 ) );
		}

		$data = json_decode( $result['data'], true );

		if ( ! isset( $data['id'] ) ) {
			return new \WP_Error( 'api_error', 'Invalid response from Pinterest API', array( 'status' => 500 ) );
		}

		$pin_id  = $data['id'];
		$pin_url = 'https://pinterest.com/pin/' . $pin_id;

		return array(
			'success' => true,
			'pin_id'  => $pin_id,
			'pin_url' => $pin_url,
		);
	}

	public function execute( array $input ): array|\WP_Error {
		$action = $input['action'] ?? 'user';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Pinterest auth provider not available', array( 'status' => 401 ) );
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		// Parse date range
		$start_date = $input['start_date'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = $input['end_date'] ?? gmdate( 'Y-m-d' );

		// Validate date format
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return new \WP_Error( 'missing_param', 'Invalid date format. Use YYYY-MM-DD.', array( 'status' => 400 ) );
		}

		// Parse metrics (default to key engagement metrics)
		$default_metrics = array( 'IMPRESSION', 'SAVE', 'PIN_CLICK', 'COMMENT', 'CLOSEUP' );
		$metrics         = $input['metrics'] ?? $default_metrics;

		// Validate metrics
		$metrics = array_filter( (array) $metrics, function ( $m ) {
			return in_array( strtoupper( $m ), self::METRIC_TYPES, true );
		});

		if ( empty( $metrics ) ) {
			$metrics = $default_metrics;
		}

		$metrics_param = implode( ',', array_map( 'strtoupper', $metrics ) );

		switch ( $action ) {
			case 'user':
				return $this->getUserAnalytics( $token, $start_date, $end_date, $metrics_param );

			case 'pin':
				if ( empty( $input['pin_id'] ) ) {
					return new \WP_Error( 'missing_param', 'pin_id is required for the pin action', array( 'status' => 400 ) );
				}
				return $this->getPinAnalytics( $token, $input['pin_id'], $start_date, $end_date, $metrics_param );

			case 'board':
				if ( empty( $input['board_id'] ) ) {
					return new \WP_Error( 'missing_param', 'board_id is required for the board action', array( 'status' => 400 ) );
				}
				return $this->getBoardAnalytics( $token, $input['board_id'], $start_date, $end_date, $metrics_param );

			default:
				return new \WP_Error( 'api_error', "Unknown action: {$action}. Use user, pin, or board.", array( 'status' => 500 ) );
		}
	}

	private function getAuthProvider(): ?PinterestAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( $provider instanceof PinterestAuth ) {
			return $provider;
		}

		return null;
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
