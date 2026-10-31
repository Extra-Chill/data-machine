<?php
/**
 * Term Classification Policy
 *
 * Network term classification costs an inference call per post. Terms exist
 * here to make *upcoming* shows discoverable, so classifying an event that
 * has already happened changes nothing anyone can find.
 *
 * At the time of writing, 96,589 of 131,008 published events on the events
 * site (74%) are in the past. This vetoes automatic classification for them.
 *
 * The network plugin owns scheduling and exposes the seam; the rule lives
 * here because whether an event has passed is knowledge only this plugin
 * has — it owns the post type and the dates table.
 *
 * @package DataMachine_Events
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skip automatic term classification for events that have already happened.
 *
 * "Upcoming" matches the definition the calendar already queries with
 * (UpcomingFilter::upcoming_where): an event counts as upcoming while either
 * its start or its end is still ahead, so a multi-day festival mid-run is not
 * treated as past. Comparison uses site-local time because that is the zone
 * start_datetime is stored in.
 *
 * Only vetoes when the event's timing is actually known. A row that is
 * missing, or carries no start, is left to the network plugin's own rules
 * rather than being silently skipped — an event with unknown dates is a data
 * problem, not a decision to withhold classification.
 *
 * This affects only the automatic path. An explicit reclassification request
 * still runs, by design of the filter.
 *
 * @param bool   $should_classify Whether classification should be scheduled.
 * @param mixed  $post            Post under consideration. Typed loosely
 *                                because a filter argument is whatever the
 *                                caller passed, and this plugin does not own
 *                                the call site — the instanceof below is a
 *                                real guard, not a formality.
 * @param string $site_key        Network site key.
 * @return bool
 */
function skip_term_classification_for_past_events( $should_classify, $post, $site_key ) {
	unset( $site_key );

	if ( ! $should_classify ) {
		return $should_classify;
	}

	if ( ! $post instanceof \WP_Post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return $should_classify;
	}

	$dates = EventDatesTable::get( (int) $post->ID );
	if ( ! $dates || empty( $dates->start_datetime ) ) {
		return $should_classify;
	}

	$now = current_time( 'mysql' );
	$end = ! empty( $dates->end_datetime ) ? (string) $dates->end_datetime : (string) $dates->start_datetime;

	return (string) $dates->start_datetime >= $now || $end >= $now;
}

add_filter(
	'extrachill_network_should_classify_post',
	__NAMESPACE__ . '\\skip_term_classification_for_past_events',
	10,
	3
);
