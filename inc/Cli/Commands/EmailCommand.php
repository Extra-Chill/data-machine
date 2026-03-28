<?php
/**
 * Email CLI Command
 *
 * WP-CLI commands for email operations: send, fetch, reply, delete, move, flag, test.
 * All commands delegate to registered abilities.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class EmailCommand extends BaseCommand {

	/**
	 * Send an email.
	 *
	 * ## OPTIONS
	 *
	 * --to=<emails>
	 * : Comma-separated recipient email addresses.
	 *
	 * --subject=<subject>
	 * : Email subject. Supports {month}, {year}, {site_name}, {date} placeholders.
	 *
	 * --body=<body>
	 * : Email body content (HTML or plain text).
	 *
	 * [--cc=<emails>]
	 * : Comma-separated CC addresses.
	 *
	 * [--bcc=<emails>]
	 * : Comma-separated BCC addresses.
	 *
	 * [--from-name=<name>]
	 * : Sender name. Defaults to site name.
	 *
	 * [--from-email=<email>]
	 * : Sender email. Defaults to admin email.
	 *
	 * [--reply-to=<email>]
	 * : Reply-to address.
	 *
	 * [--content-type=<type>]
	 * : Content type: text/html or text/plain.
	 * ---
	 * default: text/html
	 * ---
	 *
	 * [--attachments=<paths>]
	 * : Comma-separated file paths to attach.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email send --to=user@example.com --subject="Report" --body="<p>Hello</p>"
	 *     wp datamachine email send --to=a@x.com,b@x.com --subject="Monthly {month}" --body="Report" --attachments=/tmp/report.csv
	 *
	 * @subcommand send
	 */
	public function send( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( ! $ability ) {
			WP_CLI::error( 'Send email ability not available.' );
		}

		$input = array(
			'to'           => $assoc_args['to'],
			'subject'      => $assoc_args['subject'],
			'body'         => $assoc_args['body'],
			'cc'           => $assoc_args['cc'] ?? '',
			'bcc'          => $assoc_args['bcc'] ?? '',
			'from_name'    => $assoc_args['from-name'] ?? '',
			'from_email'   => $assoc_args['from-email'] ?? '',
			'reply_to'     => $assoc_args['reply-to'] ?? '',
			'content_type' => $assoc_args['content-type'] ?? 'text/html',
			'attachments'  => array(),
		);

		if ( ! empty( $assoc_args['attachments'] ) ) {
			$input['attachments'] = array_map( 'trim', explode( ',', $assoc_args['attachments'] ) );
		}

		$result = $ability->execute( $input );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Email sent.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Email send failed.' );
		}
	}

	/**
	 * Fetch emails from IMAP inbox.
	 *
	 * ## OPTIONS
	 *
	 * [--folder=<folder>]
	 * : Mail folder to fetch from.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--search=<criteria>]
	 * : IMAP search criteria (UNSEEN, ALL, FROM "x", SINCE "1-Mar-2026").
	 * ---
	 * default: UNSEEN
	 * ---
	 *
	 * [--max=<count>]
	 * : Maximum number of messages to retrieve.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Number of messages to skip (for pagination).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--headers-only]
	 * : Fast mode — fetch headers only, skip body parsing.
	 *
	 * [--mark-read]
	 * : Mark fetched messages as read.
	 *
	 * [--download-attachments]
	 * : Download email attachments.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to display.
	 * ---
	 * default: uid,from,subject,date
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email fetch
	 *     wp datamachine email fetch --search=ALL --max=50 --headers-only
	 *     wp datamachine email fetch --search='FROM "boss@company.com"' --max=5
	 *     wp datamachine email fetch --search=ALL --max=20 --offset=20
	 *
	 * @subcommand fetch
	 */
	public function fetch( array $args, array $assoc_args ): void {
		$auth = $this->getAuthProvider();
		if ( ! $auth || ! $auth->is_authenticated() ) {
			WP_CLI::error( 'IMAP credentials not configured. Run: wp datamachine auth save email_imap' );
		}

		$ability = wp_get_ability( 'datamachine/fetch-email' );
		if ( ! $ability ) {
			WP_CLI::error( 'Fetch email ability not available.' );
		}

		$input = array(
			'imap_host'            => $auth->getHost(),
			'imap_port'            => $auth->getPort(),
			'imap_encryption'      => $auth->getEncryption(),
			'imap_user'            => $auth->getUser(),
			'imap_password'        => $auth->getPassword(),
			'folder'               => $assoc_args['folder'] ?? 'INBOX',
			'search_criteria'      => $assoc_args['search'] ?? 'UNSEEN',
			'max_messages'         => (int) ( $assoc_args['max'] ?? 10 ),
			'offset'               => (int) ( $assoc_args['offset'] ?? 0 ),
			'headers_only'         => isset( $assoc_args['headers-only'] ),
			'mark_as_read'         => isset( $assoc_args['mark-read'] ),
			'download_attachments' => isset( $assoc_args['download-attachments'] ),
		);

		$result = $ability->execute( $input );

		if ( ! ( $result['success'] ?? false ) ) {
			WP_CLI::error( $result['error'] ?? 'Fetch failed.' );
		}

		$data  = $result['data'] ?? array();
		$items = $data['items'] ?? array();

		if ( empty( $items ) ) {
			WP_CLI::success( 'No messages found.' );
			return;
		}

		// Flatten items for table display.
		$rows = array();
		foreach ( $items as $item ) {
			$meta   = $item['metadata'] ?? array();
			$rows[] = array(
				'uid'         => $meta['uid'] ?? '',
				'from'        => $meta['from'] ?? '',
				'from_name'   => $meta['from_name'] ?? '',
				'to'          => $meta['to'] ?? '',
				'subject'     => $item['title'] ?? '',
				'date'        => $meta['date'] ?? '',
				'seen'        => ( $meta['seen'] ?? false ) ? 'Y' : 'N',
				'flagged'     => ( $meta['flagged'] ?? false ) ? '*' : '',
				'size'        => $meta['size'] ?? '',
				'attachments' => $meta['attachment_count'] ?? '',
				'message_id'  => $meta['message_id'] ?? '',
				'in_reply_to' => $meta['in_reply_to'] ?? '',
			);
		}

		$fields = explode( ',', $assoc_args['fields'] ?? 'uid,from,subject,date' );
		$this->format_items( $rows, $fields, $assoc_args );

		// Pagination info.
		$total    = $data['total_matches'] ?? 0;
		$offset   = $data['offset'] ?? 0;
		$has_more = $data['has_more'] ?? false;
		$format   = $assoc_args['format'] ?? 'table';

		if ( 'table' === $format && $total > 0 ) {
			$showing_end = $offset + count( $items );
			WP_CLI::line( '' );
			WP_CLI::line( sprintf(
				'Showing %d–%d of %d matches.%s',
				$offset + 1,
				$showing_end,
				$total,
				$has_more ? ' Use --offset=' . $showing_end . ' for next page.' : ''
			) );
		}
	}

	/**
	 * Read a single email by UID.
	 *
	 * ## OPTIONS
	 *
	 * <uid>
	 * : Message UID to read.
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email read 12345
	 *     wp datamachine email read 12345 --format=json
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		$uid = (int) $args[0];
		if ( $uid <= 0 ) {
			WP_CLI::error( 'Invalid message UID.' );
		}

		$auth = $this->getAuthProvider();
		if ( ! $auth || ! $auth->is_authenticated() ) {
			WP_CLI::error( 'IMAP credentials not configured.' );
		}

		$ability = wp_get_ability( 'datamachine/fetch-email' );
		if ( ! $ability ) {
			WP_CLI::error( 'Fetch email ability not available.' );
		}

		$result = $ability->execute( array(
			'imap_host'       => $auth->getHost(),
			'imap_port'       => $auth->getPort(),
			'imap_encryption' => $auth->getEncryption(),
			'imap_user'       => $auth->getUser(),
			'imap_password'   => $auth->getPassword(),
			'folder'          => $assoc_args['folder'] ?? 'INBOX',
			'uid'             => $uid,
		) );

		if ( ! ( $result['success'] ?? false ) ) {
			WP_CLI::error( $result['error'] ?? 'Message not found.' );
		}

		$item = $result['data']['items'][0] ?? null;
		if ( ! $item ) {
			WP_CLI::error( 'Message not found.' );
		}

		$format = $assoc_args['format'] ?? 'text';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		// Human-readable text output.
		$meta = $item['metadata'] ?? array();
		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( 'From:    ' . ( $meta['from_name'] ? $meta['from_name'] . ' <' . $meta['from'] . '>' : $meta['from'] ) );
		WP_CLI::line( 'To:      ' . ( $meta['to'] ?? '' ) );
		WP_CLI::line( 'Date:    ' . ( $meta['date'] ?? '' ) );
		WP_CLI::line( 'Subject: ' . ( $item['title'] ?? '' ) );

		if ( ! empty( $meta['in_reply_to'] ) ) {
			WP_CLI::line( 'Reply-To: ' . $meta['in_reply_to'] );
		}
		if ( ! empty( $meta['attachment_count'] ) && $meta['attachment_count'] > 0 ) {
			WP_CLI::line( 'Attachments: ' . $meta['attachment_count'] );
		}

		WP_CLI::line( str_repeat( '─', 60 ) );
		WP_CLI::line( '' );
		WP_CLI::line( $item['content'] ?? '' );
	}

	/**
	 * Reply to an email.
	 *
	 * ## OPTIONS
	 *
	 * --to=<email>
	 * : Recipient email address.
	 *
	 * --subject=<subject>
	 * : Reply subject (typically starts with Re:).
	 *
	 * --body=<body>
	 * : Reply body content.
	 *
	 * --in-reply-to=<message_id>
	 * : Message-ID of the email being replied to.
	 *
	 * [--references=<refs>]
	 * : References header chain for threading.
	 *
	 * [--cc=<emails>]
	 * : Comma-separated CC addresses.
	 *
	 * [--content-type=<type>]
	 * : Content type.
	 * ---
	 * default: text/html
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email reply --to=sender@x.com --subject="Re: Hello" --body="Thanks!" --in-reply-to="<msgid@x.com>"
	 *
	 * @subcommand reply
	 */
	public function reply( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-reply' );
		if ( ! $ability ) {
			WP_CLI::error( 'Email reply ability not available.' );
		}

		$input = array(
			'to'           => $assoc_args['to'],
			'subject'      => $assoc_args['subject'],
			'body'         => $assoc_args['body'],
			'in_reply_to'  => $assoc_args['in-reply-to'],
			'references'   => $assoc_args['references'] ?? '',
			'cc'           => $assoc_args['cc'] ?? '',
			'content_type' => $assoc_args['content-type'] ?? 'text/html',
		);

		$result = $ability->execute( $input );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Reply sent.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Reply failed.' );
		}
	}

	/**
	 * Delete an email from the server.
	 *
	 * ## OPTIONS
	 *
	 * <uid>
	 * : Message UID to delete.
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email delete 12345
	 *     wp datamachine email delete 12345 --folder=Trash --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$uid = (int) $args[0];
		if ( $uid <= 0 ) {
			WP_CLI::error( 'Invalid message UID.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Delete message UID {$uid}?" );
		}

		$ability = wp_get_ability( 'datamachine/email-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'Email delete ability not available.' );
		}

		$result = $ability->execute( array(
			'uid'    => $uid,
			'folder' => $assoc_args['folder'] ?? 'INBOX',
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Message deleted.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Delete failed.' );
		}
	}

	/**
	 * Move an email to a different folder.
	 *
	 * ## OPTIONS
	 *
	 * <uid>
	 * : Message UID to move.
	 *
	 * <destination>
	 * : Target folder name.
	 *
	 * [--folder=<folder>]
	 * : Source folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email move 12345 Archive
	 *     wp datamachine email move 12345 "[Gmail]/Trash" --folder=INBOX
	 *
	 * @subcommand move
	 */
	public function move( array $args, array $assoc_args ): void {
		$uid         = (int) $args[0];
		$destination = $args[1] ?? '';

		if ( $uid <= 0 || empty( $destination ) ) {
			WP_CLI::error( 'Usage: wp datamachine email move <uid> <destination>' );
		}

		$ability = wp_get_ability( 'datamachine/email-move' );
		if ( ! $ability ) {
			WP_CLI::error( 'Email move ability not available.' );
		}

		$result = $ability->execute( array(
			'uid'         => $uid,
			'destination' => $destination,
			'folder'      => $assoc_args['folder'] ?? 'INBOX',
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Message moved.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Move failed.' );
		}
	}

	/**
	 * Set or clear a flag on an email.
	 *
	 * ## OPTIONS
	 *
	 * <uid>
	 * : Message UID.
	 *
	 * <flag>
	 * : Flag to set: Seen, Flagged, Answered, Deleted, Draft.
	 *
	 * [--action=<action>]
	 * : set or clear the flag.
	 * ---
	 * default: set
	 * options:
	 *   - set
	 *   - clear
	 * ---
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email flag 12345 Flagged
	 *     wp datamachine email flag 12345 Seen --action=clear
	 *
	 * @subcommand flag
	 */
	public function flag( array $args, array $assoc_args ): void {
		$uid  = (int) $args[0];
		$flag = $args[1] ?? '';

		if ( $uid <= 0 || empty( $flag ) ) {
			WP_CLI::error( 'Usage: wp datamachine email flag <uid> <flag>' );
		}

		$ability = wp_get_ability( 'datamachine/email-flag' );
		if ( ! $ability ) {
			WP_CLI::error( 'Email flag ability not available.' );
		}

		$result = $ability->execute( array(
			'uid'    => $uid,
			'flag'   => $flag,
			'action' => $assoc_args['action'] ?? 'set',
			'folder' => $assoc_args['folder'] ?? 'INBOX',
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Flag updated.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Flag operation failed.' );
		}
	}

	/**
	 * Move all emails matching a search to a folder.
	 *
	 * ## OPTIONS
	 *
	 * --search=<criteria>
	 * : IMAP search criteria (e.g., FROM "github.com", SUBJECT "newsletter").
	 *
	 * --destination=<folder>
	 * : Target folder (e.g., Archive, [Gmail]/GitHub, [Gmail]/Spam).
	 *
	 * [--folder=<folder>]
	 * : Source folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--max=<count>]
	 * : Maximum messages to move (safety limit).
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email batch-move --search='FROM "github.com"' --destination="[Gmail]/GitHub"
	 *     wp datamachine email batch-move --search='FROM "linkedin.com"' --destination="[Gmail]/Trash" --yes
	 *     wp datamachine email batch-move --search='SUBJECT "newsletter"' --destination=Newsletters --max=100
	 *
	 * @subcommand batch-move
	 */
	public function batch_move( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-batch-move' );
		if ( ! $ability ) {
			WP_CLI::error( 'Batch move ability not available.' );
		}

		$search      = $assoc_args['search'] ?? '';
		$destination = $assoc_args['destination'] ?? '';

		if ( empty( $search ) || empty( $destination ) ) {
			WP_CLI::error( 'Both --search and --destination are required.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Move all messages matching '{$search}' to '{$destination}'?" );
		}

		$result = $ability->execute( array(
			'search'      => $search,
			'destination' => $destination,
			'folder'      => $assoc_args['folder'] ?? 'INBOX',
			'max'         => (int) ( $assoc_args['max'] ?? 500 ),
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Batch move complete.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Batch move failed.' );
		}
	}

	/**
	 * Set or clear a flag on all emails matching a search.
	 *
	 * ## OPTIONS
	 *
	 * --search=<criteria>
	 * : IMAP search criteria.
	 *
	 * <flag>
	 * : Flag: Seen, Flagged, Answered, Deleted, Draft.
	 *
	 * [--action=<action>]
	 * : set or clear the flag.
	 * ---
	 * default: set
	 * options:
	 *   - set
	 *   - clear
	 * ---
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--max=<count>]
	 * : Maximum messages to flag (safety limit).
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email batch-flag --search='FROM "linkedin.com"' Seen
	 *     wp datamachine email batch-flag --search='FROM "github.com"' Seen --action=clear
	 *     wp datamachine email batch-flag --search=UNSEEN Flagged --max=10
	 *
	 * @subcommand batch-flag
	 */
	public function batch_flag( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-batch-flag' );
		if ( ! $ability ) {
			WP_CLI::error( 'Batch flag ability not available.' );
		}

		$search = $assoc_args['search'] ?? '';
		$flag   = $args[0] ?? '';
		$action = $assoc_args['action'] ?? 'set';

		if ( empty( $search ) || empty( $flag ) ) {
			WP_CLI::error( 'Both --search and a flag argument are required.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "{$action} flag '{$flag}' on all messages matching '{$search}'?" );
		}

		$result = $ability->execute( array(
			'search' => $search,
			'flag'   => $flag,
			'action' => $action,
			'folder' => $assoc_args['folder'] ?? 'INBOX',
			'max'    => (int) ( $assoc_args['max'] ?? 500 ),
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Batch flag complete.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Batch flag failed.' );
		}
	}

	/**
	 * Delete all emails matching a search.
	 *
	 * ## OPTIONS
	 *
	 * --search=<criteria>
	 * : IMAP search criteria.
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--max=<count>]
	 * : Maximum messages to delete (safety limit).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email batch-delete --search='FROM "spam@example.com"'
	 *     wp datamachine email batch-delete --search='SUBJECT "unsubscribe" BEFORE "1-Jan-2025"' --max=200 --yes
	 *
	 * @subcommand batch-delete
	 */
	public function batch_delete( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-batch-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'Batch delete ability not available.' );
		}

		$search = $assoc_args['search'] ?? '';
		if ( empty( $search ) ) {
			WP_CLI::error( '--search is required.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "DELETE all messages matching '{$search}'? This cannot be undone." );
		}

		$result = $ability->execute( array(
			'search' => $search,
			'folder' => $assoc_args['folder'] ?? 'INBOX',
			'max'    => (int) ( $assoc_args['max'] ?? 100 ),
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Batch delete complete.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Batch delete failed.' );
		}
	}

	/**
	 * Unsubscribe from a mailing list.
	 *
	 * Parses the List-Unsubscribe header from the email and executes
	 * the unsubscribe via One-Click POST, URL GET, or mailto.
	 *
	 * ## OPTIONS
	 *
	 * <uid>
	 * : Message UID to unsubscribe from.
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email unsubscribe 83012
	 *
	 * @subcommand unsubscribe
	 */
	public function unsubscribe( array $args, array $assoc_args ): void {
		$uid = (int) $args[0];
		if ( $uid <= 0 ) {
			WP_CLI::error( 'Invalid message UID.' );
		}

		$ability = wp_get_ability( 'datamachine/email-unsubscribe' );
		if ( ! $ability ) {
			WP_CLI::error( 'Unsubscribe ability not available.' );
		}

		$result = $ability->execute( array(
			'uid'    => $uid,
			'folder' => $assoc_args['folder'] ?? 'INBOX',
		) );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Unsubscribed.' );
		} else {
			WP_CLI::error( $result['error'] ?? 'Unsubscribe failed.' );
		}
	}

	/**
	 * Unsubscribe from all mailing lists matching a search.
	 *
	 * Deduplicates by sender — processes one unsubscribe per unique
	 * From address using the most recent message's headers.
	 *
	 * ## OPTIONS
	 *
	 * --search=<criteria>
	 * : IMAP search criteria.
	 *
	 * [--folder=<folder>]
	 * : Mail folder.
	 * ---
	 * default: INBOX
	 * ---
	 *
	 * [--max=<count>]
	 * : Maximum unique senders to unsubscribe from.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email batch-unsubscribe --search='FROM "linkedin.com"'
	 *     wp datamachine email batch-unsubscribe --search='FROM "dominos.com"' --yes
	 *     wp datamachine email batch-unsubscribe --search='SUBJECT "newsletter"' --max=10
	 *
	 * @subcommand batch-unsubscribe
	 */
	public function batch_unsubscribe( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-batch-unsubscribe' );
		if ( ! $ability ) {
			WP_CLI::error( 'Batch unsubscribe ability not available.' );
		}

		$search = $assoc_args['search'] ?? '';
		if ( empty( $search ) ) {
			WP_CLI::error( '--search is required.' );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Unsubscribe from all mailing lists matching '{$search}'?" );
		}

		$result = $ability->execute( array(
			'search' => $search,
			'folder' => $assoc_args['folder'] ?? 'INBOX',
			'max'    => (int) ( $assoc_args['max'] ?? 20 ),
		) );

		if ( ! ( $result['success'] ?? false ) ) {
			WP_CLI::error( $result['error'] ?? 'Batch unsubscribe failed.' );
		}

		// Show results per sender.
		$results = $result['results'] ?? array();
		if ( ! empty( $results ) ) {
			foreach ( $results as $r ) {
				$status = ( $r['success'] ?? false )
					? WP_CLI::colorize( '%G✓%n ' . ( $r['method'] ?? '' ) )
					: WP_CLI::colorize( '%R✗%n ' . ( $r['reason'] ?? 'failed' ) );
				WP_CLI::line( sprintf( '  %-40s %s', $r['sender'] ?? '', $status ) );
			}
			WP_CLI::line( '' );
		}

		WP_CLI::success( $result['message'] ?? 'Batch unsubscribe complete.' );
	}

	/**
	 * Test the IMAP connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine email test-connection
	 *
	 * @subcommand test-connection
	 */
	public function test_connection( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/email-test-connection' );
		if ( ! $ability ) {
			WP_CLI::error( 'Email test connection ability not available.' );
		}

		$result = $ability->execute( array() );

		if ( $result['success'] ?? false ) {
			WP_CLI::success( $result['message'] ?? 'Connection OK.' );

			$info = $result['mailbox_info'] ?? array();
			if ( ! empty( $info['folders'] ) ) {
				WP_CLI::line( '' );
				WP_CLI::line( 'Available folders:' );
				foreach ( $info['folders'] as $folder ) {
					WP_CLI::line( '  - ' . $folder );
				}
			}
		} else {
			WP_CLI::error( $result['error'] ?? 'Connection failed.' );
		}
	}

	/**
	 * Get the IMAP auth provider.
	 *
	 * @return object|null
	 */
	private function getAuthProvider(): ?object {
		$providers = apply_filters( 'datamachine_auth_providers', array() );
		return $providers['email_imap'] ?? null;
	}
}
