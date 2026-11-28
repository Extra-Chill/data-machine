<?php
/**
 * Curated source-venue aliases.
 *
 * Some sources keep sending wrong venue data. Ticketmaster venue
 * `Z7r9jZa7-j`, for example, names Round Rock Amp "Round Rock Amphitheater"
 * and gives it the address of a different city venue. Once an editor
 * corrects the venue term, address and name matching can no longer connect
 * the bad payload to the corrected term, so every re-import recreates the
 * wrong venue (#878).
 *
 * An alias records "this source's venue is that term". Two alias forms exist:
 *
 * - Source identity: `<source>:<venue id>`, e.g. `ticketmaster:Z7r9jZa7-j`,
 *   for sources that expose a stable venue ID.
 * - Fingerprint: `fingerprint:<normalized name>|<street identity key>`, for
 *   sources without venue IDs, built from the exact wrong name and street
 *   the source sends.
 *
 * Aliases live as multi-value term meta on the canonical venue term. An
 * alias belongs to at most one venue. A resolved alias means the source's
 * venue data is known to be wrong, so callers must use the term's data and
 * must not merge the source data onto it.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class VenueSourceAliases {

	/** Multi-value term meta key holding a venue's source aliases. */
	public const META_KEY = '_venue_source_alias';

	/** Prefix for name + street fingerprint aliases. */
	public const FINGERPRINT_PREFIX = 'fingerprint:';

	/** Engine data / event data key carrying the source venue identity. */
	public const EVENT_FIELD = 'venueSourceIdentity';

	/**
	 * Event data keys rewritten from the canonical term, mapped to venue data keys.
	 *
	 * Timezone is deliberately absent: handlers parse source datetimes in the
	 * source timezone before canonicalization, and the event identifier is
	 * built from that same value.
	 */
	private const EVENT_FIELD_MAP = array(
		'venueAddress'      => 'address',
		'venueCity'         => 'city',
		'venueState'        => 'state',
		'venueZip'          => 'zip',
		'venueCountry'      => 'country',
		'venuePhone'        => 'phone',
		'venueWebsite'      => 'website',
		'venueTicketingUrl' => 'ticketing_url',
		'venueCoordinates'  => 'coordinates',
		'venueCapacity'     => 'capacity',
	);

	/**
	 * Build a source identity alias.
	 *
	 * @param string $source   Source slug, e.g. `ticketmaster`.
	 * @param string $venue_id Source venue ID.
	 * @return string Alias, or '' when either part is empty.
	 */
	public static function source_identity( string $source, string $venue_id ): string {
		$source   = sanitize_key( $source );
		$venue_id = trim( $venue_id );

		if ( '' === $source || '' === $venue_id ) {
			return '';
		}

		return $source . ':' . $venue_id;
	}

	/**
	 * Build a fingerprint alias from the name and street a source sends.
	 *
	 * Requires a real street component (house number + street type) so a
	 * bare name or a city-only string can never become an alias.
	 *
	 * @param string $venue_name Source venue name.
	 * @param string $address    Source street address.
	 * @return string Alias, or '' when the inputs cannot identify a place.
	 */
	public static function fingerprint( string $venue_name, string $address ): string {
		if ( ! Venue_Taxonomy::address_has_street_component( $address ) ) {
			return '';
		}

		$name   = Venue_Taxonomy::normalize_venue_name_for_matching( $venue_name );
		$street = Venue_Taxonomy::street_identity_key( $address );

		if ( '' === $name || '' === $street ) {
			return '';
		}

		return self::FINGERPRINT_PREFIX . $name . '|' . $street;
	}

	/**
	 * Normalize and validate an alias supplied by an operator.
	 *
	 * @param string $alias Raw alias.
	 * @return string|\WP_Error Normalized alias.
	 */
	public static function normalize( string $alias ): string|\WP_Error {
		$alias = trim( $alias );

		if ( ! preg_match( '/^([a-z0-9_-]+):(.+)$/', $alias, $parts ) ) {
			return new \WP_Error(
				'venue_alias_invalid',
				'A venue source alias must look like "<source>:<venue id>" or "fingerprint:<name>|<street>".',
				array( 'status' => 400 )
			);
		}

		if ( 'fingerprint' === $parts[1] ) {
			// Operators may paste a raw "name|street" pair; normalize it so it
			// matches what fingerprint() produces from a source payload.
			$pair = explode( '|', $parts[2], 2 );
			if ( 2 !== count( $pair ) ) {
				return new \WP_Error( 'venue_alias_invalid', 'A fingerprint alias must look like "fingerprint:<name>|<street address>".', array( 'status' => 400 ) );
			}
			$fingerprint = self::fingerprint( $pair[0], $pair[1] );
			if ( '' === $fingerprint ) {
				// Already-normalized fingerprints use a street identity key,
				// which no longer carries a street type. Accept them verbatim.
				return self::FINGERPRINT_PREFIX . trim( $pair[0] ) . '|' . trim( $pair[1] );
			}
			return $fingerprint;
		}

		return self::source_identity( $parts[1], $parts[2] );
	}

	/**
	 * Candidate aliases for an incoming source venue.
	 *
	 * @param string $venue_name Incoming venue name.
	 * @param array  $venue_data Incoming venue data; reads `source_identity` and `address`.
	 * @return string[]
	 */
	public static function candidates( string $venue_name, array $venue_data ): array {
		$candidates = array();

		$identity = trim( (string) ( $venue_data['source_identity'] ?? '' ) );
		if ( '' !== $identity ) {
			$candidates[] = $identity;
		}

		$fingerprint = self::fingerprint( $venue_name, (string) ( $venue_data['address'] ?? '' ) );
		if ( '' !== $fingerprint ) {
			$candidates[] = $fingerprint;
		}

		return $candidates;
	}

	/**
	 * Find the venue term owning any of the given aliases.
	 *
	 * @param string[] $aliases Aliases to look up.
	 * @return \WP_Term|null
	 */
	public static function find_term( array $aliases ): ?\WP_Term {
		$aliases = array_values( array_filter( array_map( 'strval', $aliases ), static fn( $alias ) => '' !== $alias ) );

		if ( empty( $aliases ) ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 2,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup on a small, curated key.
					array(
						'key'     => self::META_KEY,
						'value'   => $aliases,
						'compare' => 'IN',
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		if ( count( $terms ) > 1 ) {
			// add() refuses to assign one alias to two venues, so this only
			// happens when a source identity and a fingerprint point at
			// different venues. Refuse to guess.
			do_action(
				'datamachine_log',
				'error',
				'Venue source aliases resolve to more than one venue',
				array(
					'aliases'  => $aliases,
					'term_ids' => array_map( static fn( $term ) => (int) $term->term_id, $terms ),
				)
			);
			return null;
		}

		return $terms[0];
	}

	/**
	 * Resolve an incoming source venue to its aliased canonical term.
	 *
	 * @param string $venue_name Incoming venue name.
	 * @param array  $venue_data Incoming venue data.
	 * @return \WP_Term|null
	 */
	public static function resolve( string $venue_name, array $venue_data ): ?\WP_Term {
		return self::find_term( self::candidates( $venue_name, $venue_data ) );
	}

	/**
	 * Rewrite an import handler's standardized event to the aliased venue.
	 *
	 * Runs before handlers build event identity, the AI packet, and engine
	 * data, so the source's wrong venue name and address never reach the
	 * model or the stored event. Canonical fields that are empty stay empty:
	 * the source value for an aliased venue is known to be unreliable.
	 *
	 * @param array $event Standardized event using `venue` / `venue*` keys.
	 * @return array Event, rewritten when an alias resolves.
	 */
	public static function canonicalize_event( array $event ): array {
		$venue_name = trim( (string) ( $event['venue'] ?? '' ) );
		$venue_data = array(
			'source_identity' => (string) ( $event[ self::EVENT_FIELD ] ?? '' ),
			'address'         => (string) ( $event['venueAddress'] ?? '' ),
		);

		$term = self::resolve( $venue_name, $venue_data );
		if ( ! $term ) {
			return $event;
		}

		$canonical = Venue_Taxonomy::get_venue_data( (int) $term->term_id );

		$event['venue'] = $term->name;
		foreach ( self::EVENT_FIELD_MAP as $event_key => $data_key ) {
			$event[ $event_key ] = (string) ( $canonical[ $data_key ] ?? '' );
		}

		do_action(
			'datamachine_log',
			'debug',
			'Source venue canonicalized through venue source alias',
			array(
				'source_venue' => $venue_name,
				'term_id'      => (int) $term->term_id,
				'term_name'    => $term->name,
			)
		);

		return $event;
	}

	/**
	 * List a venue's aliases.
	 *
	 * @param int $term_id Venue term ID.
	 * @return string[]
	 */
	public static function get( int $term_id ): array {
		$aliases = get_term_meta( $term_id, self::META_KEY, false );

		return is_array( $aliases ) ? array_values( array_map( 'strval', $aliases ) ) : array();
	}

	/**
	 * Add an alias to a venue.
	 *
	 * Idempotent for the owning venue; an alias owned by another venue is
	 * rejected rather than moved.
	 *
	 * @param int    $term_id Venue term ID.
	 * @param string $alias   Alias.
	 * @return string|\WP_Error Normalized alias that is now on the venue.
	 */
	public static function add( int $term_id, string $alias ): string|\WP_Error {
		$term = get_term( $term_id, 'venue' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) );
		}

		$alias = self::normalize( $alias );
		if ( is_wp_error( $alias ) ) {
			return $alias;
		}

		if ( in_array( $alias, self::get( $term_id ), true ) ) {
			return $alias;
		}

		$owner = self::find_term( array( $alias ) );
		if ( $owner && (int) $owner->term_id !== $term_id ) {
			return new \WP_Error(
				'venue_alias_conflict',
				sprintf( 'Alias "%s" already belongs to venue %d (%s).', $alias, (int) $owner->term_id, $owner->name ),
				array(
					'status'  => 409,
					'term_id' => (int) $owner->term_id,
				)
			);
		}

		if ( false === add_term_meta( $term_id, self::META_KEY, $alias ) ) {
			return new \WP_Error( 'venue_alias_write_failed', 'Could not save the venue source alias.', array( 'status' => 500 ) );
		}

		return $alias;
	}

	/**
	 * Remove an alias from a venue.
	 *
	 * @param int    $term_id Venue term ID.
	 * @param string $alias   Alias.
	 * @return bool|\WP_Error True when removed, false when the venue did not have it.
	 */
	public static function remove( int $term_id, string $alias ): bool|\WP_Error {
		$term = get_term( $term_id, 'venue' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) );
		}

		$alias = self::normalize( $alias );
		if ( is_wp_error( $alias ) ) {
			return $alias;
		}

		if ( ! in_array( $alias, self::get( $term_id ), true ) ) {
			return false;
		}

		return delete_term_meta( $term_id, self::META_KEY, $alias );
	}
}
