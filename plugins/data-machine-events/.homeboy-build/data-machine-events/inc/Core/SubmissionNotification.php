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
	 * Send the publish notification email.
	 *
	 * @param \WP_Post $post            The published event post.
	 * @param string   $submitter_email  Email address of the submitter.
	 */
	private static function send_publish_notification( \WP_Post $post, string $submitter_email ): void {
		$submitter_name = get_post_meta( $post->ID, '_datamachine_submitter_name', true );
		$event_url      = get_permalink( $post->ID );
		$event_title    = $post->post_title;
		$site_name      = get_bloginfo( 'name' );

		$greeting = ! empty( $submitter_name ) ? "Hey {$submitter_name}," : 'Hey,';

		$subject = "Your event is live: {$event_title}";

		$message = "{$greeting}\n\n"
			. "Your event submission has been reviewed and published on {$site_name}!\n\n"
			. "Event: {$event_title}\n"
			. "View it here: {$event_url}\n\n"
			. "Thanks for contributing to the calendar.\n\n"
			. "- {$site_name}";

		wp_mail( $submitter_email, $subject, $message );
	}
}
