<?php
// phpcs:disable Universal.Operators.DisallowShortTernary.Found -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Event Taxonomy Assigner
 *
 * Event-specific venue and promoter taxonomy assignment, extracted from
 * EventUpsert in #425. Owns the upsert-path venue/promoter assignment
 * (processVenue / processPromoter) and the custom TaxonomyHandler callbacks
 * (assignVenueTaxonomy / assignPromoterTaxonomy) registered for the generic
 * taxonomy pass. Pure refactor — no behavior change.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachine\Core\Selection\SelectionMode;
use DataMachineEvents\Steps\Upsert\Events\Venue;
use DataMachineEvents\Steps\Upsert\Events\Promoter;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\Venue_Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns venue and promoter taxonomy terms to event posts.
 *
 * Venue creation/update is delegated to Venue_Taxonomy::find_or_create_venue;
 * promoter creation/update to Promoter_Taxonomy::find_or_create_promoter.
 * The assigner itself only resolves the term and assigns it to the post.
 */
class EventTaxonomyAssigner {

	/**
	 * Process venue taxonomy assignment.
	 * Engine data takes precedence over AI-provided values.
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param EngineData $engine Engine data helper
	 * @param array $handler_config Handler configuration with taxonomy selections
	 */
	public function processVenue( int $post_id, array $parameters, EngineData $engine, array $handler_config = array() ): void {
		$venue_name = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';

		if ( empty( $venue_name ) ) {
			$venue_context = $engine->get( 'venue_context' );
			if ( is_array( $venue_context ) && ! empty( $venue_context['name'] ) ) {
				$venue_name = $venue_context['name'];
			}
		}

		if ( empty( $venue_name ) ) {
			return;
		}

		if ( $this->isVenueNameMatchingArtist( $venue_name, $handler_config ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Venue candidate matches pre-selected artist name; leaving venue unassigned',
				array(
					'post_id'     => $post_id,
					'venue_name'  => $venue_name,
					'artist_name' => $this->getPreSelectedArtistName( $handler_config ),
				)
			);
			return;
		}

		// Merge engine data with AI parameters (engine takes precedence)
		$merged_params  = array_merge( $parameters, $engine->all() );
		$venue_metadata = VenueParameterProvider::extractFromParameters( $merged_params );

		$venue_result = Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

		if ( $venue_result['term_id'] ) {
			Venue::assign_venue_to_event(
				$post_id,
				array(
					'venue' => $venue_result['term_id'],
				)
			);
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
	public function processPromoter( int $post_id, array $parameters, EngineData $engine, array $handler_config = array() ): void {
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

		if ( $this->isVenueNameMatchingArtist( $venue_name, $handler_config ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Venue candidate matches pre-selected artist name; leaving venue unassigned',
				array(
					'post_id'     => $post_id,
					'venue_name'  => $venue_name,
					'artist_name' => $this->getPreSelectedArtistName( $handler_config ),
				)
			);
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

		$venue_result = Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

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

	/**
	 * Process location taxonomy assignment for an event.
	 *
	 * City `location` pipelines fetch events within a discovery radius (e.g. a
	 * 50-mile Ticketmaster/Dice sweep) but, when location is PRE_SELECTED, the
	 * generic taxonomy pass stamps EVERY fetched event with the pipeline's
	 * single city term — so a city archive absorbs the whole surrounding metro.
	 * A Galveston-centered sweep tagged Houston, Sugar Land, Dallas, and even
	 * Reno events as "Galveston". See data-machine-events#379.
	 *
	 * This intercepts the PRE_SELECTED location path and derives the term from
	 * the event's actual venue city instead of the ingest center. When the
	 * venue city resolves to a location term, that term wins. When it cannot be
	 * resolved, the pipeline's configured term is kept as a conservative
	 * fallback so the event stays discoverable by location. AI_DECIDES and SKIP
	 * modes are left to the generic taxonomy pass (this returns false to signal
	 * "not handled here").
	 *
	 * @param int $post_id Post ID.
	 * @param array $parameters Event parameters.
	 * @param EngineData $engine Engine data helper.
	 * @param array $handler_config Handler configuration.
	 * @return bool True when location was assigned here (caller should skip the
	 *              generic pass for location); false when left to the generic pass.
	 */
	public function processLocation( int $post_id, array $parameters, EngineData $engine, array $handler_config = array() ): bool {
		$selection = (string) ( $handler_config['taxonomy_location_selection'] ?? 'skip' );

		// Only intercept PRE_SELECTED. AI_DECIDES is handled by the generic
		// taxonomy pass (the AI picks from event data); SKIP needs no work.
		if ( ! SelectionMode::isPreSelected( $selection ) ) {
			return false;
		}

		if ( ! taxonomy_exists( 'location' ) ) {
			return false;
		}

		// Resolve the pipeline-configured ingest-center term as the fallback.
		$fallback_term_id = $this->resolveLocationSelectionTermId( $selection );

		// Determine the event's actual venue city/state.
		$venue_location = $this->getVenueLocationContext( $post_id, $parameters, $engine );

		/**
		 * Resolve the event location term from the venue's city.
		 *
		 * Lets a consumer layer that owns market knowledge (e.g. suburb→market
		 * rollups like Cambridge→Boston, or state-abbreviation disambiguation)
		 * supply a richer resolver than the substrate's generic city-name match.
		 * Returning a WP_Term overrides the default resolution; returning null
		 * lets the substrate fall back to its own name match, then to the
		 * pipeline's configured term.
		 *
		 * @param \WP_Term|null $term    Resolved term so far (null by default).
		 * @param string        $city    Venue city.
		 * @param string        $state   Venue state (abbreviation or full name).
		 * @param string        $zip     Venue zip code.
		 * @param int           $post_id Event post ID.
		 */
		$resolved = apply_filters( 'data_machine_events_resolve_event_location_term', null, $venue_location['city'], $venue_location['state'], $venue_location['zip'], $post_id );

		if ( ! $resolved instanceof \WP_Term ) {
			$resolved = $this->resolveLocationTermForVenueCity( $venue_location['city'], $venue_location['state'] );
		}

		$term_id = $resolved instanceof \WP_Term ? (int) $resolved->term_id : $fallback_term_id;

		if ( $term_id <= 0 ) {
			// Neither the venue city nor the pipeline selection resolved to a
			// usable term — nothing to assign. Still report handled so the
			// generic pass does not re-stamp a dead selection value.
			return true;
		}

		$result = wp_set_object_terms( $post_id, array( $term_id ), 'location' );

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Event location assignment failed',
				array(
					'post_id' => $post_id,
					'term_id' => $term_id,
					'error'   => $result->get_error_message(),
				)
			);
		} elseif ( $resolved instanceof \WP_Term && $fallback_term_id > 0 && (int) $resolved->term_id !== $fallback_term_id ) {
			// Log when the venue city overrode the pipeline-center term — the
			// exact metro-sweep mis-tag this method exists to correct.
			do_action(
				'datamachine_log',
				'info',
				'Event location derived from venue city (overrode pipeline center)',
				array(
					'post_id'          => $post_id,
					'venue_city'       => $venue_location['city'],
					'assigned_term'    => $resolved->name,
					'pipeline_term_id' => $fallback_term_id,
				)
			);
		}

		return true;
	}

	/**
	 * Resolve a PRE_SELECTED location selection to a term ID.
	 *
	 * @param string $selection Term ID, name, or slug.
	 * @return int Term ID, or 0 when unresolvable.
	 */
	private function resolveLocationSelectionTermId( string $selection ): int {
		if ( is_numeric( $selection ) ) {
			$term = get_term( (int) $selection, 'location' );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		$term = get_term_by( 'name', $selection, 'location' );
		if ( ! $term ) {
			$term = get_term_by( 'slug', $selection, 'location' );
		}

		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Gather the venue city/state/zip for an event.
	 *
	 * Reads from the venue term attached to the post (canonical, set earlier
	 * in the upsert pass by processVenue), falling back to the engine/parameter
	 * values when the venue term carries no city meta.
	 *
	 * @param int $post_id Post ID.
	 * @param array $parameters Event parameters.
	 * @param EngineData $engine Engine data helper.
	 * @return array{city:string, state:string, zip:string}
	 */
	private function getVenueLocationContext( int $post_id, array $parameters, EngineData $engine ): array {
		$venue_terms = wp_get_object_terms( $post_id, 'venue' );

		if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
			$venue = $venue_terms[0];
			$city  = trim( (string) get_term_meta( $venue->term_id, '_venue_city', true ) );

			if ( '' !== $city ) {
				return array(
					'city'  => $city,
					'state' => trim( (string) get_term_meta( $venue->term_id, '_venue_state', true ) ),
					'zip'   => trim( (string) get_term_meta( $venue->term_id, '_venue_zip', true ) ),
				);
			}
		}

		return array(
			'city'  => trim( (string) ( $engine->get( 'venueCity' ) ?? $parameters['venueCity'] ?? '' ) ),
			'state' => trim( (string) ( $engine->get( 'venueState' ) ?? $parameters['venueState'] ?? '' ) ),
			'zip'   => trim( (string) ( $engine->get( 'venueZip' ) ?? $parameters['venueZip'] ?? '' ) ),
		);
	}

	/**
	 * Resolve a city name to a `location` taxonomy term.
	 *
	 * Generic, consumer-agnostic resolution: exact (case-insensitive) city-name
	 * match, with best-effort state disambiguation when multiple location terms
	 * share a city name (e.g. Portland, OR vs Portland, ME — location is
	 * hierarchical Country > State > City). No market mapping (suburb→city
	 * rollups like Cambridge→Boston live in the consumer layer via the
	 * data_machine_events_resolve_event_location_term filter).
	 *
	 * @param string $city  Venue city.
	 * @param string $state Venue state (abbreviation or full name).
	 * @return \WP_Term|null Resolved term, or null when unresolved.
	 */
	private function resolveLocationTermForVenueCity( string $city, string $state ): ?\WP_Term {
		$city = trim( $city );
		if ( '' === $city ) {
			return null;
		}

		$matches = $this->getLocationTermsByName()[ strtolower( $city ) ] ?? array();

		if ( 1 === count( $matches ) ) {
			return reset( $matches );
		}

		if ( count( $matches ) > 1 ) {
			return $this->disambiguateLocationByState( $matches, $state );
		}

		return null;
	}

	/**
	 * Get all location terms grouped by lowercased name (request-cached).
	 *
	 * @return array<string, array<int, \WP_Term>>
	 */
	private function getLocationTermsByName(): array {
		static $by_name = null;

		if ( null !== $by_name ) {
			return $by_name;
		}

		$by_name = array();

		if ( ! taxonomy_exists( 'location' ) ) {
			return $by_name;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $by_name;
		}

		foreach ( $terms as $term ) {
			$key = strtolower( $term->name );
			if ( ! isset( $by_name[ $key ] ) ) {
				$by_name[ $key ] = array();
			}
			$by_name[ $key ][] = $term;
		}

		return $by_name;
	}

	/**
	 * Disambiguate same-named location terms using the venue's state.
	 *
	 * Location terms are hierarchical (Country > State > City). Compares the
	 * venue's state against each candidate's parent (state-level) term name,
	 * case-insensitively. Best-effort: state-abbreviation → full-name mapping
	 * (e.g. "SC" → "South Carolina") is intentionally NOT owned by this
	 * substrate layer — consumers with locale knowledge should hook
	 * data_machine_events_resolve_event_location_term for robust disambiguation.
	 *
	 * @param array<int, \WP_Term> $matches Location terms sharing a city name.
	 * @param string               $state   Venue state.
	 * @return \WP_Term|null Matched term, or null when state doesn't resolve it.
	 */
	private function disambiguateLocationByState( array $matches, string $state ): ?\WP_Term {
		$state = trim( $state );
		if ( '' === $state ) {
			return null;
		}

		$state_lower = strtolower( $state );

		foreach ( $matches as $match ) {
			if ( $match->parent <= 0 ) {
				continue;
			}

			$parent = get_term( $match->parent, 'location' );
			if ( ! $parent instanceof \WP_Term ) {
				continue;
			}

			if ( strtolower( $parent->name ) === $state_lower ) {
				return $match;
			}
		}

		return null;
	}

	/**
	 * Resolve the pre-selected artist term name from handler config, if any.
	 *
	 * Only returns a name when `taxonomy_artist_selection` is a pre-selected
	 * value (numeric term ID, term name, or slug). AI_DECIDES or SKIP modes
	 * return null so the guardrail does not apply.
	 *
	 * @param array $handler_config Handler configuration with taxonomy selections.
	 * @return string|null Artist term name, or null if not pre-selected / not found.
	 */
	private function getPreSelectedArtistName( array $handler_config ): ?string {
		$selection = $handler_config['taxonomy_artist_selection'] ?? 'skip';

		if ( ! SelectionMode::isPreSelected( $selection ) ) {
			return null;
		}

		$term = null;
		if ( is_numeric( $selection ) ) {
			$term = get_term( (int) $selection, 'artist' );
		} else {
			$term = get_term_by( 'name', $selection, 'artist' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', $selection, 'artist' );
			}
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return $term->name;
	}

	/**
	 * Check whether a venue candidate matches the pre-selected artist name.
	 *
	 * Uses the same normalization as the venue matcher so variants like
	 * "The X" / "X" or punctuation differences are caught. When the artist
	 * is not pre-selected, this always returns false.
	 *
	 * @param string $venue_name Venue candidate name.
	 * @param array  $handler_config Handler configuration with taxonomy selections.
	 * @return bool True when the venue candidate matches the pre-selected artist.
	 */
	private function isVenueNameMatchingArtist( string $venue_name, array $handler_config ): bool {
		$artist_name = $this->getPreSelectedArtistName( $handler_config );

		if ( empty( $artist_name ) || empty( $venue_name ) ) {
			return false;
		}

		$normalized_venue  = Venue_Taxonomy::normalize_venue_name_for_matching( $venue_name );
		$normalized_artist = Venue_Taxonomy::normalize_venue_name_for_matching( $artist_name );

		if ( empty( $normalized_venue ) || empty( $normalized_artist ) ) {
			return false;
		}

		return $normalized_venue === $normalized_artist;
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
