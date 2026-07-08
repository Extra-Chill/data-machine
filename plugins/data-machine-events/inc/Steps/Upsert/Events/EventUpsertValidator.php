<?php
/**
 * Event Upsert Validator
 *
 * Pre-publish validation gate extracted from EventUpsert. Enforces a minimum
 * quality bar before any event is written: mandatory title, junk-marker title
 * leakage, placeholder/noise titles, valid start date, and junk/test-payload
 * filtering. Every fetch handler funnels through this single boundary so junk
 * never reaches publish regardless of source.
 *
 * Extracted from EventUpsert in #425. Pure refactor — no behavior change.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachineEvents\Steps\EventImport\JunkPayloadFilter;

defined( 'ABSPATH' ) || exit;

/**
 * Consolidated pre-publish validation gate for event upserts.
 *
 * Rejected items are SKIP + LOG (never published, never drafted); each
 * rejection is logged via datamachine_log so upstream parsers and the junk
 * pattern table can be tuned.
 */
class EventUpsertValidator {

	/**
	 * Reusable junk/test-payload matcher (see #416).
	 *
	 * @var JunkPayloadFilter
	 */
	private JunkPayloadFilter $junk_filter;

	/**
	 * The owning handler class, emitted as `tool_name` in rejection responses
	 * so consumers see the same value EventUpsert produced before extraction.
	 *
	 * @var string
	 */
	private string $tool_class;

	public function __construct( JunkPayloadFilter $junk_filter, string $tool_class ) {
		$this->junk_filter = $junk_filter;
		$this->tool_class  = $tool_class;
	}

