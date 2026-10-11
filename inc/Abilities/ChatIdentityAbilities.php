<?php
/**
 * Chat Identity Abilities
 *
 * Admin-only abilities that link, unlink and list platform-scoped chat identities
 * on WordPress users, and resolve a chat actor to a WordPress person for an agent
 * (`datamachine/resolve-chat-person`).
 *
 * Platform names are data: nothing here knows or enumerates specific platforms.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Agents\ChatIdentityLinks;
use DataMachine\Core\Agents\ChatPersonResolver;

defined( 'ABSPATH' ) || exit;

class ChatIdentityAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerLink();
			$this->registerUnlink();
			$this->registerList();
			$this->registerResolve();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerLink(): void {
		wp_register_ability(
			'datamachine/link-chat-identity',
			array(
				'label'               => __( 'Link Chat Identity', 'data-machine' ),
				'description'         => __( 'Link a platform-scoped external chat actor ID to a WordPress user.', 'data-machine' ),
				'category'            => AbilityCategories::AGENT,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'user_id', 'platform', 'actor_id' ),
					'properties' => self::linkProperties(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'success' => array( 'type' => 'boolean' ) ) + self::linkProperties(),
				),
				'execute_callback'    => array( self::class, 'link' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function registerUnlink(): void {
		wp_register_ability(
			'datamachine/unlink-chat-identity',
			array(
				'label'               => __( 'Unlink Chat Identity', 'data-machine' ),
				'description'         => __( 'Remove a WordPress user\'s chat identity link for a platform.', 'data-machine' ),
				'category'            => AbilityCategories::AGENT,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'user_id', 'platform' ),
					'properties' => array(
						'user_id'  => array( 'type' => 'integer' ),
						'platform' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'removed' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( self::class, 'unlink' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function registerList(): void {
		wp_register_ability(
			'datamachine/list-chat-identities',
			array(
				'label'               => __( 'List Chat Identities', 'data-machine' ),
				'description'         => __( 'List linked chat identities, optionally filtered by user or platform.', 'data-machine' ),
				'category'            => AbilityCategories::AGENT,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'  => array( 'type' => 'integer' ),
						'platform' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'identities' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => self::linkProperties(),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'listLinks' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function registerResolve(): void {
		wp_register_ability(
			'datamachine/resolve-chat-person',
			array(
				'label'               => __( 'Resolve Chat Person', 'data-machine' ),
				'description'         => __( 'Resolve a chat actor to a WordPress user for an agent: access decision, role (admin|operator|viewer) and memory sections. Fails closed for unlinked or unauthorized actors.', 'data-machine' ),
				'category'            => AbilityCategories::AGENT,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'agent_slug', 'platform', 'actor_id' ),
					'properties' => array(
						'agent_slug' => array( 'type' => 'string' ),
						'platform'   => array( 'type' => 'string' ),
						'actor_id'   => array( 'type' => 'string' ),
						'event'      => array(
							'type'        => 'string',
							'description' => 'Event name. The turn event returns personal memory for the speaker; any other event returns agent memory.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'allowed'  => array( 'type' => 'boolean' ),
						'user_id'  => array( 'type' => 'integer' ),
						'role'     => array(
							'type'        => 'string',
							'description' => 'admin, operator or viewer; empty string when not allowed.',
						),
						'sections' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'     => array( 'type' => 'string' ),
									'layer'    => array( 'type' => 'string' ),
									'priority' => array( 'type' => 'integer' ),
									'content'  => array( 'type' => 'string' ),
								),
							),
						),
						'reason'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'resolve' ),
				'permission_callback' => array( self::class, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	/**
	 * Admin-only. The chat bridge calls these as a host operator.
	 *
	 * WP-CLI is treated as the host operator, matching PermissionHelper.
	 */
	/**
	 * Schema properties shared by every chat identity link record.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function linkProperties(): array {
		return array(
			'user_id'  => array( 'type' => 'integer' ),
			'platform' => array( 'type' => 'string' ),
			'actor_id' => array( 'type' => 'string' ),
		);
	}

	public static function checkPermission(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI && (bool) apply_filters( 'datamachine_cli_bypass_permissions', true );
	}

	public static function link( array $input ): array|\WP_Error {
		$result = ChatIdentityLinks::link(
			(int) ( $input['user_id'] ?? 0 ),
			(string) ( $input['platform'] ?? '' ),
			(string) ( $input['actor_id'] ?? '' )
		);

		return is_wp_error( $result ) ? $result : array_merge( array( 'success' => true ), $result );
	}

	public static function unlink( array $input ): array {
		return array(
			'success' => true,
			'removed' => ChatIdentityLinks::unlink( (int) ( $input['user_id'] ?? 0 ), (string) ( $input['platform'] ?? '' ) ),
		);
	}

	public static function listLinks( array $input ): array {
		return array(
			'success'    => true,
			'identities' => ChatIdentityLinks::list_links( (int) ( $input['user_id'] ?? 0 ), (string) ( $input['platform'] ?? '' ) ),
		);
	}

	public static function resolve( array $input ): array {
		return ( new ChatPersonResolver() )->resolve(
			(string) ( $input['agent_slug'] ?? '' ),
			(string) ( $input['platform'] ?? '' ),
			(string) ( $input['actor_id'] ?? '' ),
			(string) ( $input['event'] ?? '' )
		);
	}
}
