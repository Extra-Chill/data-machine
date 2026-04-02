<?php
/**
 * Email publish handler.
 *
 * Sends emails via wp_mail() as a pipeline publish step. Delegates to
 * SendEmailAbility for core logic. The handler resolves default recipients,
 * subject templates, and content type from handler config, then lets the
 * AI override per execution.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Email
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Email;

use DataMachine\Abilities\Publish\SendEmailAbility;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email extends PublishHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'Email' );

		self::registerHandler(
			'email_publish',
			'publish',
			self::class,
			'Email',
			'Send emails with optional attachments',
			false,
			null,
			EmailSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'email_publish' === $handler_slug ) {
					$tools['email_send'] = array(
						'class'          => self::class,
						'method'         => 'handle_tool_call',
						'handler'        => 'email_publish',
						'description'    => 'Send an email. Compose the subject and body (HTML). Optionally override recipients and attach files by providing server file paths.',
						'parameters'     => array(
							'to'          => array(
								'type'        => 'string',
								'required'    => false,
								'description' => 'Comma-separated recipient emails. Leave empty to use the default recipients from handler settings.',
							),
							'subject'     => array(
								'type'        => 'string',
								'required'    => true,
								'description' => 'Email subject. Supports {month}, {year}, {site_name} placeholders.',
							),
							'body'        => array(
								'type'        => 'string',
								'required'    => true,
								'description' => 'Email body content in HTML format.',
							),
							'attachments' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'required'    => false,
								'description' => 'Array of server file paths to attach to the email.',
							),
						),
						'handler_config' => $handler_config,
					);
				}
				return $tools;
			}
		);
	}

	/**
	 * Execute email publish.
	 *
	 * Merges handler-level defaults with AI-provided parameters,
	 * then delegates to SendEmailAbility.
	 *
	 * @param array $parameters Tool call parameters.
	 * @param array $handler_config Handler configuration.
	 * @return array Success/error response.
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		// Resolve recipients: AI override → handler default → admin email.
		$to = ! empty( $parameters['to'] )
			? $parameters['to']
			: ( $handler_config['default_to'] ?? get_option( 'admin_email' ) );

		$subject = $parameters['subject']
			?? $handler_config['default_subject']
			?? __( 'Data Machine Report', 'data-machine' );

		$body        = $parameters['body'] ?? '';
		$attachments = $parameters['attachments'] ?? array();

		// Resolve attachment paths from engine data if available.
		$engine = $parameters['engine'] ?? null;
		if ( $engine instanceof EngineData ) {
			$engine_attachments = $engine->get( 'email_attachments' );
			if ( is_array( $engine_attachments ) ) {
				$attachments = array_merge( $attachments, $engine_attachments );
			}
		}

		// Build ability input.
		$ability_input = array(
			'to'           => $to,
			'cc'           => $handler_config['default_cc'] ?? '',
			'bcc'          => $handler_config['default_bcc'] ?? '',
			'subject'      => $subject,
			'body'         => $body,
			'content_type' => $handler_config['content_type'] ?? 'text/html',
			'from_name'    => $handler_config['from_name'] ?? '',
			'from_email'   => $handler_config['from_email'] ?? '',
			'reply_to'     => $handler_config['reply_to'] ?? '',
			'attachments'  => $attachments,
		);

		// Delegate to ability.
		$ability = new SendEmailAbility();
		$result  = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			$this->log( 'error', 'Email publish ability failed: ' . $result->get_error_message() );
			return $this->errorResponse(
				$result->get_error_message(),
				array( 'wp_error_code' => $result->get_error_code() )
			);
		}

		// Relay ability logs.
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$this->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] ) {
			return $this->errorResponse(
				$result['error'] ?? __( 'Email send failed', 'data-machine' ),
				array( 'ability_result' => $result )
			);
		}

		return $this->successResponse(
			array(
				'message'    => $result['message'] ?? '',
				'recipients' => $result['recipients'] ?? array(),
				'subject'    => $result['subject'] ?? '',
			)
		);
	}

	/**
	 * Get the display label for the Email handler.
	 *
	 * @return string Handler label.
	 */
	public static function get_label(): string {
		return 'Email';
	}
}
