<?php
// phpcs:disable WordPress.PHP.YodaConditions.NotYoda,Generic.CodeAnalysis.UnusedFunctionParameter.Found,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.Security.NonceVerification.Missing -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Venue Taxonomy Registration and Management
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Core\GeoNamesService;
use DataMachineEvents\Core\NominatimClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comprehensive venue taxonomy with 9 meta fields and admin UI
 */
class Venue_Taxonomy {

	public static array $meta_fields = array(
		'address'     => '_venue_address',
		'city'        => '_venue_city',
		'state'       => '_venue_state',
		'zip'         => '_venue_zip',
		'country'     => '_venue_country',
		'phone'       => '_venue_phone',
		'website'     => '_venue_website',
		'capacity'    => '_venue_capacity',
		'coordinates' => '_venue_coordinates',
		'timezone'    => '_venue_timezone',
	);

	private static $field_labels = array(
		'address'     => 'Address',
		'city'        => 'City',
		'state'       => 'State',
		'zip'         => 'Postal Code',
		'country'     => 'Country',
		'phone'       => 'Phone',
		'website'     => 'Website',
		'capacity'    => 'Capacity',
		'coordinates' => 'Coordinates',
		'timezone'    => 'Timezone',
	);

	/**
	 * US state / DC / territory postal abbreviations.
	 *
	 * Used by extract_address_from_name() as the conservative guard that
	 * decides whether a trailing comma-separated segment is really a state
	 * (and therefore the tail is an address blob) rather than two
	 * incidental capital letters that happen to appear at the end of a
	 * legitimate venue name.
	 *
	 * @var string[]
	 */
	private static $us_state_abbreviations = array(
		'AL',
		'AK',
		'AZ',
		'AR',
		'CA',
		'CO',
		'CT',
		'DE',
		'FL',
		'GA',
		'HI',
		'ID',
		'IL',
		'IN',
		'IA',
		'KS',
		'KY',
		'LA',
		'ME',
		'MD',
		'MA',
		'MI',
		'MN',
		'MS',
		'MO',
		'MT',
		'NE',
		'NV',
		'NH',
		'NJ',
		'NM',
		'NY',
		'NC',
		'ND',
		'OH',
		'OK',
		'OR',
		'PA',
		'RI',
		'SC',
		'SD',
		'TN',
		'TX',
		'UT',
		'VT',
		'VA',
		'WA',
		'WV',
		'WI',
		'WY',
		'DC',
		'PR',
		'VI',
		'GU',
		'AS',
		'MP',
	);

	/**
	 * US state / DC / territory names keyed by postal abbreviation.
	 *
	 * Import sources provide both full region names and postal abbreviations.
	 * This bounded map keeps those generic representations equivalent during
	 * venue identity comparison.
	 *
	 * @var array<string, string>
	 */
	private static $us_state_names = array(
		'AL' => 'Alabama',
		'AK' => 'Alaska',
		'AZ' => 'Arizona',
		'AR' => 'Arkansas',
		'CA' => 'California',
		'CO' => 'Colorado',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'FL' => 'Florida',
		'GA' => 'Georgia',
		'HI' => 'Hawaii',
		'ID' => 'Idaho',
		'IL' => 'Illinois',
		'IN' => 'Indiana',
		'IA' => 'Iowa',
		'KS' => 'Kansas',
		'KY' => 'Kentucky',
		'LA' => 'Louisiana',
		'ME' => 'Maine',
		'MD' => 'Maryland',
		'MA' => 'Massachusetts',
		'MI' => 'Michigan',
		'MN' => 'Minnesota',
		'MS' => 'Mississippi',
		'MO' => 'Missouri',
		'MT' => 'Montana',
		'NE' => 'Nebraska',
		'NV' => 'Nevada',
		'NH' => 'New Hampshire',
		'NJ' => 'New Jersey',
		'NM' => 'New Mexico',
		'NY' => 'New York',
		'NC' => 'North Carolina',
		'ND' => 'North Dakota',
		'OH' => 'Ohio',
		'OK' => 'Oklahoma',
		'OR' => 'Oregon',
		'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',
		'SC' => 'South Carolina',
		'SD' => 'South Dakota',
		'TN' => 'Tennessee',
		'TX' => 'Texas',
		'UT' => 'Utah',
		'VT' => 'Vermont',
		'VA' => 'Virginia',
		'WA' => 'Washington',
		'WV' => 'West Virginia',
		'WI' => 'Wisconsin',
		'WY' => 'Wyoming',
		'DC' => 'District of Columbia',
		'PR' => 'Puerto Rico',
		'VI' => 'U.S. Virgin Islands',
		'GU' => 'Guam',
		'AS' => 'American Samoa',
		'MP' => 'Northern Mariana Islands',
	);

	public static function register() {
		self::register_venue_taxonomy();

		self::init_admin_hooks();
	}

	private static function register_venue_taxonomy() {
		if ( taxonomy_exists( 'venue' ) ) {
			register_taxonomy_for_object_type( 'venue', Event_Post_Type::POST_TYPE );
		} else {
			register_taxonomy(
				'venue',
				array( 'post', Event_Post_Type::POST_TYPE ),
				array(
					'hierarchical'      => false,
					'labels'            => array(
						'name'          => _x( 'Venues', 'taxonomy general name', 'data-machine-events' ),
						'singular_name' => _x( 'Venue', 'taxonomy singular name', 'data-machine-events' ),
						'search_items'  => __( 'Search Venues', 'data-machine-events' ),
						'all_items'     => __( 'All Venues', 'data-machine-events' ),
						'edit_item'     => __( 'Edit Venue', 'data-machine-events' ),
						'update_item'   => __( 'Update Venue', 'data-machine-events' ),
						'add_new_item'  => __( 'Add New Venue', 'data-machine-events' ),
						'new_item_name' => __( 'New Venue Name', 'data-machine-events' ),
						'menu_name'     => __( 'Venues', 'data-machine-events' ),
					),
					'show_ui'           => true,
					'show_admin_column' => true,
					'query_var'         => true,
					'rewrite'           => array( 'slug' => 'venue' ),
					'show_in_rest'      => true,
				)
			);
		}

		register_taxonomy_for_object_type( 'venue', Event_Post_Type::POST_TYPE );
	}

