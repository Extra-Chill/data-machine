<?php
/** Forward original message content and every attachment from an authorized inbox. */

namespace DataMachine\Abilities\Email;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;
use DataMachine\Core\Email\MailboxTransport;

defined( 'ABSPATH' ) || exit;

final class ForwardEmailAbility {
	private const MAX_BYTES = 20971520;

	public function __construct() {
		AbilityRegistration::on_abilities_api_init( function (): void {
			wp_register_ability( 'datamachine/email-forward', array(
				'label'               => __( 'Forward Email', 'data-machine' ),
				'description'         => __( 'Forward one original email and all its attachments using the source mailbox SMTP identity. Preserve an original.eml copy and avoid duplicate delivery.', 'data-machine' ),
				'category'            => 'datamachine-email',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'auth_ref', 'uid', 'to' ),
					'properties'           => array(
						'auth_ref' => array( 'type' => 'string' ),
						'uid'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'folder'   => array(
							'type'    => 'string',
							'default' => 'INBOX',
						),
						'to'       => array( 'type' => 'string' ),
						'body'     => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			) );
		} );
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' ) || PermissionHelper::can_manage();
	}

	public function execute( array $input ): array|\WP_Error {
		if ( ! $this->checkPermission() ) {
			return new \WP_Error( 'email_forward_forbidden', 'Email forwarding is not authorized.', array( 'status' => 403 ) );
		}
		$to = array_map( 'trim', explode( ',', $input['to'] ?? '' ) );
		if ( count( $to ) > 10 || count( array_filter( $to, static fn( string $email ): bool => false !== is_email( $email ) ) ) !== count( $to ) || (int) ( $input['uid'] ?? 0 ) <= 0 ) {
			return new \WP_Error( 'email_forward_invalid', 'Provide a message UID and valid recipients.' );
		}
		$mailbox = ( new EmailAuth() )->resolve_mailbox( $input['auth_ref'] ?? '', array( 'read', 'send' ) );
		if ( is_wp_error( $mailbox ) ) {
			return $mailbox;
		}
		$config = $mailbox['credentials'];
		if ( empty( $config['smtp_host'] ) || empty( $config['smtp_user'] ) || empty( $config['smtp_password'] ) ) {
			return new \WP_Error( 'email_smtp_required', 'Configure SMTP for the source mailbox before forwarding.' );
		}
		if ( ! function_exists( 'imap_open' ) ) {
			return new \WP_Error( 'email_imap_unavailable', 'PHP IMAP extension is required for forwarding.' );
		}
		$folder = $input['folder'] ?? 'INBOX';
		if ( ! is_string( $folder ) || preg_match( '/[\r\n\x00{}]/', $folder ) || ! preg_match( '/^[a-z0-9.-]+$/i', $config['imap_host'] ) ) {
			return new \WP_Error( 'email_folder_invalid', 'Invalid mailbox folder or host.' );
		}
		$flags  = 'ssl' === ( $config['imap_encryption'] ?? 'ssl' ) ? '/imap/ssl/validate-cert' : '/imap/tls/validate-cert';
		$prefix = sprintf( '{%s:%d%s}', $config['imap_host'], (int) ( $config['imap_port'] ?? 993 ), $flags );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Return a credential-free connection error below.
		$connection = @imap_open( $prefix . $folder, $config['imap_user'], $config['imap_password'] );
		if ( false === $connection ) {
			return new \WP_Error( 'email_imap_connection_failed', 'Could not connect to the source mailbox.' );
		}
		try {
			$uid      = (int) $input['uid'];
			$overview = imap_fetch_overview( $connection, (string) $uid, FT_UID );
			if ( empty( $overview ) || (int) ( $overview[0]->size ?? 0 ) > self::MAX_BYTES ) {
				return new \WP_Error( 'email_message_invalid', 'Message not found or exceeds the 20 MiB forwarding limit.' );
			}
			$header    = imap_fetchheader( $connection, $uid, FT_UID );
			$raw_body  = imap_body( $connection, $uid, FT_UID | FT_PEEK );
			$structure = imap_fetchstructure( $connection, $uid, FT_UID );
			if ( false === $header || false === $raw_body || false === $structure || strlen( $header ) + strlen( $raw_body ) > self::MAX_BYTES ) {
				return new \WP_Error( 'email_message_read_failed', 'The complete original email could not be read.' );
			}
			$parts = array(
				'plain'       => '',
				'html'        => '',
				'attachments' => array(),
				'bytes'       => 0,
			);
			$error = $this->collectParts( $connection, $uid, $structure, '', $parts );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$parts['attachments'][] = array(
				'filename'  => 'original.eml',
				'mime_type' => 'message/rfc822',
				'data'      => rtrim( $header, "\r\n" ) . "\r\n\r\n" . $raw_body,
			);
			$original_id            = (string) ( $overview[0]->message_id ?? '' );
			sort( $to );
			$identity = wp_json_encode( array( $mailbox['owner'], $mailbox['ref'], $to, $original_id, hash( 'sha256', $header . $raw_body ) ) );
			if ( false === $identity ) {
				return new \WP_Error( 'email_forward_identity_invalid', 'Original message identity could not be encoded.' );
			}
			$key      = 'datamachine_email_forward_' . hash( 'sha256', $identity );
			$previous = get_option( $key );
			if ( is_array( $previous ) && 'sent' === ( $previous['status'] ?? '' ) ) {
				return array(
					'success'           => true,
					'already_forwarded' => true,
					'message_id'        => $previous['message_id'],
					'auth_ref'          => $mailbox['ref'],
				);
			}
			if ( ! add_option( $key, array(
				'status'     => 'sending',
				'started_at' => gmdate( 'c' ),
			), '', false ) ) {
				return new \WP_Error( 'email_forward_outcome_unknown', 'This email is already being forwarded or a previous delivery outcome needs verification.' );
			}
			$subject = 'Fwd: ' . imap_utf8( $overview[0]->subject ?? '' );
			$intro   = (string) ( $input['body'] ?? '' );
			$summary = sprintf( "\n\n---------- Forwarded message ----------\nFrom: %s\nDate: %s\nSubject: %s\n\n", $overview[0]->from ?? '', $overview[0]->date ?? '', imap_utf8( $overview[0]->subject ?? '' ) );
			$html    = '' !== $parts['html'];
			$body    = $html ? '<pre>' . esc_html( $intro . $summary ) . '</pre>' . $parts['html'] : $intro . $summary . $parts['plain'];
			$result  = MailboxTransport::send( $config, $to, $subject, $body, $parts['attachments'], array(
				'content_type' => $html ? 'text/html' : 'text/plain',
				'alt_body'     => $intro . $summary . $parts['plain'],
			) );
			if ( is_wp_error( $result ) ) {
				update_option( $key, array(
					'status'     => 'unknown',
					'started_at' => gmdate( 'c' ),
				), false );
				return $result;
			}
			update_option( $key, array(
				'status'     => 'sent',
				'message_id' => $result['message_id'],
			), false );
			$sent_folder = $config['sent_folder'] ?? 'Sent';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Sent-copy failure is reported separately from successful delivery.
			$sent_copy = ! preg_match( '/[\r\n\x00{}]/', $sent_folder ) && @imap_append( $connection, $prefix . $sent_folder, $result['raw_message'], '\\Seen' );
			do_action( 'datamachine_email_operation_audit', array(
				'mailbox_ref'     => $mailbox['ref'],
				'owner_principal' => $mailbox['owner'],
				'operation'       => array( 'read', 'send' ),
				'result'          => 'forwarded',
				'message_id'      => $result['message_id'],
			) );
			return array(
				'success'           => true,
				'auth_ref'          => $mailbox['ref'],
				'source_message_id' => $original_id,
				'message_id'        => $result['message_id'],
				'attachment_count'  => count( $parts['attachments'] ),
				'sent_copy_saved'   => (bool) $sent_copy,
				'delivery'          => 'accepted_by_smtp',
			);
		} finally {
			imap_close( $connection );
		}
	}