	/**
	 * Consolidated pre-publish validation gate.
	 *
	 * Single boundary at upsert_event that enforces a minimum quality bar
	 * before any event is written. Every fetch handler passes through here,
	 * so junk never reaches publish regardless of source. Rejected items are
	 * SKIP + LOG (never published, never drafted); each rejection is logged
	 * via datamachine_log so upstream parsers and the junk pattern table can
	 * be tuned.
	 *
	 * Consolidates (rather than duplicates) the prior scattered guards:
	 *  - Empty / unparseable startDate gate (#415) — folded in from the
	 *    former inline check; getDateTimeConfidence() reused as-is.
	 *  - Junk-marker title guard (#349, #367) — isJunkTitle() reused.
	 *  - Junk / test-payload filter (#416) — JunkPayloadFilter reused, now
	 *    evaluated at the upsert boundary for every source instead of only
	 *    inside the Ticketmaster fetch handler.
	 *
	 * @since 0.46.4
	 *
	 * @param array      $evidence Pre-extracted event fields {
	 *     @type string $title
	 *     @type string $venue
	 *     @type string $startDate
	 *     @type string $startTime
	 *     @type string $source_type
	 *     @type string $artist
	 * }
	 * @param array      $parameters Full tool parameters.
	 * @param EngineData $engine     Engine data.
	 * @return array|null Null when the item passes the gate; otherwise an
	 *                    error response array (success:false) carrying the
	 *                    rejection reason and a machine-readable `rule` slug.
	 */
	public function validateForPublish( array $evidence, array $parameters, EngineData $engine ): ?array {
		$title     = (string) ( $evidence['title'] ?? '' );
		$venue     = (string) ( $evidence['venue'] ?? '' );
		$startDate = (string) ( $evidence['startDate'] ?? '' );
		$startTime = (string) ( $evidence['startTime'] ?? '' );

		// 1. Title is mandatory.
		if ( '' === $title ) {
			return $this->gateRejection(
				'title parameter is required for event upsert',
				array(
					'provided_parameters' => array_keys( $parameters ),
					'engine_data_keys'    => array_keys( $engine->all() ),
				),
				'missing_title'
			);
		}

		// 2. Junk-marker title leakage (Rejected:/Duplicate:/Consolidate:/…).
		//    The AI is meant to call reject_source; this catches prompt
		//    regressions where it improvises a marker in the title instead.
		//    See issues #349, #367.
		if ( $this->isJunkTitle( $title ) ) {
			return $this->gateRejection(
				'Refusing to upsert event with a junk marker title (Rejected/Duplicate/Consolidate/merged/see canonical, or control characters); the AI should call reject_source instead of publishing a marked item (see issues #349, #367)',
				array( 'title' => $title ),
				'junk_marker_title'
			);
		}

		// 3. Placeholder / noise title. Generic, source-agnostic catch for
		//    titles that carry no real event identity ("Test Event", bare
		//    "?", etc.). The deny-list is filterable; matching is generic.
		if ( $this->isPlaceholderTitle( $title ) ) {
			return $this->gateRejection(
				'Refusing to upsert event with a placeholder or noise title; the AI should call reject_source for non-events (see issue #417)',
				array( 'title' => $title ),
				'placeholder_title'
			);
		}

		// 4. Valid start date — non-empty and parseable Y-m-d. The #415 fix
		//    lives here now: getDateTimeConfidence() returns 'none' for empty
		//    or unparseable dates (and impossible calendar dates).
		$datetime_confidence = $this->getDateTimeConfidence( $parameters, $engine );
		if ( 'none' === $datetime_confidence ) {
			// A startTime present with an empty/unparseable startDate is the
			// exact signature of a date-extraction failure upstream (the fetch
			// step got the time but not the date). Surface it as a distinct
			// warning so the source parser can be improved. See issue #415.
			if ( '' !== $startTime ) {
				do_action(
					'datamachine_log',
					'warning',
					'Event Upsert: rejected undated event — startTime present but startDate is missing or unparseable (upstream date extraction failed)',
					array(
						'title'               => $title,
						'venue'               => $venue,
						'startDate'           => $startDate,
						'startTime'           => $startTime,
						'datetime_confidence' => $datetime_confidence,
					)
				);
			}

			return $this->gateRejection(
				'valid startDate is required for event upsert',
				array(
					'title'               => $title,
					'venue'               => $venue,
					'startDate'           => $startDate,
					'startTime'           => $startTime,
					'datetime_confidence' => $datetime_confidence,
				),
				'invalid_start_date'
			);
		}

		// 5. Junk / test payload — reuse the filterable JunkPayloadFilter
		//    (#416) so test/placeholder records are caught at the upsert
		//    boundary regardless of which feed produced them. Source handlers
		//    seed their own deny-list via
		//    data_machine_events_junk_payload_patterns; the resolved
		//    source_type selects the right buckets. This is the key
		//    consolidation win: the check now runs for every source, not only
		//    inside the Ticketmaster handler.
		$junk_evidence = array(
			'source_id'        => (string) ( $engine->get( 'item_identifier' ) ?? $parameters['item_identifier'] ?? '' ),
			'title'            => $title,
			'artist'           => (string) ( $evidence['artist'] ?? '' ),
			// The raw upstream test flag is evaluated at fetch time; at the
			// upsert boundary it is unknown, so leave it null and let the
			// title/id pattern buckets do the work.
			'is_explicit_test' => null,
		);
		$source_type   = (string) ( $evidence['source_type'] ?? '' );

		if ( $this->junk_filter->is_junk( $junk_evidence, $source_type ) ) {
			return $this->gateRejection(
				'Refusing to upsert known junk/test event payload; the item matches the junk deny-list for its source (see issues #416, #417)',
				array(
					'title'       => $title,
					'source_id'   => $junk_evidence['source_id'],
					'source_type' => $source_type,
				),
				'junk_payload'
			);
		}

		return null;
	}

