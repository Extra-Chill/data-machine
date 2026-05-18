<?php
/**
 * Send Email Ability
 *
 * Abilities API primitive for sending emails via wp_mail().
 * Centralizes email composition, header building, attachment validation,
 * and placeholder replacement.
 *
 * This is the bottom layer — pure business logic, no handler config,
 * no engine data, no pipeline context. Any caller (REST, CLI, chat tool,
 * pipeline handler) can invoke this directly.
 *
 * @package DataMachine\Abilities\Publish
 */

namespace DataMachine\Abilities\Publish;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class SendEmailAbility {

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
				'datamachine/send-email',
				array(
					'label'               => __( 'Send Email', 'data-machine' ),
					'description'         => __( 'Send an email with optional attachments via wp_mail(). Body can be supplied directly or rendered from a registered template via the datamachine_email_templates filter.', 'data-machine' ),
					'category'            => 'datamachine-publishing',
					'input_schema'        => array(
						'type'       => 'object',
						// Either `body` or `template` must be provided. Both branches
						// are validated in execute(); the schema marks only `to` and
						// `subject` as universally required.
						'required'   => array( 'to', 'subject' ),
						'properties' => array(
							'to'           => array(
								'type'        => 'string',
								'description' => __( 'Comma-separated recipient email addresses', 'data-machine' ),
							),
							'cc'           => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Comma-separated CC addresses', 'data-machine' ),
							),
							'bcc'          => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Comma-separated BCC addresses', 'data-machine' ),
							),
							'subject'      => array(
								'type'        => 'string',
								'description' => __( 'Email subject line. Supports {month}, {year}, {site_name}, {date}, {admin_email} placeholders (replaced after template render).', 'data-machine' ),
							),
							'body'         => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Email body content (HTML or plain text). Used verbatim when `template` is omitted. Supports {month}, {year}, {site_name}, {date}, {admin_email} placeholders (replaced after template render).', 'data-machine' ),
							),
							'template'     => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Optional template id. Resolved via the datamachine_email_templates filter; the resolved callable receives the `context` array and returns the body. Placeholder replacement runs AFTER template render so templates may emit {site_name}, {date}, etc.', 'data-machine' ),
							),
							'context'      => array(
								'type'        => 'object',
								'default'     => array(),
								'description' => __( 'Opaque context object passed to the template renderer. Ignored when `template` is omitted.', 'data-machine' ),
							),
							'content_type' => array(
								'type'        => 'string',
								'default'     => 'text/html',
								'description' => __( 'Content type: text/html or text/plain', 'data-machine' ),
							),
							'from_name'    => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Sender name. Falls back to site name.', 'data-machine' ),
							),
							'from_email'   => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Sender email. Falls back to admin email.', 'data-machine' ),
							),
							'reply_to'     => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Reply-to email address', 'data-machine' ),
							),
							'attachments'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'default'     => array(),
								'description' => __( 'Array of server file paths to attach', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'message'    => array( 'type' => 'string' ),
							'recipients' => array( 'type' => 'array' ),
							'subject'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
							'logs'       => array( 'type' => 'array' ),
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
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute email send ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success/error and logs.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		// 1. Parse and validate recipients.
		$to = array_map( 'trim', explode( ',', $config['to'] ) );
		$to = array_filter(
			$to,
			static fn( string $email ): bool => false !== is_email( $email )
		);

		if ( empty( $to ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email: No valid recipient addresses provided',
				'data'    => array( 'raw_to' => $config['to'] ),
			);
			return array(
				'success' => false,
				'error'   => 'No valid recipient email addresses',
				'logs'    => $logs,
			);
		}

		// 1b. Resolve template (if provided) → body. Runs BEFORE placeholder
		// replacement so templates may themselves emit {site_name}, {date}, etc.
		if ( '' !== $config['template'] ) {
			$templates = apply_filters( 'datamachine_email_templates', array() );
			if ( ! is_array( $templates ) || ! isset( $templates[ $config['template'] ] ) || ! is_callable( $templates[ $config['template'] ] ) ) {
				$error  = sprintf( 'Unknown email template: %s', $config['template'] );
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: ' . $error,
					'data'    => array(
						'template'             => $config['template'],
						'registered_templates' => is_array( $templates ) ? array_keys( $templates ) : array(),
					),
				);
				return array(
					'success' => false,
					'error'   => $error,
					'logs'    => $logs,
				);
			}

			$rendered = call_user_func( $templates[ $config['template'] ], (array) $config['context'] );
			if ( ! is_string( $rendered ) ) {
				$error  = sprintf( 'Email template renderer did not return a string: %s', $config['template'] );
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: ' . $error,
					'data'    => array(
						'template'    => $config['template'],
						'return_type' => gettype( $rendered ),
					),
				);
				return array(
					'success' => false,
					'error'   => $error,
					'logs'    => $logs,
				);
			}

			$config['body'] = $rendered;
			$logs[]         = array(
				'level'   => 'debug',
				'message' => 'Email: Template rendered',
				'data'    => array(
					'template'    => $config['template'],
					'body_length' => strlen( $rendered ),
				),
			);
		} elseif ( '' === (string) $config['body'] ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email: No body provided and no template specified',
			);
			return array(
				'success' => false,
				'error'   => 'Either `body` or `template` must be provided.',
				'logs'    => $logs,
			);
		}

		// 2. Build headers.
		$headers = array();

		// Content type.
		$content_type = $config['content_type'];
		if ( ! in_array( $content_type, array( 'text/html', 'text/plain' ), true ) ) {
			$content_type = 'text/html';
		}
		$headers[] = "Content-Type: {$content_type}; charset=UTF-8";

		// From.
		$from_name  = ! empty( $config['from_name'] ) ? $config['from_name'] : get_bloginfo( 'name' );
		$from_email = ! empty( $config['from_email'] ) ? $config['from_email'] : get_option( 'admin_email' );
		if ( $from_name && $from_email ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		// Reply-To.
		if ( ! empty( $config['reply_to'] ) && is_email( $config['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $config['reply_to'];
		}

		// CC.
		if ( ! empty( $config['cc'] ) ) {
			$cc_addresses = array_map( 'trim', explode( ',', $config['cc'] ) );
			foreach ( $cc_addresses as $cc ) {
				if ( is_email( $cc ) ) {
					$headers[] = 'Cc: ' . $cc;
				}
			}
		}

		// BCC.
		if ( ! empty( $config['bcc'] ) ) {
			$bcc_addresses = array_map( 'trim', explode( ',', $config['bcc'] ) );
			foreach ( $bcc_addresses as $bcc ) {
				if ( is_email( $bcc ) ) {
					$headers[] = 'Bcc: ' . $bcc;
				}
			}
		}

		// 3. Process subject placeholders.
		$subject = $this->replacePlaceholders( $config['subject'] );

		// 4. Process body placeholders.
		$body = $this->replacePlaceholders( $config['body'] );

		// 5. Validate attachments exist.
		$attachments = array();
		foreach ( $config['attachments'] as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$attachments[] = $path;
				$logs[]        = array(
					'level'   => 'info',
					'message' => 'Email: Attachment validated: ' . basename( $path ),
					'data'    => array(
						'path' => $path,
						'size' => filesize( $path ),
					),
				);
			} else {
				$logs[] = array(
					'level'   => 'warning',
					'message' => 'Email: Attachment not found or not readable: ' . $path,
				);
			}
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Email: Sending',
			'data'    => array(
				'to'               => $to,
				'subject'          => $subject,
				'content_type'     => $content_type,
				'attachment_count' => count( $attachments ),
				'body_length'      => strlen( $body ),
			),
		);

		// 6. Send via wp_mail().
		$sent = wp_mail( $to, $subject, $body, $headers, $attachments );

		if ( $sent ) {
			$logs[] = array(
				'level'   => 'info',
				'message' => 'Email: Sent successfully to ' . implode( ', ', $to ),
			);

			return array(
				'success'    => true,
				'message'    => 'Email sent to ' . implode( ', ', $to ),
				'recipients' => $to,
				'subject'    => $subject,
				'logs'       => $logs,
			);
		}

		// wp_mail failed — attempt to extract error info.
		global $phpmailer;
		$error_msg = 'wp_mail() returned false';
		if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			$error_msg = ! empty( $phpmailer->ErrorInfo ) ? $phpmailer->ErrorInfo : $error_msg;
		}

		$logs[] = array(
			'level'   => 'error',
			'message' => 'Email: Send failed - ' . $error_msg,
		);

		return array(
			'success' => false,
			'error'   => $error_msg,
			'logs'    => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 *
	 * @param array $input Raw input.
	 * @return array Normalized config.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'to'           => '',
			'cc'           => '',
			'bcc'          => '',
			'subject'      => '',
			'body'         => '',
			'template'     => '',
			'context'      => array(),
			'content_type' => 'text/html',
			'from_name'    => '',
			'from_email'   => '',
			'reply_to'     => '',
			'attachments'  => array(),
		);

		$merged = array_merge( $defaults, $input );

		// Normalize types defensively — REST/JSON callers may pass nulls.
		$merged['template'] = is_string( $merged['template'] ) ? trim( $merged['template'] ) : '';
		$merged['context']  = is_array( $merged['context'] ) ? $merged['context'] : array();
		$merged['body']     = is_string( $merged['body'] ) ? $merged['body'] : '';

		return $merged;
	}

	/**
	 * Replace placeholders in a string.
	 *
	 * Supports: {month}, {year}, {site_name}, {date}, {admin_email}
	 *
	 * @param string $text Text with placeholders.
	 * @return string Text with placeholders replaced.
	 */
	private function replacePlaceholders( string $text ): string {
		$replacements = array(
			'{month}'       => gmdate( 'F' ),
			'{year}'        => gmdate( 'Y' ),
			'{site_name}'   => get_bloginfo( 'name' ),
			'{date}'        => wp_date( get_option( 'date_format' ) ),
			'{admin_email}' => get_option( 'admin_email' ),
		);

		return str_replace( array_keys( $replacements ), array_map( 'strval', array_values( $replacements ) ), $text );
	}
}
