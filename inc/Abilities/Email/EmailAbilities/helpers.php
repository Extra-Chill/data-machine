//! helpers — extracted from EmailAbilities.php.


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
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'to', 'subject', 'body', 'in_reply_to' ),
						'properties' => array(
							'to'          => array(
								'type'        => 'string',
								'description' => __( 'Recipient email address', 'data-machine' ),
							),
							'subject'     => array(
								'type'        => 'string',
								'description' => __( 'Reply subject (typically Re: original subject)', 'data-machine' ),
							),
							'body'        => array(
								'type'        => 'string',
								'description' => __( 'Reply body content', 'data-machine' ),
							),
							'in_reply_to' => array(
								'type'        => 'string',
								'description' => __( 'Message-ID of the email being replied to', 'data-machine' ),
							),
							'references'  => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'References header chain for threading', 'data-machine' ),
							),
							'cc'          => array(
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
								'type'    => 'string',
								'default' => 'set',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
								'type'    => 'string',
								'default' => 'set',
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
							'success'        => array( 'type' => 'boolean' ),
							'message'        => array( 'type' => 'string' ),
							'flagged_count'  => array( 'type' => 'integer' ),
							'total_matches'  => array( 'type' => 'integer' ),
							'error'          => array( 'type' => 'string' ),
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
					'category'            => 'datamachine',
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
							'success'        => array( 'type' => 'boolean' ),
							'message'        => array( 'type' => 'string' ),
							'deleted_count'  => array( 'type' => 'integer' ),
							'total_matches'  => array( 'type' => 'integer' ),
							'error'          => array( 'type' => 'string' ),
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
							'success'     => array( 'type' => 'boolean' ),
							'message'     => array( 'type' => 'string' ),
							'results'     => array( 'type' => 'array' ),
							'unsubscribed' => array( 'type' => 'integer' ),
							'failed'      => array( 'type' => 'integer' ),
							'no_header'   => array( 'type' => 'integer' ),
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
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'mailbox_info'  => array( 'type' => 'object' ),
							'error'         => array( 'type' => 'string' ),
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

			$from = $header->from[0];
			$sender = $from->mailbox . '@' . $from->host;

			if ( isset( $seen_senders[ $sender ] ) ) {
				continue;
			}

			$seen_senders[ $sender ] = true;

			// Fetch unsubscribe headers.
			$raw = imap_fetchheader( $connection, $uid, FT_UID );
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

		$has_one_click = stripos( $post_header, 'List-Unsubscribe=One-Click' ) !== false;

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
