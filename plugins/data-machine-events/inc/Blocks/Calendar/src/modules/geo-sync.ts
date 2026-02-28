/**
 * Geo Sync Module
 *
 * Listens for `datamachine-map-bounds-changed` custom events fired by the
 * EventsMap block and re-fetches the calendar via REST API, swapping the
 * DOM in-place without a page reload.
 *
 * This enables reactive map ↔ calendar sync: pan/zoom the map and the
 * event list updates automatically.
 *
 * @package DataMachineEvents
 * @since 0.14.0
 */

import { fetchCalendarEvents } from './api-client';
import { getFilterState } from './filter-state';
import { initLazyRender, destroyLazyRender } from './lazy-render';
import { initCarousel, destroyCarousel } from './carousel';

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
		'datamachine-map-bounds-changed',
		state.handler
	);
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
		'datamachine-map-bounds-changed',
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
			const filterState = getFilterState( calendar );
			const existingGeo = filterState.getGeoContext();

			const geo: GeoContext = {
				lat: String( detail.center.lat ),
				lng: String( detail.center.lng ),
				radius: existingGeo.radius || 25,
				radius_unit: existingGeo.radius_unit || 'mi',
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

	const params = new URLSearchParams();

	// Geo params.
	if ( geo.lat && geo.lng ) {
		params.set( 'lat', geo.lat );
		params.set( 'lng', geo.lng );
		params.set( 'radius', String( geo.radius ) );
		params.set( 'radius_unit', geo.radius_unit );
	}

	// Preserve existing filters from URL.
	const urlParams = new URLSearchParams( window.location.search );

	const passthroughKeys = [
		'event_search',
		'date_start',
		'date_end',
		'past',
		'paged',
	];
	passthroughKeys.forEach( ( key ) => {
		const val = urlParams.get( key );
		if ( val ) {
			params.set( key, val );
		}
	} );

	// Taxonomy filters.
	for ( const [ key, value ] of urlParams.entries() ) {
		if ( key.startsWith( 'tax_filter[' ) ) {
			params.append( key, value );
		}
	}

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

	// Clean up existing dynamic UI before re-fetch.
	destroyLazyRender( calendar );
	destroyCarousel( calendar );

	await fetchCalendarEvents( calendar, params, archiveContext );

	// Re-initialize dynamic UI on new DOM.
	initLazyRender( calendar );
	initCarousel( calendar );

	// Re-bind pagination links for REST fetching.
	rebindPagination( calendar, geo );
}

/**
 * Re-bind pagination links after a REST update so they also fetch
 * via REST instead of triggering a page reload.
 */
function rebindPagination(
	calendar: HTMLElement,
	geo: GeoContext
): void {
	const paginationContainer = calendar.querySelector< HTMLElement >(
		'.datamachine-events-pagination'
	);
	if ( ! paginationContainer ) {
		return;
	}

	paginationContainer.addEventListener( 'click', function ( e: Event ) {
		const target = e.target as HTMLElement;
		const link = target.closest< HTMLAnchorElement >( 'a' );
		if ( ! link ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();

		const url = new URL( link.href );
		const linkParams = new URLSearchParams( url.search );

		// Inject current geo into the pagination request.
		if ( geo.lat && geo.lng ) {
			linkParams.set( 'lat', geo.lat );
			linkParams.set( 'lng', geo.lng );
			linkParams.set( 'radius', String( geo.radius ) );
			linkParams.set( 'radius_unit', geo.radius_unit );
		}

		const filterState = getFilterState( calendar );
		filterState.updateUrl( linkParams );

		destroyLazyRender( calendar );
		destroyCarousel( calendar );

		fetchCalendarEvents(
			calendar,
			linkParams,
			filterState.getArchiveContext()
		).then( () => {
			initLazyRender( calendar );
			initCarousel( calendar );
			rebindPagination( calendar, geo );
		} );
	} );
}