	/**
	 * Find or create a venue with given name and metadata
	 *
	 * Matching cascade:
	 * 0. Address-in-name extraction (strips a trailing "street, city, ST zip"
	 *    blob baked into the name — common with AI-extracted venue strings —
	 *    and routes it into $venue_data BEFORE matching runs, so step 1 below
	 *    can resolve it against the canonical venue by address)
	 * 1. Address-based matching (normalized street + city comparison, qualified
	 *    by state/country when both the incoming and stored venue supply them)
	 * 2. Exact name match, qualified by supplied geographic evidence
	 * 3. "The" prefix toggle ("The Royal American" ↔ "Royal American"),
	 *    qualified by supplied geographic evidence
	 * 4. Normalized name matching (strips punctuation, dashes, case, articles),
	 *    qualified by supplied geographic evidence
	 *    Catches: "Saturn - Birmingham" = "Saturn Birmingham",
	 *             "Reggie's Rock Club" = "Reggies Rock Club",
	 *             "RADIO/EAST" = "Radio East"
	 *
	 * @param string $venue_name Venue name
	 * @param array $venue_data Venue metadata (address, city, state, etc.)
	 * @return array Array with keys: term_id, was_created, and match_status.
	 */
	public static function find_or_create_venue( $venue_name, $venue_data = array() ) {
		// Strip a trailing address blob baked into the venue name (AI
		// extraction sometimes returns "Venue Name, street, city, ST zip"
		// as a single string). Must run first: it both cleans the name used
		// for matching/creation below AND fills $venue_data so the
		// address-based match immediately below can use it. See #433.
		$extracted_address = self::extract_address_from_name( $venue_name );
		if ( ! empty( $extracted_address ) ) {
			$venue_name = $extracted_address['name'];
			foreach ( array( 'address', 'city', 'state', 'zip' ) as $field ) {
				if ( ! empty( $extracted_address[ $field ] ) && empty( $venue_data[ $field ] ) ) {
					$venue_data[ $field ] = $extracted_address[ $field ];
				}
			}
		}

		$identity   = self::resolve_venue_identity( $venue_name, $venue_data );
		$venue_name = $identity['venue_name'];

		if ( $identity['term'] ) {
			$term_id = $identity['term_id'];

			if ( ! empty( $venue_data ) ) {
				self::smart_merge_venue_meta( $term_id, $venue_data );
			}

			return array(
				'term_id'      => $term_id,
				'was_created'  => false,
				'match_status' => 'matched',
			);
		}

		if ( 'ambiguous' === $identity['match_status'] ) {
			do_action(
				'datamachine_log',
				'warning',
				'Venue name match rejected due to ambiguous or conflicting geographic evidence',
				array(
					'venue_name' => $venue_name,
					'venue_data' => $venue_data,
				)
			);

			return array(
				'term_id'      => null,
				'was_created'  => false,
				'match_status' => 'ambiguous',
			);
		}

		// Create new venue
		$result = wp_insert_term( $venue_name, 'venue' );

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create venue term',
				array(
					'venue_name' => $venue_name,
					'error'      => $result->get_error_message(),
				)
			);
			return array(
				'term_id'      => null,
				'was_created'  => false,
				'match_status' => 'error',
			);
		}

		$term_id = $result['term_id'];

		// Update all metadata for new venue
		self::update_venue_meta( $term_id, $venue_data );

		return array(
			'term_id'      => $term_id,
			'was_created'  => true,
			'match_status' => 'created',
		);
	}

	/**
	 * Resolve an existing venue without creating a term or merging metadata.
	 *
	 * This exposes the same address-first and geographically qualified name
	 * rules to duplicate detection without introducing a separate identity
	 * service or mutating the venue taxonomy during a read.
	 *
	 * @param string $venue_name Venue name.
	 * @param array  $venue_data Venue metadata.
	 * @return array{term: \WP_Term|null, term_id: int|null, match_status: string, venue_name: string}
	 */
	public static function resolve_venue_identity( string $venue_name, array $venue_data = array() ): array {
		$extracted_address = self::extract_address_from_name( $venue_name );
		if ( ! empty( $extracted_address ) ) {
			$venue_name = $extracted_address['name'];
			foreach ( array( 'address', 'city', 'state', 'zip' ) as $field ) {
				if ( ! empty( $extracted_address[ $field ] ) && empty( $venue_data[ $field ] ) ) {
					$venue_data[ $field ] = $extracted_address[ $field ];
				}
			}
		}

		$address_match = self::find_venue_by_address(
			$venue_data['address'] ?? '',
			$venue_data['city'] ?? '',
			$venue_data['state'] ?? '',
			$venue_data['country'] ?? ''
		);

		if ( $address_match ) {
			$term = get_term( $address_match, 'venue' );
			if ( $term instanceof \WP_Term ) {
				return array(
					'term'         => $term,
					'term_id'      => (int) $term->term_id,
					'match_status' => 'matched',
					'venue_name'   => $venue_name,
				);
			}
		}

		$venue_name = apply_filters( 'data_machine_events_normalize_venue_name', $venue_name );
		$name_match = self::find_venue_by_qualified_name( $venue_name, $venue_data );
		$term       = $name_match['term'];

		return array(
			'term'         => $term,
			'term_id'      => $term ? (int) $term->term_id : null,
			'match_status' => $name_match['ambiguous'] ? 'ambiguous' : ( $term ? 'matched' : 'no_match' ),
			'venue_name'   => $venue_name,
		);
	}

	/**
	 * Resolve name variants without crossing conflicting geographic evidence.
	 *
	 * Exact, article-toggle, and normalized matches retain their precedence. A
	 * tier with one compatible candidate wins; multiple compatible candidates
	 * or candidates rejected by supplied geography produce an ambiguous result.
	 *
	 * @param string $venue_name Venue name to resolve.
	 * @param array  $venue_data Incoming venue metadata.
	 * @return array{term: \WP_Term|null, ambiguous: bool}
	 */
	private static function find_venue_by_qualified_name( string $venue_name, array $venue_data ): array {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return array(
				'term'      => null,
				'ambiguous' => false,
			);
		}

		$alt_name              = 0 === stripos( $venue_name, 'The ' ) ? substr( $venue_name, 4 ) : 'The ' . $venue_name;
		$normalized_name       = self::normalize_venue_name_for_matching( $venue_name );
		$normalized_candidates = strlen( $normalized_name ) < 3
			? array()
			: array_filter(
				$venues,
				static fn( $venue ) => $normalized_name === self::normalize_venue_name_for_matching( $venue->name )
			);
		$tiers                 = array(
			array_filter(
				$venues,
				static fn( $venue ) => 0 === strcasecmp( trim( $venue->name ), trim( $venue_name ) )
			),
			array_filter(
				$venues,
				static fn( $venue ) => 0 === strcasecmp( trim( $venue->name ), trim( $alt_name ) )
			),
			$normalized_candidates,
		);
		$had_candidates        = false;

		foreach ( $tiers as $candidates ) {
			if ( empty( $candidates ) ) {
				continue;
			}

			$had_candidates = true;
			$compatible     = array_values(
				array_filter(
					$candidates,
					static fn( $venue ) => ! self::has_geographic_conflict( $venue->term_id, $venue_data )
				)
			);

			if ( 1 === count( $compatible ) ) {
				return array(
					'term'      => $compatible[0],
					'ambiguous' => false,
				);
			}

			if ( count( $compatible ) > 1 ) {
				return array(
					'term'      => null,
					'ambiguous' => true,
				);
			}
		}

		return array(
			'term'      => null,
			'ambiguous' => $had_candidates,
		);
	}

	/**
	 * Determine whether supplied geography contradicts stored venue evidence.
	 *
	 * Missing values on either side are incomplete evidence, not a conflict.
	 *
	 * @param int   $term_id    Venue term ID.
	 * @param array $venue_data Incoming venue metadata.
	 * @return bool
	 */
	private static function has_geographic_conflict( int $term_id, array $venue_data ): bool {
		foreach ( array( 'address', 'city', 'state', 'country' ) as $field ) {
			$incoming = $venue_data[ $field ] ?? '';
			$stored   = get_term_meta( $term_id, self::$meta_fields[ $field ], true );

			if ( '' === trim( (string) $incoming ) || '' === trim( (string) $stored ) ) {
				continue;
			}

			$incoming = 'address' === $field
				? self::normalize_address_for_matching( (string) $incoming )
				: self::normalize_geographic_value( (string) $incoming, $field );
			$stored   = 'address' === $field
				? self::normalize_address_for_matching( (string) $stored )
				: self::normalize_geographic_value( (string) $stored, $field );

			if ( $incoming !== $stored ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a city, state, or country value for identity comparison.
	 *
	 * @param string $value Geographic value.
	 * @param string $field Geographic field name.
	 * @return string
	 */
	private static function normalize_geographic_value( string $value, string $field ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = strtolower( remove_accents( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		$us_country_aliases = array( 'us', 'u s', 'usa', 'u s a', 'united states', 'united states of america' );
		if ( 'country' === $field && in_array( $value, $us_country_aliases, true ) ) {
			return 'us';
		}

		if ( 'state' === $field ) {
			foreach ( self::$us_state_names as $abbreviation => $state_name ) {
				$normalized_state_name = self::normalize_geographic_value( $state_name, '' );
				if ( strtolower( $abbreviation ) === $value || $normalized_state_name === $value ) {
					return strtolower( $abbreviation );
				}
			}
		}

		return $value;
	}

	/**
	 * Detect and strip a trailing address blob baked into a venue name.
	 *
	 * AI-driven venue extraction (AI_DECIDES resolution) sometimes returns
	 * the venue name and its address concatenated into ONE string, comma
	 * separated, e.g.:
	 *
	 *   "The Dinghy , 8 J C Long Blvd, Isle of Palms, SC 29451"
	 *   "Blind Tiger Pub, 36-38 Broad St, Charleston, SC 29403"
	 *   "Lake Oconee , Greensboro, GA"
	 *
	 * Left alone, this becomes the taxonomy term NAME, which (a) produces
	 * ugly terms and (b) defeats find_or_create_venue()'s dedup: the SAME
	 * venue splits into a clean term and an address-suffixed term depending
	 * on whether the AI happened to include the address that run. See #433.
	 *
	 * Heuristic (deliberately conservative — false negatives are cheap,
	 * false positives truncate a real venue name):
	 *
	 * 1. Split the string on commas.
	 * 2. A single-segment name (no commas) never matches — nothing to strip.
	 * 3. The LAST segment must look like "<City>, <ST> <zip?>" or a bare
	 *    "<ST> <zip>" / "<ST>" — i.e. it must end in a two-letter token that
	 *    is a real US state/territory abbreviation (see
	 *    self::$us_state_abbreviations), optionally followed by a 5 or
	 *    5+4 digit ZIP. This is the load-bearing guard: legitimate venue
	 *    names with commas ("Bar, Restaurant & Grill") never end in a state
	 *    abbreviation, so they never match and are never truncated.
	 * 4. Once the state-bearing tail segment is confirmed, everything from
	 *    the SECOND segment onward is treated as the address blob (segment
	 *    1 is the clean venue name). Within that blob, the last segment is
	 *    parsed for a "<city>? <ST> <zip?>" pattern — if it carries its own
	 *    leading city text (e.g. one comma-separated segment holds
	 *    "Isle of Palms SC 29451"), that leading text becomes the city;
	 *    otherwise the city is pulled from the next-to-last segment (e.g.
	 *    when city and state are each their own comma segment). Anything
	 *    remaining before the city is the street.
	 *
	 * Examples walked through the heuristic:
	 * - "The Dinghy , 8 J C Long Blvd, Isle of Palms, SC 29451"
	 *   → segments: ["The Dinghy", "8 J C Long Blvd", "Isle of Palms", "SC 29451"]
	 *   → last segment "SC 29451" ends in state+zip, no leading city text → match.
	 *   → name = "The Dinghy", street = "8 J C Long Blvd", city = "Isle of Palms",
	 *     state = "SC", zip = "29451".
	 * - "Lake Oconee , Greensboro, GA"
	 *   → segments: ["Lake Oconee", "Greensboro", "GA"]
	 *   → last segment "GA" is a bare state → match.
	 *   → name = "Lake Oconee", street = "" (only 2 tail segments: city + state),
	 *     city = "Greensboro", state = "GA".
	 * - "Bar, Restaurant & Grill"
	 *   → segments: ["Bar", "Restaurant & Grill"]
	 *   → last segment "Restaurant & Grill" does not end in a state
	 *     abbreviation → no match, name returned unchanged (empty array).
	 * - "The Dinghy"
	 *   → no commas → no match, name returned unchanged (empty array).
	 *
	 * @param string $venue_name Raw venue name, possibly with an address baked in.
	 * @return array{name: string, address: string, city: string, state: string, zip: string}|array{}
	 *         Empty array when no address tail was detected (name should be used as-is).
	 */
	public static function extract_address_from_name( string $venue_name ): array {
		if ( false === strpos( $venue_name, ',' ) ) {
			return array();
		}

		$segments = array_map( 'trim', explode( ',', $venue_name ) );
		$segments = array_values( array_filter( $segments, static fn( $segment ) => '' !== $segment ) );

		// Need at least "name" + one address segment to have anything to strip.
		if ( count( $segments ) < 2 ) {
			return array();
		}

		$last = end( $segments );

		// The tail segment must end in a real state/territory abbreviation,
		// optionally preceded by leading city text and optionally followed
		// by a ZIP (5 or ZIP+4). This is what distinguishes an address blob
		// from an ordinary comma-containing venue name (e.g.
		// "Restaurant & Grill" never matches this pattern).
		if ( ! preg_match(
			'/^(?<city>.*?)\s*\b(?<state>[A-Z]{2})\s*(?<zip>\d{5}(?:-\d{4})?)?$/',
			strtoupper( $last ),
			$tail_matches
		) ) {
			return array();
		}

		$state = $tail_matches['state'];
		$zip   = $tail_matches['zip'] ?? '';

		if ( ! in_array( $state, self::$us_state_abbreviations, true ) ) {
			return array();
		}

		// Confirmed: everything from the second segment onward is the
		// address blob. First segment is the clean venue name.
		$name         = array_shift( $segments );
		$address_tail = $segments; // Remaining segments, ending in the state(+zip) segment just parsed.

		// Drop the state(+zip) segment — already parsed above.
		array_pop( $address_tail );

		// Recover the original (non-uppercased) leading city text from
		// inside the last segment, if any (e.g. "Isle of Palms SC 29451").
		$city_in_last_segment = trim( mb_substr( $last, 0, mb_strlen( trim( $tail_matches['city'] ) ) ) );

		if ( '' !== $city_in_last_segment ) {
			// City was embedded in the same segment as the state/zip.
			$city   = $city_in_last_segment;
			$street = implode( ', ', $address_tail );
		} else {
			// City is its own comma-separated segment (or absent).
			$city   = ! empty( $address_tail ) ? array_pop( $address_tail ) : '';
			$street = implode( ', ', $address_tail );
		}

		if ( '' === $name ) {
			return array();
		}

		return array(
			'name'    => $name,
			'address' => $street,
			'city'    => $city,
			'state'   => $state,
			'zip'     => $zip,
		);
	}

	/**
	 * Find an existing venue by normalized name comparison.
	 *
	 * Normalizes both the input name and all existing venue names by:
	 * - Decoding HTML entities
	 * - Lowercasing
	 * - Removing articles ("the", "a", "an")
	 * - Stripping all non-alphanumeric characters (punctuation, dashes, apostrophes)
	 * - Collapsing whitespace
	 *
	 * This catches variants that exact name matching misses:
	 * - "Saturn - Birmingham" vs "Saturn Birmingham"
	 * - "Reggie's Rock Club" vs "Reggies Rock Club"
	 * - "RADIO/EAST" vs "Radio East"
	 * - "Emo's-Austin" vs "Emo's Austin"
	 * - "Lo-Fi Brewing" vs "Lofi Brewing"
	 *
	 * Requires the normalized name to be at least 3 characters to avoid
	 * false matches on very short names.
	 *
	 * @param string $venue_name Venue name to search for.
	 * @return \WP_Term|null Matching term or null.
	 */
	private static function find_venue_by_normalized_name( string $venue_name ): ?\WP_Term {
		$normalized_input = self::normalize_venue_name_for_matching( $venue_name );

		if ( strlen( $normalized_input ) < 3 ) {
			return null;
		}

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return null;
		}

		foreach ( $venues as $venue ) {
			$normalized_existing = self::normalize_venue_name_for_matching( $venue->name );

			if ( $normalized_input === $normalized_existing ) {
				do_action(
					'datamachine_log',
					'info',
					'Venue matched via normalized name',
					array(
						'input_name'    => $venue_name,
						'matched_name'  => $venue->name,
						'matched_id'    => $venue->term_id,
						'normalized_as' => $normalized_input,
					)
				);
				return $venue;
			}
		}

		return null;
	}

	/**
	 * Normalize a venue name for matching purposes.
	 *
	 * Delegates to Data Machine core's canonical, taxonomy-agnostic
	 * name normalizer (`ResolveTermAbility::normalize_name_for_matching`) so
	 * venue dedup shares one source of truth with every other taxonomy's
	 * fuzzy matching. Falls back to an inline copy of the same algorithm when
	 * core isn't loaded, so this class carries no hard version dependency.
	 *
	 * @param string $name Venue name.
	 * @return string Normalized name.
	 */
	public static function normalize_venue_name_for_matching( string $name ): string {
		if ( class_exists( '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility' )
			&& method_exists( '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility', 'normalize_name_for_matching' )
		) {
			return \DataMachine\Abilities\Taxonomy\ResolveTermAbility::normalize_name_for_matching( $name );
		}

		// Fallback: inline copy of the canonical algorithm (core not loaded).
		$text = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = remove_accents( $text );
		$text = strtolower( $text );
		$text = preg_replace( '/\s*&\s*/', ' and ', $text );
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );
		$text = preg_replace( '/[^a-z0-9\s]/', '', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}

	/**
	 * Public accessor for normalized name venue lookup.
	 *
	 * Used by EventDuplicateStrategy to resolve venue names that differ
	 * in punctuation, dashes, or case from the stored term name.
	 *
	 * @param string $venue_name Venue name to search for.
	 * @return \WP_Term|null Matching term or null.
	 */
	public static function find_venue_by_normalized_name_public( string $venue_name ): ?\WP_Term {
		return self::find_venue_by_normalized_name( $venue_name );
	}

	/**
	 * Public accessor for address-based venue lookup.
	 *
	 * Used by EventDuplicateStrategy to resolve an incoming venue to a
	 * canonical taxonomy term when the venue string differs from the
	 * stored term name but the address matches. This mirrors the
	 * address-first cascade used by find_or_create_venue().
	 *
	 * Returns the resolved \WP_Term (rather than just the term ID) so
	 * callers can use it the same way as find_venue_by_normalized_name_public().
	 *
	 * @param string $address Street address.
	 * @param string $city    City name.
	 * @return \WP_Term|null Matching term or null.
	 */
	public static function find_venue_by_address_public( string $address, string $city ): ?\WP_Term {
		$term_id = self::find_venue_by_address( $address, $city );

		if ( ! $term_id ) {
			return null;
		}

		$term = get_term( $term_id, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return $term;
	}

	/**
	 * Smartly merge new venue data into existing venue
	 * Only updates fields that are currently empty in the database
	 *
	 * Uses the canonical venue mutation contract's fill-empty strategy so the
	 * decision is rechecked after its per-venue lock is acquired.
	 *
	 * @param int $term_id Venue term ID
	 * @param array $venue_data New venue data
	 */
	private static function smart_merge_venue_meta( $term_id, $venue_data ) {
		$result = VenueProfileMutations::updateSystem( (int) $term_id, $venue_data, VenueProfileMutations::STRATEGY_FILL_EMPTY );

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			return;
		}
	}

	/**
	 * Update venue term meta with venue data
	 *
	 * Supports selective updates - only updates fields present in $venue_data array.
	 * This allows updating only changed fields without overwriting unchanged ones.
	 * Automatically geocodes address to coordinates if address fields are updated.
	 *
	 * Uses the canonical venue mutation contract's overwrite strategy, including
	 * atomic clear-and-rederive behavior for location fields.
	 *
	 * @param int $term_id Venue term ID
	 * @param array $venue_data Venue data array (can contain subset of fields)
	 * @return bool Success status
	 */
	public static function update_venue_meta( $term_id, $venue_data ) {
		if ( ! $term_id || ! is_array( $venue_data ) ) {
			return false;
		}

		$result = VenueProfileMutations::updateSystem( (int) $term_id, $venue_data );

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Geocode venue address if coordinates are missing
	 *
	 * @param int $term_id Venue term ID
	 * @return bool True if geocoding was performed, false otherwise
	 */
	public static function maybe_geocode_venue( $term_id ) {
		if ( ! $term_id ) {
			return false;
		}

		$existing_coords = get_term_meta( $term_id, '_venue_coordinates', true );
		if ( ! empty( $existing_coords ) ) {
			self::maybe_derive_timezone( $term_id, $existing_coords );
			return false;
		}

		$venue_data  = self::get_venue_data( $term_id );
		$coordinates = self::geocode_address( $venue_data );

		if ( $coordinates ) {
			$result = VenueProfileMutations::updateSystem( (int) $term_id, array( 'coordinates' => $coordinates ) );
			return ! is_wp_error( $result ) && ! empty( $result['success'] );
		}

		return false;
	}

	/**
	 * Derive timezone from coordinates if timezone is missing
	 *
	 * Uses GeoNames API to lookup IANA timezone from lat/lng coordinates.
	 * Only runs if GeoNames username is configured in settings.
	 *
	 * @param int $term_id Venue term ID
	 * @param string $coordinates Coordinates as "lat,lng"
	 * @return bool True if timezone was derived and saved, false otherwise
	 */
	public static function maybe_derive_timezone( $term_id, $coordinates = '' ) {
		if ( ! $term_id ) {
			return false;
		}

		$existing_timezone = get_term_meta( $term_id, '_venue_timezone', true );
		if ( ! empty( $existing_timezone ) ) {
			return false;
		}

		if ( empty( $coordinates ) ) {
			$coordinates = get_term_meta( $term_id, '_venue_coordinates', true );
		}

		if ( empty( $coordinates ) ) {
			return false;
		}

		if ( ! GeoNamesService::isConfigured() ) {
			return false;
		}

		$timezone = GeoNamesService::getTimezoneFromCoordinates( $coordinates );

		if ( $timezone ) {
			$result = VenueProfileMutations::updateSystem( (int) $term_id, array( 'timezone' => $timezone ) );
			return ! is_wp_error( $result ) && ! empty( $result['success'] );
		}

		return false;
	}

	/**
	 * Geocode an address using Nominatim API
	 *
	 * Tries multiple query strategies to handle messy address data from imports.
	 * Falls back to venue name + city if structured address queries fail.
	 *
	 * @param array $venue_data Venue data with address fields
	 * @return string|null Coordinates as "lat,lng" or null on failure
	 */
	public static function geocode_address( $venue_data ) {
		$queries = self::build_geocode_queries( $venue_data );

		if ( empty( $queries ) ) {
			return null;
		}

		foreach ( $queries as $strategy => $query ) {
			$coordinates = self::query_nominatim( $query );

			if ( $coordinates ) {
				do_action(
					'datamachine_log',
					'info',
					'Geocoding succeeded',
					array(
						'strategy' => $strategy,
						'query'    => $query,
						'result'   => $coordinates,
						'name'     => $venue_data['name'] ?? '',
					)
				);
				return $coordinates;
			}
		}

		do_action(
			'datamachine_log',
			'warning',
			'Geocoding failed all strategies',
			array(
				'queries' => $queries,
				'name'    => $venue_data['name'] ?? '',
			)
		);

		return null;
	}

	/**
	 * Build ordered list of geocoding query strategies
	 *
	 * Handles common import data issues:
	 * - Address field containing full address with city/state/zip already embedded
	 * - Suite/unit numbers that confuse Nominatim
	 * - HTML entities in venue names
	 * - Written-out numbers (e.g., "One Lincoln Financial Way")
	 *
	 * @param array $venue_data Venue data with address fields
	 * @return array Ordered queries keyed by strategy name
	 */
	private static function build_geocode_queries( array $venue_data ): array {
		$address = html_entity_decode( trim( $venue_data['address'] ?? '' ) );
		$city    = html_entity_decode( trim( $venue_data['city'] ?? '' ) );
		$state   = trim( $venue_data['state'] ?? '' );
		$zip     = trim( $venue_data['zip'] ?? '' );
		$country = trim( $venue_data['country'] ?? '' );
		$name    = html_entity_decode( trim( $venue_data['name'] ?? '' ) );

		$queries = array();

		if ( empty( $address ) && empty( $city ) && empty( $name ) ) {
			return $queries;
		}

		// Detect if address already contains city/state/zip (common with imports)
		$address_has_city = ! empty( $city ) && false !== stripos( $address, $city );

		// Clean the street portion of the address
		$street = $address;

		if ( $address_has_city ) {
			// Extract just the street part before the city
			$street = self::extract_street_from_address( $address, $city );
		}

		// Strip suite/unit/apartment suffixes that confuse Nominatim
		$street = preg_replace( '/,?\s*(?:Suite|Ste|Unit|Apt|#|Room|Rm)\s*[\w\-#]+.*/i', '', $street );
		// Strip "Located in: ..." annotations
		$street = preg_replace( '/,?\s*Located in:.*$/i', '', $street );
		$street = trim( $street, ', ' );

		// Strategy 1: Cleaned street + city + state + zip
		if ( ! empty( $street ) && ! empty( $city ) ) {
			$parts = array_filter( array( $street, $city, $state, $zip ) );
			$query = implode( ', ', $parts );
			if ( ! empty( $query ) ) {
				$queries['cleaned_address'] = $query;
			}
		}

		// Strategy 2: Street + city + state (no zip — zip sometimes causes false negatives)
		if ( ! empty( $street ) && ! empty( $city ) && ! empty( $state ) ) {
			$query = implode( ', ', array( $street, $city, $state ) );
			if ( ! isset( $queries['cleaned_address'] ) || $query !== $queries['cleaned_address'] ) {
				$queries['no_zip'] = $query;
			}
		}

		// Strategy 3: Venue name + city + state (Nominatim indexes many POIs by name)
		if ( ! empty( $name ) && ! empty( $city ) ) {
			$parts                  = array_filter( array( $name, $city, $state ) );
			$queries['name_lookup'] = implode( ', ', $parts );
		}

		// Strategy 4: Raw address field as-is (in case it's already a complete, well-formatted address).
		//
		// Guard against coordinate roulette: a bare street with no embedded
		// city/state (e.g. "500 College Drive") must NOT be geocoded alone when
		// we have separate city meta to build a contextual query — Nominatim
		// will happily match the street number to an unrelated city (the real
		// "500 College Drive" resolves to Reno, NV, mis-placing a Lake Jackson,
		// TX venue 1,500+ miles away). See data-machine-events#379.
		//
		// Only fall back to the raw address when it carries its own context
		// (contains a comma, i.e. multi-part) OR when there is no city meta to
		// produce a better-scoped query in strategies 1-3.
		$raw_has_context = false !== strpos( $address, ',' );
		if (
			! empty( $address )
			&& $address !== ( $queries['cleaned_address'] ?? '' )
			&& ( $raw_has_context || empty( $city ) )
		) {
			$queries['raw_address'] = html_entity_decode( $address );
		}

		return $queries;
	}

	/**
	 * Extract just the street address from a full address string that contains city info
	 *
	 * @param string $address Full address string
	 * @param string $city City name to find
	 * @return string Street portion of the address
	 */
	private static function extract_street_from_address( string $address, string $city ): string {
		$parts        = preg_split( '/,\s*/', $address );
		$street_parts = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			// Stop if this part contains the city name
			if ( false !== stripos( $part, $city ) ) {
				break;
			}

			// Stop if this part looks like a state abbreviation
			if ( preg_match( '/^[A-Z]{2}$/', $part ) ) {
				break;
			}

			// Stop if this part looks like a zip code
			if ( preg_match( '/^\d{5}(-\d{4})?$/', $part ) ) {
				break;
			}

			// Stop if this part is a country
			if ( in_array( strtoupper( $part ), array( 'US', 'USA', 'UNITED STATES' ), true ) ) {
				break;
			}

			$street_parts[] = $part;
		}

		return ! empty( $street_parts ) ? implode( ', ', $street_parts ) : $address;
	}

	/**
	 * Query Nominatim API for coordinates.
	 *
	 * @deprecated 0.40.0 Use {@see NominatimClient::geocodeOne()} which
	 *             returns a richer array (`lat` / `lng` / `display_name` /
	 *             `cached`) instead of the legacy comma-joined string.
	 *             This method is preserved as a back-compat shim because
	 *             external consumers may still depend on the
	 *             `"lat,lng"` return contract.
	 *
	 * @param string $query Search query string.
	 * @return string|null Coordinates as "lat,lng" or null on failure.
	 */
	public static function query_nominatim( string $query ): ?string {
		if ( empty( $query ) ) {
			return null;
		}

		$result = NominatimClient::geocodeOne( $query );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();

			// 'geocode_failed' is the no-results case — legacy contract
			// surfaced that as a silent null. Other errors (transport,
			// invalid response) keep the original log line.
			if ( 'geocode_failed' !== $error_code ) {
				do_action(
					'datamachine_log',
					'error',
					'Geocoding request failed',
					array(
						'error' => $result->get_error_message(),
						'query' => $query,
					)
				);
			}

			return null;
		}

		if ( empty( $result['lat'] ) || empty( $result['lng'] ) ) {
			return null;
		}

		return $result['lat'] . ',' . $result['lng'];
	}

	/**
	 * Normalize address string for consistent comparison
	 *
	 * Strips suite/unit/apartment suffixes BEFORE running the
	 * street-suffix replacements so "3010 Minnehaha Ave STE 420"
	 * and "3010 Minnehaha Ave" collapse to the same key.
	 *
	 * @param string $address Raw address string
	 * @return string Normalized address for matching
	 */
	public static function normalize_address_for_matching( string $address ): string {
		$address = strtolower( trim( $address ) );

		// Strip suite/unit/apartment/room suffixes that produce false
		// non-matches for the same physical street address. Runs first
		// so the downstream replacements operate on the cleaned street.
		// Two passes:
		//   1. Word-prefixed suffix tokens (ste, suite, unit, apt, …) — \b works.
		//   2. Bare `#NNN` style — handled separately because `#` is not a
		//      word character and \b would not anchor it.
		$address = preg_replace(
			'/\b(ste|suite|unit|apt|apartment|room|rm)\s*[a-z0-9\-]+\b/i',
			'',
			$address
		);
		$address = preg_replace( '/#\s*[a-z0-9\-]+/i', '', $address );

		$replacements = array(
			'/\bstreet\b/'    => 'st',
			'/\bavenue\b/'    => 'ave',
			'/\bboulevard\b/' => 'blvd',
			'/\bdrive\b/'     => 'dr',
			'/\broad\b/'      => 'rd',
			'/\blane\b/'      => 'ln',
			'/\bcourt\b/'     => 'ct',
			'/\bsuite\b/'     => 'ste',
			'/\bapartment\b/' => 'apt',
			'/\bhighway\b/'   => 'hwy',
			'/\bparkway\b/'   => 'pkwy',
			'/\bplace\b/'     => 'pl',
			'/\bcircle\b/'    => 'cir',
			'/[.,#]/'         => '',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$address = preg_replace( $pattern, $replacement, $address );
		}

		// Collapse whitespace and strip stray trailing separators
		// (suite-suffix removal can leave a dangling comma).
		$address = preg_replace( '/\s+/', ' ', trim( $address ) );
		$address = trim( $address, ' ,-' );

		return $address;
	}

	/**
	 * Find existing venue by address and geographic context.
	 *
	 * @param string $address Street address.
	 * @param string $city City name.
	 * @param string $state State or region, when supplied.
	 * @param string $country Country, when supplied.
	 * @return int|null Term ID if found, null otherwise
	 */
	public static function find_venue_by_address(
		string $address,
		string $city,
		string $state = '',
		string $country = ''
	): ?int {
		if ( empty( $address ) || empty( $city ) ) {
			return null;
		}

		$normalized_address = self::normalize_address_for_matching( $address );
		$normalized_city    = strtolower( trim( $city ) );

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'     => '_venue_city',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return null;
		}

		$matches = array();

		foreach ( $venues as $venue ) {
			$venue_address = get_term_meta( $venue->term_id, '_venue_address', true );
			$venue_city    = get_term_meta( $venue->term_id, '_venue_city', true );

			if ( empty( $venue_address ) || empty( $venue_city ) ) {
				continue;
			}

			$venue_normalized_address = self::normalize_address_for_matching( $venue_address );
			$venue_normalized_city    = strtolower( trim( $venue_city ) );

			if ( $venue_normalized_address === $normalized_address &&
				$venue_normalized_city === $normalized_city &&
				! self::has_geographic_conflict(
					$venue->term_id,
					array(
						'address' => $address,
						'city'    => $city,
						'state'   => $state,
						'country' => $country,
					)
				) ) {
				$matches[] = $venue->term_id;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * Check if venue has any metadata populated
	 *
	 * @param int $term_id Venue term ID
	 * @return bool True if venue has at least one metadata field populated
	 */
	private static function has_venue_metadata( $term_id ) {
		if ( ! $term_id ) {
			return false;
		}

		foreach ( self::$meta_fields as $data_key => $meta_key ) {
			$value = get_term_meta( $term_id, $meta_key, true );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieves complete venue data with all 9 meta fields populated
	 */
	public static function get_venue_data( $term_id ) {
		$term = get_term( $term_id, 'venue' );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		$venue_data = array(
			'name'        => $term->name,
			'term_id'     => $term_id,
			'slug'        => $term->slug,
			'description' => $term->description,
		);

		foreach ( self::$meta_fields as $data_key => $meta_key ) {
			$venue_data[ $data_key ] = get_term_meta( $term_id, $meta_key, true );
		}

		return $venue_data;
	}

	/**
	 * Generate formatted address string from venue meta fields
	 *
	 * @param int $term_id Venue term ID
	 * @return string Formatted address string
	 */
	public static function get_formatted_address( $term_id, ?array $venue_data = null ) {
		if ( null === $venue_data ) {
			$venue_data = self::get_venue_data( $term_id );
		}

		$address_parts = array();

		if ( ! empty( $venue_data['address'] ) ) {
			$address_parts[] = $venue_data['address'];
		}

		$city_state = array();
		if ( ! empty( $venue_data['city'] ) ) {
			$city_state[] = $venue_data['city'];
		}
		if ( ! empty( $venue_data['state'] ) ) {
			$city_state[] = $venue_data['state'];
		}

		if ( ! empty( $city_state ) ) {
			$address_parts[] = implode( ', ', $city_state );
		}

		if ( ! empty( $venue_data['zip'] ) ) {
			$address_parts[] = $venue_data['zip'];
		}

		return implode( ', ', $address_parts );
	}

	public static function get_all_venues() {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array();
		}

		$venue_data = array();
		foreach ( $venues as $venue ) {
			$venue_data[] = self::get_venue_data( $venue->term_id );
		}

		return $venue_data;
	}

	public static function get_venues_by_event_count( $min_events = 1 ) {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => true,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array();
		}

		$venue_data = array();
		foreach ( $venues as $venue ) {
			if ( $venue->count >= $min_events ) {
				$venue_data[] = self::get_venue_data( $venue->term_id );
			}
		}

		return $venue_data;
	}

	private static function init_admin_hooks() {
		add_filter( 'wp_update_term_parent', array( VenueProfileMutations::class, 'guardNativeTermEdit' ), 10, 3 );
		add_action( 'edit_terms', array( VenueProfileMutations::class, 'beginNativeTermEdit' ), 10, 2 );
		add_action( 'saved_venue', array( VenueProfileMutations::class, 'endNativeTermEdit' ), 99 );

		add_action( 'venue_add_form_fields', array( __CLASS__, 'add_venue_form_fields' ) );

		add_action( 'venue_edit_form_fields', array( __CLASS__, 'edit_venue_form_fields' ) );

		add_action( 'created_venue', array( __CLASS__, 'save_venue_meta' ) );

		add_action( 'edited_venue', array( __CLASS__, 'save_venue_meta' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_term_edit_assets' ) );
	}
	public static function add_venue_form_fields( $taxonomy ) {
		foreach ( self::$meta_fields as $key => $meta_key ) {
			$label = self::$field_labels[ $key ] ?? ucfirst( $key );
			echo '<div class="form-field">';
			echo "<label for='$meta_key'>$label</label>";

			if ( 'address' === $key ) {
				echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text venue-address-autocomplete' ";
				echo "data-city-field='_venue_city' data-state-field='_venue_state' data-zip-field='_venue_zip' data-country-field='_venue_country' />";
			} else {
				echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text' />";
			}

			echo '</div>';
		}
	}

	public static function edit_venue_form_fields( $term ) {
		foreach ( self::$meta_fields as $key => $meta_key ) {
			$label = self::$field_labels[ $key ] ?? ucfirst( $key );
			$value = get_term_meta( $term->term_id, $meta_key, true );
			echo '<tr class="form-field">';
			echo "<th scope='row'><label for='$meta_key'>$label</label></th>";

			if ( 'address' === $key ) {
				echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr( $value ) . "' class='regular-text venue-address-autocomplete' ";
				echo "data-city-field='_venue_city' data-state-field='_venue_state' data-zip-field='_venue_zip' data-country-field='_venue_country' /></td>";
			} else {
				echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr( $value ) . "' class='regular-text' /></td>";
			}

			echo '</tr>';
		}
	}

	public static function save_venue_meta( $term_id ) {
		if ( VenueProfileMutations::isInternalTermUpdate() ) {
			return;
		}

		$changes = array();

		foreach ( self::$meta_fields as $key => $meta_key ) {
			if ( isset( $_POST[ $meta_key ] ) ) {
				$changes[ $key ] = wp_unslash( $_POST[ $meta_key ] );
			}
		}

		if ( ! empty( $changes ) ) {
			VenueProfileMutations::updateSystem( (int) $term_id, $changes );
		}
	}

	public static function enqueue_term_edit_assets( $hook ) {
		if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'venue' !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_script(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-autocomplete.js',
			array(),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-autocomplete.js' ),
			true
		);

		// Localize the outbound Nominatim User-Agent off the deploying site host
		// so the client never announces one hard-coded site to OSM.
		wp_localize_script(
			'data-machine-events-venue-autocomplete',
			'dmEventsVenueAutocomplete',
			array(
				'userAgent' => NominatimClient::userAgent(),
			)
		);

		wp_enqueue_style(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
			array(),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css' )
		);
	}
}
