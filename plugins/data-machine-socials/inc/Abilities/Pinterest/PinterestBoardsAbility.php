<?php
/**
 * Pinterest Boards Ability
 *
 * Primitive ability for Pinterest board management.
 * Handles syncing, caching, and resolution of Pinterest boards.
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
 * Pinterest Boards Ability
 *
 * Self-contained ability class following core patterns.
 */
class PinterestBoardsAbility {

	/**
	 * Option key for storing cached Pinterest boards.
	 *
	 * @var string
	 */
	const BOARDS_OPTION = 'datamachine_pinterest_boards';

	/**
	 * Option key for storing last sync timestamp.
	 *
	 * @var string
	 */
	const BOARDS_SYNCED_OPTION = 'datamachine_pinterest_boards_synced';

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
	 * Register Pinterest board abilities.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			// Sync boards ability
			wp_register_ability(
				'datamachine/pinterest-sync-boards',
				array(
					'label'               => __( 'Sync Pinterest Boards', 'data-machine-socials' ),
					'description'         => __( 'Sync Pinterest boards from API and cache locally', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'count'   => array( 'type' => 'integer' ),
							'boards'  => array( 'type' => 'array' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'sync_boards' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// List boards ability
			wp_register_ability(
				'datamachine/pinterest-list-boards',
				array(
					'label'               => __( 'List Pinterest Boards', 'data-machine-socials' ),
					'description'         => __( 'Get cached Pinterest boards', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'boards'  => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_list_boards' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Status ability
			wp_register_ability(
				'datamachine/pinterest-status',
				array(
					'label'               => __( 'Pinterest Status', 'data-machine-socials' ),
					'description'         => __( 'Get Pinterest integration status', 'data-machine-socials' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'authenticated' => array( 'type' => 'boolean' ),
							'board_count'   => array( 'type' => 'integer' ),
							'last_synced'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'execute_status' ),
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
	 * Execute list boards ability.
	 *
	 * @param array $input Ability input.
	 * @return array Response with boards.
	 */
	public static function execute_list_boards( array $input ): array|\WP_Error {
		$input;
		return array(
			'success' => true,
			'boards'  => self::get_cached_boards(),
		);
	}

	/**
	 * Execute status ability.
	 *
	 * @param array $input Ability input.
	 * @return array Response with status.
	 */
	public static function execute_status( array $input ): array|\WP_Error {
		$input;
		$status                  = self::get_sync_status();
		$status['success']       = true;
		$status['authenticated'] = self::is_configured();
		return $status;
	}

	/**
	 * Sync boards from Pinterest API v5.
	 *
	 * @return array Result with success, count, and boards.
	 */
	public static function sync_boards(): array|\WP_Error {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return new \WP_Error( 'missing_auth', 'Pinterest not authenticated', array( 'status' => 401 ) );
		}

		$token = $provider->get_valid_access_token();
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_auth', 'Pinterest access token is missing or expired — re-authorize in WP Admin > Data Machine > Settings', array( 'status' => 401 ) );
		}

		$all_boards = array();
		$bookmark   = null;

		for ( $i = 0; $i < 10; $i++ ) {
			$url = 'https://api.pinterest.com/v5/boards?page_size=100';
			if ( $bookmark ) {
				$url .= '&bookmark=' . urlencode( $bookmark );
			}

			$result = HttpClient::get( $url, array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
				'context' => 'Pinterest Board Sync',
			) );

			if ( ! $result['success'] ) {
				break;
			}

			$data = json_decode( $result['data'], true );

			foreach ( $data['items'] ?? array() as $board ) {
				$all_boards[] = array(
					'id'          => $board['id'],
					'name'        => $board['name'] ?? '',
					'description' => $board['description'] ?? '',
				);
			}

			$bookmark = $data['bookmark'] ?? null;
			if ( ! $bookmark ) {
				break;
			}
		}

		update_option( self::BOARDS_OPTION, $all_boards );
		update_option( self::BOARDS_SYNCED_OPTION, time() );

		return array(
			'success' => true,
			'count'   => count( $all_boards ),
			'boards'  => $all_boards,
		);
	}

	/**
	 * Get cached Pinterest boards.
	 *
	 * @return array Array of cached boards.
	 */
	public static function get_cached_boards(): array|\WP_Error {
		return get_option( self::BOARDS_OPTION, array() );
	}

	/**
	 * Get sync status information.
	 *
	 * @return array Board count and last synced timestamp.
	 */
	public static function get_sync_status(): array|\WP_Error {
		$boards = self::get_cached_boards();
		$synced = get_option( self::BOARDS_SYNCED_OPTION, 0 );

		return array(
			'board_count'           => count( $boards ),
			'last_synced'           => $synced ? gmdate( 'Y-m-d H:i:s', $synced ) : 'never',
			'last_synced_timestamp' => $synced,
		);
	}

	/**
	 * Resolve board ID based on handler config and post categories.
	 *
	 * @param int $post_id WordPress post ID.
	 * @param array $handler_config Handler configuration.
	 * @return string|null Board ID or null.
	 */
	public static function resolve_board_id( int $post_id, array $handler_config ): ?string {
		$mode = $handler_config['board_selection_mode'] ?? 'pre_selected';

		if ( 'category_mapping' === $mode && $post_id > 0 ) {
			$lines   = explode( "\n", $handler_config['board_mapping'] ?? '' );
			$mapping = array();

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) || strpos( $line, '=' ) === false ) {
					continue;
				}
				[ $slug, $bid ]   = array_map( 'trim', explode( '=', $line, 2 ) );
				$mapping[ $slug ] = $bid;
			}

			if ( ! empty( $mapping ) ) {
				$terms = get_the_terms( $post_id, 'category' );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( isset( $mapping[ $term->slug ] ) ) {
							return $mapping[ $term->slug ];
						}
					}
				}
			}
		}

		// Default fallback.
		$default = $handler_config['board_id'] ?? '';
		return ! empty( $default ) ? $default : null;
	}

	/**
	 * Check if Pinterest is authenticated and configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );
		return $provider && $provider->is_authenticated();
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
