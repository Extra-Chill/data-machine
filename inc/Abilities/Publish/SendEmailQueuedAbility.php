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
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentTokens;

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
	 * Action Scheduler hook for bounded orphan payload cleanup.
	 */
	public const CLEANUP_HOOK = 'datamachine_cleanup_queued_email_payload';

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
	private const RETRY_BACKOFF_SECONDS     = 300;
	private const CLEANUP_RETENTION_SECONDS = 604800;
	private const LOCK_TIMEOUT_SECONDS      = 5;

	/**
	 * Authenticated queue payload and compact reference formats.
	 */
	private const ENVELOPE_KEY      = '_datamachine_encrypted_email';
	private const ENVELOPE_VERSION  = 1;
	private const REFERENCE_KEY     = '_datamachine_email_payload';
	private const REFERENCE_VERSION = 1;
	private const OPTION_PREFIX     = 'datamachine_email_payload_';
	private const CIPHER_ALGORITHM  = 'aes-256-gcm';
	private const IV_LENGTH         = 12;
	private const AUTH_TAG_LENGTH   = 16;
	private const KEY_CONTEXT       = 'datamachine-send-email-queued-v1';
	private const REFERENCE_CONTEXT = 'datamachine-send-email-queued-reference-v1';
	private const AAD               = 'datamachine/send-email-queued|v1|aes-256-gcm';

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
		add_action( self::CLEANUP_HOOK, array( $instance, 'runCleanup' ), 10, 1 );
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
	public function execute( array $input ): array|\WP_Error {
		$logs    = array();
		$context = array(
			'user_id'  => PermissionHelper::acting_user_id(),
			'agent_id' => absint( PermissionHelper::get_acting_agent_id() ),
			'token_id' => absint( PermissionHelper::get_acting_token_id() ),
			'system'   => PermissionHelper::is_authenticated_context(),
		);
		if ( $context['user_id'] <= 0 && ! $context['system'] ) {
			return $this->queueError( 'email_queue_issuer_required', 'An identified issuer is required to queue email.', 403, $logs );
		}
		if ( ! empty( $input['auth_ref'] ) ) {
			$providers = apply_filters( 'datamachine_auth_providers', array() );
			$auth      = $providers['email_imap'] ?? null;
			if ( ! is_object( $auth ) || ! method_exists( $auth, 'resolve_mailbox' ) || is_wp_error( $auth->resolve_mailbox( $input['auth_ref'], 'send' ) ) ) {
				return $this->queueError( 'email_queue_authorization_failed', 'Mailbox send authorization failed.', 403, $logs );
			}
		} elseif ( ! $this->canUseLegacySender() ) {
			return $this->queueError( 'email_queue_mailbox_required', 'An authorized mailbox ref is required to queue email.', 403, $logs );
		}

		// Validate the bare minimum here. The underlying ability re-validates
		// the full payload when the worker runs.
		$to = isset( $input['to'] ) ? (string) $input['to'] : '';
		if ( '' === trim( $to ) ) {
			return $this->queueError( 'invalid_email_recipient', 'Recipient (to) is required.', 400, $logs );
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
			return new \WP_Error( $timestamp->get_error_code(), $timestamp->get_error_message(), array_merge( array( 'status' => 400 ), is_array( $timestamp->get_error_data() ) ? $timestamp->get_error_data() : array(), array( 'logs' => $logs ) ) );
		}

		$reference = $this->storeQueuedPayload( $input );
		if ( is_wp_error( $reference ) ) {
			return $this->queueError( 'email_queue_encryption_failed', 'Failed to protect queued email payload.', 500, $logs );
		}

		// Action Scheduler receives only a compact authenticated reference. The
		// encrypted envelope remains in a non-autoloaded option until dispatch.
		$as_args = array( $reference );

		try {
			if ( null === $timestamp ) {
				$action_id     = as_enqueue_async_action( self::WORKER_HOOK, $as_args, self::GROUP );
				$scheduled_for = time();
			} else {
				$action_id     = as_schedule_single_action( $timestamp, self::WORKER_HOOK, $as_args, self::GROUP );
				$scheduled_for = $timestamp;
			}
		} catch ( \Throwable ) {
			$this->deleteStoredPayload( $reference );
			return $this->queueError( 'email_queue_failed', 'Failed to schedule email action.', 500, $logs );
		}

		if ( empty( $action_id ) ) {
			$this->deleteStoredPayload( $reference );
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email queue: Action Scheduler did not return an action id',
			);
			return $this->queueError( 'email_queue_failed', 'Failed to schedule email action.', 500, $logs );
		}

		if ( ! $this->schedulePayloadCleanup( $reference, $scheduled_for ) ) {
			// The send action already owns this payload. Never roll it back merely
			// because best-effort orphan cleanup could not be scheduled.
			$logs[] = array(
				'level'   => 'warning',
				'message' => 'Email queue: payload cleanup could not be scheduled',
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

		if ( array_key_exists( self::REFERENCE_KEY, $payload ) ) {
			$reference_id = $this->validatePayloadReference( $payload );
			if ( is_wp_error( $reference_id ) ) {
				$this->deleteReferencedOptionByShape( $payload );
				do_action( 'datamachine_log', 'error', 'Email worker: stored payload reference rejected', array( 'reason' => $reference_id->get_error_code() ) );
				return;
			}

			if ( ! $this->acquireReferenceLock( $reference_id ) ) {
				do_action( 'datamachine_log', 'warning', 'Email worker: payload dispatch already active', array( 'reason' => 'email_queue_dispatch_locked' ) );
				return;
			}

			try {
				// Re-read only after acquiring the lock. A prior worker may have sent
				// and deleted the option while this worker waited.
				$envelope = get_option( $this->payloadOptionName( $reference_id ), null );
				if ( ! is_array( $envelope ) ) {
					do_action( 'datamachine_log', 'error', 'Email worker: stored payload unavailable', array( 'reason' => 'email_queue_payload_missing' ) );
					return;
				}

				$decrypted = $this->decryptQueuedPayload( $envelope, $reference_id );
				if ( is_wp_error( $decrypted ) ) {
					$this->deleteStoredPayload( $payload );
					do_action( 'datamachine_log', 'error', 'Email worker: stored payload rejected', array( 'reason' => $decrypted->get_error_code() ) );
					return;
				}

				$this->dispatchPayload( $decrypted, $payload );
			} finally {
				$this->releaseReferenceLock( $reference_id );
			}
			return;
		}

		// Already-persisted legacy Action Scheduler jobs carry plaintext arrays.
		$this->dispatchPayload( $payload, null );
	}

	/**
	 * Delete an orphaned encrypted payload after its bounded retention window.
	 *
	 * @param mixed $reference Compact authenticated payload reference.
	 */
	public function runCleanup( $reference ): void {
		if ( ! is_array( $reference ) ) {
			return;
		}
		$reference_id = $this->validatePayloadReference( $reference );
		if ( is_wp_error( $reference_id ) ) {
			$this->deleteReferencedOptionByShape( $reference );
			return;
		}
		if ( ! $this->acquireReferenceLock( $reference_id ) ) {
			do_action( 'datamachine_log', 'warning', 'Email queue cleanup: payload dispatch still active', array( 'reason' => 'email_queue_cleanup_locked' ) );
			return;
		}
		try {
			delete_option( $this->payloadOptionName( $reference_id ) );
		} finally {
			$this->releaseReferenceLock( $reference_id );
		}
	}

	/**
	 * Verify authorization and dispatch one decrypted or legacy payload.
	 */
	private function dispatchPayload( array $payload, ?array $reference ): void {

		$attempt = isset( $payload['_attempt'] ) ? max( 1, (int) $payload['_attempt'] ) : 1;
		$grant   = $this->verifyMailboxGrant( $payload );
		if ( is_wp_error( $grant ) || ! $this->currentIssuerAuthorized( $grant ) ) {
			$this->deleteStoredPayload( $reference );
			do_action( 'datamachine_log', 'error', 'Email worker: queued authorization denied', array( 'attempt' => $attempt ) );
			return;
		}

		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( ! $ability ) {
			$this->deleteStoredPayload( $reference );
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

		// Delivery is intentionally at-least-once. A process crash after the mail
		// provider accepts the message but before local option deletion can replay
		// it; exactly-once requires provider-supported idempotency.
		$result = $ability->execute( $ability_input );

		$success = is_array( $result ) && ! empty( $result['success'] );

		if ( $success ) {
			$this->deleteStoredPayload( $reference );
			do_action(
				'datamachine_log',
				'info',
				'Email worker: send succeeded',
				array( 'attempt' => $attempt )
			);
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->deleteStoredPayload( $reference );
			do_action(
				'datamachine_log',
				'error',
				'Email worker: send failed after max attempts, giving up',
				array(
					'attempt'      => $attempt,
					'max_attempts' => self::MAX_ATTEMPTS,
				)
			);
			return;
		}

		// Re-enqueue with backoff. Bump the attempt counter on the payload.
		$payload['_attempt'] = $attempt + 1;
		$retry_at            = time() + self::RETRY_BACKOFF_SECONDS;
		$created_retry_store = null === $reference;
		$retry_reference     = $created_retry_store ? $this->storeQueuedPayload( $payload ) : $this->replaceStoredPayload( $reference, $payload );
		if ( is_wp_error( $retry_reference ) ) {
			$this->deleteStoredPayload( $reference );
			do_action(
				'datamachine_log',
				'error',
				'Email worker: retry payload encryption failed',
				array( 'attempt' => $attempt )
			);
			return;
		}

		try {
			$retry_action_id = as_schedule_single_action( $retry_at, self::WORKER_HOOK, array( $retry_reference ), self::GROUP );
		} catch ( \Throwable ) {
			$this->deleteStoredPayload( $retry_reference );
			do_action( 'datamachine_log', 'error', 'Email worker: retry scheduling failed', array( 'attempt' => $attempt ) );
			return;
		}
		if ( empty( $retry_action_id ) ) {
			$this->deleteStoredPayload( $retry_reference );
			do_action( 'datamachine_log', 'error', 'Email worker: retry scheduling failed', array( 'attempt' => $attempt ) );
			return;
		}
		if ( $created_retry_store && ! $this->schedulePayloadCleanup( $retry_reference, $retry_at ) ) {
			do_action( 'datamachine_log', 'warning', 'Email worker: retry payload cleanup could not be scheduled', array( 'attempt' => $attempt ) );
		}

		do_action(
			'datamachine_log',
			'warning',
			'Email worker: send failed, retry scheduled',
			array(
				'attempt'         => $attempt,
				'next_attempt'    => $attempt + 1,
				'retry_at'        => $retry_at,
				'retry_action_id' => $retry_action_id,
			)
		);
	}

	/**
	 * Encrypt a complete worker payload before it enters scheduler storage.
	 *
	 * The key is deliberately domain-separated from OAuth credential storage.
	 * There is no key ring: rotating WordPress auth salts makes outstanding jobs
	 * undecryptable, and the worker rejects them with a non-secret reason code.
	 *
	 * @param array $payload Plaintext worker payload.
	 * @return array<string, array<string, int|string>>|\WP_Error
	 */
	private function encryptQueuedPayload( array $payload, ?string $reference_id = null ): array|\WP_Error {
		try {
			$iv = random_bytes( self::IV_LENGTH );
		} catch ( \Throwable ) {
			return new \WP_Error( 'email_queue_random_failed', 'Queued email encryption could not generate a nonce.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Authenticated internal payload preserves exact PHP values across dispatch.
		$plaintext  = serialize( $payload );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER_ALGORITHM, $this->queuedPayloadKey(), OPENSSL_RAW_DATA, $iv, $tag, $this->envelopeAad( $reference_id ), self::AUTH_TAG_LENGTH );
		if ( false === $ciphertext || self::AUTH_TAG_LENGTH !== strlen( $tag ) ) {
			return new \WP_Error( 'email_queue_encryption_failed', 'Queued email encryption failed.' );
		}

		return array(
			self::ENVELOPE_KEY => array(
				'version'    => self::ENVELOPE_VERSION,
				'algorithm'  => self::CIPHER_ALGORITHM,
				// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary authenticated-envelope fields.
				'iv'         => base64_encode( $iv ),
				'tag'        => base64_encode( $tag ),
				'ciphertext' => base64_encode( $ciphertext ),
				// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);
	}

	/**
	 * Validate and decrypt a scheduler envelope at dispatch time.
	 *
	 * @param array $payload Raw worker argument.
	 * @return array|\WP_Error
	 */
	private function decryptQueuedPayload( array $payload, ?string $reference_id = null ): array|\WP_Error {
		if ( array( self::ENVELOPE_KEY ) !== array_keys( $payload ) || ! is_array( $payload[ self::ENVELOPE_KEY ] ) ) {
			return new \WP_Error( 'email_queue_envelope_malformed', 'Encrypted queued email payload is malformed.' );
		}

		$envelope = $payload[ self::ENVELOPE_KEY ];
		if ( array( 'version', 'algorithm', 'iv', 'tag', 'ciphertext' ) !== array_keys( $envelope ) ) {
			return new \WP_Error( 'email_queue_envelope_malformed', 'Encrypted queued email payload is malformed.' );
		}
		if ( self::ENVELOPE_VERSION !== $envelope['version'] || self::CIPHER_ALGORITHM !== $envelope['algorithm'] ) {
			return new \WP_Error( 'email_queue_envelope_unsupported', 'Encrypted queued email payload version is unsupported.' );
		}
		if ( ! is_string( $envelope['iv'] ) || ! is_string( $envelope['tag'] ) || ! is_string( $envelope['ciphertext'] ) ) {
			return new \WP_Error( 'email_queue_envelope_malformed', 'Encrypted queued email payload is malformed.' );
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary authenticated-envelope fields.
		$iv         = base64_decode( $envelope['iv'], true );
		$tag        = base64_decode( $envelope['tag'], true );
		$ciphertext = base64_decode( $envelope['ciphertext'], true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $iv || false === $tag || false === $ciphertext || self::IV_LENGTH !== strlen( $iv ) || self::AUTH_TAG_LENGTH !== strlen( $tag ) || '' === $ciphertext ) {
			return new \WP_Error( 'email_queue_envelope_malformed', 'Encrypted queued email payload is malformed.' );
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER_ALGORITHM, $this->queuedPayloadKey(), OPENSSL_RAW_DATA, $iv, $tag, $this->envelopeAad( $reference_id ) );
		if ( false === $plaintext ) {
			return new \WP_Error( 'email_queue_envelope_decryption_failed', 'Encrypted queued email payload could not be decrypted.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Only authenticated ciphertext created by this class reaches unserialize.
		$decrypted = unserialize( $plaintext, array( 'allowed_classes' => false ) );
		if ( ! is_array( $decrypted ) ) {
			return new \WP_Error( 'email_queue_envelope_payload_invalid', 'Encrypted queued email payload is invalid.' );
		}

		return $decrypted;
	}

	/**
	 * Persist an encrypted payload and return its compact scheduler reference.
	 */
	private function storeQueuedPayload( array $payload ): array|\WP_Error {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			try {
				$reference_id = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable ) {
				return new \WP_Error( 'email_queue_random_failed', 'Queued email storage could not generate a reference.' );
			}

			$envelope = $this->encryptQueuedPayload( $payload, $reference_id );
			if ( is_wp_error( $envelope ) ) {
				return $envelope;
			}
			if ( add_option( $this->payloadOptionName( $reference_id ), $envelope, '', false ) ) {
				return $this->payloadReference( $reference_id );
			}
		}

		return new \WP_Error( 'email_queue_storage_failed', 'Queued email payload could not be stored.' );
	}

	/**
	 * Replace a stored envelope before scheduling its retry.
	 */
	private function replaceStoredPayload( array $reference, array $payload ): array|\WP_Error {
		$reference_id = $this->validatePayloadReference( $reference );
		if ( is_wp_error( $reference_id ) ) {
			return $reference_id;
		}

		$envelope = $this->encryptQueuedPayload( $payload, $reference_id );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}
		if ( ! update_option( $this->payloadOptionName( $reference_id ), $envelope, false ) ) {
			return new \WP_Error( 'email_queue_storage_failed', 'Queued email retry payload could not be stored.' );
		}

		return $reference;
	}

	/**
	 * Schedule idempotent orphan cleanup well beyond the maximum retry horizon.
	 */
	private function schedulePayloadCleanup( array $reference, int $scheduled_for ): bool {
		$retry_window = ( self::MAX_ATTEMPTS - 1 ) * self::RETRY_BACKOFF_SECONDS;
		$cleanup_at   = $scheduled_for + $retry_window + self::CLEANUP_RETENTION_SECONDS;
		try {
			return ! empty( as_schedule_single_action( $cleanup_at, self::CLEANUP_HOOK, array( $reference ), self::GROUP ) );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Acquire a bounded connection-scoped MySQL lock for one opaque reference.
	 */
	private function acquireReferenceLock( string $reference_id ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks have no WordPress API.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->referenceLockName( $reference_id ), self::LOCK_TIMEOUT_SECONDS ) );
		return '1' === (string) $acquired;
	}

	/**
	 * Release a connection-scoped lock on every worker exit path.
	 */
	private function releaseReferenceLock( string $reference_id ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Releases the matching connection-scoped advisory lock.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->referenceLockName( $reference_id ) ) );
	}

	private function referenceLockName( string $reference_id ): string {
		return 'datamachine_email_' . $reference_id;
	}

	/**
	 * Build an authenticated opaque scheduler reference.
	 */
	private function payloadReference( string $reference_id ): array {
		return array(
			self::REFERENCE_KEY => array(
				'version' => self::REFERENCE_VERSION,
				'id'      => $reference_id,
				'mac'     => hash_hmac( 'sha256', self::REFERENCE_CONTEXT . '|' . $reference_id, $this->queuedPayloadKey() ),
			),
		);
	}

	/**
	 * Validate a scheduler reference before using it as an option key.
	 */
	private function validatePayloadReference( array $reference ): string|\WP_Error {
		if ( array( self::REFERENCE_KEY ) !== array_keys( $reference ) || ! is_array( $reference[ self::REFERENCE_KEY ] ) ) {
			return new \WP_Error( 'email_queue_reference_malformed', 'Queued email payload reference is malformed.' );
		}

		$value = $reference[ self::REFERENCE_KEY ];
		if ( array( 'version', 'id', 'mac' ) !== array_keys( $value ) || self::REFERENCE_VERSION !== $value['version'] || ! is_string( $value['id'] ) || ! is_string( $value['mac'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $value['id'] ) ) {
			return new \WP_Error( 'email_queue_reference_malformed', 'Queued email payload reference is malformed.' );
		}

		$expected = hash_hmac( 'sha256', self::REFERENCE_CONTEXT . '|' . $value['id'], $this->queuedPayloadKey() );
		if ( ! hash_equals( $expected, $value['mac'] ) ) {
			return new \WP_Error( 'email_queue_reference_invalid', 'Queued email payload reference is invalid.' );
		}

		return $value['id'];
	}

	private function deleteStoredPayload( ?array $reference ): void {
		if ( null === $reference ) {
			return;
		}
		$reference_id = $this->validatePayloadReference( $reference );
		if ( ! is_wp_error( $reference_id ) ) {
			delete_option( $this->payloadOptionName( $reference_id ) );
		}
	}

	/**
	 * Remove storage for a syntactically bounded reference that cannot be
	 * authenticated, including after salt rotation. The opaque 128-bit id is
	 * accepted only in the exact reference schema and cannot select other options.
	 */
	private function deleteReferencedOptionByShape( array $reference ): void {
		$value = $reference[ self::REFERENCE_KEY ] ?? null;
		if ( ! is_array( $value ) || array( 'version', 'id', 'mac' ) !== array_keys( $value ) || ! is_string( $value['id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $value['id'] ) ) {
			return;
		}
		if ( ! $this->acquireReferenceLock( $value['id'] ) ) {
			return;
		}
		try {
			delete_option( $this->payloadOptionName( $value['id'] ) );
		} finally {
			$this->releaseReferenceLock( $value['id'] );
		}
	}

	private function payloadOptionName( string $reference_id ): string {
		return self::OPTION_PREFIX . $reference_id;
	}

	private function envelopeAad( ?string $reference_id ): string {
		return null === $reference_id ? self::AAD : self::AAD . '|' . $reference_id;
	}

	/**
	 * Derive a queue-only 256-bit key from the WordPress auth salts.
	 */
	private function queuedPayloadKey(): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, wp_salt( 'auth' ), true );
	}

	private function createMailboxGrant( array $input, array $context ): array {
		$issued_at = time();
		$nonce     = wp_generate_uuid4();
		$system    = ! empty( $context['system'] ) && (int) $context['user_id'] <= 0;
		$grant     = array(
			'user_id'       => (int) $context['user_id'],
			'agent_id'      => (int) $context['agent_id'],
			'token_id'      => (int) $context['token_id'],
			'issuer_type'   => $system ? 'system' : ( (int) $context['agent_id'] > 0 ? ( (int) $context['token_id'] > 0 ? 'agent_token' : 'agent' ) : 'user' ),
			'issued_at'     => $issued_at,
			'nonce'         => $nonce,
			'legacy_sender' => empty( $input['auth_ref'] ),
		);

		$grant['signature'] = hash_hmac( 'sha256', $this->mailboxGrantPayload( $input, $grant ), wp_salt( 'auth' ) );
		return $grant;
	}

	private function queueError( string $code, string $message, int $status, array $logs ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'logs'   => $logs,
			)
		);
	}

	private function verifyMailboxGrant( array $payload ): array|\WP_Error {
		$grant = $payload['_mailbox_grant'] ?? null;
		if ( ! is_array( $grant ) || empty( $grant['signature'] ) ) {
			return new \WP_Error( 'email_queue_grant_missing', 'Queued email authorization is missing.' );
		}
		$expected = hash_hmac( 'sha256', $this->mailboxGrantPayload( $payload, $grant ), wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $grant['signature'] ) ) {
			return new \WP_Error( 'email_queue_grant_invalid', 'Queued email authorization is invalid.' );
		}
		return $grant;
	}

	private function mailboxGrantPayload( array $input, array $grant ): string {
		$payload = $input;
		unset( $payload['_attempt'], $payload['_mailbox_grant'] );
		$payload = $this->canonicalizeGrantValue( $payload );
		return implode( '|', array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Existing queued grants depend on this signature payload.
			hash( 'sha256', serialize( $payload ) ),
			(string) absint( $grant['user_id'] ?? 0 ),
			(string) absint( $grant['agent_id'] ?? 0 ),
			(string) absint( $grant['token_id'] ?? 0 ),
			(string) ( $grant['issuer_type'] ?? '' ),
			(string) absint( $grant['issued_at'] ?? 0 ),
			(string) ( $grant['nonce'] ?? '' ),
			! empty( $grant['legacy_sender'] ) ? '1' : '0',
		) );
	}

	private function currentIssuerAuthorized( array $grant ): bool {
		$user_id     = absint( $grant['user_id'] ?? 0 );
		$agent_id    = absint( $grant['agent_id'] ?? 0 );
		$token_id    = absint( $grant['token_id'] ?? 0 );
		$issuer_type = (string) ( $grant['issuer_type'] ?? '' );
		if ( 'system' === $issuer_type ) {
			return 0 === $user_id && 0 === $agent_id && 0 === $token_id;
		}
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		if ( 'user' === $issuer_type ) {
			return 0 === $agent_id && 0 === $token_id && $this->userHasQueueCapability( $user_id );
		}
		if ( ! in_array( $issuer_type, array( 'agent', 'agent_token' ), true ) || $agent_id <= 0 ) {
			return false;
		}

		$agent = ( new Agents() )->get_agent( $agent_id );
		if ( ! is_array( $agent ) || absint( $agent['owner_id'] ?? 0 ) !== $user_id ) {
			return false;
		}
		if ( 'agent' === $issuer_type ) {
			return 0 === $token_id && $this->userHasQueueCapability( $user_id );
		}
		if ( $token_id <= 0 ) {
			return false;
		}

		$token = ( new AgentTokens() )->get_token( $token_id );
		if ( ! $token instanceof \WP_Agent_Token || $token->is_expired() || $agent_id !== (int) $token->agent_id || $user_id !== $token->owner_user_id ) {
			return false;
		}
		if ( ! $this->userHasQueueCapability( $user_id, $token->allowed_capabilities ) ) {
			return false;
		}

		$scope = isset( $token->metadata['datamachine_scope'] ) && is_array( $token->metadata['datamachine_scope'] )
			? $token->metadata['datamachine_scope']
			: $token->allowed_capabilities;
		return $this->scopeAllowsQueuedEmail( $scope );
	}

	private function userHasQueueCapability( int $user_id, ?array $ceiling = null ): bool {
		foreach ( array( 'datamachine_use_tools', 'datamachine_manage_flows', 'datamachine_manage_settings', 'datamachine_manage_agents' ) as $capability ) {
			if ( null !== $ceiling && ! in_array( $capability, $ceiling, true ) ) {
				continue;
			}
			if ( user_can( $user_id, $capability ) || user_can( $user_id, 'manage_options' ) ) {
				return true;
			}
		}
		return false;
	}

	private function scopeAllowsQueuedEmail( ?array $payload ): bool {
		$scope = AgentTokens::normalize_capability_payload( $payload )['stored_payload'];
		if ( null === $scope || array_is_list( $scope ) ) {
			return true;
		}
		$ability = 'datamachine/send-email-queued';
		if ( in_array( $ability, (array) ( $scope['ability_deny'] ?? array() ), true ) ) {
			return false;
		}
		if ( in_array( $ability, (array) ( $scope['ability_allow'] ?? array() ), true ) ) {
			return true;
		}
		$categories = (array) ( $scope['ability_categories'] ?? array() );
		return array() === $categories || in_array( 'datamachine-publishing', $categories, true );
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
