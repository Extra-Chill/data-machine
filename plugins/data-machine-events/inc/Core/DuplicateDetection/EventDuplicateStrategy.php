<?php
/**
 * Event Duplicate Detection Strategy
 *
 * Registered via the `datamachine_duplicate_strategies` filter in DM core.
 * Replaces the 4-method cascade in EventUpsert with indexed lookups against
 * the PostIdentityIndex table.
 *
 * Strategy cascade (same order as the old EventUpsert::findExistingEvent):
 * 1. Ticket URL + date (most reliable — stable platform identifier)
 * 2. Venue + date + fuzzy title (venue-scoped matching)
 * 3. Exact title + date (with venue confirmation)
 * 4. Date + fuzzy title fallback (venue-agnostic last resort)
 *
 * All query logic uses PostIdentityIndex (indexed columns) instead of
 * wp_postmeta LIKE scans.
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.18.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;
use function DataMachineEvents\Core\datamachine_extract_ticket_identity;

defined( 'ABSPATH' ) || exit;

class EventDuplicateStrategy {

	/**
	 * Register this strategy with DM core's duplicate detection system.
	 */
	public static function register(): void {
		add_filter( 'datamachine_duplicate_strategies', array( static::class, 'addStrategy' ) );
	}

	/**
	 * Add event dedup strategy to the registry.
	 *
	 * @param array $strategies Existing strategies.
	 * @return array Strategies with event strategy added.
	 */
	public static function addStrategy( array $strategies ): array {
		$strategies[] = array(
			'id'        => 'event_identity_index',
			'post_type' => Event_Post_Type::POST_TYPE,
			'callback'  => array( static::class, 'check' ),
			'priority'  => 5, // Run before core strategies.
		);
		return $strategies;
	}

	/**
	 * Execute the event duplicate check against the identity index.
	 *
	 * Called by DuplicateCheckAbility when the post_type matches.
	 *
	 * @param array $input {
	 *     @type string $title      Event title.
	 *     @type string $post_type  Post type (data_machine_events).
	 *     @type array  $context    { venue, startDate, ticketUrl, address, city }
	 * }
	 * @return array|null Duplicate result or null if clear.
	 */
	public static function check( array $input ): ?array {
		$title     = $input['title'] ?? '';
		$context   = $input['context'] ?? array();
		$venue     = $context['venue'] ?? '';
		$startDate = $context['startDate'] ?? '';
		$ticketUrl = $context['ticketUrl'] ?? '';
		$address   = $context['address'] ?? '';
		$city      = $context['city'] ?? '';

		if ( empty( $title ) || empty( $startDate ) ) {
			return null;
		}

		$date_only           = self::extractDateOnly( $startDate );
		$identity_confidence = EventIdentifierGenerator::getIdentityConfidence( $title, $startDate, $venue );

		// Resolve the incoming venue once via the same cascade used by
		// Venue_Taxonomy::find_or_create_venue (address-first, then name).
		// Reused across strategies so dedup matches the canonicalization
		// that the upsert path will perform.
		$venue_term = self::resolveVenueTerm( $venue, $address, $city );

		// Strategy 1: Ticket URL + date (most reliable).
		if ( ! empty( $ticketUrl ) ) {
			$match = self::findByTicketUrl( $ticketUrl, $date_only );
			if ( $match ) {
				return $match;
			}
		}

		// Strategy 2: Venue + date + fuzzy title.
		if ( ! empty( $venue ) || $venue_term ) {
			$match = self::findByVenueDateAndFuzzyTitle( $title, $venue, $date_only, $startDate, $venue_term );
			if ( $match ) {
				return $match;
			}
		}

		// Strategy 3: Exact title + date (with venue confirmation).
		$match = self::findByExactTitle( $title, $venue, $date_only, $identity_confidence, $venue_term, $startDate );
		if ( $match ) {
			return $match;
		}

		// Strategy 4: Date + fuzzy title fallback (venue-agnostic).
		if ( 'low' !== $identity_confidence ) {
			$match = self::findByDateAndFuzzyTitle( $title, $date_only, $startDate, $venue, $venue_term );
			if ( $match ) {
				return $match;
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Strategy 1: Ticket URL matching
	// -----------------------------------------------------------------------

	/**
	 * Find event by ticket URL on the same date.
	 *
	 * Strategy A: exact normalized URL match.
	 * Strategy B: canonical ticket identity comparison (unwraps affiliate links).
	 *
	 * @param string $ticketUrl Ticket URL.
	 * @param string $date_only Date in YYYY-MM-DD.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByTicketUrl( string $ticketUrl, string $date_only ): ?array {
		$normalized_url = datamachine_normalize_ticket_url( $ticketUrl );
		if ( empty( $normalized_url ) ) {
			return null;
		}

		$index = new PostIdentityIndex();

		// Strategy A: exact normalized URL match in the index.
		$match = $index->find_by_ticket_url_and_date( $normalized_url, $date_only );
		if ( $match ) {
			$post_id = (int) $match['post_id'];
			if ( self::isValidPost( $post_id ) ) {
				return self::duplicateResult( $post_id, 'ticket_url_exact' );
			}
		}

		// Strategy B: canonical identity comparison (unwrap affiliate wrappers).
		$canonical_identity = datamachine_extract_ticket_identity( $ticketUrl );
		if ( empty( $canonical_identity ) || $canonical_identity === $normalized_url ) {
			return null;
		}

		$candidates = $index->find_with_ticket_url_on_date( $date_only );
		foreach ( $candidates as $candidate ) {
			$candidate_identity = datamachine_extract_ticket_identity( $candidate['ticket_url'] );
			if ( $canonical_identity === $candidate_identity ) {
				$post_id = (int) $candidate['post_id'];
				if ( self::isValidPost( $post_id ) ) {
					return self::duplicateResult( $post_id, 'ticket_url_canonical' );
				}
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Strategy 2: Venue + date + fuzzy title
	// -----------------------------------------------------------------------

	/**
	 * Find event by venue, date, and fuzzy title match.
	 *
	 * Queries the identity index for events at the same venue on the same date,
	 * then compares titles using the SimilarityEngine and checks time windows.
	 *
	 * @param string        $title      Event title.
	 * @param string        $venue      Venue name.
	 * @param string        $date_only  Date in YYYY-MM-DD.
	 * @param string        $startDate  Full datetime for time window comparison.
	 * @param \WP_Term|null $venue_term Optional pre-resolved venue term (address-aware).
	 *                                  When null, falls back to a name-only cascade.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByVenueDateAndFuzzyTitle( string $title, string $venue, string $date_only, string $startDate, ?\WP_Term $venue_term = null ): ?array {
		if ( EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			return null;
		}

		// Use the pre-resolved venue term when available (address-aware).
		// Otherwise fall back to a name-only cascade: exact → slug → normalized.
		if ( ! $venue_term ) {
			$venue_term = self::resolveVenueTerm( $venue );
		}
		if ( ! $venue_term ) {
			return null;
		}

		$index      = new PostIdentityIndex();
		$candidates = $index->find_by_date_and_venue( $date_only, (int) $venue_term->term_id, 10 );

		foreach ( $candidates as $candidate ) {
			$post_id = (int) $candidate['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			if ( ! EventIdentifierGenerator::titlesMatch( $title, $post->post_title ) ) {
				continue;
			}

			// Check time window.
			$candidate_dates   = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
			$existing_datetime = $candidate_dates ? $candidate_dates->start_datetime : '';
			if ( ! self::isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				continue;
			}

			return self::duplicateResult( $post_id, 'venue_date_fuzzy_title' );
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Strategy 3: Exact title + date with venue confirmation
	// -----------------------------------------------------------------------

	/**
	 * Find event by exact title and date, with optional venue confirmation.
	 *
	 * Uses the title_hash index for fast exact-title lookup.
	 *
	 * @param string        $title               Event title.
	 * @param string        $venue               Venue name.
	 * @param string        $date_only           Date in YYYY-MM-DD.
	 * @param string        $identity_confidence Identity confidence level.
	 * @param \WP_Term|null $venue_term          Optional pre-resolved venue term
	 *                                           (address-aware). When provided, the
	 *                                           candidate's venue term_ids are
	 *                                           compared directly — bypassing the
	 *                                           name-string compare so dedup still
	 *                                           fires when the incoming venue
	 *                                           string differs from the canonical
	 *                                           term name.
	 * @param string        $startDate           Full datetime (YYYY-MM-DDTHH:MM or
	 *                                           similar) used to enforce a 2-hour
	 *                                           window when the term_id bypass
	 *                                           fires. Without this guard, two
	 *                                           genuinely distinct shows that
	 *                                           share a title hash and venue term
	 *                                           on the same date but at very
	 *                                           different times (e.g. an early
	 *                                           and a late show at a multi-room
	 *                                           venue) could be falsely merged.
	 *                                           When empty, the time window is
	 *                                           skipped (matches legacy behavior).
	 * @return array|null Duplicate result or null.
	 */
	private static function findByExactTitle( string $title, string $venue, string $date_only, string $identity_confidence, ?\WP_Term $venue_term = null, string $startDate = '' ): ?array {
		if ( empty( $date_only ) ) {
			return null;
		}

		$title_hash = self::computeTitleHash( $title );
		$index      = new PostIdentityIndex();
		$match      = $index->find_by_date_and_title_hash( $date_only, $title_hash );

		if ( ! $match ) {
			return null;
		}

		$post_id = (int) $match['post_id'];
		if ( ! self::isValidPost( $post_id ) ) {
			return null;
		}

		// Venue confirmation logic (same as old EventUpsert::findEventByExactTitle).
		if ( empty( $venue ) && ! $venue_term ) {
			if ( 'low' === $identity_confidence ) {
				return null;
			}
			return self::duplicateResult( $post_id, 'exact_title_no_venue' );
		}

		// Term-id-aware short-circuit: when the incoming venue resolved to a
		// term (via address or name), match directly on the candidate's
		// venue term_ids. This catches dupes where the incoming venue string
		// differs from the stored term name (e.g. "Monks Jazz" → term "Monks"),
		// which the name-string compare below would miss.
		//
		// Time-window guard: Strategies 2 and 4 enforce a 2-hour window when
		// matching by title fuzziness; Strategy 3 historically did not,
		// because exact-title-hash + venue-name compare is highly specific.
		// The term_id bypass widens that specificity in one direction (the
		// incoming venue can now differ from the candidate's stored venue
		// name), so we add the same 2-hour guard here to avoid falsely
		// merging early/late shows at multi-room venues that share a title
		// hash on the same date. When the guard fails, fall through to the
		// existing venue-name compare path (legacy behavior).
		if ( $venue_term ) {
			$candidate_term_ids = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $candidate_term_ids ) && ! empty( $candidate_term_ids )
				&& in_array( (int) $venue_term->term_id, array_map( 'intval', $candidate_term_ids ), true ) ) {

				$within_window = true;
				if ( '' !== $startDate ) {
					$candidate_dates   = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
					$existing_datetime = $candidate_dates ? $candidate_dates->start_datetime : '';
					$within_window     = self::isWithinTimeWindow( $startDate, $existing_datetime );
				}

				if ( $within_window ) {
					return self::duplicateResult( $post_id, 'exact_title_venue_term_id_match' );
				}
				// Outside time window: skip the bypass and fall through to
				// the existing venue-name compare. If that also fails to
				// confirm, we return null (treat as distinct event).
			}
		}

		$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
		if ( empty( $venue_terms ) || is_wp_error( $venue_terms ) ) {
			if ( 'low' === $identity_confidence ) {
				return null;
			}
			return self::duplicateResult( $post_id, 'exact_title_no_existing_venue' );
		}

		if ( '' !== $venue ) {
			foreach ( $venue_terms as $existing_venue ) {
				if ( $venue === $existing_venue || EventIdentifierGenerator::venuesMatch( $venue, $existing_venue ) ) {
					return self::duplicateResult( $post_id, 'exact_title_venue_confirmed' );
				}
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Strategy 4: Date + fuzzy title fallback
	// -----------------------------------------------------------------------

	/**
	 * Last-resort venue-agnostic fuzzy search.
	 *
	 * Queries all events on the date and compares titles.
	 * When both sides have venue data, venue match is required.
	 *
	 * @param string        $title      Event title.
	 * @param string        $date_only  Date in YYYY-MM-DD.
	 * @param string        $startDate  Full datetime for time window.
	 * @param string        $venue      Incoming venue for confirmation.
	 * @param \WP_Term|null $venue_term Optional pre-resolved venue term
	 *                                  (address-aware). When provided, the
	 *                                  candidate's venue term_ids are compared
	 *                                  first — bypassing the name-string
	 *                                  compare so dupes where the incoming
	 *                                  venue string differs from the canonical
	 *                                  term name still match.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByDateAndFuzzyTitle( string $title, string $date_only, string $startDate, string $venue = '', ?\WP_Term $venue_term = null ): ?array {
		if ( EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			return null;
		}

		$index      = new PostIdentityIndex();
		$candidates = $index->find_by_date( $date_only, 20 );

		foreach ( $candidates as $candidate ) {
			$post_id = (int) $candidate['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			if ( ! EventIdentifierGenerator::titlesMatch( $title, $post->post_title ) ) {
				continue;
			}

			$candidate_dates   = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
			$existing_datetime = $candidate_dates ? $candidate_dates->start_datetime : '';
			if ( ! self::isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				continue;
			}

			// When both sides have venue data, require venue match to avoid
			// false positives on generic titles at different venues.
			if ( ! empty( $venue ) || $venue_term ) {
				// Term-id-aware short-circuit: when the incoming venue resolved
				// to a term, accept the match if the candidate is tagged with
				// that same term — regardless of how the venue is spelled in
				// either post's content.
				if ( $venue_term ) {
					$candidate_term_ids = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $candidate_term_ids ) && ! empty( $candidate_term_ids )
						&& in_array( (int) $venue_term->term_id, array_map( 'intval', $candidate_term_ids ), true ) ) {
						return self::duplicateResult( $post_id, 'date_fuzzy_title_venue_term_id_match' );
					}
				}

				if ( '' !== $venue ) {
					$candidate_venues = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
					$candidate_venue  = ( ! is_wp_error( $candidate_venues ) && ! empty( $candidate_venues ) ) ? $candidate_venues[0] : '';

					if ( ! empty( $candidate_venue ) && ! EventIdentifierGenerator::venuesMatch( $venue, $candidate_venue ) ) {
						continue;
					}
				} elseif ( $venue_term ) {
					// Incoming side has a resolved term but the candidate
					// doesn't share it; skip to avoid cross-venue false
					// positives on generic titles.
					continue;
				}
			}

			return self::duplicateResult( $post_id, 'date_fuzzy_title' );
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Extract date-only portion from a datetime string.
	 *
	 * @param string $datetime Datetime string.
	 * @return string Date in YYYY-MM-DD format.
	 */
	private static function extractDateOnly( string $datetime ): string {
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $datetime, $matches ) ) {
			return $matches[1];
		}
		return $datetime;
	}

	/**
	 * Compute a title hash for exact-match lookups.
	 *
	 * Uses normalizeBasic (lowercase, trim, remove articles) to create
	 * a stable hash. The same normalization must be used when writing
	 * identity rows.
	 *
	 * @param string $title Event title.
	 * @return string MD5 hash of normalized title.
	 */
	public static function computeTitleHash( string $title ): string {
		$normalized = \DataMachine\Core\Similarity\SimilarityEngine::normalizeBasic( $title );
		return md5( $normalized );
	}

	/**
	 * Check if two datetimes are within a 2-hour window.
	 *
	 * Preserves the same logic as EventUpsert::isWithinTimeWindow().
	 *
	 * @param string $datetime1 First datetime.
	 * @param string $datetime2 Second datetime.
	 * @return bool True if within 2 hours or if either lacks time component.
	 */
	private static function isWithinTimeWindow( string $datetime1, string $datetime2 ): bool {
		// If either lacks a time component, allow the match.
		if ( ! preg_match( '/\d{2}:\d{2}/', $datetime1 ) || ! preg_match( '/\d{2}:\d{2}/', $datetime2 ) ) {
			return true;
		}

		$time1 = strtotime( $datetime1 );
		$time2 = strtotime( $datetime2 );

		if ( false === $time1 || false === $time2 ) {
			return true;
		}

		return abs( $time1 - $time2 ) <= 7200; // 2 hours
	}

	/**
	 * Check if a post exists and has a valid status.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if valid.
	 */
	private static function isValidPost( int $post_id ): bool {
		$status = get_post_status( $post_id );
		return $status && in_array( $status, array( 'publish', 'draft', 'pending' ), true );
	}

	/**
	 * Resolve a venue name to a WP_Term with cascading lookup.
	 *
	 * Mirrors the address-first cascade used by
	 * Venue_Taxonomy::find_or_create_venue() so that dedup resolves the
	 * same canonical term the upsert path will land on:
	 *
	 * 1. Address + city match (most authoritative — survives venue rename/alias)
	 * 2. Exact name match
	 * 3. Slug-based match (catches minor name variations)
	 * 4. Normalized name match (strips punctuation, dashes, apostrophes, case)
	 *
	 * Without step 1, dedup misses cases where the incoming venue string
	 * differs from the canonical term name but matches by address — e.g.
	 * "Monks Jazz" vs term "Monks", "Humphreys Backstage Live" vs term
	 * "Humphreys Concerts By the Bay". See issue #252.
	 *
	 * @param string $venue   Venue name from import source.
	 * @param string $address Optional street address (enables address-first lookup).
	 * @param string $city    Optional city name (required alongside address).
	 * @return \WP_Term|null Resolved venue term or null.
	 */
	private static function resolveVenueTerm( string $venue, string $address = '', string $city = '' ): ?\WP_Term {
		// 1. Address + city match (mirrors find_or_create_venue).
		if ( '' !== $address && '' !== $city ) {
			$venue_term = \DataMachineEvents\Core\Venue_Taxonomy::find_venue_by_address_public( $address, $city );
			if ( $venue_term ) {
				return $venue_term;
			}
		}

		if ( '' === $venue ) {
			return null;
		}

		// 2. Exact name match.
		$venue_term = get_term_by( 'name', $venue, 'venue' );
		if ( $venue_term ) {
			return $venue_term;
		}

		// 3. Slug-based lookup.
		$venue_slug = sanitize_title( $venue );
		$venue_term = get_term_by( 'slug', $venue_slug, 'venue' );
		if ( $venue_term ) {
			return $venue_term;
		}

		// 4. Normalized name match via Venue_Taxonomy.
		$venue_term = \DataMachineEvents\Core\Venue_Taxonomy::find_venue_by_normalized_name_public( $venue );
		if ( $venue_term ) {
			return $venue_term;
		}

		return null;
	}

	/**
	 * Build a standard duplicate result array.
	 *
	 * @param int    $post_id  Matched post ID.
	 * @param string $strategy Strategy that matched.
	 * @return array Duplicate result.
	 */
	private static function duplicateResult( int $post_id, string $strategy ): array {
		$title = get_the_title( $post_id );

		do_action(
			'datamachine_log',
			'info',
			'EventDuplicateStrategy: matched existing event',
			array(
				'post_id'  => $post_id,
				'title'    => $title,
				'strategy' => $strategy,
			)
		);

		return array(
			'verdict'  => 'duplicate',
			'source'   => 'identity_index',
			'match'    => array(
				'post_id' => $post_id,
				'title'   => $title,
				'url'     => get_permalink( $post_id ),
			),
			'reason'   => sprintf(
				'Matched existing event "%s" (ID %d) via %s.',
				$title,
				$post_id,
				$strategy
			),
			'strategy' => 'event_identity_index',
		);
	}
}
