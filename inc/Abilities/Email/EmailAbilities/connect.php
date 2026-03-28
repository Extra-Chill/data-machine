//! connect — extracted from EmailAbilities.php.


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
				'success'        => true,
				'message'        => 'No messages matching search criteria',
				'deleted_count'  => 0,
				'total_matches'  => 0,
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
			'success'        => true,
			'message'        => $message,
			'deleted_count'  => count( $to_delete ),
			'total_matches'  => $total,
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
