<?php
/**
 * Send Email Queued Ability
 *
 * Queued companion to datamachine/send-email. Defers the actual send to
 * Action Scheduler via the datamachine_send_email_worker hook. Same input
 * schema as the sync ability plus optional send_at and priority.
 *
 * The worker hook is registered on init so Action Scheduler can dispatch
 * it without the calling request still being alive. The worker calls the
 * synchronous datamachine/send-email ability, logs the structured result,
 * and on failure re-enqueues itself with exponential backoff. Hard cap of
 * 3 total attempts.
 *
 * @package DataMachine\Abilities\Publish
 */

namespace DataMachine\Abilities\Publish;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class SendEmailQueuedAbility {

	private static bool $registered = false;

	/**
	 * Action Scheduler group for queued email work.
	 */
	public const GROUP = 'datamachine-email';

	/**
	 * Action Scheduler hook the worker listens on.
	 */
	public const WORKER_HOOK = 'datamachine_send_email_worker';

	/**
	 * Hard cap on attempts (initial + retries).
	 */
	public const MAX_ATTEMPTS = 3;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		$this->registerWorkerHook();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/send-email-queued',
				array(
					'label'               => __( 'Send Email (Queued)', 'data-machine' ),
					'description'         => __( 'Queue an email for delivery via Action Scheduler. Same input as datamachine/send-email, plus optional send_at and priority. Retries with exponential backoff up to 3 attempts.', 'data-machine' ),
					'category'            => 'datamachine-publishing',
					'input_schema'        => array(
						'type'       => 'object',
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
								'description' => __( 'Email subject line. Supports placeholders (see datamachine/send-email).', 'data-machine' ),
							),
							'body'         => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Email body content. Used verbatim when `template` is omitted.', 'data-machine' ),
							),
							'template'     => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Optional template id resolved via datamachine_email_templates.', 'data-machine' ),
							),
							'context'      => array(
								'type'        => 'object',
								'default'     => array(),
								'description' => __( 'Opaque context object passed to the template renderer.', 'data-machine' ),
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
							'send_at'      => array(
								'type'        => array( 'string', 'integer' ),
								'description' => __( 'Optional ISO 8601 timestamp or unix timestamp. Omit for "send now (async)".', 'data-machine' ),
							),
							'priority'     => array(
								'type'        => 'integer',
								'default'     => 10,
								'description' => __( 'Action Scheduler priority hint (default 10).', 'data-machine' ),
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
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
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
	 * Register the worker hook on init so Action Scheduler can dispatch it
	 * outside the original request lifecycle.
	 */
	private function registerWorkerHook(): void {
		$register = function (): void {
			add_action( self::WORKER_HOOK, array( __CLASS__, 'runWorker' ), 10, 1 );
		};

		if ( did_action( 'init' ) ) {
			$register();
		} else {
			add_action( 'init', $register );
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
	 * Execute queued email send.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success/action_id/scheduled_for/error/logs.
	 */
	public function execute( array $input ): array {
		$logs = array();

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email queue: Action Scheduler not available',
			);
			return array(
				'success' => false,
				'error'   => 'Action Scheduler not available.',
				'logs'    => $logs,
			);
		}

		// Strip queue-only fields from the payload that gets handed to the
		// synchronous ability. send_at/priority are scheduler concerns.
		$send_at_raw = $input['send_at'] ?? null;
		$priority    = isset( $input['priority'] ) ? (int) $input['priority'] : 10;
		unset( $input['send_at'], $input['priority'] );

		// Worker payload carries an internal _attempt counter. Not exposed in
		// the public input_schema; defaults to 1 on first enqueue.
		$payload             = $input;
		$payload['_attempt'] = 1;

		$timestamp = $this->normalizeSendAt( $send_at_raw );

		if ( null === $timestamp ) {
			// Async "send now".
			$action_id     = as_enqueue_async_action( self::WORKER_HOOK, array( $payload ), self::GROUP );
			$scheduled_for = time();
		} else {
			$action_id     = as_schedule_single_action( $timestamp, self::WORKER_HOOK, array( $payload ), self::GROUP );
			$scheduled_for = $timestamp;
		}

		if ( ! $action_id ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Email queue: Action Scheduler returned no action id',
				'data'    => array(
					'send_at_raw' => $send_at_raw,
					'priority'    => $priority,
				),
			);
			return array(
				'success' => false,
				'error'   => 'Failed to enqueue email.',
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'info',
			'message' => null === $timestamp
				? 'Email queue: Enqueued async send'
				: 'Email queue: Scheduled send at ' . gmdate( 'c', $scheduled_for ),
			'data'    => array(
				'action_id'     => (int) $action_id,
				'scheduled_for' => $scheduled_for,
				'priority'      => $priority,
			),
		);

		return array(
			'success'       => true,
			'action_id'     => (int) $action_id,
			'scheduled_for' => $scheduled_for,
			'logs'          => $logs,
		);
	}

	/**
	 * Normalize send_at into a unix timestamp or null for "send now".
	 *
	 * @param mixed $send_at Raw input (string|int|null).
	 * @return int|null Unix timestamp or null.
	 */
	private function normalizeSendAt( $send_at ): ?int {
		if ( null === $send_at || '' === $send_at ) {
			return null;
		}

		if ( is_int( $send_at ) ) {
			return $send_at;
		}

		if ( is_string( $send_at ) ) {
			// Numeric strings (and @-prefixed) are unix timestamps.
			if ( ctype_digit( ltrim( $send_at, '@' ) ) ) {
				return (int) ltrim( $send_at, '@' );
			}

			$parsed = strtotime( $send_at );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Worker callback invoked by Action Scheduler.
	 *
	 * Calls the synchronous datamachine/send-email ability, logs the result,
	 * and re-enqueues itself with exponential backoff on failure. Hard caps
	 * at MAX_ATTEMPTS total attempts.
	 *
	 * @param array $payload Worker payload (includes _attempt counter).
	 */
	public static function runWorker( $payload ): void {
		if ( ! is_array( $payload ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Email queue worker: invalid payload',
				array( 'payload_type' => gettype( $payload ) )
			);
			return;
		}

		$attempt = isset( $payload['_attempt'] ) ? max( 1, (int) $payload['_attempt'] ) : 1;
		$send    = $payload;
		unset( $send['_attempt'] );

		if ( ! function_exists( 'wp_get_ability' ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Email queue worker: wp_get_ability() not available',
				array( 'attempt' => $attempt )
			);
			return;
		}

		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( ! $ability ) {
			do_action(
				'datamachine_log',
				'error',
				'Email queue worker: datamachine/send-email ability not registered',
				array( 'attempt' => $attempt )
			);
			return;
		}

		$result = $ability->execute( $send );

		// Log the structured result for downstream observability.
		do_action(
			'datamachine_log',
			( is_array( $result ) && ! empty( $result['success'] ) ) ? 'info' : 'error',
			'Email queue worker: send attempt complete',
			array(
				'attempt' => $attempt,
				'success' => is_array( $result ) ? (bool) ( $result['success'] ?? false ) : false,
				'result'  => $result,
			)
		);

		$succeeded = is_array( $result ) && ! empty( $result['success'] );

		if ( $succeeded ) {
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			do_action(
				'datamachine_log',
				'error',
				'Email queue worker: giving up after max attempts',
				array(
					'attempt'      => $attempt,
					'max_attempts' => self::MAX_ATTEMPTS,
					'last_error'   => is_array( $result ) ? ( $result['error'] ?? '' ) : '',
				)
			);
			return;
		}

		// Exponential backoff: 60s, 300s, 1500s, ... (60 * 5^(attempt-1)).
		$delay            = 60 * (int) pow( 5, $attempt - 1 );
		$retry_at         = time() + $delay;
		$send['_attempt'] = $attempt + 1;

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Email queue worker: cannot reschedule, Action Scheduler missing',
				array( 'attempt' => $attempt )
			);
			return;
		}

		$action_id = as_schedule_single_action( $retry_at, self::WORKER_HOOK, array( $send ), self::GROUP );

		do_action(
			'datamachine_log',
			'warning',
			'Email queue worker: rescheduled with exponential backoff',
			array(
				'attempt_just_completed' => $attempt,
				'next_attempt'           => $attempt + 1,
				'retry_at'               => $retry_at,
				'delay_seconds'          => $delay,
				'action_id'              => (int) $action_id,
			)
		);
	}
}
