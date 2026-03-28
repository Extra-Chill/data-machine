/**
 * Events Map API Client
 *
 * Fetches venue data from the public REST endpoint.
 *
 * @package DataMachineEvents
 * @since 0.5.0
 */

import type { VenueListResponse, MapBounds } from './types';

interface FetchVenuesParams {
	bounds?: MapBounds;
	lat?: number;
	lng?: number;
	radius?: number;
	radiusUnit?: 'mi' | 'km';
	taxonomy?: string;
	termId?: number;
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
 * @param restUrl  Base REST URL (e.g. /wp-json/datamachine/v1/events/venues).
 * @param _nonce   Deprecated — kept for API compatibility but no longer sent.
 * @param params   Optional filter parameters.
 * @returns Promise resolving to the venue list response.
 */
export async function fetchVenues(
	restUrl: string,
	_nonce: string,
	params: FetchVenuesParams = {},
): Promise<VenueListResponse> {
	const url = new URL( restUrl, window.location.origin );

	if ( params.bounds ) {
		const { swLat, swLng, neLat, neLng } = params.bounds;
		url.searchParams.set( 'bounds', `${ swLat },${ swLng },${ neLat },${ neLng }` );
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

	const response = await fetch( url.toString(), {
		headers: { Accept: 'application/json' },
	} );

	if ( ! response.ok ) {
		throw new Error( `Venue fetch failed: ${ response.status } ${ response.statusText }` );
	}

	return response.json() as Promise<VenueListResponse>;
}
