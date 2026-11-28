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
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;
use DataMachine\Abilities\Fetch\Traits\HasApplyKeywordSearch;
use DataMachine\Abilities\Fetch\Traits\HasApplyKeywordExclusion;

defined( 'ABSPATH' ) || exit;

class FetchEmailAbility {
	use HasApplyKeywordSearch;
	use HasApplyKeywordExclusion;

	private const KEYWORD_SCAN_LIMIT = 500;

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
			wp_register_ability(
				'datamachine/fetch-email',
				array(
					'label'               => __( 'Fetch Emails', 'data-machine' ),
					'description'         => __( 'Retrieve emails from an IMAP inbox', 'data-machine' ),
					'category'            => 'datamachine-fetch',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array(),
						'properties' => array(
							'auth_ref'             => array(
								'type'        => 'string',
								'default'     => 'email_imap:default',
								'description' => __( 'Non-secret mailbox auth ref', 'data-machine' ),
							),
							'folder'               => array(
								'type'        => 'string',
								'default'     => 'INBOX',
								'description' => __( 'Mail folder to fetch from', 'data-machine' ),
							),
							'search_criteria'      => array(
								'anyOf'       => array(
									array( 'type' => 'string' ),
									array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
								'default'     => 'UNSEEN',
								'description' => __( 'IMAP search string (UNSEEN, ALL, FROM "x", SINCE "1-Mar-2026"), or a list of strings whose results are merged (OR)', 'data-machine' ),
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
							'search'               => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Comma-separated keywords; a message matches if its subject or body contains any of them', 'data-machine' ),
							),
							'exclude_keywords'     => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Comma-separated keywords; a message is skipped if its subject or body contains any of them', 'data-machine' ),
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
							'success' => array( 'type' => 'boolean' ),
							'data'    => array(
								'type'       => 'object',
								'properties' => array(
									'items'         => array( 'type' => 'array' ),
									'count'         => array( 'type' => 'integer' ),
									'total_matches' => array( 'type' => 'integer' ),
									'offset'        => array( 'type' => 'integer' ),
									'has_more'      => array( 'type' => 'boolean' ),
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

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Permission callback.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' ) || PermissionHelper::can_manage();
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
	 * @return array|\WP_Error Result with items or an error.
	 */
	public function execute( array $input ): array|\WP_Error {
		return $this->executeWithContext( $input, array() );
	}

	public function executeWithContext( array $input, array $context ): array|\WP_Error {
		$logs = array();

		if ( ! function_exists( 'imap_open' ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email Fetch: PHP IMAP extension is not installed',
			);
			return new \WP_Error( 'email_imap_unavailable', 'PHP IMAP extension is required but not installed. Install php-imap and restart your web server.', array( 'status' => 400 ) );
		}

		$config     = $this->normalizeConfig( $input );
		$operations = array( 'read' );
		if ( empty( $config['uid'] ) && ! empty( $config['search_criteria'] ) ) {
			$operations[] = 'search';
		}
		if ( $config['mark_as_read'] ) {
			$operations[] = 'organize';
		}
		$auth = $this->getAuthProvider();
		if ( ! $auth ) {
			return new \WP_Error( 'email_imap_not_configured', 'Email IMAP provider is not registered.', array( 'status' => 400 ) );
		}
		$resolved = empty( $context )
			? $auth->resolve_mailbox( $config['auth_ref'], $operations )
			: $auth->resolve_mailbox_for_principal( $config['auth_ref'], $operations, $context );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$config = array_merge( $config, $resolved['credentials'] );

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
			return new \WP_Error( 'email_imap_connection_failed', 'IMAP connection failed: ' . $imap_error, array( 'status' => 400 ) );
		}

		// Single message fetch by UID (detail mode).
		if ( ! empty( $config['uid'] ) ) {
			$item = $this->fetchMessage( $connection, (int) $config['uid'], $config );
			imap_close( $connection );

			if ( null === $item ) {
				return new \WP_Error( 'email_message_not_found', 'Message UID ' . $config['uid'] . ' not found', array( 'status' => 400 ) );
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
				'logs'    => $logs,
			);
		}

		// Search for messages. c-client has no OR support, so callers pass
		// one criteria string per alternative and the UIDs are merged here.
		$message_ids = $this->searchUids( $connection, $config['search_criteria'] );

		if ( empty( $message_ids ) ) {
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
				'logs'    => $logs,
			);
		}

		$offset    = max( 0, (int) $config['offset'] );
		$max       = (int) $config['max_messages'];
		$selection = $this->selectUids( $connection, $message_ids, $config, $offset, $max, $logs );

		$message_ids   = $selection['uids'];
		$total_matches = $selection['total_matches'];
		$has_more      = $selection['has_more'];

		$items = array();
		foreach ( $message_ids as $uid ) {
			$item = $config['headers_only']
				? $this->fetchHeaders( $connection, (int) $uid )
				: $this->fetchMessage( $connection, (int) $uid, $config );
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
			'logs'    => $logs,
		);
	}

	/**
	 * Run one IMAP search per criteria string and merge the UIDs newest first.
	 *
	 * @param \IMAP\Connection        $connection IMAP connection.
	 * @param string|string[] $criteria   One criteria string or a list of OR'd alternatives.
	 * @return int[] Unique UIDs, newest first.
	 */
	private function searchUids( $connection, string|array $criteria ): array {
		$uids = array();
		foreach ( (array) $criteria as $single ) {
			$found = imap_search( $connection, (string) $single, SE_UID );
			if ( is_array( $found ) ) {
				$uids = array_merge( $uids, $found );
			}
		}

		$uids = array_values( array_unique( array_map( 'intval', $uids ) ) );
		// UIDs increase with arrival order within a folder.
		rsort( $uids, SORT_NUMERIC );

		return $uids;
	}

	/**
	 * Pick the page of UIDs to fetch, applying keyword filters before the cap.
	 *
	 * Without keywords this is plain pagination and fetches nothing extra.
	 * With keywords it walks UIDs newest first, decides on the subject when it
	 * can, reads the body text (peek, no attachments) only when it must, and
	 * stops once the requested page is filled or the scan limit is reached.
	 *
	 * @param \IMAP\Connection $connection IMAP connection.
	 * @param int[]    $uids       Candidate UIDs, newest first.
	 * @param array    $config     Normalized config.
	 * @param int      $offset     Page offset.
	 * @param int      $max        Page size.
	 * @param array    $logs       Log entries, appended by reference.
	 * @return array{uids: int[], total_matches: int, has_more: bool}
	 */
	private function selectUids( $connection, array $uids, array $config, int $offset, int $max, array &$logs ): array {
		$include = trim( (string) ( $config['search'] ?? '' ) );
		$exclude = trim( (string) ( $config['exclude_keywords'] ?? '' ) );

		if ( '' === $include && '' === $exclude ) {
			$total = count( $uids );
			return array(
				'uids'          => array_slice( $uids, $offset, max( 0, $max ) ),
				'total_matches' => $total,
				'has_more'      => ( $offset + $max ) < $total,
			);
		}

		$needed  = $offset + max( 1, $max );
		$limit   = (int) apply_filters( 'datamachine_email_keyword_scan_limit', self::KEYWORD_SCAN_LIMIT );
		$scanned = 0;
		$matched = array();

		foreach ( $uids as $uid ) {
			if ( $scanned >= $limit || count( $matched ) >= $needed ) {
				break;
			}
			++$scanned;

			if ( $this->messageMatchesKeywords( $connection, $uid, $include, $exclude ) ) {
				$matched[] = $uid;
			}
		}

		$unscanned = $scanned < count( $uids );
		if ( $unscanned && $scanned >= $limit && count( $matched ) < $needed ) {
			$logs[] = array(
				'level'   => 'warning',
				'message' => sprintf( 'Email Fetch: keyword scan stopped at the %d-message limit; narrow the search criteria or timeframe to reach older matches', $limit ),
			);
		}

		return array(
			'uids'          => array_slice( $matched, $offset, max( 0, $max ) ),
			// Lower bound when the scan stopped early.
			'total_matches' => count( $matched ),
			'has_more'      => $unscanned || count( $matched ) > $offset + $max,
		);
	}

	/**
	 * Decide whether one message passes the include/exclude keyword filters.
	 *
	 * Subject first; the body text is only read when the subject cannot decide.
	 *
	 * @param \IMAP\Connection $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @param string   $include_keywords Comma-separated include keywords (OR).
	 * @param string   $exclude    Comma-separated exclude keywords.
	 * @return bool
	 */
	private function messageMatchesKeywords( $connection, int $uid, string $include_keywords, string $exclude ): bool {
		$headers = $this->fetchHeaders( $connection, $uid );
		if ( null === $headers ) {
			return false;
		}

		$subject = (string) ( $headers['title'] ?? '' );
		if ( $this->applyKeywordExclusion( $subject, $exclude ) ) {
			return false;
		}

		// Subject already includes a keyword and there is no body exclusion to check.
		if ( '' === $exclude && '' !== $include_keywords && $this->applyKeywordSearch( $subject, $include_keywords ) ) {
			return true;
		}

		$text = $subject . ' ' . $this->fetchBody( $connection, $uid, true );

		return $this->applyKeywordSearch( $text, $include_keywords ) && ! $this->applyKeywordExclusion( $text, $exclude );
	}

	/**
	 * Fetch headers only for a message (fast list mode).
	 *
	 * No body parsing, no structure fetching, no attachment scanning.
	 * Just From, Subject, Date, UID, and basic flags.
	 *
	 * @param \IMAP\Connection $connection IMAP connection.
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
				'item_identifier'   => $message_id,
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
	 * @param \IMAP\Connection $connection IMAP connection.
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
		$body = $this->fetchBody( $connection, $uid, ! $config['mark_as_read'] );

		// Check for attachments.
		$structure        = imap_fetchstructure( $connection, $uid, FT_UID );
		$attachment_count = 0;
		$downloaded_files = array();
		$skipped_count    = 0;

		if ( $structure ) {
			$attachments      = $this->findAttachments( $structure );
			$attachment_count = count( $attachments );

			if ( $config['download_attachments'] && ! empty( $attachments ) ) {
				foreach ( $attachments as $attachment ) {
					$file_info = $this->downloadAttachment( $connection, $uid, $attachment, ! $config['mark_as_read'] );
					if ( null === $file_info ) {
						++$skipped_count;
						do_action(
							'datamachine_log',
							'warning',
							'Email attachment download failed',
							array(
								'uid'         => $uid,
								'filename'    => $attachment['filename'] ?? '(unknown)',
								'part_number' => $attachment['part_number'] ?? '',
							)
						);
						continue;
					}
					$downloaded_files[] = $file_info;
				}
			}
		}

		$metadata = array(
			'uid'               => $uid,
			'message_id'        => $message_id,
			'item_identifier'   => $message_id,
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
		);

		// Expose every downloaded attachment under metadata.files so callers
		// that need access to attachments beyond the first can read them.
		// file_info stays as the first attachment for backward compatibility
		// with the FetchHandler pipeline contract (one file_info per item).
		if ( ! empty( $downloaded_files ) ) {
			$metadata['files'] = $downloaded_files;
		}
		if ( $skipped_count > 0 ) {
			$metadata['attachments_skipped'] = $skipped_count;
		}

		$item = array(
			'title'    => $subject,
			'content'  => $body,
			'metadata' => $metadata,
		);

		if ( ! empty( $downloaded_files ) ) {
			$item['file_info'] = $downloaded_files[0];
		}

		return $item;
	}

	/**
	 * Fetch message body, preferring plain text.
	 *
	 * @param \IMAP\Connection $connection IMAP connection.
	 * @param int      $uid        Message UID.
	 * @return string Message body text.
	 */
	private function fetchBody( $connection, int $uid, bool $peek ): string {
		$fetch_flags = FT_UID | ( $peek ? FT_PEEK : 0 );
		$structure   = imap_fetchstructure( $connection, $uid, FT_UID );
		if ( ! $structure ) {
			return '';
		}

		// Simple single-part message.
		if ( empty( $structure->parts ) ) {
			$body = imap_fetchbody( $connection, $uid, '1', $fetch_flags );
			return $this->decodeBody( (string) $body, $structure->encoding ?? 0 );
		}

		// Multipart — find text/plain first, then text/html.
		$plain_body = '';
		$html_body  = '';

		foreach ( $structure->parts as $part_index => $part ) {
			$part_number = (string) ( $part_index + 1 );

			if ( 0 === ( $part->type ?? -1 ) ) {
				$subtype = strtolower( $part->subtype ?? '' );
				$body    = imap_fetchbody( $connection, $uid, $part_number, $fetch_flags );
				$decoded = $this->decodeBody( (string) $body, $part->encoding ?? 0 );

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
						$body    = imap_fetchbody( $connection, $uid, $sub_number, $fetch_flags );
						$decoded = $this->decodeBody( (string) $body, $sub_part->encoding ?? 0 );

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
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes MIME transfer encoding, not executable payloads.
				$decoded_body = base64_decode( $body, true );
				return false !== $decoded_body ? $decoded_body : $body;
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
	private function downloadAttachment( $connection, int $uid, array $attachment_info, bool $peek ): ?array {
		$flags = FT_UID | ( $peek ? FT_PEEK : 0 );
		$body  = imap_fetchbody( $connection, $uid, $attachment_info['part_number'], $flags );
		if ( empty( $body ) ) {
			return null;
		}

		$decoded = $this->decodeBody( (string) $body, $attachment_info['encoding'] );
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
			'filename'  => basename( $file_path ),
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
			'auth_ref'             => 'email_imap:default',
			'folder'               => 'INBOX',
			'search_criteria'      => 'UNSEEN',
			'max_messages'         => 10,
			'offset'               => 0,
			'uid'                  => 0,
			'headers_only'         => false,
			'mark_as_read'         => false,
			'download_attachments' => false,
			'search'               => '',
			'exclude_keywords'     => '',
		);

		return array_merge( $defaults, $input );
	}

	private function getAuthProvider(): ?EmailAuth {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$auth      = $providers['email_imap'] ?? null;
		return $auth instanceof EmailAuth ? $auth : null;
	}
}