	/**
	 * Determine whether an event title is a junk-marker leak.
	 *
	 * The AI is meant to call the reject_source disposition tool for non-events
	 * and discovered duplicates; when a stale prompt omits that guidance it can
	 * instead improvise a marker in the title and publish the junk item. This
	 * catches (case-insensitive, after trimming surrounding whitespace):
	 *
	 * - "Rejected:" / "Rejected -" / "Rejected —" prefixes (issue #349)
	 * - "Duplicate:" / "Consolidate:" prefixes and their dash variants (issue #367)
	 * - "(duplicate)" / "(merged)" / "(consolidated)" markers anywhere in the title
	 * - "see canonical" substring (e.g. "— see canonical listing")
	 * - Any control characters or null bytes (unconditionally rejected)
	 *
	 * See issues #349 and #367.
	 *
	 * @param string $title Event title.
	 * @return bool True if the title is a junk-marker leak.
	 */
	public function isJunkTitle( string $title ): bool {
		// Control characters / null bytes are never legitimate in a title.
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title ) ) {
			return true;
		}

		$trimmed = trim( $title );

		// "Rejected:" / "Duplicate:" / "Consolidate:" prefixes with colon,
		// hyphen, en dash, or em dash separators (issue #349, #367).
		if ( preg_match( '/^(rejected|duplicate|consolidate)\s*[:\-\x{2013}\x{2014}]/iu', $trimmed ) ) {
			return true;
		}

		// "(duplicate)" / "(merged)" / "(consolidated)" markers anywhere,
		// including compound forms like "(Duplicate — Consolidated)".
		if ( preg_match( '/\(\s*(duplicate|merged|consolidated)\b[^)]*\)/i', $trimmed ) ) {
			return true;
		}

		// "see canonical" substring (e.g. "— see canonical listing").
		if ( false !== stripos( $trimmed, 'see canonical' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether an event title is a generic placeholder carrying no
	 * real event identity.
	 *
	 * Source-agnostic complement to the per-source JunkPayloadFilter: catches
	 * titles that are pure noise regardless of which feed produced them.
	 * Matching is generic (no vendor named); the exact-match deny-list is
	 * filterable via `data_machine_events_placeholder_titles`.
	 *
	 * @since 0.46.4
	 *
	 * @param string $title Event title.
	 * @return bool True when the title is a generic placeholder or pure noise.
	 */
	private function isPlaceholderTitle( string $title ): bool {
		$trimmed = trim( $title );

		if ( '' === $trimmed ) {
			return true;
		}

		// Bare punctuation / symbols / whitespace only: "?", "??", "-", "…".
		if ( preg_match( '/^[\p{P}\p{S}\s]+$/u', $trimmed ) ) {
			return true;
		}

		/**
		 * Filter the generic placeholder title deny-list.
		 *
		 * Exact, case-insensitive matches (after trim) against any entry are
		 * rejected at the upsert gate. Add source-specific patterns via
		 * `data_machine_events_junk_payload_patterns` instead — this filter
		 * is for titles that are placeholders regardless of source.
		 *
		 * @param string[] $placeholders Placeholder titles.
		 */
		$placeholders = (array) apply_filters(
			'data_machine_events_placeholder_titles',
			array(
				'Test Event',
				'Upcoming Event',
			)
		);

		foreach ( $placeholders as $placeholder ) {
			$placeholder = trim( (string) $placeholder );
			if ( '' === $placeholder ) {
				continue;
			}

			if ( 0 === strcasecmp( $trimmed, $placeholder ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine datetime confidence from engine/AI parameters.
	 *
	 * @param array $parameters Event parameters.
	 * @param EngineData $engine Engine data helper.
	 * @return string One of full|date_only|none.
	 */
	public function getDateTimeConfidence( array $parameters, EngineData $engine ): string {
		$start_date = trim( (string) ( $engine->get( 'startDate' ) ?? $parameters['startDate'] ?? '' ) );
		$start_time = trim( (string) ( $engine->get( 'startTime' ) ?? $parameters['startTime'] ?? '' ) );
		$end_date   = trim( (string) ( $engine->get( 'endDate' ) ?? $parameters['endDate'] ?? '' ) );

		if ( '' === $start_date ) {
			return 'none';
		}

		// A non-empty-but-unparseable startDate (e.g. "2026-07-??", "2026-??-??")
		// must not auto-publish a junk date. Treat it like a missing date so the
		// existing rejection path fires and the AI calls reject_source instead.
		// Mirrors the junk-title guard. See issue #394.
		if ( ! \DataMachineEvents\Core\DateTimeParser::isValidYmd( $start_date ) ) {
			return 'none';
		}

		// A present-but-invalid endDate is equally unstorable; reject rather than
		// concatenate a malformed DATETIME downstream.
		if ( '' !== $end_date && ! \DataMachineEvents\Core\DateTimeParser::isValidYmd( $end_date ) ) {
			return 'none';
		}

		if ( '' === $start_time ) {
			return 'date_only';
		}

		return 'full';
	}

	/**
	 * Build a uniform validation-gate rejection response.
	 *
	 * Every gate rule routes through here so rejections are logged with a
	 * consistent prefix and carry a machine-readable `rule` slug for metrics
	 * and tuning. The returned array matches the errorResponse() shape
	 * (success:false) so the tool executor treats it like any other failed
	 * tool call — the item is neither published nor drafted.
	 *
	 * @since 0.46.4
	 *
	 * @param string $message Human-readable rejection reason.
	 * @param array  $context Diagnostic context for the log entry.
	 * @param string $rule    Machine-readable rule slug.
	 * @return array Error response with success:false.
	 */
	private function gateRejection( string $message, array $context, string $rule ): array {
		do_action(
			'datamachine_log',
			'warning',
			'Event Upsert: validation gate rejected item — ' . $rule,
			array_merge( $context, array( 'rule' => $rule ) )
		);

		return array(
			'success'   => false,
			'error'     => $message,
			'tool_name' => $this->tool_class,
			'rule'      => $rule,
		);
	}
}
