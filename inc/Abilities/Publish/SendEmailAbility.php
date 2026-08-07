<?php
/**
 * Send Email Ability
 *
 * Abilities API primitive for sending emails via wp_mail().
 * Centralizes email composition, header building, attachment validation,
 * placeholder replacement, optional template rendering via the
 * `datamachine_email_templates` filter, and optional per-site SMTP routing
 * via `switch_to_blog()`.
 *
 * This is the bottom layer — pure business logic, no handler config,
 * no engine data, no pipeline context. Any caller (REST, CLI, chat tool,
 * pipeline handler) can invoke this directly.
 *
 * Placeholder ordering: template render runs FIRST, then placeholder
 * replacement runs on the rendered body. Templates may therefore emit
 * `{site_name}`, `{date}`, etc. and have them resolved by the standard
 * replacement pass.
 *
 * @package DataMachine\Abilities\Publish
 */

namespace DataMachine\Abilities\Publish;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class SendEmailAbility {

	private static bool $registered           = false;
	private static bool $registration_pending = false;
	private static ?self $instance            = null;

	public function __construct() {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		self::ensure_registered();
	}

	/**
	 * Ensure the send-email ability is registered across all registry timing states.
	 *
	 * @return void
	 */
	public static function ensure_registered(): void {
		if ( self::$registered || self::$registration_pending ) {
			return;
		}

		if ( null === self::$instance ) {
			new self();
			return;
		}

		$register_via_helper = static function (): void {
			if ( null === self::$instance ) {
				return;
			}

			$definitions = self::get_ability_definitions( self::$instance );
			foreach ( $definitions as $name => $args ) {
				wp_register_ability( $name, $args );
			}
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_via_helper();
			self::$registered = true;
			return;
		}

		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			self::$registration_pending = true;
			add_action(
				'wp_abilities_api_init',
				static function () use ( $register_via_helper ): void {
					if ( self::$registered ) {
						return;
					}
					$register_via_helper();
					self::$registered           = true;
					self::$registration_pending = false;
				}
			);
			return;
		}

