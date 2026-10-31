<?php
/**
 * Pre-AI event dedup gate.
 *
 * Hooks into the `datamachine_pre_ai_step_check` filter to skip the AI
 * conversation entirely when the event already exists in the database.
 *
 * The child job's engine_data already contains identity fields (title,
 * venue, startDate, startTime, ticketUrl) from the fetch handler. By checking the
 * canonical duplicate-check ability BEFORE burning AI tokens, we eliminate the most
 * expensive form of waste: running a full AI conversation just to have
 * upsert_event return "no_change".
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.12.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachine\Core\EngineData;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PreAIEventDedupGate {

	/**
	 * Register the filter.
	 */
	public static function register(): void {
		add_filter( 'datamachine_pre_ai_step_check', array( static::class, 'check' ), 10, 4 );
	}

	/**
	 * Check if the event already exists before running the AI step.
	 *
	 * Only activates when the pipeline has an event handler (upsert_event)
	 * and the engine_data contains enough identity fields for a reliable lookup.
	 *
	 * @param mixed      $result          Current filter result (null = proceed).
	 * @param EngineData $engine          Engine data for this job.
	 * @param array      $flow_step_config Flow step configuration.
	 * @param int        $job_id          Current job ID.
	 * @return array|null Skip result or null to proceed.
	 */
	public static function check( $result, EngineData $engine, array $flow_step_config, int $job_id ): ?array {
		// Already short-circuited by another filter.
		if ( null !== $result ) {
			return $result;
		}

		// Ticketmaster suppresses unchanged source revisions before fan-out.
		// Changed revisions must reach upsert so mutable future event data is not
		// frozen by the post identity index.
		if ( 'ticketmaster' === $engine->get( 'source_type' ) ) {
			return null;
		}

		// Only activate for event pipelines.
		// Check if any adjacent step has upsert_event as a handler.
		if ( ! self::isEventPipeline( $engine ) ) {
			return null;
		}

		// Extract identity fields from engine_data.
		// These are set by the fetch handler (Ticketmaster, Dice, venue scrapers).
		$title     = $engine->get( 'title' ) ?? $engine->get( 'label' ) ?? '';
		$venue     = $engine->get( 'venue' ) ?? '';
		$startDate = EventIdentifierGenerator::normalizeStartDateTime(
			(string) ( $engine->get( 'startDate' ) ?? '' ),
			(string) ( $engine->get( 'startTime' ) ?? '' ),
			(string) ( $engine->get( 'venueTimezone' ) ?? '' )
		);
		$ticketUrl = $engine->get( 'ticketUrl' ) ?? '';
		$address   = $engine->get( 'venueAddress' ) ?? '';
		$city      = $engine->get( 'venueCity' ) ?? '';
		$state     = $engine->get( 'venueState' ) ?? '';
		$country   = $engine->get( 'venueCountry' ) ?? '';

		// Need at least title + startDate for a meaningful lookup.
		// Without these, let the AI step run normally.
		if ( empty( $title ) || empty( $startDate ) ) {
			return null;
		}

		$duplicate_check = function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/check-duplicate' )
			? wp_get_ability( 'datamachine/check-duplicate' )
			: null;
		if ( ! $duplicate_check ) {
			throw new \RuntimeException( 'Data Machine 0.39.0 or newer is required: datamachine/check-duplicate is unavailable.' );
		}

		$match = $duplicate_check->execute( array(
			'title'     => $title,
			'post_type' => \DataMachineEvents\Core\Event_Post_Type::POST_TYPE,
			'scope'     => 'published',
			'context'   => array(
				'venue'     => $venue,
				'startDate' => $startDate,
				'ticketUrl' => $ticketUrl,
				'address'   => $address,
				'city'      => $city,
				'state'     => $state,
				'country'   => $country,
			),
		) );

		if (
			! is_array( $match )
			|| 'duplicate' !== ( $match['verdict'] ?? '' )
			|| 'event_identity_index' !== ( $match['strategy'] ?? '' )
		) {
			return null;
		}

		// Event exists. Decide whether the packet is a changed revision that
		// must still reach upsert.
		$existing_post_id = (int) ( $match['match']['post_id'] ?? 0 );
		// The guard above already verified `strategy` is `event_identity_index`,
		// so the key is known to exist here.
		$strategy = (string) $match['strategy'];

		if ( $existing_post_id > 0 && self::isChangedRevision( $title, $startDate, $existing_post_id ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'PreAIEventDedupGate: matched post differs from incoming packet, proceeding so upsert can update it',
				array(
					'job_id'        => $job_id,
					'title'         => $title,
					'venue'         => $venue,
					'startDate'     => $startDate,
					'existing_post' => $existing_post_id,
					'strategy'      => $strategy,
					'reason'        => (string) ( $match['reason'] ?? '' ),
				)
			);

			return null;
		}

		// Event exists and the packet matches it. Skip the AI step.
		do_action(
			'datamachine_log',
			'debug',
			'PreAIEventDedupGate: Event already exists, skipping AI step',
			array(
				'job_id'        => $job_id,
				'title'         => $title,
				'venue'         => $venue,
				'startDate'     => $startDate,
				'existing_post' => $existing_post_id,
				'strategy'      => $strategy,
				'reason'        => (string) ( $match['reason'] ?? '' ),
			)
		);

		return array(
			'skip'   => true,
			'reason' => sprintf(
				'event already exists (post %d, matched via %s)',
				$existing_post_id,
				$strategy
			),
			'status' => \DataMachine\Core\JobStatus::COMPLETED_NO_ITEMS,
		);
	}

	/**
	 * Determine whether an incoming packet is a changed revision of a matched post.
	 *
	 * "Exists" is not "nothing to do": sources edit events after publication
	 * (Google Calendar publishes an edited occurrence of a recurring series as
	 * a separate VEVENT, venues rename shows and shift start times). When the
	 * packet differs from the matched post, it must reach upsert so the update
	 * path can apply it in place — the same reasoning as the Ticketmaster
	 * carve-out above, keyed on evidence rather than source name.
	 *
	 * Evidence is limited to what reaches engine_data: the normalized title and
	 * the normalized local start datetime. ICS revision markers (SEQUENCE,
	 * LAST-MODIFIED, RECURRENCE-ID) are intentionally not used as signals —
	 * they are not seeded into engine_data, and presence-based signals would
	 * be redundant anyway: a packet only reaches this gate when its content
	 * hash (title + start + venue) was never fetched before, so any packet
	 * here that matches an existing post byte-for-byte is by definition an
	 * unchanged duplicate.
	 *
	 * @param string $title     Incoming title (raw).
	 * @param string $startDate Incoming normalized start datetime (Y-m-d H:i).
	 * @param int    $post_id  Matched post ID.
	 * @return bool True when the packet is a changed revision.
	 */
	private static function isChangedRevision( string $title, string $startDate, int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$incoming_title = EventIdentifierGenerator::normalizeBasic( $title );
		$existing_title = EventIdentifierGenerator::normalizeBasic( (string) $post->post_title );
		if ( '' !== $incoming_title && $incoming_title !== $existing_title ) {
			return true;
		}

		$existing_dates = EventDatesTable::get( $post_id );
		if ( ! $existing_dates || '' === (string) $existing_dates->start_datetime ) {
			return false;
		}

		// The stored value is venue-local wall clock with seconds; normalize
		// both sides through the same identity normalizer used by the gate.
		$existing_start = EventIdentifierGenerator::normalizeStartDateTime( (string) $existing_dates->start_datetime );

		return '' !== $existing_start && $existing_start !== $startDate;
	}

	/**
	 * Determine if this pipeline involves event upsert.
	 *
	 * Checks the flow config for any step with upsert_event in handler_slugs.
	 *
	 * @param EngineData $engine Engine data.
	 * @return bool True if this is an event pipeline.
	 */
	private static function isEventPipeline( EngineData $engine ): bool {
		$flow_config = $engine->get( 'flow_config' );

		if ( ! is_array( $flow_config ) ) {
			return false;
		}

		foreach ( $flow_config as $step_config ) {
			$handler_slugs = $step_config['handler_slugs'] ?? array();
			if ( in_array( 'upsert_event', $handler_slugs, true ) ) {
				return true;
			}
		}

		return false;
	}
}
