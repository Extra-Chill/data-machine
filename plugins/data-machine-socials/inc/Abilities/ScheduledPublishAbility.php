<?php
/**
 * Scheduled Social Publish Ability
 *
 * Queued companion to the per-platform `datamachine/{platform}-publish`
 * abilities. Mirrors Data Machine's `datamachine/send-email-queued`: the
 * publish payload is stored on an Action Scheduler single action that fires
 * at `publish_at`, and a worker hook executes the platform publish ability
 * at that time.
 *
 * - Permission is enforced at schedule time (PublishAuthorization).
 * - Platform auth is resolved at execution time; no tokens are queued.
 * - Transient failures retry with a fixed backoff, capped at MAX_ATTEMPTS.
 *   Validation/auth errors (4xx) and `publish_unconfirmed` (the publish
 *   request may already have landed) never retry, so a retry cannot
 *   double-post.
 * - Outcomes are written to the Action Scheduler action log.
 *
 * @package DataMachineSocials\Abilities
 */

namespace DataMachineSocials\Abilities;

use DataMachineSocials\PublishAuthorization;

defined( 'ABSPATH' ) || exit;

class ScheduledPublishAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	private static bool $worker_registered = false;

	/** Action Scheduler hook that performs the publish. */
	public const WORKER_HOOK = 'datamachine_socials_scheduled_publish';

	/** Action Scheduler group for scheduled social publishes. */
	public const GROUP = 'data-machine-socials-scheduled';

	/** Hard cap on total publish attempts (initial + retries). */
	public const MAX_ATTEMPTS = 3;

	/** Backoff between attempts, in seconds. */
	public const RETRY_BACKOFF_SECONDS = 300;

	/** Action Scheduler stores args as JSON; keep well under its 8000-char cap. */
	private const MAX_PAYLOAD_BYTES = 7000;

	/** Error codes that must never be retried. */
	private const NON_RETRYABLE_CODES = array( 'publish_unconfirmed', 'missing_auth', 'missing_param', 'invalid_collaborators', 'invalid_location', 'unsupported_location', 'unsupported_collaborators', 'unsupported_user_tags' );

	/** Action currently executing, captured for log writes. */
	private static int $current_action_id = 0;

	public function __construct() {
		self::register_worker();
		$this->registerAbility( $this->registerCallback() );
	}

	/** Register the Action Scheduler worker hooks once. */
	public static function register_worker(): void {
		if ( self::$worker_registered ) {
			return;
		}
		self::$worker_registered = true;

		add_action( self::WORKER_HOOK, array( self::class, 'run_worker' ), 10, 1 );
		add_action(
			'action_scheduler_before_execute',
			static function ( $action_id ): void {
				self::$current_action_id = (int) $action_id;
			}
		);
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/social-publish-scheduled',
				array(
					'label'               => __( 'Schedule Social Publish', 'data-machine-socials' ),
					'description'         => __( 'Schedule a social post to publish at a future time. The payload is the input of the platform publish ability (e.g. datamachine/instagram-publish), executed via Action Scheduler at publish_at.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'platform', 'payload', 'publish_at' ),
						'properties' => array(
							'platform'   => array(
								'type'        => 'string',
								'description' => 'Platform slug; the payload is executed by datamachine/{platform}-publish (e.g. instagram).',
								'pattern'     => '^[a-z0-9-]+$',
							),
							'payload'    => array(
								'type'        => 'object',
								'description' => 'Input for the platform publish ability.',
							),
							'publish_at' => array(
								'type'        => array( 'string', 'integer' ),
								'description' => 'When to publish: ISO 8601 datetime (site timezone if none given) or Unix timestamp. Must be in the future.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'schedule_id' => array( 'type' => 'string' ),
							'platform'    => array( 'type' => 'string' ),
							'publish_at'  => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'schedule' ),
					'permission_callback' => array( PublishAuthorization::class, 'can_publish' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/social-publish-scheduled-list',
				array(
					'label'               => __( 'List Scheduled Social Publishes', 'data-machine-socials' ),
					'description'         => __( 'List scheduled social publishes and their outcomes.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'status'   => array(
								'type'    => 'string',
								'enum'    => array( 'pending', 'complete', 'failed', 'canceled', 'any' ),
								'default' => 'pending',
							),
							'platform' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'items'   => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( self::class, 'list_scheduled' ),
					'permission_callback' => array( PublishAuthorization::class, 'can_edit' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/social-publish-scheduled-cancel',
				array(
					'label'               => __( 'Cancel Scheduled Social Publish', 'data-machine-socials' ),
					'description'         => __( 'Cancel a pending scheduled social publish.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'schedule_id' ),
						'properties' => array(
							'schedule_id' => array( 'type' => array( 'string', 'integer' ) ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'schedule_id' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'cancel' ),
					'permission_callback' => array( PublishAuthorization::class, 'can_publish' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	/**
	 * Schedule a publish.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function schedule( array $input ): array|\WP_Error {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new \WP_Error( 'scheduler_unavailable', 'Action Scheduler is not available', array( 'status' => 500 ) );
		}

		$platform = (string) ( $input['platform'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $platform ) ) {
			return new \WP_Error( 'invalid_platform', 'platform must be a platform slug such as "instagram"', array( 'status' => 400 ) );
		}
		if ( ! wp_has_ability( self::publish_ability_name( $platform ) ) ) {
			return new \WP_Error( 'invalid_platform', sprintf( 'No publish ability is registered for platform "%s"', $platform ), array( 'status' => 400 ) );
		}

		$payload = $input['payload'] ?? null;
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return new \WP_Error( 'missing_param', 'payload is required', array( 'status' => 400 ) );
		}

		$timestamp = self::parse_publish_at( $input['publish_at'] ?? null );
		if ( is_wp_error( $timestamp ) ) {
			return $timestamp;
		}

		$job = array(
			'platform'     => $platform,
			'payload'      => $payload,
			'attempt'      => 1,
			'scheduled_by' => get_current_user_id(),
		);
		if ( strlen( (string) wp_json_encode( array( $job ) ) ) > self::MAX_PAYLOAD_BYTES ) {
			return new \WP_Error( 'payload_too_large', 'Scheduled publish payload is too large', array( 'status' => 400 ) );
		}

		$action_id = as_schedule_single_action( $timestamp, self::WORKER_HOOK, array( $job ), self::GROUP );
		if ( empty( $action_id ) ) {
			return new \WP_Error( 'schedule_failed', 'Action Scheduler did not accept the scheduled publish', array( 'status' => 500 ) );
		}

		return array(
			'success'     => true,
			'schedule_id' => (string) $action_id,
			'platform'    => $platform,
			'publish_at'  => wp_date( DATE_ATOM, $timestamp ),
		);
	}

	/**
	 * List scheduled publishes.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_scheduled( array $input ): array|\WP_Error {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new \WP_Error( 'scheduler_unavailable', 'Action Scheduler is not available', array( 'status' => 500 ) );
		}

		$status = (string) ( $input['status'] ?? 'pending' );
		$query  = array(
			'hook'     => self::WORKER_HOOK,
			'group'    => self::GROUP,
			'per_page' => 100,
			'orderby'  => 'date',
			'order'    => 'pending' === $status ? 'ASC' : 'DESC',
		);
		if ( 'any' !== $status ) {
			$query['status'] = $status;
		}

		$platform = (string) ( $input['platform'] ?? '' );
		$items    = array();
		foreach ( as_get_scheduled_actions( $query ) as $action_id => $action ) {
			$job = self::job_from_action( $action );
			if ( null === $job || ( '' !== $platform && $platform !== $job['platform'] ) ) {
				continue;
			}

			$date    = $action->get_schedule()->get_date();
			$items[] = array(
				'schedule_id' => (string) $action_id,
				'platform'    => $job['platform'],
				'publish_at'  => $date ? wp_date( DATE_ATOM, $date->getTimestamp() ) : '',
				'status'      => self::action_status( (int) $action_id ),
				'attempt'     => (int) $job['attempt'],
				'caption'     => mb_substr( (string) ( $job['payload']['content'] ?? '' ), 0, 80 ),
				'last_log'    => self::last_log( (int) $action_id ),
			);
		}

		return array(
			'success' => true,
			'items'   => $items,
		);
	}

	/**
	 * Cancel a pending scheduled publish.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function cancel( array $input ): array|\WP_Error {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return new \WP_Error( 'scheduler_unavailable', 'Action Scheduler is not available', array( 'status' => 500 ) );
		}

		$action_id = absint( $input['schedule_id'] ?? 0 );
		$action    = $action_id > 0 ? \ActionScheduler::store()->fetch_action( $action_id ) : null;
		if ( ! $action || self::WORKER_HOOK !== $action->get_hook() ) {
			return new \WP_Error( 'not_found', 'Scheduled publish not found', array( 'status' => 404 ) );
		}

		if ( 'pending' !== self::action_status( $action_id ) ) {
			return new \WP_Error( 'not_pending', 'Only pending scheduled publishes can be canceled', array( 'status' => 409 ) );
		}

		\ActionScheduler::store()->cancel_action( $action_id );

		return array(
			'success'     => true,
			'schedule_id' => (string) $action_id,
		);
	}

	/**
	 * Action Scheduler worker: execute the platform publish ability.
	 *
	 * Throwing marks the action failed with the message in its log.
	 *
	 * @param mixed $job Queued job.
	 * @throws \RuntimeException When the publish fails terminally.
	 */
	public static function run_worker( $job ): void {
		$action_id = self::$current_action_id;
		if ( ! is_array( $job ) || empty( $job['platform'] ) || ! is_array( $job['payload'] ?? null ) ) {
			throw new \RuntimeException( 'Malformed scheduled publish job' );
		}

		$attempt = max( 1, (int) ( $job['attempt'] ?? 1 ) );
		$name    = self::publish_ability_name( (string) $job['platform'] );
		$ability = wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			throw new \RuntimeException( esc_html( sprintf( 'Publish ability for "%s" is not registered', $job['platform'] ) ) );
		}

		$result = $ability->execute( $job['payload'] );

		if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['success'] ) ) {
			$message = sprintf(
				'Published to %s: media %s %s',
				$job['platform'],
				(string) ( $result['media_id'] ?? $result['id'] ?? '' ),
				(string) ( $result['permalink'] ?? $result['url'] ?? '' )
			);
			self::log( $action_id, trim( $message ) );
			do_action(
				'datamachine_log',
				'info',
				'Scheduled social publish succeeded',
				array(
					'schedule_id' => $action_id,
					'platform'    => $job['platform'],
					'result'      => $result,
				)
			);
			return;
		}

		$error   = is_wp_error( $result ) ? $result : new \WP_Error( 'publish_failed', (string) ( is_array( $result ) ? ( $result['error'] ?? 'Publish failed' ) : 'Publish failed' ) );
		$message = sprintf( 'Attempt %d/%d failed [%s]: %s', $attempt, self::MAX_ATTEMPTS, $error->get_error_code(), $error->get_error_message() );
		do_action(
			'datamachine_log',
			'error',
			'Scheduled social publish failed',
			array(
				'schedule_id' => $action_id,
				'platform'    => $job['platform'],
				'attempt'     => $attempt,
				'error'       => $error->get_error_message(),
			)
		);

		if ( self::is_retryable( $error ) && $attempt < self::MAX_ATTEMPTS ) {
			$job['attempt'] = $attempt + 1;
			$retry_id       = as_schedule_single_action( time() + self::RETRY_BACKOFF_SECONDS, self::WORKER_HOOK, array( $job ), self::GROUP );
			self::log( $action_id, $message . sprintf( '; retry scheduled as %s', (string) $retry_id ) );
			return;
		}

		throw new \RuntimeException( esc_html( $message ) );
	}

	/** Whether a failed publish may be retried safely. */
	public static function is_retryable( \WP_Error $error ): bool {
		if ( in_array( $error->get_error_code(), self::NON_RETRYABLE_CODES, true ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		return ! ( $status >= 400 && $status < 500 );
	}

	/**
	 * Parse publish_at into a future Unix timestamp.
	 *
	 * @param mixed $raw ISO 8601 string or Unix timestamp.
	 * @return int|\WP_Error
	 */
	public static function parse_publish_at( $raw ): int|\WP_Error {
		if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
			$timestamp = (int) $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			try {
				$timestamp = ( new \DateTimeImmutable( trim( $raw ), wp_timezone() ) )->getTimestamp();
			} catch ( \Exception $e ) {
				return new \WP_Error( 'invalid_publish_at', 'publish_at must be an ISO 8601 datetime or Unix timestamp', array( 'status' => 400 ) );
			}
		} else {
			return new \WP_Error( 'invalid_publish_at', 'publish_at is required', array( 'status' => 400 ) );
		}

		if ( $timestamp <= time() ) {
			return new \WP_Error( 'invalid_publish_at', 'publish_at must be in the future', array( 'status' => 400 ) );
		}

		return $timestamp;
	}

	private static function publish_ability_name( string $platform ): string {
		return 'datamachine/' . $platform . '-publish';
	}

	private static function job_from_action( $action ): ?array {
		$args = $action instanceof \ActionScheduler_Action ? $action->get_args() : array();
		$job  = $args[0] ?? null;

		return is_array( $job ) && isset( $job['platform'] ) && is_array( $job['payload'] ?? null ) ? $job : null;
	}

	private static function action_status( int $action_id ): string {
		try {
			return (string) \ActionScheduler::store()->get_status( $action_id );
		} catch ( \Exception $e ) {
			return 'unknown';
		}
	}

	private static function last_log( int $action_id ): string {
		if ( ! class_exists( '\ActionScheduler_Logger' ) ) {
			return '';
		}
		$logs = \ActionScheduler_Logger::instance()->get_logs( (string) $action_id );
		$last = end( $logs );

		return $last ? (string) $last->get_message() : '';
	}

	private static function log( int $action_id, string $message ): void {
		if ( $action_id > 0 && class_exists( '\ActionScheduler_Logger' ) ) {
			\ActionScheduler_Logger::instance()->log( (string) $action_id, $message );
		}
	}
}
