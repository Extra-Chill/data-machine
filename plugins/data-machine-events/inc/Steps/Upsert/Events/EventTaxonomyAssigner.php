<?php
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
