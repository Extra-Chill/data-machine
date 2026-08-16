<?php
/**
 * Send Email Queued Ability
 *
 * Queued companion to `datamachine/send-email`. Accepts the same payload
 * shape and defers the actual send to Action Scheduler.
 *
 * - When `send_at` is omitted, enqueues an async action that fires on the
 *   next AS dispatch.
 * - When `send_at` is set (ISO 8601 or unix timestamp), schedules a single
 *   action at that time.
 *
 * The worker hook `datamachine_send_email_worker` invokes
 * `datamachine/send-email` synchronously and retries on failure with a
 * 5-minute backoff, hard-capped at 3 total attempts. The attempt counter
 * rides in `_attempt` inside the payload and is not part of the public
 * input schema.
 *
 * @package DataMachine\Abilities\Publish
 */

namespace DataMachine\Abilities\Publish;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class SendEmailQueuedAbility {

	private static bool $registered           = false;
	private static bool $registration_pending = false;
	private static bool $worker_registered    = false;
	private static ?self $instance            = null;

	/**
	 * Action Scheduler hook for the worker that performs the actual send.
	 */
	public const WORKER_HOOK = 'datamachine_send_email_worker';

	/**
	 * Action Scheduler group for queued email actions.
	 */
	public const GROUP = 'data-machine-email';

	/**
	 * Hard cap on total send attempts (initial + retries).
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Retry backoff in seconds applied between attempts.
	 */
	private const RETRY_BACKOFF_SECONDS = 300;

	public function __construct() {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		self::ensure_registered();
		self::ensure_worker_registered( self::$instance );
	}

	/**
	 * Register the `datamachine/send-email-queued` ability.
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
			'datamachine/send-email-queued' => array(
				'label'               => __( 'Send Email (Queued)', 'data-machine' ),
				'description'         => __( 'Queue an email for delivery via Action Scheduler. Accepts the same payload as datamachine/send-email plus optional send_at and priority. Returns the scheduled action id.', 'data-machine' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'       => 'object',
					// Mirror datamachine/send-email: `to` + `subject` are required at the
					// schema level; `body`/`template` are validated by the worker when the
					// underlying ability runs.
					'required'   => array( 'to', 'subject' ),
					'properties' => array(
						'auth_ref'     => array(
							'type'        => 'string',
							'description' => __( 'Optional non-secret mailbox auth ref authorizing the sender identity', 'data-machine' ),
						),
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
							'description' => __( 'Email subject line. Supports placeholders.', 'data-machine' ),
						),
						'body'         => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Email body. Ignored when `template` is supplied.', 'data-machine' ),
						),
						'template'     => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Template id resolved via datamachine_email_templates at worker run time.', 'data-machine' ),
						),
						'context'      => array(
							'type'        => 'object',
							'default'     => array(),
							'description' => __( 'Opaque context passed to the template callable.', 'data-machine' ),
						),
						'mail_site_id' => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Optional multisite blog id used to wrap wp_mail() in switch_to_blog().', 'data-machine' ),
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
							'description' => __( 'Reply-to email address.', 'data-machine' ),
						),
						'attachments'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
							'description' => __( 'Array of server file paths to attach.', 'data-machine' ),
						),
						'send_at'      => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'When to send. Accepts ISO 8601 string or unix timestamp (as string or int). Empty enqueues asynchronously.', 'data-machine' ),
						),
						'priority'     => array(
							'type'        => 'integer',
							'default'     => 10,
							'description' => __( 'Reserved for future Action Scheduler priority/group hints; currently informational.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'action_id'     => array( 'type' => 'integer' ),
						'scheduled_for' => array( 'type' => 'integer' ),
						'error'         => array( 'type' => 'string' ),
						'logs'          => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $instance, 'execute' ),
				'permission_callback' => array( $instance, 'checkPermission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			),
		) );
	}

	/**
	 * Register the worker action hook.
	 *
	 * Hooked on plugins_loaded so Action Scheduler can dispatch it even when
	 * the originating HTTP request that scheduled the job is no longer alive.
	 */
	private static function ensure_worker_registered( self $instance ): void {
		if ( self::$worker_registered ) {
			return;
		}

		add_action( self::WORKER_HOOK, array( $instance, 'runWorker' ), 10, 1 );
		self::$worker_registered = true;
	}

