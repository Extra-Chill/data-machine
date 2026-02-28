<?php
/**
 * Event Upsert Handler
 *
 * Intelligently creates or updates event posts based on event identity.
 * Searches for existing events by (title, venue, startDate) and updates if found,
 * creates if new, or skips if data unchanged.
 *
 * Replaces Publisher with smarter create/update logic and change detection.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since   0.2.0
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachineEvents\Steps\Upsert\Events\Venue;
use DataMachineEvents\Steps\Upsert\Events\Promoter;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;
use function DataMachineEvents\Core\datamachine_extract_ticket_identity;
use DataMachine\Core\Steps\Update\Handlers\UpdateHandler;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;
use DataMachine\Core\WordPress\WordPressPublishHelper;

defined( 'ABSPATH' ) || exit;

class EventUpsert extends UpdateHandler {

	protected $taxonomy_handler;

	public function __construct() {
		$this->taxonomy_handler = new TaxonomyHandler();
		// Register custom handler for venue taxonomy
		TaxonomyHandler::addCustomHandler( 'venue', array( $this, 'assignVenueTaxonomy' ) );
		// Register custom handler for promoter taxonomy
		TaxonomyHandler::addCustomHandler( 'promoter', array( $this, 'assignPromoterTaxonomy' ) );
	}

	/**
	 * Execute event upsert (create or update)
	 *
	 * @param array $parameters Event data from AI tool call
	 * @param array $handler_config Handler configuration
	 * @return array Tool call result with action: created|updated|no_change
	 */
	protected function executeUpdate( array $parameters, array $handler_config ): array {
		// Get engine data FIRST (before validation)
		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine_snapshot = $job_id ? $this->getEngineData( $job_id ) : array();
			$engine          = new EngineData( $engine_snapshot, $job_id );
		}

		// Extract event identity fields (AI title takes precedence, engine data fallback for other fields)
		$title     = sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' );
		$venue     = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';
		$startDate = $engine->get( 'startDate' ) ?? $parameters['startDate'] ?? '';
		$ticketUrl = $engine->get( 'ticketUrl' ) ?? $parameters['ticketUrl'] ?? '';

		// Validate title after extraction from engine data or parameters
		if ( empty( $title ) ) {
			return $this->errorResponse(
				'title parameter is required for event upsert',
				array(
					'provided_parameters' => array_keys( $parameters ),
					'engine_data_keys'    => array_keys( $engine->all() ),
				)
			);
		}

		do_action(
			'datamachine_log',
			'debug',
			'Event Upsert: Processing event',
			array(
				'title'     => $title,
				'venue'     => $venue,
				'startDate' => $startDate,
				'ticketUrl' => $ticketUrl,
			)
		);

		// Search for existing event
		$existing_post_id = $this->findExistingEvent( $title, $venue, $startDate, $ticketUrl );

		if ( $existing_post_id ) {
			// Event exists - check if data changed
			$existing_data = $this->extractEventData( $existing_post_id );

			if ( $this->hasDataChanged( $existing_data, $parameters ) ) {
				// UPDATE existing event
				$this->updateEventPost( $existing_post_id, $parameters, $handler_config, $engine );

				do_action(
					'datamachine_log',
					'info',
					'Event Upsert: Updated existing event',
					array(
						'post_id' => $existing_post_id,
						'title'   => $title,
					)
				);

				return $this->successResponse(
					array(
						'post_id'  => $existing_post_id,
						'post_url' => get_permalink( $existing_post_id ),
						'action'   => 'updated',
					)
				);
			} else {
				// SKIP - no changes detected
				do_action(
					'datamachine_log',
					'debug',
					'Event Upsert: Skipped event (no changes)',
					array(
						'post_id' => $existing_post_id,
						'title'   => $title,
					)
				);

				return $this->successResponse(
					array(
						'post_id'  => $existing_post_id,
						'post_url' => get_permalink( $existing_post_id ),
						'action'   => 'no_change',
					)
				);
			}
		} else {
			// CREATE new event
			$post_id = $this->createEventPost( $parameters, $handler_config, $engine );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return $this->errorResponse(
					'Event post creation failed',
					array(
						'title' => $title,
					)
				);
			}

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Created new event',
				array(
					'post_id' => $post_id,
					'title'   => $title,
				)
			);

			return $this->successResponse(
				array(
					'post_id'  => $post_id,
					'post_url' => get_permalink( $post_id ),
					'action'   => 'created',
				)
			);
		}
	}

	/**
	 * Find existing event by title, venue, start date, and ticket URL
	 *
	 * Checks in order of reliability:
	 * 1. Ticket URL matching (most reliable - stable identifier from ticketing platform)
	 * 2. Fuzzy title matching at same venue/date
	 * 3. Exact title matching
	 *
	 * @param string $title Event title
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @param string $ticketUrl Ticket purchase URL
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findExistingEvent( string $title, string $venue, string $startDate, string $ticketUrl = '' ): ?int {
		// Try ticket URL matching first (most reliable)
		if ( ! empty( $ticketUrl ) && ! empty( $startDate ) ) {
			$ticket_match = $this->findEventByTicketUrl( $ticketUrl, $startDate );
			if ( $ticket_match ) {
				return $ticket_match;
			}
		}

		// Try fuzzy title matching when we have venue and date
		if ( ! empty( $venue ) && ! empty( $startDate ) ) {
			$fuzzy_match = $this->findEventByVenueDateAndFuzzyTitle( $title, $venue, $startDate );
			if ( $fuzzy_match ) {
				return $fuzzy_match;
			}
		}

		// Try exact title matching (with venue confirmation)
		$exact_match = $this->findEventByExactTitle( $title, $venue, $startDate );
		if ( $exact_match ) {
			return $exact_match;
		}

		// Final safety net: fuzzy title + date search without venue constraint.
		// Catches cross-source duplicates where venue names differ between scrapers
		// (e.g. "Come and Take It Live" vs "Come and Take It Productions").
		// Venue is passed for confirmation when both sides have it.
		if ( ! empty( $startDate ) ) {
			return $this->findEventByDateAndFuzzyTitle( $title, $startDate, $venue );
		}

		return null;
	}

	/**
	 * Find event by venue + date, then fuzzy title comparison
	 *
	 * Queries all events at a venue on a given date, then compares titles
	 * using core title extraction to catch variations like tour names or openers.
	 *
	 * @param string $title Event title to match
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if fuzzy match found, null otherwise
	 */
	private function findEventByVenueDateAndFuzzyTitle( string $title, string $venue, string $startDate ): ?int {
		// Find venue term — try exact name first, then slug-based lookup
		$venue_term = get_term_by( 'name', $venue, 'venue' );
		if ( ! $venue_term ) {
			// Try slug-based lookup to catch minor name variations
			$venue_slug = sanitize_title( $venue );
			$venue_term = get_term_by( 'slug', $venue_slug, 'venue' );
		}
		if ( ! $venue_term ) {
			do_action(
				'datamachine_log',
				'debug',
				'Event Upsert: Venue term not found for fuzzy title matching, will try venue-agnostic fallback',
				array(
					'venue_name' => $venue,
					'title'      => $title,
					'startDate'  => $startDate,
				)
			);
			return null;
		}

		// Query events at this venue on this date
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => 10,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'tax_query'      => array(
				array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => $venue_term->term_id,
				),
			),
			'meta_query'     => array(
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $startDate,
					'compare' => 'LIKE',
				),
			),
		);

		$candidates = get_posts( $args );

		if ( empty( $candidates ) ) {
			return null;
		}

		// Compare titles using core extraction and time window
		foreach ( $candidates as $candidate ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
				continue;
			}

			// Check time window if both events have time data
			$existing_datetime = get_post_meta( $candidate->ID, EVENT_DATETIME_META_KEY, true );
			if ( ! $this->isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				do_action(
					'datamachine_log',
					'debug',
					'Event Upsert: Title matched but outside time window (possible early/late show)',
					array(
						'incoming_title'    => $title,
						'matched_title'     => $candidate->post_title,
						'incoming_datetime' => $startDate,
						'existing_datetime' => $existing_datetime,
						'post_id'           => $candidate->ID,
					)
				);
				continue;
			}

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Fuzzy matched incoming title to existing event',
				array(
					'incoming_title' => $title,
					'matched_title'  => $candidate->post_title,
					'post_id'        => $candidate->ID,
					'venue'          => $venue,
					'date'           => $startDate,
				)
			);
			return $candidate->ID;
		}

		return null;
	}

	/**
	 * Check if two datetimes are within a tolerance window
	 *
	 * Used to distinguish early/late shows (3+ hours apart) from the same event
	 * listed with different times across sources (typically within 1-2 hours).
	 *
	 * If either datetime lacks a time component, returns true (allows match).
	 *
	 * @param string $datetime1 First datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM)
	 * @param string $datetime2 Second datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM)
	 * @param int $windowHours Maximum hours apart to consider a match (default 2)
	 * @return bool True if within window or time data unavailable
	 */
	private function isWithinTimeWindow( string $datetime1, string $datetime2, int $windowHours = 2 ): bool {
		// If either is empty, allow match
		if ( empty( $datetime1 ) || empty( $datetime2 ) ) {
			return true;
		}

		// Check if both have time components (look for T or space followed by time)
		$has_time1 = preg_match( '/[T\s]\d{2}:\d{2}/', $datetime1 );
		$has_time2 = preg_match( '/[T\s]\d{2}:\d{2}/', $datetime2 );

		// If either lacks time, allow match (can't compare)
		if ( ! $has_time1 || ! $has_time2 ) {
			return true;
		}

		// Parse both datetimes
		$time1 = strtotime( $datetime1 );
		$time2 = strtotime( $datetime2 );

		if ( false === $time1 || false === $time2 ) {
			return true;
		}

		// Calculate absolute difference in hours
		$diff_hours = abs( $time1 - $time2 ) / 3600;

		return $diff_hours <= $windowHours;
	}

	/**
	 * Find event by exact title match with venue confirmation
	 *
	 * Matches are returned when:
	 * - No incoming venue (can't verify, trust the title+date match)
	 * - Existing post has no venue assigned (can't verify, trust the match)
	 * - Venues match exactly OR fuzzy-match (normalized comparison)
	 *
	 * @param string $title Event title
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findEventByExactTitle( string $title, string $venue, string $startDate ): ?int {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'title'          => $title,
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'fields'         => 'ids',
		);

		if ( ! empty( $startDate ) ) {
			$args['meta_query'] = array(
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $startDate,
					'compare' => 'LIKE',
				),
			);
		}

		$posts = get_posts( $args );

		if ( ! empty( $posts ) ) {
			$post_id = $posts[0];

			// No incoming venue — trust the title+date match
			if ( empty( $venue ) ) {
				return $post_id;
			}

			$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );

			// Existing post has no venue — trust the title+date match
			if ( empty( $venue_terms ) ) {
				return $post_id;
			}

			// Check venue: exact match first, then normalized comparison
			foreach ( $venue_terms as $existing_venue ) {
				if ( $venue === $existing_venue ) {
					return $post_id;
				}

				if ( EventIdentifierGenerator::venuesMatch( $venue, $existing_venue ) ) {
					do_action(
						'datamachine_log',
						'info',
						'Event Upsert: Fuzzy venue match in exact title search',
						array(
							'incoming_venue' => $venue,
							'existing_venue' => $existing_venue,
							'post_id'        => $post_id,
							'title'          => $title,
						)
					);
					return $post_id;
				}
			}
		}

		return null;
	}

	/**
	 * Find event by matching ticket URL on the same date
	 *
	 * Ticket URLs are stable identifiers from ticketing platforms.
	 * Same ticket URL + same date = definitively the same event.
	 *
	 * @param string $ticketUrl Ticket purchase URL
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findEventByTicketUrl( string $ticketUrl, string $startDate ): ?int {
		if ( empty( $ticketUrl ) || empty( $startDate ) ) {
			return null;
		}

		$normalized_url = datamachine_normalize_ticket_url( $ticketUrl );
		if ( empty( $normalized_url ) ) {
			return null;
		}

		// Strategy A: exact match on stored normalized URL
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => EVENT_TICKET_URL_META_KEY,
					'value'   => $normalized_url,
					'compare' => '=',
				),
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $startDate,
					'compare' => 'LIKE',
				),
			),
		);

		$posts = get_posts( $args );

		if ( ! empty( $posts ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Found duplicate by ticket URL (exact)',
				array(
					'ticket_url'      => $ticketUrl,
					'normalized_url'  => $normalized_url,
					'matched_post_id' => $posts[0],
					'date'            => $startDate,
				)
			);
			return $posts[0];
		}

		// Strategy B: match on canonical ticket identity (unwraps affiliate URLs).
		// This catches cases where one source provides an affiliate link and another
		// provides the direct URL for the same event.
		$canonical_identity = datamachine_extract_ticket_identity( $ticketUrl );
		if ( empty( $canonical_identity ) || $canonical_identity === $normalized_url ) {
			return null; // No different identity to check
		}

		// Search all events on the same date and compare their canonical identities
		$date_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => 50,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => EVENT_TICKET_URL_META_KEY,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $startDate,
					'compare' => 'LIKE',
				),
			),
		);

		$candidates = get_posts( $date_args );

		foreach ( $candidates as $candidate_id ) {
			$stored_url       = get_post_meta( $candidate_id, EVENT_TICKET_URL_META_KEY, true );
			$stored_canonical = datamachine_extract_ticket_identity( $stored_url );

			if ( $stored_canonical === $canonical_identity ) {
				do_action(
					'datamachine_log',
					'info',
					'Event Upsert: Found duplicate by ticket canonical identity',
					array(
						'ticket_url'         => $ticketUrl,
						'canonical_identity' => $canonical_identity,
						'stored_url'         => $stored_url,
						'matched_post_id'    => $candidate_id,
						'date'               => $startDate,
					)
				);
				return $candidate_id;
			}
		}

		return null;
	}

	/**
	 * Find event by date and fuzzy title without venue constraint
	 *
	 * Last-resort deduplication for cross-source imports where the same event
	 * appears with different venue names (e.g. "Come and Take It Live" vs
	 * "Come and Take It Productions") or where one source omits the venue entirely.
	 *
	 * Queries all events on a given date and compares titles using core extraction.
	 * More permissive than venue-scoped fuzzy matching — only fires as a final
	 * fallback after ticket URL, venue+fuzzy, and exact title strategies all fail.
	 *
	 * When both the incoming event and a candidate have venue data, venue matching
	 * is required to prevent false positives (e.g. "Open Mic Night" at two
	 * different bars on the same date).
	 *
	 * @param string $title Event title to match
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @param string $venue Incoming venue name for confirmation (may be empty)
	 * @return int|null Post ID if fuzzy match found, null otherwise
	 */
	private function findEventByDateAndFuzzyTitle( string $title, string $startDate, string $venue = '' ): ?int {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => 20,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'meta_query'     => array(
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $startDate,
					'compare' => 'LIKE',
				),
			),
		);

		$candidates = get_posts( $args );

		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $candidate ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
				continue;
			}

			$existing_datetime = get_post_meta( $candidate->ID, EVENT_DATETIME_META_KEY, true );
			if ( ! $this->isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				continue;
			}

			// When both sides have venue data, require venue match to avoid
			// false positives on generic titles at different venues.
			if ( ! empty( $venue ) ) {
				$candidate_venues = wp_get_post_terms( $candidate->ID, 'venue', array( 'fields' => 'names' ) );
				$candidate_venue  = ( ! is_wp_error( $candidate_venues ) && ! empty( $candidate_venues ) ) ? $candidate_venues[0] : '';

				if ( ! empty( $candidate_venue ) && ! EventIdentifierGenerator::venuesMatch( $venue, $candidate_venue ) ) {
					do_action(
						'datamachine_log',
						'debug',
						'Event Upsert: Title matched but venues differ in venue-agnostic fallback',
						array(
							'incoming_title' => $title,
							'matched_title'  => $candidate->post_title,
							'incoming_venue' => $venue,
							'existing_venue' => $candidate_venue,
							'post_id'        => $candidate->ID,
						)
					);
					continue;
				}
			}

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Cross-source fuzzy match (venue-agnostic) found duplicate',
				array(
					'incoming_title' => $title,
					'matched_title'  => $candidate->post_title,
					'post_id'        => $candidate->ID,
					'date'           => $startDate,
				)
			);
			return $candidate->ID;
		}

		return null;
	}

	/**
	 * Extract event data from existing post
	 *
	 * @param int $post_id Post ID
	 * @return array Event attributes from event-details block
	 */
	private function extractEventData( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Compare existing and incoming event data
	 *
	 * @param array $existing Existing event attributes
	 * @param array $incoming Incoming event parameters
	 * @return bool True if data changed, false if identical
	 */
	private function hasDataChanged( array $existing, array $incoming ): bool {
		// Fields to compare
		$compare_fields = array(
			'startDate',
			'endDate',
			'startTime',
			'endTime',
			'venue',
			'address',
			'price',
			'ticketUrl',
			'performer',
			'performerType',
			'organizer',
			'organizerType',
			'organizerUrl',
			'eventStatus',
			'previousStartDate',
			'priceCurrency',
			'offerAvailability',
		);

		foreach ( $compare_fields as $field ) {
			$existing_value = trim( (string) ( $existing[ $field ] ?? '' ) );
			$incoming_value = trim( (string) ( $incoming[ $field ] ?? '' ) );

			if ( $existing_value !== $incoming_value ) {
				do_action(
					'datamachine_log',
					'debug',
					"Event Upsert: Field changed: {$field}",
					array(
						'existing' => $existing_value,
						'incoming' => $incoming_value,
					)
				);
				return true;
			}
		}

		// Check description (may be in inner blocks)
		$existing_description = trim( (string) ( $existing['description'] ?? '' ) );
		$incoming_description = trim( (string) ( $incoming['description'] ?? '' ) );

		if ( $existing_description !== $incoming_description ) {
			do_action( 'datamachine_log', 'debug', 'Event Upsert: Description changed' );
			return true;
		}

		return false; // No changes detected
	}

	/**
	 * Create new event post
	 *
	 * @param array $parameters Event parameters (AI-provided, already filtered at definition time)
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine snapshot helper
	 * @return int|WP_Error Post ID on success
	 */
	private function createEventPost( array $parameters, array $handler_config, EngineData $engine ): int|\WP_Error {
		$job_id      = (int) ( $parameters['job_id'] ?? 0 );
		$post_status = WordPressSettingsResolver::getPostStatus( $handler_config );
		$post_author = WordPressSettingsResolver::getPostAuthor( $handler_config );

		// Build event data: engine data takes precedence, then AI params
		$event_data = $this->buildEventData( $parameters, $handler_config, $engine );

		$post_data = array(
			'post_type'    => Event_Post_Type::POST_TYPE,
			'post_title'   => $event_data['title'],
			'post_status'  => $post_status,
			'post_author'  => $post_author,
			'post_content' => $this->generate_event_block_content( $event_data, $parameters ),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id;
		}

		$this->processEventFeaturedImage( $post_id, $handler_config, $engine );
		$this->processVenue( $post_id, $parameters, $engine );
		$this->processPromoter( $post_id, $parameters, $engine, $handler_config );

		// Map performer to artist taxonomy if not explicitly provided
		if ( empty( $parameters['artist'] ) && ! empty( $event_data['performer'] ) ) {
			$parameters['artist'] = $event_data['performer'];
		}

		$handler_config_for_tax = $handler_config;

		$handler_config_for_tax['taxonomy_venue_selection']    = 'skip';
		$handler_config_for_tax['taxonomy_promoter_selection'] = 'skip';
		$engine_data_array                                     = $engine instanceof EngineData ? $engine->all() : array();
		$this->taxonomy_handler->processTaxonomies( $post_id, $parameters, $handler_config_for_tax, $engine_data_array );

		if ( $job_id ) {
			datamachine_merge_engine_data(
				$job_id,
				array(
					'event_id'  => $post_id,
					'event_url' => get_permalink( $post_id ),
				)
			);
		}

		return $post_id;
	}

	/**
	 * Update existing event post
	 *
	 * @param int $post_id Existing post ID
	 * @param array $parameters Event parameters (AI-provided, already filtered at definition time)
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine snapshot helper
	 */
	private function updateEventPost( int $post_id, array $parameters, array $handler_config, EngineData $engine ): void {
		// Build event data: engine data takes precedence, then AI params
		$event_data = $this->buildEventData( $parameters, $handler_config, $engine );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $event_data['title'],
				'post_content' => $this->generate_event_block_content( $event_data, $parameters ),
			)
		);

		$this->processEventFeaturedImage( $post_id, $handler_config, $engine );
		$this->processVenue( $post_id, $parameters, $engine );
		$this->processPromoter( $post_id, $parameters, $engine, $handler_config );

		// Map performer to artist taxonomy if not explicitly provided
		if ( empty( $parameters['artist'] ) && ! empty( $event_data['performer'] ) ) {
			$parameters['artist'] = $event_data['performer'];
		}

		$handler_config_for_tax = $handler_config;

		$handler_config_for_tax['taxonomy_venue_selection']    = 'skip';
		$handler_config_for_tax['taxonomy_promoter_selection'] = 'skip';
		$engine_data_array                                     = $engine instanceof EngineData ? $engine->all() : array();
		$this->taxonomy_handler->processTaxonomies( $post_id, $parameters, $handler_config_for_tax, $engine_data_array );
	}

	/**
	 * Build event data by merging engine data with AI parameters.
	 *
	 * Engine data takes precedence since AI only received parameters
	 * for fields not already in engine data (filtered at definition time).
	 *
	 * @param array $parameters AI-provided parameters
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine data helper
	 * @return array Merged event data
	 */
	private function buildEventData( array $parameters, array $handler_config, EngineData $engine ): array {
		$event_data = array(
			'title'       => sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' ),
			'description' => $parameters['description'] ?? '',
		);

		// Engine data takes precedence - use schema providers as single source of truth
		$schema_fields     = EventSchemaProvider::getFieldKeys();
		$venue_fields      = VenueParameterProvider::getParameterKeys();
		$all_engine_fields = array_unique( array_merge( $schema_fields, $venue_fields ) );

		foreach ( $all_engine_fields as $field ) {
			$value = $engine->get( $field );
			if ( null !== $value && '' !== $value ) {
				$event_data[ $field ] = $value;
			}
		}

		// AI parameters fill in remaining fields
		foreach ( $schema_fields as $field ) {
			if ( ! isset( $event_data[ $field ] ) && ! empty( $parameters[ $field ] ) ) {
				if ( 'ticketUrl' === $field ) {
					$event_data[ $field ] = trim( $parameters[ $field ] );
				} else {
					$event_data[ $field ] = sanitize_text_field( $parameters[ $field ] );
				}
			}
		}

		// Handler config venue override (highest priority)
		if ( ! empty( $handler_config['venue'] ) ) {
			$event_data['venue'] = $handler_config['venue'];
		}

		// Persist datetime values from meta as system-level fallbacks
		$resolved_post_id = $engine->get( 'post_id' ) ?? $parameters['post_id'] ?? 0;
		if ( ! empty( $resolved_post_id ) ) {
			$this->hydrateStartDateFromMeta( (int) $resolved_post_id, $event_data );
			$this->hydrateEndDateFromMeta( (int) $resolved_post_id, $event_data );
		}

		return $event_data;
	}

	private function hydrateStartDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['startDate'] ) && ! empty( $event_data['startTime'] ) ) {
			return;
		}

		$start_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
		if ( empty( $start_datetime ) ) {
			return;
		}

		$date_obj = date_create( $start_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['startDate'] ) ) {
			$event_data['startDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( empty( $event_data['startTime'] ) ) {
			$event_data['startTime'] = $date_obj->format( 'H:i:s' );
		}
	}

	private function hydrateEndDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['endDate'] ) && ! empty( $event_data['endTime'] ) ) {
			return;
		}

		$end_datetime = get_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, true );
		if ( empty( $end_datetime ) ) {
			return;
		}

		$date_obj = date_create( $end_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['endDate'] ) ) {
			$event_data['endDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( empty( $event_data['endTime'] ) ) {
			$event_data['endTime'] = $date_obj->format( 'H:i:s' );
		}
	}

	/**
	 * Process venue taxonomy assignment.
	 * Engine data takes precedence over AI-provided values.
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param EngineData $engine Engine data helper
	 */
	private function processVenue( int $post_id, array $parameters, EngineData $engine ): void {
		$venue_name = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';

		if ( ! empty( $venue_name ) ) {
			// Merge engine data with AI parameters (engine takes precedence)
			$merged_params  = array_merge( $parameters, $engine->all() );
			$venue_metadata = VenueParameterProvider::extractFromParameters( $merged_params );

			$venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

			if ( $venue_result['term_id'] ) {
				Venue::assign_venue_to_event(
					$post_id,
					array(
						'venue' => $venue_result['term_id'],
					)
				);
			}
		}
	}

	/**
	 * Process promoter taxonomy assignment.
	 * Engine data takes precedence over AI-provided values.
	 * Maps to Schema.org "organizer" property.
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param EngineData $engine Engine data helper
	 * @param array $handler_config Handler configuration
	 */
	private function processPromoter( int $post_id, array $parameters, EngineData $engine, array $handler_config = array() ): void {
		$selection = $this->getPromoterSelection( $handler_config );

		if ( 'skip' === $selection ) {
			return;
		}

		if ( $this->isPromoterTermSelection( $selection ) ) {
			$this->assignConfiguredPromoter( $post_id, (int) $selection );
			return;
		}

		if ( ! $this->isPromoterAiSelection( $selection ) ) {
			return;
		}

		// Organizer field name maps to promoter taxonomy
		$promoter_name = $engine->get( 'organizer' ) ?? $parameters['organizer'] ?? '';

		if ( empty( $promoter_name ) ) {
			return;
		}

		$promoter_metadata = array(
			'url'  => $engine->get( 'organizerUrl' ) ?? $parameters['organizerUrl'] ?? '',
			'type' => $engine->get( 'organizerType' ) ?? $parameters['organizerType'] ?? 'Organization',
		);

		$promoter_result = Promoter_Taxonomy::find_or_create_promoter( $promoter_name, $promoter_metadata );

		if ( $promoter_result['term_id'] ) {
			Promoter::assign_promoter_to_event(
				$post_id,
				array(
					'promoter' => $promoter_result['term_id'],
				)
			);
		}
	}

	/**
	 * Process featured image with EngineData context and handler fallbacks.
	 */
	private function processEventFeaturedImage( int $post_id, array $handler_config, EngineData $engine ): void {
		if ( empty( $handler_config['include_images'] ) ) {
			return;
		}

		$image_path = $engine->getImagePath();

		if ( ! empty( $image_path ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $image_path, $handler_config );
		} elseif ( ! empty( $handler_config['eventImage'] ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $handler_config['eventImage'], $handler_config );
		}
	}

	/**
	 * Generate Event Details block content
	 *
	 * @param array $event_data Event data
	 * @param array $parameters Full parameters (includes engine data)
	 * @return string Block content
	 */
	private function generate_event_block_content( array $event_data, array $parameters = array() ): string {
		$block_attributes = array(
			'startDate'         => $event_data['startDate'] ?? '',
			'startTime'         => $event_data['startTime'] ?? '',
			'endDate'           => $event_data['endDate'] ?? '',
			'endTime'           => $event_data['endTime'] ?? '',
			'occurrenceDates'   => $event_data['occurrenceDates'] ?? array(),
			'venue'             => $event_data['venue'] ?? $parameters['venue'] ?? '',
			'address'           => $event_data['venueAddress'] ?? $parameters['venueAddress'] ?? '',
			'price'             => $event_data['price'] ?? '',
			'ticketUrl'         => $event_data['ticketUrl'] ?? '',

			'performer'         => $event_data['performer'] ?? '',
			'performerType'     => $event_data['performerType'] ?? 'PerformingGroup',
			'organizer'         => $event_data['organizer'] ?? '',
			'organizerType'     => $event_data['organizerType'] ?? 'Organization',
			'organizerUrl'      => $event_data['organizerUrl'] ?? '',
			'eventStatus'       => $event_data['eventStatus'] ?? 'EventScheduled',
			'previousStartDate' => $event_data['previousStartDate'] ?? '',
			'priceCurrency'     => $event_data['priceCurrency'] ?? 'USD',
			'offerAvailability' => $event_data['offerAvailability'] ?? 'InStock',

			'showVenue'         => true,
			'showPrice'         => true,
			'showTicketLink'    => true,
		);

		$block_attributes = array_filter(
			$block_attributes,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$block_attributes['showVenue']      = true;
		$block_attributes['showPrice']      = true;
		$block_attributes['showTicketLink'] = true;

		$block_json  = wp_json_encode( $block_attributes, JSON_UNESCAPED_UNICODE );
		$description = ! empty( $event_data['description'] ) ? wp_kses_post( $event_data['description'] ) : '';

		$inner_blocks = $this->generate_description_blocks( $description );

		return '<!-- wp:data-machine-events/event-details ' . $block_json . ' -->' . "\n" .
				'<div class="wp-block-data-machine-events-event-details">' .
				( $inner_blocks ? "\n" . $inner_blocks . "\n" : '' ) .
				'</div>' . "\n" .
				'<!-- /wp:data-machine-events/event-details -->';
	}

	/**
	 * Generate paragraph blocks from HTML description
	 *
	 * @param string $description HTML description content
	 * @return string InnerBlocks content with proper paragraph blocks
	 */
	private function generate_description_blocks( string $description ): string {
		if ( empty( $description ) ) {
			return '';
		}

		// Split on closing/opening p tags or double line breaks
		$paragraphs = preg_split( '/<\/p>\s*<p[^>]*>|<\/p>\s*<p>|\n\n+/', $description );

		$blocks = array();
		foreach ( $paragraphs as $para ) {
			// Strip outer p tags but keep inline formatting
			$para = preg_replace( '/^<p[^>]*>|<\/p>$/', '', trim( $para ) );
			$para = trim( $para );

			if ( ! empty( $para ) ) {
				$blocks[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . $para . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			}
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Custom taxonomy handler for venue
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param array $handler_config Handler configuration
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @return array|null Assignment result
	 */
	public function assignVenueTaxonomy( int $post_id, array $parameters, array $handler_config, $engine_context = null ): ?array {
		$engine     = $this->resolveEngineContext( $engine_context, $parameters );
		$venue_name = $parameters['venue'] ?? $engine->get( 'venue' ) ?? '';

		if ( empty( $venue_name ) ) {
			return null;
		}

		$venue_metadata = array(
			'address'     => $this->getParameterValue( $parameters, 'venueAddress' ) ?: ( $engine->get( 'venueAddress' ) ?? '' ),
			'city'        => $this->getParameterValue( $parameters, 'venueCity' ) ?: ( $engine->get( 'venueCity' ) ?? '' ),
			'state'       => $this->getParameterValue( $parameters, 'venueState' ) ?: ( $engine->get( 'venueState' ) ?? '' ),
			'zip'         => $this->getParameterValue( $parameters, 'venueZip' ) ?: ( $engine->get( 'venueZip' ) ?? '' ),
			'country'     => $this->getParameterValue( $parameters, 'venueCountry' ) ?: ( $engine->get( 'venueCountry' ) ?? '' ),
			'phone'       => $this->getParameterValue( $parameters, 'venuePhone' ) ?: ( $engine->get( 'venuePhone' ) ?? '' ),
			'website'     => $this->getParameterValue( $parameters, 'venueWebsite' ) ?: ( $engine->get( 'venueWebsite' ) ?? '' ),
			'coordinates' => $this->getParameterValue( $parameters, 'venueCoordinates' ) ?: ( $engine->get( 'venueCoordinates' ) ?? '' ),
			'capacity'    => $this->getParameterValue( $parameters, 'venueCapacity' ) ?: ( $engine->get( 'venueCapacity' ) ?? '' ),
		);

		$venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

		if ( ! empty( $venue_result['term_id'] ) ) {
			$assignment_result = Venue::assign_venue_to_event( $post_id, array( 'venue' => $venue_result['term_id'] ) );

			if ( ! empty( $assignment_result ) ) {
				return array(
					'success'   => true,
					'taxonomy'  => 'venue',
					'term_id'   => $venue_result['term_id'],
					'term_name' => $venue_name,
					'source'    => 'event_venue_handler',
				);
			}

			return array(
				'success' => false,
				'error'   => 'Failed to assign venue term',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to create or find venue',
		);
	}

	/**
	 * Custom taxonomy handler for promoter
	 * Maps Schema.org "organizer" field to promoter taxonomy
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param array $handler_config Handler configuration
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @return array|null Assignment result
	 */
	public function assignPromoterTaxonomy( int $post_id, array $parameters, array $handler_config, $engine_context = null ): ?array {
		$selection = $this->getPromoterSelection( $handler_config );

		if ( 'skip' === $selection ) {
			return null;
		}

		if ( $this->isPromoterTermSelection( $selection ) ) {
			$result = $this->assignConfiguredPromoter( $post_id, (int) $selection );
			if ( $result ) {
				return $result;
			}
			return array(
				'success' => false,
				'error'   => 'Failed to assign configured promoter',
			);
		}

		if ( ! $this->isPromoterAiSelection( $selection ) ) {
			return null;
		}

		$engine        = $this->resolveEngineContext( $engine_context, $parameters );
		$promoter_name = $parameters['organizer'] ?? $engine->get( 'organizer' ) ?? '';

		if ( empty( $promoter_name ) ) {
			return null;
		}

		$promoter_metadata = array(
			'url'  => $this->getParameterValue( $parameters, 'organizerUrl' ) ?: ( $engine->get( 'organizerUrl' ) ?? '' ),
			'type' => $this->getParameterValue( $parameters, 'organizerType' ) ?: ( $engine->get( 'organizerType' ) ?? 'Organization' ),
		);

		$promoter_result = Promoter_Taxonomy::find_or_create_promoter( $promoter_name, $promoter_metadata );

		if ( ! empty( $promoter_result['term_id'] ) ) {
			$assignment_result = Promoter::assign_promoter_to_event( $post_id, array( 'promoter' => $promoter_result['term_id'] ) );

			if ( ! empty( $assignment_result ) ) {
				return array(
					'success'   => true,
					'taxonomy'  => 'promoter',
					'term_id'   => $promoter_result['term_id'],
					'term_name' => $promoter_name,
					'source'    => 'event_promoter_handler',
				);
			}

			return array(
				'success' => false,
				'error'   => 'Failed to assign promoter term',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to create or find promoter',
		);
	}

	private function getPromoterSelection( array $handler_config ): string {
		$selection = $handler_config['taxonomy_promoter_selection'] ?? 'skip';
		if ( is_numeric( $selection ) ) {
			return (string) absint( $selection );
		}
		return $selection;
	}

	private function isPromoterTermSelection( string $selection ): bool {
		return is_numeric( $selection ) && (int) $selection > 0;
	}

	private function isPromoterAiSelection( string $selection ): bool {
		return 'ai_decides' === $selection;
	}

	private function assignConfiguredPromoter( int $post_id, int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}

		if ( ! term_exists( $term_id, 'promoter' ) ) {
			return null;
		}

		$assignment_result = Promoter::assign_promoter_to_event( $post_id, array( 'promoter' => $term_id ) );

		if ( ! empty( $assignment_result ) ) {
			$term      = get_term( $term_id, 'promoter' );
			$term_name = ( ! is_wp_error( $term ) && $term ) ? $term->name : '';

			return array(
				'success'   => true,
				'taxonomy'  => 'promoter',
				'term_id'   => $term_id,
				'term_name' => $term_name,
				'source'    => 'event_promoter_handler',
			);
		}

		return null;
	}

	/**
	 * Get parameter value (camelCase only)
	 *
	 * @param array $parameters Parameters array
	 * @param string $camelKey CamelCase parameter key
	 * @return string Parameter value or empty string
	 */
	private function getParameterValue( array $parameters, string $camelKey ): string {
		if ( ! empty( $parameters[ $camelKey ] ) ) {
			return (string) $parameters[ $camelKey ];
		}
		return '';
	}

	/**
	 * Success response wrapper
	 */
	protected function successResponse( array $data ): array {
		return array(
			'success'   => true,
			'data'      => $data,
			'tool_name' => 'data_machine_events',
		);
	}

	/**
	 * Normalize arbitrary engine context input into an EngineData instance.
	 *
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @param array $parameters Parameters array
	 * @return EngineData EngineData instance
	 */
	private function resolveEngineContext( $engine_context = null, array $parameters = array() ): EngineData {
		if ( $engine_context instanceof EngineData ) {
			return $engine_context;
		}

		$job_id = (int) ( $parameters['job_id'] ?? null );

		if ( null === $engine_context ) {
			$engine_context = $parameters['engine'] ?? ( $parameters['engine_data'] ?? array() );
		}

		if ( $engine_context instanceof EngineData ) {
			return $engine_context;
		}

		if ( ! is_array( $engine_context ) ) {
			$engine_context = is_string( $engine_context ) ? array( 'image_url' => $engine_context ) : array();
		}

		return new EngineData( $engine_context, $job_id );
	}
}
