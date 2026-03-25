<?php
/**
 * Fetch Email Ability
 *
 * Abilities API primitive for retrieving emails from an IMAP inbox.
 * Pure data retrieval — no dedup, no pipeline context.
 *
 * Supports three modes:
 * - List mode (headers_only=true): fast header-only fetch for browsing
 * - Detail mode (uid=N): fetch a single message with full body
 * - Full mode (default): fetch messages with bodies (pipeline use)
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
							'offset'               => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Number of messages to skip (for pagination)', 'data-machine' ),
							),
							'uid'                  => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Fetch a single message by UID (detail mode)', 'data-machine' ),
							),
							'headers_only'         => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Fetch headers only (fast list mode — no body parsing)', 'data-machine' ),
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
							'success'     => array( 'type' => 'boolean' ),
							'data'        => array(
								'type'       => 'object',
								'properties' => array(
									'items'         => array( 'type' => 'array' ),
									'count'         => array( 'type' => 'integer' ),
									'total_matches' => array( 'type' => 'integer' ),
									'offset'        => array( 'type' => 'integer' ),
									'has_more'      => array( 'type' => 'boolean' ),
								),
							),
							'error'       => array( 'type' => 'string' ),
							'logs'        => array( 'type' => 'array' ),
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
	 * Three modes:
	 * - uid > 0: fetch single message (detail view)
	 * - headers_only: fast header scan (list view)
	 * - default: fetch with bodies (pipeline mode)
	 *
	 * @param array $input Input parameters.
	 * @return array Result with items or error.
	 */
	public function execute( array $input ): array {
		$logs = array();

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

		$mailbox = $this->buildMailboxString(
			$config['imap_host'],
			$config['imap_port'],
			$config['imap_encryption'],
			$config['folder']
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$connection = @imap_open( $mailbox, $config['imap_user'], $config['imap_password'] );

		if ( false === $connection ) {
			$imap_error = imap_last_error();
			return array(
				'success' => false,
				'error'   => 'IMAP connection failed: ' . $imap_error,
				'logs'    => $logs,
			);
		}

		// Single message fetch by UID (detail mode).
		if ( ! empty( $config['uid'] ) ) {
			$item = $this->fetchMessage( $connection, (int) $config['uid'], $config );
			imap_close( $connection );

			if ( null === $item ) {
				return array(
					'success' => false,
					'error'   => 'Message UID ' . $config['uid'] . ' not found',
					'logs'    => $logs,
				);
			}

			return array(
				'success' => true,
				'data'    => array(
					'items'         => array( $item ),
					'count'         => 1,
					'total_matches' => 1,
					'offset'        => 0,
					'has_more'      => false,
				),
				'logs' => $logs,
			);
		}

		// Search for messages.
		$message_ids = imap_search( $connection, $config['search_criteria'], SE_UID );

		if ( false === $message_ids || empty( $message_ids ) ) {
			imap_close( $connection );
			return array(
				'success' => true,
				'data'    => array(
					'items'         => array(),
					'count'         => 0,
					'total_matches' => 0,
					'offset'        => 0,
					'has_more'      => false,
				),
				'logs' => $logs,
			);
		}

		// Most recent first.
		$message_ids   = array_reverse( $message_ids );
		$total_matches = count( $message_ids );
		$offset        = (int) $config['offset'];
		$max           = (int) $config['max_messages'];

		// Apply pagination.
		if ( $offset > 0 ) {
			$message_ids = array_slice( $message_ids, $offset );
		}
		$message_ids = array_slice( $message_ids, 0, $max );
		$has_more    = ( $offset + count( $message_ids ) ) < $total_matches;

		$items = array();

		if ( $config['headers_only'] ) {
			// Fast header-only mode — no body parsing, no structure fetching.
			foreach ( $message_ids as $uid ) {
				$item = $this->fetchHeaders( $connection, $uid );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}
		} else {
			// Full mode — bodies + attachments.
			foreach ( $message_ids as $uid ) {
				$item = $this->fetchMessage( $connection, $uid, $config );
				if ( null !== $item ) {
					$items[] = $item;
				}
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
			'message' => sprintf(
				'Email Fetch: Retrieved %d of %d messages (offset %d)',
				count( $items ),
				$total_matches,
				$offset
			),
		);

		return array(
			'success' => true,
			'data'    => array(
				'items'         => $items,
				'count'         => count( $items ),
				'total_matches' => $total_matches,
				'offset'        => $offset,
				'has_more'      => $has_more,
			),
			'logs' => $logs,
		);
	}

	/**
	 * Fetch headers only for a message (fast list mode).
	 *
	 * No body parsing, no structure fetching, no attachment scanning.
	 * Just From, Subject, Date, UID, and basic flags.
	 *
	 * @param resource $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @return array|null Header data or null on failure.
	 */
	private function fetchHeaders( $connection, int $uid ): ?array {
		$msgno = imap_msgno( $connection, $uid );
		if ( 0 === $msgno ) {
			return null;
		}

		$header_info = imap_headerinfo( $connection, $msgno );
		if ( false === $header_info ) {
			return null;
		}

		$subject    = isset( $header_info->subject ) ? imap_utf8( $header_info->subject ) : '';
		$from       = $header_info->from[0] ?? null;
		$from_email = $from ? ( $from->mailbox . '@' . $from->host ) : '';
		$from_name  = isset( $from->personal ) ? imap_utf8( $from->personal ) : '';
		$date       = isset( $header_info->date ) ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $header_info->date ) ) : '';
		$message_id = $header_info->message_id ?? '';

		// Read flags.
		$seen    = ( isset( $header_info->Unseen ) && 'U' === $header_info->Unseen ) ? false : true;
		$flagged = ( isset( $header_info->Flagged ) && 'F' === $header_info->Flagged );

		// Get to address.
		$to_address = '';
		if ( ! empty( $header_info->to ) ) {
			$to         = $header_info->to[0];
			$to_address = $to->mailbox . '@' . $to->host;
		}

		// Get size from overview (fast, no body fetch).
		$overview = imap_fetch_overview( $connection, (string) $uid, FT_UID );
		$size     = ( ! empty( $overview ) ) ? ( $overview[0]->size ?? 0 ) : 0;

		return array(
			'title'    => $subject,
			'content'  => '',
			'metadata' => array(
				'uid'               => $uid,
				'message_id'        => $message_id,
				'item_identifier'         => $message_id,
				'from'              => $from_email,
				'from_name'         => $from_name,
				'to'                => $to_address,
				'date'              => $date,
				'original_date_gmt' => $date,
				'seen'              => $seen,
				'flagged'           => $flagged,
				'size'              => $size,
				'in_reply_to'       => $header_info->in_reply_to ?? '',
			),
		);
	}

	/**
	 * Fetch a single message with full body by UID.
	 *
	 * @param resource $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @param array    $config     Fetch configuration.
	 * @return array|null Message data or null on failure.
	 */
	private function fetchMessage( $connection, int $uid, array $config ): ?array {
		$msgno = imap_msgno( $connection, $uid );
		if ( 0 === $msgno ) {
			return null;
		}

		$header_info = imap_headerinfo( $connection, $msgno );
		if ( false === $header_info ) {
			return null;
		}

		$subject    = isset( $header_info->subject ) ? imap_utf8( $header_info->subject ) : '';
		$from       = $header_info->from[0] ?? null;
		$from_email = $from ? ( $from->mailbox . '@' . $from->host ) : '';
		$from_name  = isset( $from->personal ) ? imap_utf8( $from->personal ) : '';
		$date       = isset( $header_info->date ) ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $header_info->date ) ) : '';
		$message_id = $header_info->message_id ?? '';
		$in_reply   = $header_info->in_reply_to ?? '';
		$references = $header_info->references ?? '';

		$seen    = ( isset( $header_info->Unseen ) && 'U' === $header_info->Unseen ) ? false : true;
		$flagged = ( isset( $header_info->Flagged ) && 'F' === $header_info->Flagged );

		$to_address = '';
		if ( ! empty( $header_info->to ) ) {
			$to         = $header_info->to[0];
			$to_address = $to->mailbox . '@' . $to->host;
		}

		// Fetch body.
		$body = $this->fetchBody( $connection, $uid );

		// Check for attachments.
		$structure        = imap_fetchstructure( $connection, $uid, FT_UID );
		$attachment_count = 0;
		$file_info        = null;

		if ( $structure ) {
			$attachments      = $this->findAttachments( $structure );
			$attachment_count = count( $attachments );

			if ( $config['download_attachments'] && ! empty( $attachments ) ) {
				$file_info = $this->downloadAttachment( $connection, $uid, $attachments[0] );
			}
		}

		$item = array(
			'title'    => $subject,
			'content'  => $body,
			'metadata' => array(
				'uid'               => $uid,
				'message_id'        => $message_id,
				'item_identifier'         => $message_id,
				'from'              => $from_email,
				'from_name'         => $from_name,
				'to'                => $to_address,
				'date'              => $date,
				'original_date_gmt' => $date,
				'seen'              => $seen,
				'flagged'           => $flagged,
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

			if ( 0 === ( $part->type ?? -1 ) ) {
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
	 */
	private function findAttachments( object $structure ): array {
		$attachments = array();

		if ( empty( $structure->parts ) ) {
			return $attachments;
		}

		foreach ( $structure->parts as $part_index => $part ) {
			$part_number = (string) ( $part_index + 1 );

			$is_attachment = false;
			$filename      = '';

			if ( ! empty( $part->disposition ) && 'attachment' === strtolower( $part->disposition ) ) {
				$is_attachment = true;
			}

			if ( ! empty( $part->dparameters ) ) {
				foreach ( $part->dparameters as $param ) {
					if ( 'filename' === strtolower( $param->attribute ) ) {
						$filename      = $param->value;
						$is_attachment = true;
					}
				}
			}

			if ( empty( $filename ) && ! empty( $part->parameters ) ) {
				foreach ( $part->parameters as $param ) {
					if ( 'name' === strtolower( $param->attribute ) ) {
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
	 * Normalize input configuration with defaults.
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
			'offset'               => 0,
			'uid'                  => 0,
			'headers_only'         => false,
			'mark_as_read'         => false,
			'download_attachments' => false,
		);

		return array_merge( $defaults, $input );
	}
}
