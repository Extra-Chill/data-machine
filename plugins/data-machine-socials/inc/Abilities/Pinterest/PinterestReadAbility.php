<?php
/**
 * Pinterest Read Ability
 *
 * Abilities API primitive for reading Pinterest pins, boards, and board pins.
 * Uses static bearer token authentication.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Pinterest
 * @since      0.3.0
 */

namespace DataMachineSocials\Abilities\Pinterest;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Handlers\Pinterest\PinterestAuth;

defined( 'ABSPATH' ) || exit;

class PinterestReadAbility {

	private static bool $registered = false;

	const API_URL = 'https://api.pinterest.com/v5';

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/pinterest-read',
				array(
					'label'               => __( 'Read Pinterest Pins', 'data-machine-socials' ),
					'description'         => __( 'List pins, get pin details, list boards, or list board pins', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'        => 'string',
								'enum'        => array( 'pins', 'pin', 'boards', 'board_pins' ),
								'default'     => 'pins',
								'description' => __( 'Action: pins (user pins), pin (single pin), boards (list boards), board_pins (pins from a board)', 'data-machine-socials' ),
							),
							'pin_id'   => array(
								'type'        => 'string',
								'description' => __( 'Pinterest pin ID (required for pin action)', 'data-machine-socials' ),
							),
							'board_id' => array(
								'type'        => 'string',
								'description' => __( 'Pinterest board ID (required for board_pins action)', 'data-machine-socials' ),
							),
							'bookmark' => array(
								'type'        => 'string',
								'description' => __( 'Pagination bookmark for next page', 'data-machine-socials' ),
							),
							'limit'    => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Number of items to return (max 100)', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	public function execute( array $input ): array {
		$action = $input['action'] ?? 'pins';

		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest auth provider not available',
			);
		}

		$token = $auth->get_valid_access_token();
		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings',
			);
		}

		switch ( $action ) {
			case 'pins':
				return $this->listPins( $token, $input );

			case 'pin':
				if ( empty( $input['pin_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'pin_id is required for the pin action',
					);
				}
				return $this->getPin( $token, $input['pin_id'] );

			case 'boards':
				return $this->listBoards( $token, $input );

			case 'board_pins':
				if ( empty( $input['board_id'] ) ) {
					return array(
						'success' => false,
						'error'   => 'board_id is required for the board_pins action',
					);
				}
				return $this->getBoardPins( $token, $input['board_id'], $input );

			default:
				return array(
					'success' => false,
					'error'   => "Unknown action: {$action}. Use pins, pin, boards, or board_pins.",
				);
		}
	}

	private function listPins( string $token, array $input ): array {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array( 'page_size' => $limit );

		if ( ! empty( $input['bookmark'] ) ) {
			$params['bookmark'] = $input['bookmark'];
		}

		return $this->apiGet( $token, '/pins?' . http_build_query( $params ), 'items', 'pins' );
	}

	private function getPin( string $token, string $pin_id ): array {
		$url    = self::API_URL . "/pins/{$pin_id}";
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Pinterest Read',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['code'] ) ) {
			$error_msg = $data['message'] ?? 'Failed to fetch pin details';
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	private function listBoards( string $token, array $input ): array {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array( 'page_size' => $limit );

		if ( ! empty( $input['bookmark'] ) ) {
			$params['bookmark'] = $input['bookmark'];
		}

		return $this->apiGet( $token, '/boards?' . http_build_query( $params ), 'items', 'boards' );
	}

	private function getBoardPins( string $token, string $board_id, array $input ): array {
		$limit  = min( absint( $input['limit'] ?? 25 ), 100 );
		$params = array( 'page_size' => $limit );

		if ( ! empty( $input['bookmark'] ) ) {
			$params['bookmark'] = $input['bookmark'];
		}

		return $this->apiGet( $token, "/boards/{$board_id}/pins?" . http_build_query( $params ), 'items', 'pins' );
	}

	/**
	 * Generic Pinterest GET request that returns paginated results.
	 *
	 * @param string $token     Bearer token.
	 * @param string $path      API path with query string.
	 * @param string $data_key  Key in response containing items.
	 * @param string $label     Label for the result array.
	 * @return array
	 */
	private function apiGet( string $token, string $path, string $data_key, string $label ): array {
		$url    = self::API_URL . $path;
		$result = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Pinterest Read',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Pinterest API request failed: ' . ( $result['error'] ?? 'unknown' ),
			);
		}

		$data      = json_decode( $result['data'], true );
		$http_code = $result['status_code'];

		if ( 200 !== $http_code || isset( $data['code'] ) ) {
			$error_msg = $data['message'] ?? "Failed to fetch {$label}";
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$items    = $data[ $data_key ] ?? array();
		$bookmark = $data['bookmark'] ?? null;

		return array(
			'success' => true,
			'data'    => array(
				$label     => $items,
				'count'    => count( $items ),
				'bookmark' => $bookmark,
				'has_next' => ! empty( $bookmark ),
			),
		);
	}

	private function getAuthProvider(): ?PinterestAuth {
		$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'pinterest' );

		if ( $provider instanceof PinterestAuth ) {
			return $provider;
		}

		return null;
	}
}
