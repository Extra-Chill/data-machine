<?php
/**
 * Pre-AI event dedup gate.
 *
 * Hooks into the `datamachine_pre_ai_step_check` filter to skip the AI
 * conversation entirely when the event already exists in the database.
 *
 * The child job's engine_data already contains identity fields (title,
 * venue, startDate, ticketUrl) from the fetch handler. By checking the
 * PostIdentityIndex BEFORE burning AI tokens, we eliminate the most
 * expensive form of waste: running a full AI conversation just to have
 * upsert_event return "no_change".
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.12.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachine\Core\EngineData;

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

		// Only activate for event pipelines.
		// Check if any adjacent step has upsert_event as a handler.
		if ( ! self::isEventPipeline( $engine ) ) {
			return null;
		}

		// Extract identity fields from engine_data.
		// These are set by the fetch handler (Ticketmaster, Dice, venue scrapers).
		$title     = $engine->get( 'title' ) ?? $engine->get( 'label' ) ?? '';
		$venue     = $engine->get( 'venue' ) ?? '';
		$startDate = $engine->get( 'startDate' ) ?? '';
		$ticketUrl = $engine->get( 'ticketUrl' ) ?? '';

		// Need at least title + startDate for a meaningful lookup.
		// Without these, let the AI step run normally.
		if ( empty( $title ) || empty( $startDate ) ) {
			return null;
		}

		// Use the same dedup strategy that upsert_event uses internally.
		$match = EventDuplicateStrategy::check( array(
			'title'   => $title,
			'context' => array(
				'venue'     => $venue,
				'startDate' => $startDate,
				'ticketUrl' => $ticketUrl,
			),
		) );

		if ( ! $match ) {
			return null;
		}

		// Event exists. Skip the AI step.
		$existing_post_id = $match['post_id'] ?? 0;
		$strategy         = $match['strategy'] ?? 'unknown';

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
