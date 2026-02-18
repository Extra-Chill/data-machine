<?php
/**
 * Pinterest Abilities
 *
 * Primitive ability for Pinterest board management.
 * All Pinterest board operations — sync, cache, resolution — flow through this ability.
 *
 * @package DataMachine\Abilities\Pinterest
 * @since 0.28.0
 */

namespace DataMachine\Abilities\Pinterest;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class PinterestAbilities {

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
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Register the Pinterest boards ability.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/pinterest-boards',
				[
					'label'               => 'Pinterest Boards',
					'description'         => 'Sync, list, and manage Pinterest boards',
					'category'            => 'datamachine',
					'input_schema'        => [
						'type'       => 'object',
						'required'   => [ 'action' ],
						'properties' => [
							'action' => [
								'type'        => 'string',
								'description' => 'Action: sync_boards, list_boards, status',
							],
						],
					],
					'output_schema'       => [
						'type'       => 'object',
						'properties' => [
							'success'       => [ 'type' => 'boolean' ],
							'boards'        => [ 'type' => 'array' ],
							'count'         => [ 'type' => 'integer' ],
							'board_count'   => [ 'type' => 'integer' ],
							'last_synced'   => [ 'type' => 'string' ],
							'authenticated' => [ 'type' => 'boolean' ],
							'error'         => [ 'type' => 'string' ],
						],
					],
					'execute_callback'    => [ self::class, 'execute' ],
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => [ 'show_in_rest' => false ],
				]
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute ability action.
	 *
	 * @param array $input Ability input with 'action' key.
	 * @return array Ability response.
	 */
	public static function execute( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		switch ( $action ) {
			case 'sync_boards':
				return self::sync_boards();

			case 'list_boards':
				return [ 'success' => true, 'boards' => self::get_cached_boards() ];

			case 'status':
				$status                  = self::get_sync_status();
				$status['success']       = true;
				$status['authenticated'] = self::is_configured();
				return $status;

			default:
				return [ 'success' => false, 'error' => 'Invalid action. Must be: sync_boards, list_boards, status' ];
		}
	}

	/**
	 * Sync boards from Pinterest API v5.
	 *
	 * @return array Result with success, count, and boards.
	 */
	public static function sync_boards(): array {
		$auth     = new AuthAbilities();
		$provider = $auth->getProvider( 'pinterest' );

		if ( ! $provider || ! $provider->is_authenticated() ) {
			return [ 'success' => false, 'error' => 'Pinterest not authenticated' ];
		}

		$config = $provider->get_config();
		$token  = $config['access_token'] ?? '';

		$all_boards = [];
		$bookmark   = null;

		for ( $i = 0; $i < 10; $i++ ) {
			$url = 'https://api.pinterest.com/v5/boards?page_size=100';
			if ( $bookmark ) {
				$url .= '&bookmark=' . urlencode( $bookmark );
			}

			$result = HttpClient::get( $url, [
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 15,
				'context' => 'Pinterest Board Sync',
			] );

			if ( ! $result['success'] ) {
				break;
			}

			$data = json_decode( $result['data'], true );

			foreach ( $data['items'] ?? [] as $board ) {
				$all_boards[] = [
					'id'          => $board['id'],
					'name'        => $board['name'] ?? '',
					'description' => $board['description'] ?? '',
				];
			}

			$bookmark = $data['bookmark'] ?? null;
			if ( ! $bookmark ) {
				break;
			}
		}

		update_option( self::BOARDS_OPTION, $all_boards );
		update_option( self::BOARDS_SYNCED_OPTION, time() );

		return [ 'success' => true, 'count' => count( $all_boards ), 'boards' => $all_boards ];
	}

	/**
	 * Get cached Pinterest boards.
	 *
	 * @return array Array of cached boards.
	 */
	public static function get_cached_boards(): array {
		return get_option( self::BOARDS_OPTION, [] );
	}

	/**
	 * Get sync status information.
	 *
	 * @return array Board count and last synced timestamp.
	 */
	public static function get_sync_status(): array {
		$boards = self::get_cached_boards();
		$synced = get_option( self::BOARDS_SYNCED_OPTION, 0 );

		return [
			'board_count'          => count( $boards ),
			'last_synced'          => $synced ? gmdate( 'Y-m-d H:i:s', $synced ) : 'never',
			'last_synced_timestamp' => $synced,
		];
	}

	/**
	 * Resolve board ID based on handler config and post categories.
	 *
	 * @param int   $post_id        WordPress post ID.
	 * @param array $handler_config Handler configuration.
	 * @return string|null Board ID or null.
	 */
	public static function resolve_board_id( int $post_id, array $handler_config ): ?string {
		$mode = $handler_config['board_selection_mode'] ?? 'pre_selected';

		if ( 'category_mapping' === $mode && $post_id > 0 ) {
			$lines   = explode( "\n", $handler_config['board_mapping'] ?? '' );
			$mapping = [];

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) || strpos( $line, '=' ) === false ) {
					continue;
				}
				[ $slug, $bid ] = array_map( 'trim', explode( '=', $line, 2 ) );
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
}
