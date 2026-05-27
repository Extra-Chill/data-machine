/**
 * Geo Sync Module
 *
 * Listens for `data-machine-map-bounds-changed` custom events fired by the
 * EventsMap block and re-fetches the calendar via REST API, swapping the
 * DOM in-place without a page reload.
 *
 * The map viewport IS the radius. When the user zooms in/out, the radius
 * is derived from the viewport bounds (center-to-corner distance). No
 * separate radius control is needed — the map zoom level is the control.
 *
 * @package DataMachineEvents
 * @since 0.14.0
 */

import { buildCalendarRequest, fetchCalendarEvents } from './api-client';
import { getFilterState } from './filter-state';

import type { GeoContext } from '../types';

/**
 * Shape of the custom event dispatched by the EventsMap block.
 */
interface BoundsChangedDetail {
	bounds: {
		swLat: number;
		swLng: number;
		neLat: number;
		neLng: number;
	};
	zoom: number;
	center: { lat: number; lng: number };
}

/**
 * Per-calendar state for the geo sync listener.
 */
interface GeoSyncState {
	handler: ( e: Event ) => void;
	currentGeo: GeoContext | null;
}

const instances = new WeakMap< HTMLElement, GeoSyncState >();

/**
 * Initialize geo sync for a calendar element.
 *
 * Listens for map bounds-changed events and re-fetches the calendar
 * via REST, updating the DOM in-place.
 */
export function initGeoSync( calendar: HTMLElement ): void {
	if ( instances.has( calendar ) ) {
		return;
	}

	const state: GeoSyncState = {
		handler: createBoundsHandler( calendar ),
		currentGeo: null,
	};

	instances.set( calendar, state );

	document.addEventListener(
		'data-machine-map-bounds-changed',
		state.handler
	);

	// Pagination is now owned by `load-more.ts` (issue #314). Load More
	// reads geo state from the URL via `buildCalendarRequest()` after
	// `fetchAndUpdate()` pushState-writes lat/lng/radius — geo + Load
	// More compose naturally without a dedicated handler here.
}

/**
 * Destroy geo sync listener for a calendar element.
 */
export function destroyGeoSync( calendar: HTMLElement ): void {
	const state = instances.get( calendar );
	if ( ! state ) {
		return;
	}

	document.removeEventListener(
		'data-machine-map-bounds-changed',
		state.handler
	);

	instances.delete( calendar );
}

/**
 * Programmatically update the calendar's geo context and re-fetch.
 *
 * Used by external orchestrators (e.g. near-me page) to push geo
 * updates without waiting for a map bounds-changed event.
 */
export function updateCalendarGeo(
	calendar: HTMLElement,
	geo: GeoContext
): void {
	const state = instances.get( calendar );
	if ( state ) {
		state.currentGeo = geo;
	}

	fetchAndUpdate( calendar, geo );
}

/* ------------------------------------------------------------------ */
/*  Internal helpers                                                   */
/* ------------------------------------------------------------------ */

function createBoundsHandler(
	calendar: HTMLElement
): ( e: Event ) => void {
	let debounceTimer: ReturnType< typeof setTimeout >;

	return function ( e: Event ): void {
		const detail = ( e as CustomEvent< BoundsChangedDetail > ).detail;
		if ( ! detail?.center ) {
			return;
		}

		clearTimeout( debounceTimer );

		debounceTimer = setTimeout( () => {
			// Derive radius from viewport bounds — the map zoom IS the radius.
			const radius = boundsToRadius( detail.bounds, detail.center );

			const geo: GeoContext = {
				lat: String( detail.center.lat ),
				lng: String( detail.center.lng ),
				radius,
				radius_unit: 'mi',
			};

			const state = instances.get( calendar );
			if ( state ) {
				state.currentGeo = geo;
			}

			fetchAndUpdate( calendar, geo );
		}, 300 );
	};
}

/**
 * Fetch calendar data via REST API and update the DOM.
 */
async function fetchAndUpdate(
	calendar: HTMLElement,
	geo: GeoContext
): Promise< void > {
	const filterState = getFilterState( calendar );
	const archiveContext = filterState.getArchiveContext();

	// Build via the shared helper so passthrough stays consistent with
	// day-loader and api-client (notably `scope` now survives geo
	// re-fetches — see #237). Geo lives in `geoContext` so the helper
	// applies it consistently. `paged` is intentionally cleared after
	// the helper runs because a viewport change resets pagination.
	const params = buildCalendarRequest( {
		archiveContext,
		geoContext: geo,
	} );

	// Reset to page 1 on geo change.
	params.delete( 'paged' );

	// Update URL via History API (so the state is shareable).
	filterState.updateUrl( params );

	// Save geo to storage for persistence.
	filterState.saveGeoToStorage( {
		lat: geo.lat,
		lng: geo.lng,
		radius: geo.radius,
		radius_unit: geo.radius_unit,
		label: '',
	} );

	// Module lifecycle (lazy-render, day-loader, carousel) is driven by
	// frontend.ts in response to the `data-machine-calendar-content-updated`
	// event that fetchCalendarEvents fires after swapping innerHTML. This
	// module does not destroy or re-init dynamic UI directly — single owner.
	//
	// Pagination ownership moved to `load-more.ts` (issue #314). Geo
	// updates flow into Load More through the URL (pushed by
	// `filterState.updateUrl()` above) — the next Load More click reads
	// fresh geo via `buildCalendarRequest()`.
	await fetchCalendarEvents( calendar, params, archiveContext );
}

/**
 * Derive a radius (in miles) from map viewport bounds.
 *
 * Calculates the haversine distance from the center to the NE corner
 * of the bounding box. This makes the calendar query match the map
 * viewport — the map zoom IS the radius.
 */
function boundsToRadius(
	bounds: BoundsChangedDetail[ 'bounds' ],
	center: BoundsChangedDetail[ 'center' ]
): number {
	if ( ! bounds || ! center ) {
		return 25;
	}

	const toRad = ( deg: number ): number => ( deg * Math.PI ) / 180;

	const lat1 = toRad( center.lat );
	const lat2 = toRad( bounds.neLat );
	const dLat = toRad( bounds.neLat - center.lat );
	const dLng = toRad( bounds.neLng - center.lng );

	const a =
		Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
		Math.cos( lat1 ) *
			Math.cos( lat2 ) *
			Math.sin( dLng / 2 ) *
			Math.sin( dLng / 2 );
	const c = 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );

	const EARTH_RADIUS_MI = 3959;
	const distance = EARTH_RADIUS_MI * c;

	// Clamp to reasonable range.
	return Math.max( 1, Math.min( 500, Math.round( distance ) ) );
}


