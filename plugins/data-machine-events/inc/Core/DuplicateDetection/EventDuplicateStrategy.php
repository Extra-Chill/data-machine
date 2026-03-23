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
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
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
	 *     @type array  $context    { venue, startDate, ticketUrl }
	 * }
	 * @return array|null Duplicate result or null if clear.
	 */
	public static function check( array $input ): ?array {
		$title     = $input['title'] ?? '';
		$context   = $input['context'] ?? array();
		$venue     = $context['venue'] ?? '';
		$startDate = $context['startDate'] ?? '';
		$ticketUrl = $context['ticketUrl'] ?? '';

		if ( empty( $title ) || empty( $startDate ) ) {
			return null;
		}

		$date_only           = self::extractDateOnly( $startDate );
		$identity_confidence = EventIdentifierGenerator::getIdentityConfidence( $title, $startDate, $venue );

		// Strategy 1: Ticket URL + date (most reliable).
		if ( ! empty( $ticketUrl ) ) {
			$match = self::findByTicketUrl( $ticketUrl, $date_only );
			if ( $match ) {
				return $match;
			}
		}

		// Strategy 2: Venue + date + fuzzy title.
		if ( ! empty( $venue ) ) {
			$match = self::findByVenueDateAndFuzzyTitle( $title, $venue, $date_only, $startDate );
			if ( $match ) {
				return $match;
			}
		}

		// Strategy 3: Exact title + date (with venue confirmation).
		$match = self::findByExactTitle( $title, $venue, $date_only, $identity_confidence );
		if ( $match ) {
			return $match;
		}

		// Strategy 4: Date + fuzzy title fallback (venue-agnostic).
		if ( 'low' !== $identity_confidence ) {
			$match = self::findByDateAndFuzzyTitle( $title, $date_only, $startDate, $venue );
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
	 * @param string $title     Event title.
	 * @param string $venue     Venue name.
	 * @param string $date_only Date in YYYY-MM-DD.
	 * @param string $startDate Full datetime for time window comparison.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByVenueDateAndFuzzyTitle( string $title, string $venue, string $date_only, string $startDate ): ?array {
		if ( EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			return null;
		}

		// Resolve venue to term ID with cascading lookup:
		// exact name → slug → normalized name (strips punctuation, dashes, case).
		$venue_term = self::resolveVenueTerm( $venue );
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
			$existing_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
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
	 * @param string $title               Event title.
	 * @param string $venue               Venue name.
	 * @param string $date_only           Date in YYYY-MM-DD.
	 * @param string $identity_confidence Identity confidence level.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByExactTitle( string $title, string $venue, string $date_only, string $identity_confidence ): ?array {
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
		if ( empty( $venue ) ) {
			if ( 'low' === $identity_confidence ) {
				return null;
			}
			return self::duplicateResult( $post_id, 'exact_title_no_venue' );
		}

		$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
		if ( empty( $venue_terms ) || is_wp_error( $venue_terms ) ) {
			if ( 'low' === $identity_confidence ) {
				return null;
			}
			return self::duplicateResult( $post_id, 'exact_title_no_existing_venue' );
		}

		foreach ( $venue_terms as $existing_venue ) {
			if ( $venue === $existing_venue || EventIdentifierGenerator::venuesMatch( $venue, $existing_venue ) ) {
				return self::duplicateResult( $post_id, 'exact_title_venue_confirmed' );
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
	 * @param string $title     Event title.
	 * @param string $date_only Date in YYYY-MM-DD.
	 * @param string $startDate Full datetime for time window.
	 * @param string $venue     Incoming venue for confirmation.
	 * @return array|null Duplicate result or null.
	 */
	private static function findByDateAndFuzzyTitle( string $title, string $date_only, string $startDate, string $venue = '' ): ?array {
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

			$existing_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
			if ( ! self::isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				continue;
			}

			// When both sides have venue data, require venue match to avoid
			// false positives on generic titles at different venues.
			if ( ! empty( $venue ) ) {
				$candidate_venues = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
				$candidate_venue  = ( ! is_wp_error( $candidate_venues ) && ! empty( $candidate_venues ) ) ? $candidate_venues[0] : '';

				if ( ! empty( $candidate_venue ) && ! EventIdentifierGenerator::venuesMatch( $venue, $candidate_venue ) ) {
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
	 * Tries in order:
	 * 1. Exact name match
	 * 2. Slug-based match (catches minor name variations)
	 * 3. Normalized name match (strips punctuation, dashes, apostrophes, case)
	 *
	 * @param string $venue Venue name from import source.
	 * @return \WP_Term|null Resolved venue term or null.
	 */
	private static function resolveVenueTerm( string $venue ): ?\WP_Term {
		// 1. Exact name match.
		$venue_term = get_term_by( 'name', $venue, 'venue' );
		if ( $venue_term ) {
			return $venue_term;
		}

		// 2. Slug-based lookup.
		$venue_slug = sanitize_title( $venue );
		$venue_term = get_term_by( 'slug', $venue_slug, 'venue' );
		if ( $venue_term ) {
			return $venue_term;
		}

		// 3. Normalized name match via Venue_Taxonomy.
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
