<?php
/** SMTP delivery using one authorized inbox, independent of site-wide mail hooks. */

namespace DataMachine\Core\Email;

defined( 'ABSPATH' ) || exit;

final class MailboxTransport {
	/** Attachments may be local paths or in-memory MIME parts from the source message. */
	public static function send( array $credentials, array $to, string $subject, string $body, array $attachments = array(), array $options = array() ): array|\WP_Error {
		if ( empty( $credentials['smtp_host'] ) || empty( $credentials['smtp_user'] ) || empty( $credentials['smtp_password'] ) ) {
			return new \WP_Error( 'email_smtp_required', 'Configure SMTP on this mailbox to send from its authenticated identity.' );
		}
		if ( ! in_array( $credentials['smtp_encryption'] ?? 'tls', array( 'ssl', 'tls' ), true ) ) {
			return new \WP_Error( 'email_tls_required', 'SMTP delivery requires TLS or SSL.' );
		}
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer' ) ) {
			foreach ( array( 'Exception', 'PHPMailer', 'SMTP' ) as $class ) {
				// @phpstan-ignore constant.notFound (WordPress defines WPINC during bootstrap.)
				require_once ABSPATH . WPINC . '/PHPMailer/' . $class . '.php';
			}
		}
		$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
		try {
			$mailer->isSMTP();
			$mailer->Host       = $credentials['smtp_host'];
			$mailer->Port       = (int) ( $credentials['smtp_port'] ?? 587 );
			$mailer->SMTPSecure = $credentials['smtp_encryption'] ?? 'tls';
			$mailer->SMTPAuth   = true;
			$mailer->Username   = $credentials['smtp_user'];
			$mailer->Password   = $credentials['smtp_password'];
			$mailer->Timeout    = 30;
			$mailer->CharSet    = 'UTF-8';
			$mailer->setFrom( $credentials['imap_user'], $credentials['display_name'] ?? '' );
			$mailer->addReplyTo( $credentials['imap_user'] );
			foreach ( $to as $address ) {
				$mailer->addAddress( $address );
			}
			foreach ( array(
				'cc'  => 'addCC',
				'bcc' => 'addBCC',
			) as $key => $method ) {
				foreach ( array_filter( array_map( 'trim', explode( ',', $options[ $key ] ?? '' ) ) ) as $address ) {
					$mailer->$method( $address );
				}
			}
			$mailer->Subject = $subject;
			$mailer->isHTML( 'text/html' === ( $options['content_type'] ?? 'text/plain' ) );
			$mailer->Body    = $body;
			$mailer->AltBody = $options['alt_body'] ?? '';
			foreach ( $attachments as $attachment ) {
				if ( is_string( $attachment ) ) {
					$mailer->addAttachment( $attachment );
				} elseif ( ! empty( $attachment['content_id'] ) ) {
					$mailer->addStringEmbeddedImage( $attachment['data'], $attachment['content_id'], $attachment['filename'], 'base64', $attachment['mime_type'] );
				} else {
					$mailer->addStringAttachment( $attachment['data'], $attachment['filename'], 'base64', $attachment['mime_type'] );
				}
			}
			foreach ( array( 'In-Reply-To', 'References' ) as $header ) {
				if ( ! empty( $options[ $header ] ) ) {
					$mailer->addCustomHeader( $header, $options[ $header ] );
				}
			}
			do_action( 'datamachine_email_phpmailer_init', $mailer );
			if ( ! $mailer->send() ) {
				return new \WP_Error( 'email_smtp_failed', 'SMTP delivery failed. Check the destination before retrying.' );
			}
			return array(
				'success'     => true,
				'message_id'  => $mailer->getLastMessageID(),
				'raw_message' => $mailer->getSentMIMEMessage(),
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'email_smtp_failed', 'SMTP delivery failed. Check the mailbox credentials and destination before retrying.' );
		} finally {
			$mailer->smtpClose();
		}
	}
}