	/**
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' ) || PermissionHelper::can_manage();
	}

	/**
	 * Execute: schedule the send via Action Scheduler.
	 *
	 * @param array $input Send-email payload + send_at/priority.
	 * @return array Result with success flag, action_id, scheduled_for.
	 */
	public function execute( array $input ): array {
		$logs = array();
		$context = array(
			'user_id'  => PermissionHelper::acting_user_id(),
			'agent_id' => absint( PermissionHelper::get_acting_agent_id() ),
		);
		if ( ! empty( $input['auth_ref'] ) ) {
			$providers = apply_filters( 'datamachine_auth_providers', array() );
			$auth      = $providers['email_imap'] ?? null;
			if ( ! $auth || ! method_exists( $auth, 'resolve_mailbox' ) || is_wp_error( $auth->resolve_mailbox( $input['auth_ref'], 'send' ) ) ) {
				return array( 'success' => false, 'error' => 'Mailbox send authorization failed.', 'logs' => $logs );
			}
		} elseif ( ! $this->canUseLegacySender() ) {
			return array( 'success' => false, 'error' => 'An authorized mailbox ref is required to queue email.', 'logs' => $logs );
		}

		// Validate the bare minimum here. The underlying ability re-validates
		// the full payload when the worker runs.
		$to = isset( $input['to'] ) ? (string) $input['to'] : '';
		if ( '' === trim( $to ) ) {
			return array(
				'success' => false,
				'error'   => 'Recipient (to) is required.',
				'logs'    => $logs,
			);
		}

		$send_at_raw = $input['send_at'] ?? '';
		unset( $input['send_at'] ); // Not forwarded to the underlying ability.

		// Strip control fields from payload before scheduling.
		$priority = isset( $input['priority'] ) ? (int) $input['priority'] : 10;
		unset( $input['priority'] );
		$input['_mailbox_grant'] = $this->createMailboxGrant( $input, $context );

		// Initialize the attempt counter. `_attempt` is internal — not part of
		// the public input schema.
		if ( ! isset( $input['_attempt'] ) ) {
			$input['_attempt'] = 1;
		}

		$timestamp = $this->parseSendAt( $send_at_raw );

		if ( $timestamp instanceof \WP_Error ) {
			return array(
				'success' => false,
				'error'   => $timestamp->get_error_message(),
				'logs'    => $logs,
			);
		}

		// Action Scheduler payload — wrap in an indexed array so the hook
		// receives a single $payload argument.
		$as_args = array( $input );

		if ( null === $timestamp ) {
			$action_id     = as_enqueue_async_action( self::WORKER_HOOK, $as_args, self::GROUP );
			$scheduled_for = time();
		} else {
			$action_id     = as_schedule_single_action( $timestamp, self::WORKER_HOOK, $as_args, self::GROUP );
			$scheduled_for = $timestamp;
		}

		if ( empty( $action_id ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email queue: Action Scheduler did not return an action id',
			);
			return array(
				'success' => false,
				'error'   => 'Failed to schedule email action.',
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'info',
			'message' => 'Email queued',
			'data'    => array(
				'action_id'     => (int) $action_id,
				'scheduled_for' => $scheduled_for,
				'priority'      => $priority,
			),
		);

		return array(
			'success'       => true,
			'action_id'     => (int) $action_id,
			'scheduled_for' => (int) $scheduled_for,
			'logs'          => $logs,
		);
	}

	/**
	 * Worker callback — runs when Action Scheduler dispatches the hook.
	 *
	 * Calls `datamachine/send-email` with the payload. On failure, re-enqueues
	 * a retry with a 5-minute delay, up to MAX_ATTEMPTS total attempts.
	 *
	 * @param mixed $payload Send-email payload (includes `_attempt`).
	 * @return void
	 */
	public function runWorker( $payload ): void {
		if ( ! is_array( $payload ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Email worker: payload was not an array',
				array( 'payload_type' => gettype( $payload ) )
			);
			return;
		}

		$attempt = isset( $payload['_attempt'] ) ? max( 1, (int) $payload['_attempt'] ) : 1;
		if ( is_wp_error( $this->verifyMailboxGrant( $payload ) ) ) {
			do_action( 'datamachine_log', 'error', 'Email worker: queued authorization denied', array( 'attempt' => $attempt ) );
			return;
		}

		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( ! $ability ) {
			do_action(
				'datamachine_log',
				'error',
				'Email worker: datamachine/send-email ability not registered',
				array( 'attempt' => $attempt )
			);
			return;
		}

		// Strip internal control fields before forwarding to the underlying ability.
		$ability_input = $payload;
		unset( $ability_input['_attempt'] );

		$result = $ability->execute( $ability_input );

		$success = is_array( $result ) && ! empty( $result['success'] );

		if ( $success ) {
			do_action(
				'datamachine_log',
				'info',
				'Email worker: send succeeded',
				array(
					'attempt'    => $attempt,
					'recipients' => $result['recipients'] ?? array(),
					'subject'    => $result['subject'] ?? '',
				)
			);
			return;
		}

		$error_msg = is_wp_error( $result ) ? $result->get_error_message() : 'invalid result';

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			do_action(
				'datamachine_log',
				'error',
				'Email worker: send failed after max attempts, giving up',
				array(
					'attempt'      => $attempt,
					'max_attempts' => self::MAX_ATTEMPTS,
					'error'        => $error_msg,
				)
			);
			return;
		}

		// Re-enqueue with backoff. Bump the attempt counter on the payload.
		$payload['_attempt'] = $attempt + 1;
		$retry_at            = time() + self::RETRY_BACKOFF_SECONDS;

		$retry_action_id = as_schedule_single_action( $retry_at, self::WORKER_HOOK, array( $payload ), self::GROUP );

		do_action(
			'datamachine_log',
			'warning',
			'Email worker: send failed, retry scheduled',
			array(
				'attempt'         => $attempt,
				'next_attempt'    => $attempt + 1,
				'retry_at'        => $retry_at,
				'retry_action_id' => $retry_action_id,
				'error'           => $error_msg,
			)
		);
	}

