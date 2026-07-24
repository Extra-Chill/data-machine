/**
 * Events Map API Client
 *
 * Fetches venue data from the public REST endpoint.
 *
 * @package
 * @since 0.5.0
 */

/**
 * Internal dependencies
 */
import type { MapBounds, VenueListResponse } from './types';

interface FetchVenuesParams {
	bounds?: MapBounds;
	lat?: number;
	lng?: number;
	radius?: number;
	radiusUnit?: 'mi' | 'km';
	taxonomy?: string;
	termId?: number;
	/**
	 * When true, request the opt-in `upcoming_events_at_venue` payload by
	 * appending `include=events`. Only meaningful in combination with a
	 * taxonomy/term filter (e.g. chronological-route mode).
	 */
	includeEvents?: boolean;
	/**
	 * Opaque consumer-minted scope token. Sent as `scope_token` so a
	 * consumer's server-side venue scoping (e.g. owner scoping) survives
	 * the REST round-trip. data-machine-events does not interpret it. #160.
	 */
	scopeToken?: string;
}

/**
 * Fetch venues from the REST API.
 *
 * This is a public endpoint (permission_callback: __return_true) so we
 * intentionally do NOT send X-WP-Nonce. Sending a stale nonce causes
 * WordPress to return 403 (rest_cookie_invalid_nonce) even on public
 * routes — this happens on client-side navigation where the nonce
 * from the original page render goes stale.
 *
 * @param restUrl Base REST URL (e.g. /wp-json/datamachine/v1/events/venues).
 * @param _nonce  Deprecated — kept for API compatibility but no longer sent.
 * @param params  Optional filter parameters.
 * @return Promise resolving to the venue list response.
 */
export async function fetchVenues(
	restUrl: string,
	_nonce: string,
	params: FetchVenuesParams = {}
): Promise< VenueListResponse > {
	const url = new URL( restUrl, window.location.origin );

	if ( params.bounds ) {
		const { swLat, swLng, neLat, neLng } = params.bounds;
		url.searchParams.set(
			'bounds',
			`${ swLat },${ swLng },${ neLat },${ neLng }`
		);
	}

	if ( params.lat !== undefined && params.lng !== undefined ) {
		url.searchParams.set( 'lat', String( params.lat ) );
		url.searchParams.set( 'lng', String( params.lng ) );
	}

	if ( params.radius !== undefined ) {
		url.searchParams.set( 'radius', String( params.radius ) );
	}

	if ( params.radiusUnit ) {
		url.searchParams.set( 'radius_unit', params.radiusUnit );
	}

	if ( params.taxonomy ) {
		url.searchParams.set( 'taxonomy', params.taxonomy );
	}

	if ( params.termId ) {
		url.searchParams.set( 'term_id', String( params.termId ) );
	}

	if ( params.includeEvents ) {
		url.searchParams.set( 'include', 'events' );
	}

	if ( params.scopeToken ) {
		url.searchParams.set( 'scope_token', params.scopeToken );
	}

	const response = await fetch( url.toString(), {
		headers: { Accept: 'application/json' },
	} );

	if ( ! response.ok ) {
		throw new Error(
			`Venue fetch failed: ${ response.status } ${ response.statusText }`
		);
	}

	return response.json() as Promise< VenueListResponse >;
}
