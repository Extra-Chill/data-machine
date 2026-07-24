<?php
/**
 * `wp data-machine-events check missing-venue-addresses`
 *
 * Audit + repair pass for venue terms whose `_venue_address` meta is
 * empty or missing. These terms are invisible to address-based dedup because
 * `find_venue_by_address` keys on the address+city pair.
 *
 * Repair strategy per term, in order:
 *
 *   1. REVERSE GEOCODE from `_venue_coordinates` (94.6% of venues already
 *      have coordinates from the existing geocode pipeline). Parses the
 *      Nominatim `/reverse` payload into `_venue_address`, `_venue_city`,
 *      `_venue_state`, `_venue_zip`, `_venue_country` and smart-merges
 *      into empty fields only.
 *
 *   2. PLACES LOOKUP — when no coordinates exist but `_venue_city` does,
 *      forward-search Nominatim with `"{name} {city}"`. The top result
 *      is accepted only if its display name passes
 *      VenueMergeHelper::names_are_similar() against the term name to
 *      avoid wiring the wrong venue (e.g. a different "The Local Bar"
 *      in the same city).
 *
 *   3. RESIDUE — no coordinates and no city. Emitted as
 *      `action=no_repair_possible` for operator review; no writes.
 *
 * Smart-merge is non-negotiable: only EMPTY fields are populated. A
 * non-empty `_venue_city` of `Existing City` survives even if the
 * geocoded payload reports a different city, because the operator may
 * have curated a value we should not overwrite.
 *
 * The reverse / places lookups are isolated in protected methods so unit
 * tests can stub the network surface without depending on Nominatim
 * availability or rate limits.
 *
 * Architectural note: this codebase uses Nominatim (OpenStreetMap) as
 * its only geocoding surface. Issue #277 mentioned "Google Places" as
 * the fallback strategy — that surface does not exist here. We reuse
 * Nominatim's forward-search endpoint as the places-lookup fallback
 * because adding a Google Places client would require an API key the
 * network does not provision and would duplicate the geocoding
 * primitive. Filed in PR notes for follow-up if Google coverage
 * proves materially better.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.38.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\NominatimClient;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\DuplicateDetection\VenueMergeHelper;
use DataMachineEvents\Core\VenueProfileMutations;

defined( 'ABSPATH' ) || exit;

class CheckMissingVenueAddressesCommand {

	// Nominatim endpoints, user-agent, and rate-limit live in
	// NominatimClient — the single point of HTTP plumbing for every
	// OSM call in this plugin.

	/**
	 * Address-component meta keys filled by the repair pass. Subset of
	 * Venue_Taxonomy::$meta_fields — coordinates / timezone / phone /
	 * website / capacity are NOT touched by this command.
	 *
	 * @var array<string,string>
	 */
	private const ADDRESS_META_KEYS = array(
		'address' => '_venue_address',
		'city'    => '_venue_city',
		'state'   => '_venue_state',
		'zip'     => '_venue_zip',
		'country' => '_venue_country',
	);

	/**
	 * Audit + repair venue terms with empty `_venue_address` meta.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be repaired without writing. Default behavior —
	 *   pass --apply to commit changes.
	 *
	 * [--apply]
	 * : Actually perform the repairs. Without this flag the command
	 *   behaves as --dry-run.
	 *
	 * [--limit=<count>]
	 * : Cap the number of venue terms processed per run.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for the per-venue table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check missing-venue-addresses --dry-run
	 *     wp data-machine-events check missing-venue-addresses --apply --limit=10
	 *     wp data-machine-events check missing-venue-addresses --dry-run --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply  = isset( $assoc_args['apply'] );
		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		// Default to dry-run unless --apply is passed.
		$dry_run = ! $apply;

		$candidates = $this->find_candidates();

		if ( empty( $candidates ) ) {
			\WP_CLI::success( 'No venue terms with missing _venue_address detected.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Detected %d venue term(s) with missing address meta.', count( $candidates ) ) );

		if ( count( $candidates ) > $limit ) {
			\WP_CLI::log( sprintf( 'Processing first %d this run (use --limit=N to change).', $limit ) );
			$candidates = array_slice( $candidates, 0, $limit );
		}

		$rows = array();

		foreach ( $candidates as $term ) {
			$rows[] = $this->process_candidate( $term, $dry_run );
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'term_id',
				'term_name',
				'action_taken',
				'fields_filled',
			)
		);

		if ( $dry_run ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'DRY RUN — no changes made. Re-run with --apply to commit.' );
			return;
		}

		$filled    = 0;
		$no_repair = 0;
		foreach ( $rows as $row ) {
			if ( ! empty( $row['fields_filled'] ) ) {
				++$filled;
			}
			if ( 'no_repair_possible' === $row['action_taken'] ) {
				++$no_repair;
			}
		}

		\WP_CLI::success(
			sprintf(
				'Processed %d venue(s). Filled at least one field on %d. %d had no repair path.',
				count( $rows ),
				$filled,
				$no_repair
			)
		);
	}

	/**
	 * Return every venue term whose `_venue_address` meta is empty or
	 * missing. Mirrors the audit query at issue #277.
	 *
	 * @return \WP_Term[]
	 */
	private function find_candidates(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$candidates = array();

		foreach ( $terms as $term ) {
			$address = get_term_meta( $term->term_id, '_venue_address', true );
			if ( '' === trim( (string) $address ) ) {
				$candidates[] = $term;
			}
		}

		return $candidates;
	}

	/**
	 * Process one venue term: try reverse-geocode → places-lookup →
	 * residue. Returns one row for the output table.
	 *
	 * @param \WP_Term $term    Venue term.
	 * @param bool     $dry_run Skip writes.
	 * @return array<string,mixed>
	 */
	private function process_candidate( \WP_Term $term, bool $dry_run ): array {
		$row = array(
			'term_id'       => (int) $term->term_id,
			'term_name'     => (string) $term->name,
			'action_taken'  => 'no_repair_possible',
			'fields_filled' => '',
		);

		$coords = (string) get_term_meta( $term->term_id, '_venue_coordinates', true );
		$city   = (string) get_term_meta( $term->term_id, '_venue_city', true );

		// Step 1: reverse geocode from coordinates.
		if ( '' !== trim( $coords ) ) {
			$parsed = $this->reverse_geocode( $coords );

			if ( null !== $parsed && '' !== trim( (string) ( $parsed['address'] ?? '' ) ) ) {
				$filled = $this->apply_smart_merge( $term->term_id, $parsed, $dry_run );
				if ( ! empty( $filled ) ) {
					$row['action_taken']  = 'geocoded';
					$row['fields_filled'] = implode( ',', $filled );
					return $row;
				}

				// Reverse-geocoded but everything was already populated
				// (only `_venue_address` was missing and the lookup
				// returned nothing parseable for it). Surface that so
				// the operator does not assume the lookup failed.
				$row['action_taken'] = 'smart_merge_skipped_existing';
				return $row;
			}
		}

		// Step 2: places-lookup fallback (no coords, but we have a city).
		if ( '' !== trim( $city ) ) {
			$candidate = $this->places_lookup( (string) $term->name, $city );

			if ( null !== $candidate && '' !== trim( (string) ( $candidate['address'] ?? '' ) ) ) {
				// Name-similarity gate: reject the lookup unless the
				// candidate's display name overlaps the term name well
				// enough. Mirrors the VenueMergeHelper guard so we do
				// not silently rewrite "The Local Bar" → "Texas Music
				// Theater" just because Nominatim returned a top hit.
				$candidate_name = (string) ( $candidate['display_name_short'] ?? '' );

				if ( '' === $candidate_name
					|| ! VenueMergeHelper::names_are_similar( (string) $term->name, $candidate_name )
				) {
					$row['action_taken'] = 'no_repair_possible';
					return $row;
				}

				$filled = $this->apply_smart_merge( $term->term_id, $candidate, $dry_run );
				if ( ! empty( $filled ) ) {
					$row['action_taken']  = 'places_lookup';
					$row['fields_filled'] = implode( ',', $filled );
					return $row;
				}

				$row['action_taken'] = 'smart_merge_skipped_existing';
				return $row;
			}
		}

		// Step 3: residue. Neither coords nor city — operator review.
		return $row;
	}

	/**
	 * Smart-merge a parsed address payload into a venue term. Only fills
	 * empty meta fields; never overwrites a curated value.
	 *
	 * @param int                  $term_id    Venue term ID.
	 * @param array<string,string> $components Parsed address components keyed by
	 *                                          'address' / 'city' / 'state' / 'zip' / 'country'.
	 * @param bool                 $dry_run    Skip writes.
	 * @return array<int,string> Meta keys that were (or would be) filled.
	 */
	private function apply_smart_merge( int $term_id, array $components, bool $dry_run ): array {
		$filled = array();
		$pending = array();

		foreach ( self::ADDRESS_META_KEYS as $field => $meta_key ) {
			$existing = (string) get_term_meta( $term_id, $meta_key, true );
			if ( '' !== trim( $existing ) ) {
				continue;
			}

			$incoming_value = trim( (string) ( $components[ $field ] ?? '' ) );
			if ( '' === $incoming_value ) {
				continue;
			}

			$filled[]          = $meta_key;
			$pending[ $field ] = $incoming_value;
		}

		if ( $dry_run || empty( $pending ) ) {
			return $filled;
		}

		$result = VenueProfileMutations::updateSystem( $term_id, $pending, VenueProfileMutations::STRATEGY_FILL_EMPTY );
		if ( is_wp_error( $result ) ) {
			return array();
		}

		return array_values(
			array_intersect(
				array_values( self::ADDRESS_META_KEYS ),
				array_map( static fn( string $field ): string => self::ADDRESS_META_KEYS[ $field ] ?? '', $result['updated_fields'] )
			)
		);
	}

	/**
	 * Reverse-geocode a `lat,lng` coordinates string into address
	 * components via Nominatim's /reverse endpoint.
	 *
	 * Protected so tests can stub the network call without subclassing
	 * the whole command — `\Closure::bind` or a small test-subclass is
	 * the standard pattern.
	 *
	 * @param string $coordinates Coordinates as "lat,lng".
	 * @return array{address:string,city:string,state:string,zip:string,country:string}|null
	 *   Parsed components, or null when the lookup fails or coordinates are
	 *   malformed.
	 */
	protected function reverse_geocode( string $coordinates ): ?array {
		$parts = array_map( 'trim', explode( ',', $coordinates ) );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		[ $lat, $lon ] = $parts;
		if ( '' === $lat || '' === $lon || ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return null;
		}

		$data = NominatimClient::reverseGeocode( (float) $lat, (float) $lon );

		if ( is_wp_error( $data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Reverse geocoding request failed',
				array(
					'error'       => $data->get_error_message(),
					'coordinates' => $coordinates,
				)
			);
			return null;
		}

		// Be nice to Nominatim between successive lookups when a single
		// --apply run touches many venues.
		NominatimClient::sleepForRateLimit();

		if ( empty( $data['address'] ) ) {
			return null;
		}

		return $this->parse_address_components( $data );
	}

	/**
	 * Forward-search Nominatim for `{name} {city}` and return parsed
	 * address components from the top result.
	 *
	 * The caller is responsible for the name-similarity gate; this
	 * method does NOT reject results on its own.
	 *
	 * @param string $name Venue name.
	 * @param string $city City to scope the search.
	 * @return array{address:string,city:string,state:string,zip:string,country:string,display_name_short:string}|null
	 *   Parsed components or null on no-match / network failure.
	 */
	protected function places_lookup( string $name, string $city ): ?array {
		$name = trim( html_entity_decode( $name ) );
		$city = trim( html_entity_decode( $city ) );

		if ( '' === $name || '' === $city ) {
			return null;
		}

		$query = sprintf( '%s %s', $name, $city );

		$data = NominatimClient::searchAddress( $query, 1 );

		if ( is_wp_error( $data ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Places lookup request failed',
				array(
					'error' => $data->get_error_message(),
					'query' => $query,
				)
			);
			return null;
		}

		NominatimClient::sleepForRateLimit();

		if ( empty( $data[0] ) || empty( $data[0]['address'] ) ) {
			return null;
		}

		$parsed = $this->parse_address_components( $data[0] );

		// Top hit's leading display-name token is what we name-compare
		// against. Nominatim returns the full display name with commas;
		// the first comma-segment is the POI name (e.g. "Stubb's
		// Bar-B-Q, 801 Red River Street, Austin, ...").
		$display                      = (string) ( $data[0]['display_name'] ?? '' );
		$display_head                 = strtok( $display, ',' );
		$parsed['display_name_short'] = (string) ( false === $display_head ? '' : $display_head );

		return $parsed;
	}

	/**
	 * Parse the `address` block of a Nominatim payload (forward or
	 * reverse) into the five venue meta fields we care about.
	 *
	 * Nominatim returns granular components — `house_number`, `road`,
	 * `suburb`, `neighbourhood`, `city`, `town`, `village`, `state`,
	 * `postcode`, `country` — that we collapse into our flatter schema:
	 *
	 *   address = "<house_number> <road>" (street line only)
	 *   city    = address.city || town || village || suburb
	 *   state   = address.state
	 *   zip     = address.postcode
	 *   country = address.country
	 *
	 * Missing components become empty strings so the caller's smart-merge
	 * loop can decide whether to skip them.
	 *
	 * @param array<string,mixed> $payload Nominatim response item.
	 * @return array{address:string,city:string,state:string,zip:string,country:string}
	 */
	private function parse_address_components( array $payload ): array {
		$address_block = is_array( $payload['address'] ?? null ) ? $payload['address'] : array();

		$house_number = trim( (string) ( $address_block['house_number'] ?? '' ) );
		$road         = trim( (string) ( $address_block['road'] ?? '' ) );

		$street = trim( $house_number . ' ' . $road );

		// `city` is not always present — Nominatim sometimes returns
		// `town` or `village` for smaller localities, and `suburb` as a
		// last resort. Take the first non-empty hit.
		$city = '';
		foreach ( array( 'city', 'town', 'village', 'suburb', 'hamlet' ) as $key ) {
			$value = trim( (string) ( $address_block[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$city = $value;
				break;
			}
		}

		return array(
			'address' => $street,
			'city'    => $city,
			'state'   => trim( (string) ( $address_block['state'] ?? '' ) ),
			'zip'     => trim( (string) ( $address_block['postcode'] ?? '' ) ),
			'country' => trim( (string) ( $address_block['country'] ?? '' ) ),
		);
	}
}