	private function createMailboxGrant( array $input, array $context ): array {
		$issued_at = time();
		$nonce     = wp_generate_uuid4();
		$grant     = array(
			'user_id'      => (int) $context['user_id'],
			'agent_id'     => (int) $context['agent_id'],
			'issued_at'    => $issued_at,
			'nonce'        => $nonce,
			'legacy_sender'=> empty( $input['auth_ref'] ),
		);
		$grant['signature'] = hash_hmac( 'sha256', $this->mailboxGrantPayload( $input, $grant ), wp_salt( 'auth' ) );
		return $grant;
	}

	private function verifyMailboxGrant( array $payload ): true|\WP_Error {
		$grant = $payload['_mailbox_grant'] ?? null;
		if ( ! is_array( $grant ) || empty( $grant['signature'] ) ) {
			return new \WP_Error( 'email_queue_grant_missing', 'Queued email authorization is missing.' );
		}
		$expected = hash_hmac( 'sha256', $this->mailboxGrantPayload( $payload, $grant ), wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $grant['signature'] ) ) {
			return new \WP_Error( 'email_queue_grant_invalid', 'Queued email authorization is invalid.' );
		}
		return true;
	}

	private function mailboxGrantPayload( array $input, array $grant ): string {
		$payload = $input;
		unset( $payload['_attempt'], $payload['_mailbox_grant'] );
		$payload = $this->canonicalizeGrantValue( $payload );
		return implode( '|', array(
			hash( 'sha256', serialize( $payload ) ),
			(string) absint( $grant['user_id'] ?? 0 ),
			(string) absint( $grant['agent_id'] ?? 0 ),
			(string) absint( $grant['issued_at'] ?? 0 ),
			(string) ( $grant['nonce'] ?? '' ),
			! empty( $grant['legacy_sender'] ) ? '1' : '0',
		) );
	}

	private function canonicalizeGrantValue( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->canonicalizeGrantValue( $item );
			}
		}
		return $value;
	}

	private function canUseLegacySender(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return PermissionHelper::can_manage();
		}
		return PermissionHelper::acting_user_id() > 0 && PermissionHelper::can_manage();
	}

	/**
	 * Parse a `send_at` input into a unix timestamp.
	 *
	 * Accepts:
	 *  - empty string / null         → null (caller enqueues async)
	 *  - integer or numeric string   → treated as unix timestamp
	 *  - ISO 8601 / strtotime-parseable string → converted via strtotime()
	 *
	 * @param mixed $raw Raw send_at value.
	 * @return int|null|\WP_Error Unix timestamp, null for "send now async", or WP_Error on invalid input.
	 */
	private function parseSendAt( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : new \WP_Error( 'invalid_send_at', 'send_at must be a positive timestamp.' );
		}

		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( '' === $trimmed ) {
				return null;
			}

			// Numeric strings: treat as unix timestamp.
			if ( ctype_digit( ltrim( $trimmed, '+' ) ) ) {
				$ts = (int) $trimmed;
				return $ts > 0 ? $ts : new \WP_Error( 'invalid_send_at', 'send_at must be a positive timestamp.' );
			}

			$ts = strtotime( $trimmed );
			if ( false === $ts ) {
				return new \WP_Error( 'invalid_send_at', sprintf( 'Could not parse send_at: %s', $trimmed ) );
			}

			return $ts;
		}

		return new \WP_Error( 'invalid_send_at', 'send_at must be a string or integer.' );
	}
}