	/** Recursive MIME traversal preserves nested invoices and HTML inline images. */
	private function collectParts( mixed $connection, int $uid, object $part, string $number, array &$result ): ?\WP_Error {
		if ( ! empty( $part->parts ) ) {
			foreach ( $part->parts as $index => $child ) {
				$error = $this->collectParts( $connection, $uid, $child, '' === $number ? (string) ( $index + 1 ) : $number . '.' . ( $index + 1 ), $result );
				if ( $error ) {
					return $error;
				}
			}
			return null;
		}
		$data = imap_fetchbody( $connection, $uid, '' === $number ? '1' : $number, FT_UID | FT_PEEK );
		if ( false === $data ) {
			return new \WP_Error( 'email_part_read_failed', 'A message part could not be read; nothing was forwarded.' );
		}
		if ( 3 === ( $part->encoding ?? 0 ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the original MIME attachment bytes.
			$data = base64_decode( $data, true );
		} elseif ( 4 === ( $part->encoding ?? 0 ) ) {
			$data = quoted_printable_decode( $data );
		}
		if ( false === $data ) {
			return new \WP_Error( 'email_part_invalid', 'A message part has invalid transfer encoding.' );
		}
		$result['bytes'] += strlen( $data );
		if ( $result['bytes'] > self::MAX_BYTES ) {
			return new \WP_Error( 'email_part_invalid', 'A message part is invalid or exceeds the size limit.' );
		}
		$filename = '';
		foreach ( array_merge( $part->dparameters ?? array(), $part->parameters ?? array() ) as $parameter ) {
			if ( in_array( strtolower( $parameter->attribute ), array( 'filename', 'name' ), true ) ) {
				$filename = sanitize_file_name( imap_utf8( $parameter->value ) );
			}
		}
		$type          = (int) ( $part->type ?? 0 );
		$subtype       = strtolower( $part->subtype ?? 'octet-stream' );
		$is_attachment = '' !== $filename || 'attachment' === strtolower( $part->disposition ?? '' );
		if ( 0 === $type && ! $is_attachment && in_array( $subtype, array( 'plain', 'html' ), true ) ) {
			foreach ( $part->parameters ?? array() as $parameter ) {
				if ( 'charset' === strtolower( $parameter->attribute ) && ! in_array( strtolower( $parameter->value ), array( 'utf-8', 'us-ascii' ), true ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid encoding returns a structured error without exposing the invoice.
					$converted = @iconv( $parameter->value, 'UTF-8', $data );
					if ( false === $converted ) {
						return new \WP_Error( 'email_charset_invalid', 'Original message character encoding could not be preserved.' );
					}
					$data = $converted;
				}
			}
			$result[ $subtype ] .= $data;
		} else {
			$types                   = array( 'text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'application' );
			$result['attachments'][] = array(
				'filename'   => '' !== $filename ? $filename : 'attachment-' . ( '' === $number ? '1' : $number ) . '.' . $subtype,
				'mime_type'  => ( $types[ $type ] ?? 'application' ) . '/' . $subtype,
				'data'       => $data,
				'content_id' => 'inline' === strtolower( $part->disposition ?? '' ) ? trim( $part->id ?? '', '<>' ) : '',
			);
		}
		return null;
	}
}
