<?php
/**
 * Submission Publish Notification
 *
 * Sends an email to the event submitter when their submitted event
 * is published. Fulfills the promise in the submission confirmation
 * email: "You'll receive another email once it's been reviewed."
 *
 * Only fires for events that have submitter metadata stored by
 * EventUpsert (_datamachine_submitted_by, _datamachine_submitter_email).
 *
 * Delivery goes through the `datamachine/send-email` ability (DM core)
 * rather than raw wp_mail() so DM's centralized header/template/SMTP
 * routing applies. This plugin stays vendor-neutral — operators wire
 * an optional template/context via the
 * `data_machine_events_submission_notification_template` and
 * `data_machine_events_submission_notification_context` filters.
 *
 * @package DataMachineEvents
 * @subpackage Core
 * @since 0.17.3
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubmissionNotification {

	/**
	 * Register the status transition hook.
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_transition' ), 10, 3 );
	}

	/**
	 * Handle post status transitions for submitted events.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public static function on_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'pending' !== $old_status ) {
			return;
		}

		$submitter_email = get_post_meta( $post->ID, '_datamachine_submitter_email', true );
		if ( empty( $submitter_email ) || ! is_email( $submitter_email ) ) {
			return;
		}

		self::send_publish_notification( $post, $submitter_email );
	}

	/**
	 * Send the publish notification email via the datamachine/send-email ability.
	 *
	 * Vendor-neutral: by default sends raw HTML with no template. Operators may
	 * hook `data_machine_events_submission_notification_template` to return a
	 * template id registered via DM's `datamachine_email_templates` filter, and
	 * `data_machine_events_submission_notification_context` to merge additional
	 * context keys for that template. The default context always includes
	 * `body_html`, `subject`, `event_id`, `event_title`, `event_url`,
	 * `submitter_name`, and `submitter_email` so EC-side branded templates can
	 * format the message without re-deriving it.
	 *
	 * @param \WP_Post $post            The published event post.
	 * @param string   $submitter_email Email address of the submitter.
	 */
	private static function send_publish_notification( \WP_Post $post, string $submitter_email ): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			// Abilities API not available — DM not active or too old.
			// Plugin header declares `Requires Plugins: data-machine` so this
			// should not happen in practice; log and bail rather than fall
			// back to wp_mail() (which would defeat the migration).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional fallback log when DM dependency missing.
			error_log( '[data-machine-events] SubmissionNotification: wp_get_ability() unavailable; skipping notification for event ' . $post->ID );
			return;
		}

		$ability = wp_get_ability( 'datamachine/send-email' );
		if ( null === $ability ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional fallback log when DM ability missing.
			error_log( '[data-machine-events] SubmissionNotification: datamachine/send-email ability not registered; skipping notification for event ' . $post->ID );
			return;
		}

		$submitter_name = (string) get_post_meta( $post->ID, '_datamachine_submitter_name', true );
		$event_url      = (string) get_permalink( $post->ID );
		$event_title    = (string) $post->post_title;
		$site_name      = (string) get_bloginfo( 'name' );

		$greeting = '' !== $submitter_name ? "Hey {$submitter_name}," : 'Hey,';
		$subject  = "Your event is live: {$event_title}";

		// HTML body — the ability sends as text/html by default. Newlines are
		// converted so plain-text MTAs and HTML clients both render reasonably.
		$body_html = sprintf(
			'<p>%1$s</p>'
			. '<p>Your event submission has been reviewed and published on %2$s.</p>'
			. '<p><strong>Event:</strong> %3$s<br>'
			. '<strong>View it here:</strong> <a href="%4$s">%4$s</a></p>'
			. '<p>Thanks for contributing to the calendar.</p>'
			. '<p>&mdash; %2$s</p>',
			esc_html( $greeting ),
			esc_html( $site_name ),
			esc_html( $event_title ),
			esc_url( $event_url )
		);

		/**
		 * Filter the template id used for the submission publish notification.
		 *
		 * Return a non-empty string matching a template id registered via DM's
		 * `datamachine_email_templates` filter to have the ability render the
		 * email through that template. Default empty string sends the raw
		 * `body_html` with no template wrapper — keeps this plugin usable on a
		 * vanilla DM install where no branded templates are registered.
		 *
		 * @since 0.36.0
		 *
		 * @param string   $template_id     Template id. Default ''.
		 * @param \WP_Post $post            The published event post.
		 * @param string   $submitter_email Submitter's email address.
		 */
		$template_id = (string) apply_filters(
			'data_machine_events_submission_notification_template',
			'',
			$post,
			$submitter_email
		);

		$default_context = array(
			'body_html'       => $body_html,
			'subject'         => $subject,
			'event_id'        => $post->ID,
			'event_title'     => $event_title,
			'event_url'       => $event_url,
			'submitter_name'  => $submitter_name,
			'submitter_email' => $submitter_email,
			'site_name'       => $site_name,
		);

		/**
		 * Filter the context array passed to the email template.
		 *
		 * Templates registered via `datamachine_email_templates` receive this
		 * array as their single argument. The default context above is always
		 * provided; operators may add or override keys here.
		 *
		 * @since 0.36.0
		 *
		 * @param array    $context         Context array passed to the template.
		 * @param \WP_Post $post            The published event post.
		 * @param string   $submitter_email Submitter's email address.
		 * @param string   $template_id     Resolved template id ('' when no template).
		 */
		$context = (array) apply_filters(
			'data_machine_events_submission_notification_context',
			$default_context,
			$post,
			$submitter_email,
			$template_id
		);

		$input = array(
			'to'       => $submitter_email,
			'subject'  => $subject,
			'body'     => $body_html,
			'template' => $template_id,
			'context'  => $context,
		);

		$result = self::execute_as_system( $ability, $input );

		$error = self::failure_message( $result );
		if ( null !== $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional failure log; the submitter email was not sent.
			error_log( '[data-machine-events] SubmissionNotification: send-email ability failed for event ' . $post->ID . ': ' . $error );
		}
	}

	/**
	 * Run the send as the system rather than as whoever changed the status.
	 *
	 * The notification is trusted, server-built transactional mail: the
	 * recipient is the stored submitter address and the body is rendered here.
	 * Whether it sends must not depend on the publishing user's Data Machine
	 * capabilities, nor on there being a user at all (auto-published
	 * submissions are created inside a background job).
	 *
	 * @param \WP_Ability           $ability Resolved datamachine/send-email ability.
	 * @param array<string, mixed> $input   Ability input.
	 * @return mixed Ability result.
	 */
	private static function execute_as_system( \WP_Ability $ability, array $input ) {
		return \DataMachine\Abilities\PermissionHelper::run_as_system( static fn() => $ability->execute( $input ) );
	}

	/**
	 * Describe a failed send, or null when the send succeeded.
	 *
	 * WP_Ability::execute() returns a WP_Error when the permission callback
	 * denies the call or input validation fails, and the send-email ability
	 * itself returns array|WP_Error. Both shapes must be reported (#918).
	 *
	 * @param mixed $result Ability result.
	 * @return string|null
	 */
	public static function failure_message( $result ): ?string {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_code() . ': ' . $result->get_error_message();
		}

		if ( is_array( $result ) ) {
			if ( ! empty( $result['success'] ) ) {
				return null;
			}

			return isset( $result['error'] ) ? (string) $result['error'] : 'unknown error';
		}

		return 'unexpected result type ' . gettype( $result );
	}
}
