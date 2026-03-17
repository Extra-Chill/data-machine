<?php
/**
 * Email REST API
 *
 * REST endpoints for email operations: send, fetch, reply, delete, move, flag, test.
 * All endpoints delegate to registered abilities.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class Email {

	private const NAMESPACE = 'datamachine/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// Send an email.
		register_rest_route(
			self::NAMESPACE,
			'/email/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_send' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'to'           => array( 'type' => 'string', 'required' => true ),
					'subject'      => array( 'type' => 'string', 'required' => true ),
					'body'         => array( 'type' => 'string', 'required' => true ),
					'cc'           => array( 'type' => 'string', 'default' => '' ),
					'bcc'          => array( 'type' => 'string', 'default' => '' ),
					'from_name'    => array( 'type' => 'string', 'default' => '' ),
					'from_email'   => array( 'type' => 'string', 'default' => '' ),
					'reply_to'     => array( 'type' => 'string', 'default' => '' ),
					'content_type' => array( 'type' => 'string', 'default' => 'text/html' ),
					'attachments'  => array( 'type' => 'array', 'default' => array() ),
				),
			)
		);

		// Fetch emails from inbox.
		register_rest_route(
			self::NAMESPACE,
			'/email/fetch',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_fetch' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'folder'               => array( 'type' => 'string', 'default' => 'INBOX' ),
					'search'               => array( 'type' => 'string', 'default' => 'UNSEEN' ),
					'max'                  => array( 'type' => 'integer', 'default' => 10 ),
					'offset'               => array( 'type' => 'integer', 'default' => 0 ),
					'headers_only'         => array( 'type' => 'boolean', 'default' => false ),
					'mark_as_read'         => array( 'type' => 'boolean', 'default' => false ),
					'download_attachments' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		// Read a single email by UID.
		register_rest_route(
			self::NAMESPACE,
			'/email/(?P<uid>\d+)/read',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_read' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'uid'    => array( 'type' => 'integer', 'required' => true ),
					'folder' => array( 'type' => 'string', 'default' => 'INBOX' ),
				),
			)
		);

		// Reply to an email.
		register_rest_route(
			self::NAMESPACE,
			'/email/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_reply' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'to'           => array( 'type' => 'string', 'required' => true ),
					'subject'      => array( 'type' => 'string', 'required' => true ),
					'body'         => array( 'type' => 'string', 'required' => true ),
					'in_reply_to'  => array( 'type' => 'string', 'required' => true ),
					'references'   => array( 'type' => 'string', 'default' => '' ),
					'cc'           => array( 'type' => 'string', 'default' => '' ),
					'content_type' => array( 'type' => 'string', 'default' => 'text/html' ),
				),
			)
		);

		// Delete an email.
		register_rest_route(
			self::NAMESPACE,
			'/email/(?P<uid>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'handle_delete' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'uid'    => array( 'type' => 'integer', 'required' => true ),
					'folder' => array( 'type' => 'string', 'default' => 'INBOX' ),
				),
			)
		);

		// Move an email.
		register_rest_route(
			self::NAMESPACE,
			'/email/(?P<uid>\d+)/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_move' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'uid'         => array( 'type' => 'integer', 'required' => true ),
					'destination' => array( 'type' => 'string', 'required' => true ),
					'folder'      => array( 'type' => 'string', 'default' => 'INBOX' ),
				),
			)
		);

		// Flag/unflag an email.
		register_rest_route(
			self::NAMESPACE,
			'/email/(?P<uid>\d+)/flag',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_flag' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'uid'    => array( 'type' => 'integer', 'required' => true ),
					'flag'   => array( 'type' => 'string', 'required' => true ),
					'action' => array( 'type' => 'string', 'default' => 'set' ),
					'folder' => array( 'type' => 'string', 'default' => 'INBOX' ),
				),
			)
		);

		// Test connection.
		register_rest_route(
			self::NAMESPACE,
			'/email/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_test_connection' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);
	}

	public static function check_permission(): bool {
		return PermissionHelper::can_manage();
	}

	public static function handle_send( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Send email ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'to'           => $request->get_param( 'to' ),
			'subject'      => $request->get_param( 'subject' ),
			'body'         => $request->get_param( 'body' ),
			'cc'           => $request->get_param( 'cc' ) ?? '',
			'bcc'          => $request->get_param( 'bcc' ) ?? '',
			'from_name'    => $request->get_param( 'from_name' ) ?? '',
			'from_email'   => $request->get_param( 'from_email' ) ?? '',
			'reply_to'     => $request->get_param( 'reply_to' ) ?? '',
			'content_type' => $request->get_param( 'content_type' ) ?? 'text/html',
			'attachments'  => $request->get_param( 'attachments' ) ?? array(),
		) );

		return self::to_response( $result );
	}

	public static function handle_fetch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$auth = self::get_imap_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$ability = wp_get_ability( 'datamachine/fetch-email' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Fetch email ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'imap_host'            => $auth->getHost(),
			'imap_port'            => $auth->getPort(),
			'imap_encryption'      => $auth->getEncryption(),
			'imap_user'            => $auth->getUser(),
			'imap_password'        => $auth->getPassword(),
			'folder'               => $request->get_param( 'folder' ) ?? 'INBOX',
			'search_criteria'      => $request->get_param( 'search' ) ?? 'UNSEEN',
			'max_messages'         => (int) ( $request->get_param( 'max' ) ?? 10 ),
			'offset'               => (int) ( $request->get_param( 'offset' ) ?? 0 ),
			'headers_only'         => (bool) $request->get_param( 'headers_only' ),
			'mark_as_read'         => (bool) $request->get_param( 'mark_as_read' ),
			'download_attachments' => (bool) $request->get_param( 'download_attachments' ),
		) );

		return self::to_response( $result );
	}

	public static function handle_read( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$auth = self::get_imap_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$ability = wp_get_ability( 'datamachine/fetch-email' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Fetch email ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'imap_host'       => $auth->getHost(),
			'imap_port'       => $auth->getPort(),
			'imap_encryption' => $auth->getEncryption(),
			'imap_user'       => $auth->getUser(),
			'imap_password'   => $auth->getPassword(),
			'folder'          => $request->get_param( 'folder' ) ?? 'INBOX',
			'uid'             => (int) $request->get_param( 'uid' ),
		) );

		return self::to_response( $result );
	}

	/**
	 * Get IMAP auth provider or WP_Error.
	 */
	private static function get_imap_auth(): object {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$auth      = $providers['email_imap'] ?? null;

		if ( ! $auth || ! $auth->is_authenticated() ) {
			return new \WP_Error( 'not_configured', 'IMAP credentials not configured', array( 'status' => 400 ) );
		}

		return $auth;
	}

	public static function handle_reply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/email-reply' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Email reply ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'to'           => $request->get_param( 'to' ),
			'subject'      => $request->get_param( 'subject' ),
			'body'         => $request->get_param( 'body' ),
			'in_reply_to'  => $request->get_param( 'in_reply_to' ),
			'references'   => $request->get_param( 'references' ) ?? '',
			'cc'           => $request->get_param( 'cc' ) ?? '',
			'content_type' => $request->get_param( 'content_type' ) ?? 'text/html',
		) );

		return self::to_response( $result );
	}

	public static function handle_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/email-delete' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Email delete ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'uid'    => (int) $request->get_param( 'uid' ),
			'folder' => $request->get_param( 'folder' ) ?? 'INBOX',
		) );

		return self::to_response( $result );
	}

	public static function handle_move( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/email-move' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Email move ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'uid'         => (int) $request->get_param( 'uid' ),
			'destination' => $request->get_param( 'destination' ),
			'folder'      => $request->get_param( 'folder' ) ?? 'INBOX',
		) );

		return self::to_response( $result );
	}

	public static function handle_flag( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/email-flag' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Email flag ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array(
			'uid'    => (int) $request->get_param( 'uid' ),
			'flag'   => $request->get_param( 'flag' ),
			'action' => $request->get_param( 'action' ) ?? 'set',
			'folder' => $request->get_param( 'folder' ) ?? 'INBOX',
		) );

		return self::to_response( $result );
	}

	public static function handle_test_connection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability = wp_get_ability( 'datamachine/email-test-connection' );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', 'Email test connection ability not available', array( 'status' => 500 ) );
		}

		$result = $ability->execute( array() );

		return self::to_response( $result );
	}

	/**
	 * Convert ability result to REST response.
	 */
	private static function to_response( array $result ): \WP_REST_Response|\WP_Error {
		if ( ! ( $result['success'] ?? false ) ) {
			return new \WP_Error(
				'email_error',
				$result['error'] ?? 'Operation failed',
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}
}
