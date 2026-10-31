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
 * @package
 * @since 0.14.0
 */

/**
 * Internal dependencies
 */
import { buildCalendarRequest, fetchCalendarEvents } from './api-client';
import { getFilterState } from './filter-state';

import type { GeoContext } from '../types';

/**
 * Shape of the custom event dispatched by the EventsMap block.
 */
interface BoundsChangedDetail {
	syncId: string;
	generation: number;
	bounds: {
		swLat: number;
		swLng: number;
		neLat: number;
		neLng: number;
	};
	zoom: number;
	center: { lat: number; lng: number };
	authority?:
		| 'server'
		| 'user-location'
		| 'external'
		| 'manual-search'
		| 'user-interaction';
}

const GEO_AUTHORITY_SOURCES = new Set( [
	'server',
	'user-location',
	'external',
	'manual-search',
	'user-interaction',
] );

/**
 * Per-calendar state for the geo sync listener.
 */
interface GeoSyncState {
	handler: ( e: Event ) => void;
	syncId: string;
	currentGeo: GeoContext | null;
	onGridUpdate?: (
		geo: GeoContext,
		signal: AbortSignal,
		isCurrent: () => boolean
	) => Promise< boolean >;
	debounceTimer: ReturnType< typeof setTimeout > | null;
	abortController: AbortController | null;
	requestGeneration: number;
	lastMapGeneration: number;
	destroyed: boolean;
}

const instances = new WeakMap< HTMLElement, GeoSyncState >();

/**
 * Initialize geo sync for a calendar element.
 *
 * Listens for map bounds-changed events and re-fetches the calendar
 * via REST, updating the DOM in-place.
 * @param calendar
 * @param syncId
 * @param onGridUpdate
 */
export function initGeoSync(
	calendar: HTMLElement,
	syncId: string,
	onGridUpdate?: (
		geo: GeoContext,
		signal: AbortSignal,
		isCurrent: () => boolean
	) => Promise< boolean >
): void {
	if ( instances.has( calendar ) || ! syncId ) {
		return;
	}

	const state: GeoSyncState = {
		handler: createBoundsHandler( calendar ),
		syncId,
		currentGeo: null,
		onGridUpdate,
		debounceTimer: null,
		abortController: null,
		requestGeneration: 0,
		lastMapGeneration: 0,
		destroyed: false,
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
 * @param calendar
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
	state.destroyed = true;
	state.requestGeneration++;
	if ( state.debounceTimer ) {
		clearTimeout( state.debounceTimer );
		state.debounceTimer = null;
	}
	state.abortController?.abort();
	state.abortController = null;
	calendar
		.querySelector( '.data-machine-events-content' )
		?.classList.remove( 'loading' );

	instances.delete( calendar );
}

/**
 * Programmatically update the calendar's geo context and re-fetch.
 *
 * Used by external orchestrators (e.g. near-me page) to push geo
 * updates without waiting for a map bounds-changed event.
 * @param calendar
 * @param geo
 */
export function updateCalendarGeo(
	calendar: HTMLElement,
	geo: GeoContext
): void {
	const state = instances.get( calendar );
	if ( state ) {
		state.currentGeo = geo;
		invalidateActiveRequest( state );
		startGeoRequest( calendar, state, geo );
		return;
	}

	void fetchAndUpdate( calendar, geo );
}

/* ------------------------------------------------------------------ */
/*  Internal helpers                                                   */
/* ------------------------------------------------------------------ */

function createBoundsHandler( calendar: HTMLElement ): ( e: Event ) => void {
	return function ( e: Event ): void {
		const detail = ( e as CustomEvent< BoundsChangedDetail > ).detail;
		const state = instances.get( calendar );
		if (
			! state ||
			state.destroyed ||
			! detail?.center ||
			detail.syncId !== state.syncId ||
			! Number.isInteger( detail.generation ) ||
			detail.generation <= state.lastMapGeneration ||
			! detail.authority ||
			! GEO_AUTHORITY_SOURCES.has( detail.authority )
		) {
			return;
		}
		state.lastMapGeneration = detail.generation;
		invalidateActiveRequest( state );

		if ( state.debounceTimer ) {
			clearTimeout( state.debounceTimer );
		}

		state.debounceTimer = setTimeout( () => {
			state.debounceTimer = null;
			if ( state.destroyed || instances.get( calendar ) !== state ) {
				return;
			}
			// Derive radius from viewport bounds — the map zoom IS the radius.
			const radius = boundsToRadius( detail.bounds, detail.center );

			const geo: GeoContext = {
				lat: String( detail.center.lat ),
				lng: String( detail.center.lng ),
				radius,
				radius_unit: 'mi',
			};

			state.currentGeo = geo;

			startGeoRequest( calendar, state, geo );
		}, 300 );
	};
}

function startGeoRequest(
	calendar: HTMLElement,
	state: GeoSyncState,
	geo: GeoContext
): void {
	state.abortController = new AbortController();
	const requestGeneration = ++state.requestGeneration;
	void fetchAndUpdate(
		calendar,
		geo,
		state,
		requestGeneration,
		state.abortController.signal
	);
}

function invalidateActiveRequest( state: GeoSyncState ): void {
	state.requestGeneration++;
	state.abortController?.abort();
	state.abortController = null;
}

/**
 * Fetch calendar data via REST API and update the DOM.
 * @param calendar
 * @param geo
 * @param state
 * @param requestGeneration
 * @param signal
 */
async function fetchAndUpdate(
	calendar: HTMLElement,
	geo: GeoContext,
	state?: GeoSyncState,
	requestGeneration?: number,
	signal?: AbortSignal
): Promise< void > {
	const isCurrent = (): boolean =>
		! state ||
		( ! state.destroyed &&
			instances.get( calendar ) === state &&
			state.requestGeneration === requestGeneration &&
			! signal?.aborted );
	if ( ! isCurrent() ) {
		return;
	}
	const filterState = getFilterState( calendar );
	if ( state?.onGridUpdate ) {
		const updated = await state.onGridUpdate( geo, signal!, isCurrent );
		if ( updated && isCurrent() ) {
			filterState.saveGeoToStorage( {
				lat: geo.lat,
				lng: geo.lng,
				radius: geo.radius,
				radius_unit: geo.radius_unit,
				label: '',
			} );
		}
		return;
	}
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
	await fetchCalendarEvents( calendar, params, archiveContext, {
		signal,
		shouldApply: isCurrent,
	} );
}

/**
 * Derive a radius (in miles) from map viewport bounds.
 *
 * Calculates the haversine distance from the center to the NE corner
 * of the bounding box. This makes the calendar query match the map
 * viewport — the map zoom IS the radius.
 * @param bounds
 * @param center
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
