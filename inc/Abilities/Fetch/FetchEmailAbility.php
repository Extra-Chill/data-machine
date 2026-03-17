<?php
/**
 * Fetch Email Ability
 *
 * Abilities API primitive for retrieving emails from an IMAP inbox.
 * Pure data retrieval — no dedup, no pipeline context.
 *
 * Uses PHP's native imap_* functions for broad provider compatibility
 * (Gmail, Outlook, Fastmail, self-hosted, etc.).
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchEmailAbility {

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
			wp_register_ability(
				'datamachine/fetch-email',
				array(
					'label'               => __( 'Fetch Emails', 'data-machine' ),
					'description'         => __( 'Retrieve emails from an IMAP inbox', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'imap_host', 'imap_user', 'imap_password' ),
						'properties' => array(
							'imap_host'            => array(
								'type'        => 'string',
								'description' => __( 'IMAP server hostname (e.g., imap.gmail.com)', 'data-machine' ),
							),
							'imap_port'            => array(
								'type'        => 'integer',
								'default'     => 993,
								'description' => __( 'IMAP server port', 'data-machine' ),
							),
							'imap_encryption'      => array(
								'type'        => 'string',
								'default'     => 'ssl',
								'description' => __( 'Connection encryption: ssl, tls, or none', 'data-machine' ),
							),
							'imap_user'            => array(
								'type'        => 'string',
								'description' => __( 'IMAP username (usually your email address)', 'data-machine' ),
							),
							'imap_password'        => array(
								'type'        => 'string',
								'description' => __( 'IMAP app password (not your account password)', 'data-machine' ),
							),
							'folder'               => array(
								'type'        => 'string',
								'default'     => 'INBOX',
								'description' => __( 'Mail folder to fetch from', 'data-machine' ),
							),
							'search_criteria'      => array(
								'type'        => 'string',
								'default'     => 'UNSEEN',
								'description' => __( 'IMAP search string (UNSEEN, ALL, FROM "x", SINCE "1-Mar-2026")', 'data-machine' ),
							),
							'max_messages'         => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => __( 'Maximum number of messages to retrieve', 'data-machine' ),
							),
							'mark_as_read'         => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Mark fetched messages as read', 'data-machine' ),
							),
							'download_attachments' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Download email attachments to local storage', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array(
								'type'       => 'object',
								'properties' => array(
									'items' => array( 'type' => 'array' ),
									'count' => array( 'type' => 'integer' ),
								),
							),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
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

	/**
	 * Permission callback.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute email fetch.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with items or error.
	 */
	public function execute( array $input ): array {
		$logs = array();

		// Check IMAP extension.
		if ( ! function_exists( 'imap_open' ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email Fetch: PHP IMAP extension is not installed',
			);
			return array(
				'success' => false,
				'error'   => 'PHP IMAP extension is required but not installed. Install php-imap and restart your web server.',
				'logs'    => $logs,
			);
		}

		$config = $this->normalizeConfig( $input );

		// Build IMAP connection string.
		$mailbox = $this->buildMailboxString(
			$config['imap_host'],
			$config['imap_port'],
			$config['imap_encryption'],
			$config['folder']
		);

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Email Fetch: Connecting to ' . $config['imap_host'],
			'data'    => array(
				'host'       => $config['imap_host'],
				'port'       => $config['imap_port'],
				'encryption' => $config['imap_encryption'],
				'folder'     => $config['folder'],
				'search'     => $config['search_criteria'],
			),
		);

		// Suppress warnings — imap_open triggers PHP warnings on auth failure.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$connection = @imap_open( $mailbox, $config['imap_user'], $config['imap_password'] );

		if ( false === $connection ) {
			$imap_error = imap_last_error();
			$logs[]     = array(
				'level'   => 'error',
				'message' => 'Email Fetch: Connection failed - ' . $imap_error,
			);
			return array(
				'success' => false,
				'error'   => 'IMAP connection failed: ' . $imap_error,
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'info',
			'message' => 'Email Fetch: Connected successfully',
		);

		// Search for messages.
		$message_ids = imap_search( $connection, $config['search_criteria'], SE_UID );

		if ( false === $message_ids || empty( $message_ids ) ) {
			imap_close( $connection );
			$logs[] = array(
				'level'   => 'info',
				'message' => 'Email Fetch: No messages matching criteria',
			);
			return array(
				'success' => true,
				'data'    => array(
					'items' => array(),
					'count' => 0,
				),
				'logs'    => $logs,
			);
		}

		// Limit to max_messages (most recent first).
		$message_ids = array_reverse( $message_ids );
		$message_ids = array_slice( $message_ids, 0, $config['max_messages'] );

		$items = array();

		foreach ( $message_ids as $uid ) {
			$item = $this->fetchMessage( $connection, $uid, $config );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		// Mark as read if requested.
		if ( $config['mark_as_read'] && ! empty( $items ) ) {
			foreach ( $message_ids as $uid ) {
				imap_setflag_full( $connection, (string) $uid, '\\Seen', ST_UID );
			}
			$logs[] = array(
				'level'   => 'info',
				'message' => sprintf( 'Email Fetch: Marked %d messages as read', count( $items ) ),
			);
		}

		imap_close( $connection );

		$logs[] = array(
			'level'   => 'info',
			'message' => sprintf( 'Email Fetch: Retrieved %d messages', count( $items ) ),
		);

		return array(
			'success' => true,
			'data'    => array(
				'items' => $items,
				'count' => count( $items ),
			),
			'logs'    => $logs,
		);
	}

	/**
	 * Fetch a single message by UID.
	 *
	 * @param resource $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @param array    $config     Fetch configuration.
	 * @return array|null Message data or null on failure.
	 */
	private function fetchMessage( $connection, int $uid, array $config ): ?array {
		$header_info = imap_headerinfo( $connection, imap_msgno( $connection, $uid ) );
		if ( false === $header_info ) {
			return null;
		}

		// Parse headers.
		$subject    = isset( $header_info->subject ) ? imap_utf8( $header_info->subject ) : '';
		$from       = $header_info->from[0] ?? null;
		$from_email = $from ? ( $from->mailbox . '@' . $from->host ) : '';
		$from_name  = isset( $from->personal ) ? imap_utf8( $from->personal ) : '';
		$date       = isset( $header_info->date ) ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $header_info->date ) ) : '';
		$message_id = $header_info->message_id ?? '';
		$in_reply   = $header_info->in_reply_to ?? '';
		$references = $header_info->references ?? '';

		// Get to address.
		$to_address = '';
		if ( ! empty( $header_info->to ) ) {
			$to       = $header_info->to[0];
			$to_address = $to->mailbox . '@' . $to->host;
		}

		// Fetch body — prefer plain text, fall back to HTML stripped.
		$body = $this->fetchBody( $connection, $uid );

		// Check for attachments.
		$structure        = imap_fetchstructure( $connection, $uid, FT_UID );
		$attachment_count = 0;
		$file_info        = null;

		if ( $structure ) {
			$attachments      = $this->findAttachments( $structure );
			$attachment_count = count( $attachments );

			// Download first attachment if requested.
			if ( $config['download_attachments'] && ! empty( $attachments ) ) {
				$file_info = $this->downloadAttachment( $connection, $uid, $attachments[0] );
			}
		}

		$item = array(
			'title'    => $subject,
			'content'  => $body,
			'metadata' => array(
				'message_id'        => $message_id,
				'dedup_key'         => $message_id,
				'from'              => $from_email,
				'from_name'         => $from_name,
				'to'                => $to_address,
				'date'              => $date,
				'original_date_gmt' => $date,
				'has_attachments'   => $attachment_count > 0,
				'attachment_count'  => $attachment_count,
				'in_reply_to'       => $in_reply,
				'references'        => $references,
			),
		);

		if ( $file_info ) {
			$item['file_info'] = $file_info;
		}

		return $item;
	}

	/**
	 * Fetch message body, preferring plain text.
	 *
	 * @param resource $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @return string Message body text.
	 */
	private function fetchBody( $connection, int $uid ): string {
		$structure = imap_fetchstructure( $connection, $uid, FT_UID );
		if ( ! $structure ) {
			return '';
		}

		// Simple single-part message.
		if ( empty( $structure->parts ) ) {
			$body = imap_fetchbody( $connection, $uid, '1', FT_UID );
			return $this->decodeBody( $body, $structure->encoding ?? 0 );
		}

		// Multipart — find text/plain first, then text/html.
		$plain_body = '';
		$html_body  = '';

		foreach ( $structure->parts as $part_index => $part ) {
			$part_number = (string) ( $part_index + 1 );

			if ( 0 === ( $part->type ?? -1 ) ) { // Text type.
				$subtype = strtolower( $part->subtype ?? '' );
				$body    = imap_fetchbody( $connection, $uid, $part_number, FT_UID );
				$decoded = $this->decodeBody( $body, $part->encoding ?? 0 );

				if ( 'plain' === $subtype && empty( $plain_body ) ) {
					$plain_body = $decoded;
				} elseif ( 'html' === $subtype && empty( $html_body ) ) {
					$html_body = $decoded;
				}
			}

			// Check multipart/alternative nested parts.
			if ( ! empty( $part->parts ) ) {
				foreach ( $part->parts as $sub_index => $sub_part ) {
					$sub_number = $part_number . '.' . ( $sub_index + 1 );

					if ( 0 === ( $sub_part->type ?? -1 ) ) {
						$subtype = strtolower( $sub_part->subtype ?? '' );
						$body    = imap_fetchbody( $connection, $uid, $sub_number, FT_UID );
						$decoded = $this->decodeBody( $body, $sub_part->encoding ?? 0 );

						if ( 'plain' === $subtype && empty( $plain_body ) ) {
							$plain_body = $decoded;
						} elseif ( 'html' === $subtype && empty( $html_body ) ) {
							$html_body = $decoded;
						}
					}
				}
			}
		}

		// Prefer plain text; fall back to stripped HTML.
		if ( ! empty( $plain_body ) ) {
			return $plain_body;
		}

		if ( ! empty( $html_body ) ) {
			return wp_strip_all_tags( $html_body );
		}

		return '';
	}

	/**
	 * Decode email body based on encoding type.
	 *
	 * @param string $body     Encoded body.
	 * @param int    $encoding IMAP encoding constant.
	 * @return string Decoded body.
	 */
	private function decodeBody( string $body, int $encoding ): string {
		switch ( $encoding ) {
			case 3: // BASE64.
				return base64_decode( $body, true ) ?: $body;
			case 4: // QUOTED-PRINTABLE.
				return quoted_printable_decode( $body );
			case 1: // 8BIT.
			case 2: // BINARY.
			default:
				return $body;
		}
	}

	/**
	 * Find attachment parts in message structure.
	 *
	 * @param object $structure IMAP structure object.
	 * @return array Array of attachment part info.
	 */
	private function findAttachments( object $structure ): array {
		$attachments = array();

		if ( empty( $structure->parts ) ) {
			return $attachments;
		}

		foreach ( $structure->parts as $part_index => $part ) {
			$part_number = (string) ( $part_index + 1 );

			// Check disposition for attachment.
			$is_attachment = false;
			$filename      = '';

			if ( ! empty( $part->disposition ) && strtolower( $part->disposition ) === 'attachment' ) {
				$is_attachment = true;
			}

			// Get filename from dparameters or parameters.
			if ( ! empty( $part->dparameters ) ) {
				foreach ( $part->dparameters as $param ) {
					if ( strtolower( $param->attribute ) === 'filename' ) {
						$filename      = $param->value;
						$is_attachment = true;
					}
				}
			}

			if ( empty( $filename ) && ! empty( $part->parameters ) ) {
				foreach ( $part->parameters as $param ) {
					if ( strtolower( $param->attribute ) === 'name' ) {
						$filename      = $param->value;
						$is_attachment = true;
					}
				}
			}

			if ( $is_attachment && ! empty( $filename ) ) {
				$attachments[] = array(
					'part_number' => $part_number,
					'filename'    => imap_utf8( $filename ),
					'encoding'    => $part->encoding ?? 0,
					'mime_type'   => $this->getMimeType( $part ),
					'size'        => $part->bytes ?? 0,
				);
			}
		}

		return $attachments;
	}

	/**
	 * Download an attachment to temp storage.
	 *
	 * @param resource $connection      IMAP connection.
	 * @param int      $uid             Message UID.
	 * @param array    $attachment_info Attachment info from findAttachments().
	 * @return array|null File info or null on failure.
	 */
	private function downloadAttachment( $connection, int $uid, array $attachment_info ): ?array {
		$body = imap_fetchbody( $connection, $uid, $attachment_info['part_number'], FT_UID );
		if ( empty( $body ) ) {
			return null;
		}

		$decoded = $this->decodeBody( $body, $attachment_info['encoding'] );
		if ( empty( $decoded ) ) {
			return null;
		}

		// Store in WordPress upload directory.
		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['basedir'] . '/datamachine-files/email-attachments';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$safe_filename = sanitize_file_name( $attachment_info['filename'] );
		$file_path     = $target_dir . '/' . wp_unique_filename( $target_dir, $safe_filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $file_path, $decoded );
		if ( false === $written ) {
			return null;
		}

		return array(
			'file_path' => $file_path,
			'mime_type' => $attachment_info['mime_type'],
			'file_size' => $written,
		);
	}

	/**
	 * Get MIME type from IMAP part object.
	 *
	 * @param object $part IMAP structure part.
	 * @return string MIME type string.
	 */
	private function getMimeType( object $part ): string {
		$types = array(
			0 => 'text',
			1 => 'multipart',
			2 => 'message',
			3 => 'application',
			4 => 'audio',
			5 => 'image',
			6 => 'video',
			7 => 'model',
			8 => 'other',
		);

		$type    = $types[ $part->type ?? 3 ] ?? 'application';
		$subtype = strtolower( $part->subtype ?? 'octet-stream' );

		return "{$type}/{$subtype}";
	}

	/**
	 * Build IMAP mailbox connection string.
	 *
	 * @param string $host       IMAP hostname.
	 * @param int    $port       IMAP port.
	 * @param string $encryption Encryption type.
	 * @param string $folder     Mail folder.
	 * @return string IMAP mailbox string.
	 */
	private function buildMailboxString( string $host, int $port, string $encryption, string $folder ): string {
		$flags = '';

		switch ( $encryption ) {
			case 'ssl':
				$flags = '/imap/ssl/validate-cert';
				break;
			case 'tls':
				$flags = '/imap/tls/validate-cert';
				break;
			case 'none':
			default:
				$flags = '/imap/notls';
				break;
		}

		return sprintf( '{%s:%d%s}%s', $host, $port, $flags, $folder );
	}

	/**
	 * Normalize input configuration with defaults.
	 *
	 * @param array $input Raw input.
	 * @return array Normalized config.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'imap_host'            => '',
			'imap_port'            => 993,
			'imap_user'            => '',
			'imap_password'        => '',
			'imap_encryption'      => 'ssl',
			'folder'               => 'INBOX',
			'search_criteria'      => 'UNSEEN',
			'max_messages'         => 10,
			'mark_as_read'         => false,
			'download_attachments' => false,
		);

		return array_merge( $defaults, $input );
	}
}