		// The public Abilities API does not expose a late-registration surface.
		// Once the init action has fired, avoid mutating registry internals.
	}

	/**
	 * Ability definitions used by every registration path in ensure_registered().
	 *
	 * @param self $instance Instance used for execute and permission callbacks.
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_ability_definitions( self $instance ): array {
		return AbilityRegistration::with_lazy_runtime( array(
			'datamachine/send-email' => array(
				'label'               => __( 'Send Email', 'data-machine' ),
				'description'         => __( 'Send an email with optional attachments via wp_mail(). Body may be supplied directly or rendered from a template registered via the datamachine_email_templates filter. Optionally routes the wp_mail() call through a specific site via switch_to_blog() on multisite.', 'data-machine' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'       => 'object',
					// `body` is no longer hard-required: callers may supply `template` instead.
					// Validation is enforced in execute() so existing callers passing `body`
					// continue to work unchanged.
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
							'description' => __( 'Email subject line. Supports {month}, {year}, {site_name}, {date}, {admin_email} placeholders. Placeholders are resolved after template render.', 'data-machine' ),
						),
						'body'         => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Email body content (HTML or plain text). Ignored when `template` is supplied. Supports {month}, {year}, {site_name}, {date}, {admin_email} placeholders.', 'data-machine' ),
						),
						'template'     => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional template id resolved via the datamachine_email_templates filter. When set, the registered callable receives `context` and its return value is used as the body before placeholder replacement. When empty, `body` is used verbatim.', 'data-machine' ),
						),
						'context'      => array(
							'type'        => 'object',
							'default'     => array(),
							'description' => __( 'Opaque context array passed to the template callable. Each template owns its own context contract.', 'data-machine' ),
						),
						'mail_site_id' => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Optional multisite blog id. When > 0 and multisite is active, the wp_mail() call is wrapped in switch_to_blog()/restore_current_blog() so site-scoped SMTP config applies. Validation, header building, and template rendering run in the original site context.', 'data-machine' ),
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
				'execute_callback'    => array( $instance, 'execute' ),
				'permission_callback' => array( $instance, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			),
		) );
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

		// 3. Resolve body — template (if any) renders before placeholder replacement.
		$template_id = is_string( $config['template'] ) ? trim( $config['template'] ) : '';
		$body_source = $config['body'];

		if ( '' !== $template_id ) {
			// Apply the filter lazily inside execute() so consumers can hook at any
			// priority before the first call. Shape: [ id => callable( array $context ): string ].
			$templates = apply_filters( 'datamachine_email_templates', array() );

			if ( ! is_array( $templates ) || ! isset( $templates[ $template_id ] ) || ! is_callable( $templates[ $template_id ] ) ) {
				$error  = sprintf( 'Unknown email template: %s', $template_id );
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: ' . $error,
					'data'    => array(
						'template'             => $template_id,
						'registered_templates' => is_array( $templates ) ? array_keys( $templates ) : array(),
					),
				);
				return array(
					'success' => false,
					'error'   => $error,
					'logs'    => $logs,
				);
			}

			$context = is_array( $config['context'] ) ? $config['context'] : array();

			try {
				$rendered = call_user_func( $templates[ $template_id ], $context );
			} catch ( \Throwable $e ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: Template render threw - ' . $e->getMessage(),
					'data'    => array( 'template' => $template_id ),
				);
				return array(
					'success' => false,
					'error'   => 'Template render failed: ' . $e->getMessage(),
					'logs'    => $logs,
				);
			}

			if ( ! is_string( $rendered ) ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: Template did not return a string',
					'data'    => array(
						'template'      => $template_id,
						'returned_type' => gettype( $rendered ),
					),
				);
				return array(
					'success' => false,
					'error'   => sprintf( 'Template "%s" did not return a string', $template_id ),
					'logs'    => $logs,
				);
			}

			$body_source = $rendered;

			$logs[] = array(
				'level'   => 'debug',
				'message' => 'Email: Template rendered',
				'data'    => array(
					'template'    => $template_id,
					'body_length' => strlen( $body_source ),
				),
			);
		} elseif ( '' === trim( (string) $body_source ) ) {
			// No template and no body — nothing to send.
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email: Neither `body` nor `template` provided',
			);
			return array(
				'success' => false,
				'error'   => 'Either `body` or `template` is required',
				'logs'    => $logs,
			);
		}

		// 4. Process subject + body placeholders. Runs AFTER template render so
		//    templates can emit placeholders too.
		$subject = $this->replacePlaceholders( $config['subject'] );
		$body    = $this->replacePlaceholders( $body_source );

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

		// 6. Resolve optional per-site SMTP routing.
		$mail_site_id  = (int) $config['mail_site_id'];
		$should_switch = false;

		if ( $mail_site_id > 0 ) {
			if ( ! is_multisite() ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: mail_site_id provided but multisite is not active',
					'data'    => array( 'mail_site_id' => $mail_site_id ),
				);
				return array(
					'success' => false,
					'error'   => 'mail_site_id requires a multisite install',
					'logs'    => $logs,
				);
			}

			$blog_details = get_blog_details( $mail_site_id );
			if ( ! $blog_details ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'Email: mail_site_id refers to an unknown blog',
					'data'    => array( 'mail_site_id' => $mail_site_id ),
				);
				return array(
					'success' => false,
					'error'   => sprintf( 'Unknown mail_site_id: %d', $mail_site_id ),
					'logs'    => $logs,
				);
			}

			$should_switch = true;
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
				'template'         => $template_id,
				'mail_site_id'     => $should_switch ? $mail_site_id : 0,
			),
		);

		// 7. Send via wp_mail(). Wrap ONLY this call in switch_to_blog when routing.
		if ( $should_switch ) {
			switch_to_blog( $mail_site_id );
		}

		$sent      = wp_mail( $to, $subject, $body, $headers, $attachments );
		$error_msg = '';

		if ( ! $sent ) {
			global $phpmailer;
			$error_msg = 'wp_mail() returned false';
			if ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				$error_msg = ! empty( $phpmailer->ErrorInfo ) ? $phpmailer->ErrorInfo : $error_msg;
			}
		}

		if ( $should_switch ) {
			restore_current_blog();
		}

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
			'mail_site_id' => 0,
			'content_type' => 'text/html',
			'from_name'    => '',
			'from_email'   => '',
			'reply_to'     => '',
			'attachments'  => array(),
		);

		return array_merge( $defaults, $input );
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
