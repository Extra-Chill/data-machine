<?php
/**
 * Email Abilities
 *
 * WordPress 6.9 Abilities API primitives for email CRUD operations.
 * Registers abilities for inbox management: reply, delete, move, flag.
 *
 * Send and Fetch abilities are registered separately in their respective files.
 * This class covers the remaining CRUD operations that require an IMAP connection.
 *
 * @package DataMachine\Abilities\Email
 */

namespace DataMachine\Abilities\Email;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class EmailAbilities {

	private static bool $registered = false;

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
			// Reply to an email.
			wp_register_ability(
				'datamachine/email-reply',
				array(
					'label'               => __( 'Reply to Email', 'data-machine' ),
					'description'         => __( 'Send a reply to an email, maintaining thread headers', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'to', 'subject', 'body', 'in_reply_to' ),
						'properties' => array(
							'to'           => array(
								'type'        => 'string',
								'description' => __( 'Recipient email address', 'data-machine' ),
							),
							'subject'      => array(
								'type'        => 'string',
								'description' => __( 'Reply subject (typically Re: original subject)', 'data-machine' ),
							),
							'body'         => array(
								'type'        => 'string',
								'description' => __( 'Reply body content', 'data-machine' ),
							),
							'in_reply_to'  => array(
								'type'        => 'string',
								'description' => __( 'Message-ID of the email being replied to', 'data-machine' ),
							),
							'references'   => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'References header chain for threading', 'data-machine' ),
							),
							'cc'           => array(
								'type'    => 'string',
								'default' => '',
							),
							'content_type' => array(
								'type'    => 'string',
								'default' => 'text/html',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'executeReply' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Delete an email via IMAP.
			wp_register_ability(
				'datamachine/email-delete',
				array(
					'label'               => __( 'Delete Email', 'data-machine' ),
					'description'         => __( 'Delete (expunge) an email by UID from the IMAP server', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'uid' ),
						'properties' => array(
							'uid'    => array(
								'type'        => 'integer',
								'description' => __( 'Message UID to delete', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeDelete' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Move an email to a different folder.
			wp_register_ability(
				'datamachine/email-move',
				array(
					'label'               => __( 'Move Email', 'data-machine' ),
					'description'         => __( 'Move an email to a different IMAP folder', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'uid', 'destination' ),
						'properties' => array(
							'uid'         => array(
								'type'        => 'integer',
								'description' => __( 'Message UID to move', 'data-machine' ),
							),
							'destination' => array(
								'type'        => 'string',
								'description' => __( 'Target folder (e.g., Archive, Trash, [Gmail]/All Mail)', 'data-machine' ),
							),
							'folder'      => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeMove' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Flag/unflag an email.
			wp_register_ability(
				'datamachine/email-flag',
				array(
					'label'               => __( 'Flag Email', 'data-machine' ),
					'description'         => __( 'Set or clear IMAP flags on an email (Seen, Flagged, etc.)', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'uid', 'flag' ),
						'properties' => array(
							'uid'    => array(
								'type'        => 'integer',
								'description' => __( 'Message UID', 'data-machine' ),
							),
							'flag'   => array(
								'type'        => 'string',
								'description' => __( 'IMAP flag: Seen, Flagged, Answered, Deleted, Draft', 'data-machine' ),
							),
							'action' => array(
								'type'        => 'string',
								'default'     => 'set',
								'description' => __( 'set or clear the flag', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeFlag' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Batch move: search → move all matches.
			wp_register_ability(
				'datamachine/email-batch-move',
				array(
					'label'               => __( 'Batch Move Emails', 'data-machine' ),
					'description'         => __( 'Move all emails matching a search to a destination folder', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'search', 'destination' ),
						'properties' => array(
							'search'      => array(
								'type'        => 'string',
								'description' => __( 'IMAP search criteria (e.g., FROM "github.com")', 'data-machine' ),
							),
							'destination' => array(
								'type'        => 'string',
								'description' => __( 'Target folder (e.g., [Gmail]/GitHub, Archive)', 'data-machine' ),
							),
							'folder'      => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
							'max'         => array(
								'type'        => 'integer',
								'default'     => 500,
								'description' => __( 'Maximum messages to move (safety limit)', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'moved_count'   => array( 'type' => 'integer' ),
							'total_matches' => array( 'type' => 'integer' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeBatchMove' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Batch flag: search → flag/unflag all matches.
			wp_register_ability(
				'datamachine/email-batch-flag',
				array(
					'label'               => __( 'Batch Flag Emails', 'data-machine' ),
					'description'         => __( 'Set or clear a flag on all emails matching a search', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'search', 'flag' ),
						'properties' => array(
							'search' => array(
								'type'        => 'string',
								'description' => __( 'IMAP search criteria', 'data-machine' ),
							),
							'flag'   => array(
								'type'        => 'string',
								'description' => __( 'Flag: Seen, Flagged, Answered, Deleted, Draft', 'data-machine' ),
							),
							'action' => array(
								'type'        => 'string',
								'default'     => 'set',
								'description' => __( 'set or clear', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
							'max'    => array(
								'type'    => 'integer',
								'default' => 500,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'flagged_count' => array( 'type' => 'integer' ),
							'total_matches' => array( 'type' => 'integer' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeBatchFlag' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Batch delete: search → delete all matches.
			wp_register_ability(
				'datamachine/email-batch-delete',
				array(
					'label'               => __( 'Batch Delete Emails', 'data-machine' ),
					'description'         => __( 'Delete all emails matching a search', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'search' ),
						'properties' => array(
							'search' => array(
								'type'        => 'string',
								'description' => __( 'IMAP search criteria', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
							'max'    => array(
								'type'        => 'integer',
								'default'     => 100,
								'description' => __( 'Maximum messages to delete (safety limit, lower default)', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'deleted_count' => array( 'type' => 'integer' ),
							'total_matches' => array( 'type' => 'integer' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeBatchDelete' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Unsubscribe from a mailing list.
			wp_register_ability(
				'datamachine/email-unsubscribe',
				array(
					'label'               => __( 'Unsubscribe from Email', 'data-machine' ),
					'description'         => __( 'Unsubscribe from a mailing list using List-Unsubscribe headers', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'uid' ),
						'properties' => array(
							'uid'    => array(
								'type'        => 'integer',
								'description' => __( 'Message UID to unsubscribe from', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'method'  => array( 'type' => 'string' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeUnsubscribe' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Batch unsubscribe from all matching senders.
			wp_register_ability(
				'datamachine/email-batch-unsubscribe',
				array(
					'label'               => __( 'Batch Unsubscribe', 'data-machine' ),
					'description'         => __( 'Unsubscribe from all mailing lists matching a search', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'search' ),
						'properties' => array(
							'search' => array(
								'type'        => 'string',
								'description' => __( 'IMAP search criteria', 'data-machine' ),
							),
							'folder' => array(
								'type'    => 'string',
								'default' => 'INBOX',
							),
							'max'    => array(
								'type'        => 'integer',
								'default'     => 20,
								'description' => __( 'Max unique senders to unsubscribe from (deduped by sender)', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'message'      => array( 'type' => 'string' ),
							'results'      => array( 'type' => 'array' ),
							'unsubscribed' => array( 'type' => 'integer' ),
							'failed'       => array( 'type' => 'integer' ),
							'no_header'    => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeBatchUnsubscribe' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			// Test IMAP connection.
			wp_register_ability(
				'datamachine/email-test-connection',
				array(
					'label'               => __( 'Test Email Connection', 'data-machine' ),
					'description'         => __( 'Test IMAP connection with stored credentials', 'data-machine' ),
					'category'            => 'datamachine-email',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'message'      => array( 'type' => 'string' ),
							'mailbox_info' => array( 'type' => 'object' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeTestConnection' ),
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

	/**
	 * Reply to an email with threading headers.
	 */
	public function executeReply( array $input ): array {
		$headers = array();

		$content_type = $input['content_type'] ?? 'text/html';
		$headers[]    = "Content-Type: {$content_type}; charset=UTF-8";

		// Threading headers.
		if ( ! empty( $input['in_reply_to'] ) ) {
			$headers[] = 'In-Reply-To: ' . $input['in_reply_to'];
		}

		$references = $input['references'] ?? '';
		if ( ! empty( $input['in_reply_to'] ) ) {
			$references = trim( $references . ' ' . $input['in_reply_to'] );
		}
		if ( ! empty( $references ) ) {
			$headers[] = 'References: ' . $references;
		}

		if ( ! empty( $input['cc'] ) ) {
			$cc_list = array_map( 'trim', explode( ',', $input['cc'] ) );
			foreach ( $cc_list as $cc ) {
				if ( is_email( $cc ) ) {
					$headers[] = 'Cc: ' . $cc;
				}
			}
		}

		$to = array_map( 'trim', explode( ',', $input['to'] ) );
		$to = array_filter( $to, 'is_email' );

		if ( empty( $to ) ) {
			return array(
				'success' => false,
				'error'   => 'No valid recipient address',
			);
		}

		$sent = wp_mail( $to, $input['subject'], $input['body'], $headers );

		if ( $sent ) {
			// Save a copy to the IMAP Sent folder so the message appears in
			// the user's email client (e.g. Gmail "Sent Mail" thread view).
			$this->saveToSentFolder( $to, $input['subject'], $input['body'], $headers );

			return array(
				'success' => true,
				'message' => 'Reply sent to ' . implode( ', ', $to ),
				'logs'    => array(),
			);
		}

		global $phpmailer;
		$error = 'wp_mail() returned false';
		if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			$error = $phpmailer->ErrorInfo ?: $error;
		}

		return array(
			'success' => false,
			'error'   => $error,
		);
	}

	/**
	 * Delete an email from the IMAP server.
	 */
	public function executeDelete( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$uid = (int) $input['uid'];
		imap_delete( $connection, (string) $uid, FT_UID );
		imap_expunge( $connection );
		imap_close( $connection );

		return array(
			'success' => true,
			'message' => sprintf( 'Message UID %d deleted', $uid ),
		);
	}

	/**
	 * Move an email to a different folder.
	 */
	public function executeMove( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$uid         = (int) $input['uid'];
		$destination = $input['destination'];

		$moved = imap_mail_move( $connection, (string) $uid, $destination, CP_UID );
		if ( ! $moved ) {
			$error = imap_last_error();
			imap_close( $connection );
			return array(
				'success' => false,
				'error'   => 'Move failed: ' . $error,
			);
		}

		imap_expunge( $connection );
		imap_close( $connection );

		return array(
			'success' => true,
			'message' => sprintf( 'Message UID %d moved to %s', $uid, $destination ),
		);
	}

	/**
	 * Set or clear a flag on an email.
	 */
	public function executeFlag( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$uid  = (int) $input['uid'];
		$flag = '\\' . ucfirst( strtolower( $input['flag'] ) );

		$valid_flags = array( '\\Seen', '\\Flagged', '\\Answered', '\\Deleted', '\\Draft' );
		if ( ! in_array( $flag, $valid_flags, true ) ) {
			imap_close( $connection );
			return array(
				'success' => false,
				'error'   => 'Invalid flag. Valid flags: Seen, Flagged, Answered, Deleted, Draft',
			);
		}

		$action = $input['action'] ?? 'set';
		if ( 'clear' === $action ) {
			$result = imap_clearflag_full( $connection, (string) $uid, $flag, ST_UID );
		} else {
			$result = imap_setflag_full( $connection, (string) $uid, $flag, ST_UID );
		}

		imap_close( $connection );

		if ( ! $result ) {
			return array(
				'success' => false,
				'error'   => 'Failed to ' . $action . ' flag ' . $flag,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Flag %s %s on UID %d', $flag, $action === 'clear' ? 'cleared' : 'set', $uid ),
		);
	}

	/**
	 * Test the IMAP connection with stored credentials.
	 */
	public function executeTestConnection( array $input ): array {
		if ( ! function_exists( 'imap_open' ) ) {
			return array(
				'success' => false,
				'error'   => 'PHP IMAP extension is not installed',
			);
		}

		$auth = $this->getAuthProvider();
		if ( ! $auth || ! $auth->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => 'IMAP credentials not configured',
			);
		}

		$mailbox = $this->buildMailboxString(
			$auth->getHost(),
			$auth->getPort(),
			$auth->getEncryption(),
			'INBOX'
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$connection = @imap_open( $mailbox, $auth->getUser(), $auth->getPassword() );

		if ( false === $connection ) {
			return array(
				'success' => false,
				'error'   => 'Connection failed: ' . imap_last_error(),
			);
		}

		$check = imap_check( $connection );
		$info  = array(
			'mailbox'   => $check->Mailbox ?? '',
			'messages'  => $check->Nmsgs ?? 0,
			'recent'    => $check->Recent ?? 0,
			'connected' => true,
		);

		// List available folders.
		$folders     = imap_list( $connection, $this->buildMailboxString( $auth->getHost(), $auth->getPort(), $auth->getEncryption(), '' ), '*' );
		$folder_list = array();
		if ( is_array( $folders ) ) {
			$prefix = $this->buildMailboxString( $auth->getHost(), $auth->getPort(), $auth->getEncryption(), '' );
			foreach ( $folders as $folder ) {
				$folder_list[] = str_replace( $prefix, '', imap_utf7_decode( $folder ) );
			}
		}
		$info['folders'] = $folder_list;

		imap_close( $connection );

		return array(
			'success'      => true,
			'message'      => sprintf( 'Connected to %s — %d messages in INBOX', $auth->getHost(), $info['messages'] ),
			'mailbox_info' => $info,
		);
	}

	/**
	 * Unsubscribe from a mailing list using List-Unsubscribe headers.
	 *
	 * Priority order:
	 * 1. List-Unsubscribe-Post + URL → HTTP POST (RFC 8058 one-click)
	 * 2. URL without Post header → HTTP POST attempt, fall back to GET
	 * 3. mailto: → send email via wp_mail()
	 */
	public function executeUnsubscribe( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$uid = (int) $input['uid'];

		// Fetch raw headers.
		$raw_headers = imap_fetchheader( $connection, $uid, FT_UID );
		if ( empty( $raw_headers ) ) {
			imap_close( $connection );
			return array(
				'success' => false,
				'error'   => 'Could not fetch message headers',
			);
		}

		$parsed = $this->parseUnsubscribeHeaders( $raw_headers );
		imap_close( $connection );

		if ( empty( $parsed['urls'] ) && empty( $parsed['mailto'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No List-Unsubscribe header found in this message',
			);
		}

		// Try One-Click POST first (RFC 8058).
		if ( $parsed['has_one_click'] && ! empty( $parsed['urls'] ) ) {
			$url    = $parsed['urls'][0];
			$result = $this->executeOneClickUnsubscribe( $url );
			if ( $result['success'] ) {
				return $result;
			}
			// Fall through to other methods if POST failed.
		}

		// Try URL GET/POST.
		if ( ! empty( $parsed['urls'] ) ) {
			foreach ( $parsed['urls'] as $url ) {
				$result = $this->executeUrlUnsubscribe( $url );
				if ( $result['success'] ) {
					return $result;
				}
			}
		}

		// Try mailto.
		if ( ! empty( $parsed['mailto'] ) ) {
			$result = $this->executeMailtoUnsubscribe( $parsed['mailto'] );
			if ( $result['success'] ) {
				return $result;
			}
		}

		return array(
			'success' => false,
			'error'   => 'All unsubscribe methods failed',
		);
	}

	/**
	 * Batch unsubscribe: search → unsubscribe from unique senders.
	 *
	 * Deduplicates by sender — if you have 100 emails from linkedin.com,
	 * it only unsubscribes once using the most recent message's headers.
	 */
	public function executeBatchUnsubscribe( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$search = $input['search'];
		$max    = (int) ( $input['max'] ?? 20 );

		$uids = imap_search( $connection, $search, SE_UID );
		if ( false === $uids || empty( $uids ) ) {
			imap_close( $connection );
			return array(
				'success'      => true,
				'message'      => 'No messages matching search criteria',
				'results'      => array(),
				'unsubscribed' => 0,
				'failed'       => 0,
				'no_header'    => 0,
			);
		}

		// Most recent first — we want the newest unsubscribe link per sender.
		$uids = array_reverse( $uids );

		// Deduplicate by sender — one unsubscribe per unique From address.
		$seen_senders = array();
		$to_process   = array();

		foreach ( $uids as $uid ) {
			if ( count( $to_process ) >= $max ) {
				break;
			}

			$msgno = imap_msgno( $connection, $uid );
			if ( 0 === $msgno ) {
				continue;
			}

			$header = imap_headerinfo( $connection, $msgno );
			if ( false === $header || empty( $header->from ) ) {
				continue;
			}

			$from   = $header->from[0];
			$sender = $from->mailbox . '@' . $from->host;

			if ( isset( $seen_senders[ $sender ] ) ) {
				continue;
			}

			$seen_senders[ $sender ] = true;

			// Fetch unsubscribe headers.
			$raw    = imap_fetchheader( $connection, $uid, FT_UID );
			$parsed = $this->parseUnsubscribeHeaders( $raw );

			$to_process[] = array(
				'uid'    => $uid,
				'sender' => $sender,
				'parsed' => $parsed,
			);
		}

		imap_close( $connection );

		// Now execute unsubscribes (connection closed — these are HTTP/mailto).
		$results      = array();
		$unsubscribed = 0;
		$failed       = 0;
		$no_header    = 0;

		foreach ( $to_process as $item ) {
			$parsed = $item['parsed'];

			if ( empty( $parsed['urls'] ) && empty( $parsed['mailto'] ) ) {
				$results[] = array(
					'sender'  => $item['sender'],
					'success' => false,
					'reason'  => 'no List-Unsubscribe header',
				);
				++$no_header;
				continue;
			}

			$result = null;

			// Try One-Click POST first.
			if ( $parsed['has_one_click'] && ! empty( $parsed['urls'] ) ) {
				$result = $this->executeOneClickUnsubscribe( $parsed['urls'][0] );
			}

			// Fall back to URL.
			if ( ( ! $result || ! $result['success'] ) && ! empty( $parsed['urls'] ) ) {
				$result = $this->executeUrlUnsubscribe( $parsed['urls'][0] );
			}

			// Fall back to mailto.
			if ( ( ! $result || ! $result['success'] ) && ! empty( $parsed['mailto'] ) ) {
				$result = $this->executeMailtoUnsubscribe( $parsed['mailto'] );
			}

			if ( $result && $result['success'] ) {
				$results[] = array(
					'sender'  => $item['sender'],
					'success' => true,
					'method'  => $result['method'] ?? 'unknown',
				);
				++$unsubscribed;
			} else {
				$results[] = array(
					'sender'  => $item['sender'],
					'success' => false,
					'reason'  => $result['error'] ?? 'all methods failed',
				);
				++$failed;
			}
		}

		return array(
			'success'      => true,
			'message'      => sprintf(
				'Processed %d senders: %d unsubscribed, %d failed, %d had no header',
				count( $to_process ),
				$unsubscribed,
				$failed,
				$no_header
			),
			'results'      => $results,
			'unsubscribed' => $unsubscribed,
			'failed'       => $failed,
			'no_header'    => $no_header,
		);
	}

	/**
	 * Parse List-Unsubscribe and List-Unsubscribe-Post headers.
	 *
	 * @param string $raw_headers Raw email headers.
	 * @return array Parsed data with urls, mailto, has_one_click.
	 */
	private function parseUnsubscribeHeaders( string $raw_headers ): array {
		$unsub_header = '';
		$post_header  = '';
		$collecting   = '';

		foreach ( explode( "\n", $raw_headers ) as $line ) {
			// Continuation line.
			if ( $collecting && preg_match( '/^\s/', $line ) ) {
				if ( 'unsub' === $collecting ) {
					$unsub_header .= ' ' . trim( $line );
				}
				if ( 'post' === $collecting ) {
					$post_header .= ' ' . trim( $line );
				}
				continue;
			}
			$collecting = '';

			if ( stripos( $line, 'List-Unsubscribe:' ) === 0 ) {
				$unsub_header = trim( substr( $line, 17 ) );
				$collecting   = 'unsub';
			}
			if ( stripos( $line, 'List-Unsubscribe-Post:' ) === 0 ) {
				$post_header = trim( substr( $line, 22 ) );
				$collecting  = 'post';
			}
		}

		// Decode MIME-encoded headers.
		if ( ! empty( $unsub_header ) ) {
			$unsub_header = imap_utf8( $unsub_header );
		}

		// Extract URLs and mailto from angle brackets.
		$urls   = array();
		$mailto = '';

		if ( preg_match_all( '/<([^>]+)>/', $unsub_header, $matches ) ) {
			foreach ( $matches[1] as $value ) {
				if ( strpos( $value, 'mailto:' ) === 0 ) {
					$mailto = $value;
				} elseif ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$urls[] = $value;
				}
			}
		}

		$has_one_click = false !== stripos( $post_header, 'List-Unsubscribe=One-Click' );

		return array(
			'urls'          => $urls,
			'mailto'        => $mailto,
			'has_one_click' => $has_one_click,
			'raw'           => $unsub_header,
		);
	}

	/**
	 * RFC 8058 One-Click unsubscribe via HTTP POST.
	 */
	private function executeOneClickUnsubscribe( string $url ): array {
		$response = wp_remote_post( $url, array(
			'body'    => 'List-Unsubscribe=One-Click',
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'POST failed: ' . $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 2xx = success. Some return 200, others 204.
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success' => true,
				'message' => 'Unsubscribed via One-Click POST (HTTP ' . $code . ')',
				'method'  => 'one-click-post',
			);
		}

		return array(
			'success' => false,
			'error'   => 'One-Click POST returned HTTP ' . $code,
		);
	}

	/**
	 * Unsubscribe via URL (GET request).
	 */
	private function executeUrlUnsubscribe( string $url ): array {
		$response = wp_remote_get( $url, array(
			'timeout'   => 15,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'GET failed: ' . $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 400 ) {
			return array(
				'success' => true,
				'message' => 'Unsubscribe request sent (HTTP ' . $code . ')',
				'method'  => 'url-get',
			);
		}

		return array(
			'success' => false,
			'error'   => 'URL request returned HTTP ' . $code,
		);
	}

	/**
	 * Unsubscribe via mailto: — send an email.
	 */
	private function executeMailtoUnsubscribe( string $mailto_uri ): array {
		// Parse mailto:address?subject=...
		$parts   = wp_parse_url( $mailto_uri );
		$address = str_replace( 'mailto:', '', $parts['path'] ?? '' );

		if ( empty( $address ) || ! is_email( $address ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid mailto address: ' . $mailto_uri,
			);
		}

		$subject = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$subject = $query['subject'] ?? 'unsubscribe';
		}
		if ( empty( $subject ) ) {
			$subject = 'unsubscribe';
		}

		$sent = wp_mail( $address, $subject, 'unsubscribe' );

		if ( $sent ) {
			return array(
				'success' => true,
				'message' => 'Unsubscribe email sent to ' . $address,
				'method'  => 'mailto',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to send unsubscribe email',
		);
	}

	/**
	 * Batch move: search → move all matches to destination.
	 */
	public function executeBatchMove( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$search      = $input['search'];
		$destination = $input['destination'];
		$max         = (int) ( $input['max'] ?? 500 );

		$uids = imap_search( $connection, $search, SE_UID );
		if ( false === $uids || empty( $uids ) ) {
			imap_close( $connection );
			return array(
				'success'       => true,
				'message'       => 'No messages matching search criteria',
				'moved_count'   => 0,
				'total_matches' => 0,
			);
		}

		$total   = count( $uids );
		$to_move = array_slice( $uids, 0, $max );
		$moved   = 0;

		// Use comma-separated UID range for batch operation (much faster than per-message).
		$uid_set = implode( ',', $to_move );
		$result  = imap_mail_move( $connection, $uid_set, $destination, CP_UID );

		if ( $result ) {
			$moved = count( $to_move );
			imap_expunge( $connection );
		}

		imap_close( $connection );

		$message = sprintf( 'Moved %d messages to %s', $moved, $destination );
		if ( $total > $max ) {
			$message .= sprintf( ' (%d more remain — run again to continue)', $total - $max );
		}

		return array(
			'success'       => true,
			'message'       => $message,
			'moved_count'   => $moved,
			'total_matches' => $total,
		);
	}

	/**
	 * Batch flag: search → set/clear flag on all matches.
	 */
	public function executeBatchFlag( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$search = $input['search'];
		$flag   = '\\' . ucfirst( strtolower( $input['flag'] ) );
		$action = $input['action'] ?? 'set';
		$max    = (int) ( $input['max'] ?? 500 );

		$valid_flags = array( '\\Seen', '\\Flagged', '\\Answered', '\\Deleted', '\\Draft' );
		if ( ! in_array( $flag, $valid_flags, true ) ) {
			imap_close( $connection );
			return array(
				'success' => false,
				'error'   => 'Invalid flag. Valid: Seen, Flagged, Answered, Deleted, Draft',
			);
		}

		$uids = imap_search( $connection, $search, SE_UID );
		if ( false === $uids || empty( $uids ) ) {
			imap_close( $connection );
			return array(
				'success'       => true,
				'message'       => 'No messages matching search criteria',
				'flagged_count' => 0,
				'total_matches' => 0,
			);
		}

		$total   = count( $uids );
		$to_flag = array_slice( $uids, 0, $max );
		$uid_set = implode( ',', $to_flag );

		if ( 'clear' === $action ) {
			imap_clearflag_full( $connection, $uid_set, $flag, ST_UID );
		} else {
			imap_setflag_full( $connection, $uid_set, $flag, ST_UID );
		}

		imap_close( $connection );

		$verb    = 'clear' === $action ? 'cleared' : 'set';
		$message = sprintf( '%s %s on %d messages', ucfirst( $verb ), $flag, count( $to_flag ) );
		if ( $total > $max ) {
			$message .= sprintf( ' (%d more remain)', $total - $max );
		}

		return array(
			'success'       => true,
			'message'       => $message,
			'flagged_count' => count( $to_flag ),
			'total_matches' => $total,
		);
	}

	/**
	 * Batch delete: search → delete all matches.
	 */
	public function executeBatchDelete( array $input ): array {
		$connection = $this->connect( $input['folder'] ?? 'INBOX' );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			return $connection;
		}

		$search = $input['search'];
		$max    = (int) ( $input['max'] ?? 100 );

		$uids = imap_search( $connection, $search, SE_UID );
		if ( false === $uids || empty( $uids ) ) {
			imap_close( $connection );
			return array(
				'success'       => true,
				'message'       => 'No messages matching search criteria',
				'deleted_count' => 0,
				'total_matches' => 0,
			);
		}

		$total     = count( $uids );
		$to_delete = array_slice( $uids, 0, $max );
		$uid_set   = implode( ',', $to_delete );

		imap_delete( $connection, $uid_set, FT_UID );
		imap_expunge( $connection );
		imap_close( $connection );

		$message = sprintf( 'Deleted %d messages', count( $to_delete ) );
		if ( $total > $max ) {
			$message .= sprintf( ' (%d more remain — run again to continue)', $total - $max );
		}

		return array(
			'success'       => true,
			'message'       => $message,
			'deleted_count' => count( $to_delete ),
			'total_matches' => $total,
		);
	}

	/**
	 * Open an IMAP connection using stored credentials.
	 *
	 * @param string $folder Mail folder.
	 * @return resource|array IMAP connection or error array.
	 */
	private function connect( string $folder = 'INBOX' ) {
		if ( ! function_exists( 'imap_open' ) ) {
			return array(
				'success' => false,
				'error'   => 'PHP IMAP extension is not installed',
			);
		}

		$auth = $this->getAuthProvider();
		if ( ! $auth || ! $auth->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => 'IMAP credentials not configured',
			);
		}

		$mailbox = $this->buildMailboxString(
			$auth->getHost(),
			$auth->getPort(),
			$auth->getEncryption(),
			$folder
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$connection = @imap_open( $mailbox, $auth->getUser(), $auth->getPassword() );

		if ( false === $connection ) {
			return array(
				'success' => false,
				'error'   => 'IMAP connection failed: ' . imap_last_error(),
			);
		}

		return $connection;
	}

	/**
	 * Get the IMAP auth provider.
	 *
	 * @return \DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth|null
	 */
	private function getAuthProvider(): ?object {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		return $providers['email_imap'] ?? null;
	}

	/**
	 * Build IMAP mailbox connection string.
	 */
	private function buildMailboxString( string $host, int $port, string $encryption, string $folder ): string {
		$flags = match ( $encryption ) {
			'ssl'   => '/imap/ssl/validate-cert',
			'tls'   => '/imap/tls/validate-cert',
			default => '/imap/notls',
		};

		return sprintf( '{%s:%d%s}%s', $host, $port, $flags, $folder );
	}

	/**
	 * Save a sent message to the IMAP Sent folder.
	 *
	 * After wp_mail() sends via SMTP, the message only exists on the recipient's
	 * server. This appends a copy to the sender's Sent folder so it appears in
	 * their email client (e.g. Gmail thread view).
	 *
	 * @param array  $to      Recipient addresses.
	 * @param string $subject Email subject.
	 * @param string $body    Email body.
	 * @param array  $headers Email headers (Content-Type, In-Reply-To, References, etc.).
	 */
	private function saveToSentFolder( array $to, string $subject, string $body, array $headers ): void {
		// Determine the Sent folder name. Gmail uses "[Gmail]/Sent Mail".
		$sent_folder = '[Gmail]/Sent Mail';

		$connection = $this->connect( $sent_folder );
		if ( is_array( $connection ) && ! ( $connection['success'] ?? true ) ) {
			// Non-Gmail server or folder not found — try common alternatives.
			foreach ( array( 'Sent', 'Sent Items', 'INBOX.Sent' ) as $fallback ) {
				$connection = $this->connect( $fallback );
				if ( ! is_array( $connection ) ) {
					$sent_folder = $fallback;
					break;
				}
			}

			// If still no connection, silently skip — sending succeeded, saving is best-effort.
			if ( is_array( $connection ) ) {
				return;
			}
		}

		$auth   = $this->getAuthProvider();
		$from   = $auth ? $auth->getUser() : 'noreply@extrachill.com';
		$to_str = implode( ', ', $to );
		$date   = gmdate( 'r' );

		// Build the RFC822 message.
		$message  = "From: {$from}\r\n";
		$message .= "To: {$to_str}\r\n";
		$message .= "Subject: {$subject}\r\n";
		$message .= "Date: {$date}\r\n";

		foreach ( $headers as $header ) {
			$message .= $header . "\r\n";
		}

		$message .= "MIME-Version: 1.0\r\n";
		$message .= "\r\n";
		$message .= $body;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@imap_append( $connection, $this->buildMailboxString(
			$auth->getHost(),
			$auth->getPort(),
			$auth->getEncryption(),
			$sent_folder
		), $message, '\\Seen' );

		imap_close( $connection );
	}
}
