/**
 * REST API communication and calendar DOM updates.
 */

/**
 * Internal dependencies
 */
import type {
	ArchiveContext,
	CalendarRequest,
	CalendarDataResponse,
	DateContext,
	FilterResponse,
	FilterRequestContext,
	GeoContext,
	TaxFilters,
} from '../types';

import { renderCalendarResponse } from './calendar-response-renderer';
import { emitQueryChanged, type CalendarRequestOptions } from './query-changed';

/* ------------------------------------------------------------------ */
/*  Calendar REST request builder — single source of truth             */
/* ------------------------------------------------------------------ */

/**
 * Canonical passthrough list of calendar URL params.
 *
 * Every calendar URL param the REST endpoint understands lives here.
 * `buildCalendarRequest()` reads these from `window.location.search`
 * so re-fetches (geo-sync map pan, day-loader prefetch, pagination
 * rebind) preserve every relevant URL param by default.
 *
 * Adding a new calendar URL param means adding it both here and to
 * the `CalendarRequest` interface in `../types.ts`.
 *
 * `tax_filter[*]` is dynamic and walked separately — not in this list.
 */
const CALENDAR_PASSTHROUGH_KEYS: ( keyof CalendarRequest )[] = [
	'event_search',
	'scope',
	'past',
	'paged',
	'date_start',
	'date_end',
	// #160: opaque scope token. Passed through from the URL so re-fetches
	// preserve it; the month-grid nav also injects it from the calendar
	// root's `data-scope-token` attribute (a server-embedded calendar has
	// no `?scope_token=` in the URL on first paint).
	'scope_token',
	'venue_tier',
];

/**
 * Build a URLSearchParams for the calendar REST endpoint.
 *
 * Single helper used by every JS module that hits
 * `/wp-json/datamachine/v1/events/calendar`. Replaces the three
 * separate URL builders that previously drifted (api-client,
 * day-loader, geo-sync) — each had its own `passthrough` list and
 * they silently disagreed (notably `scope` survived day-loader
 * fetches but got dropped by geo-sync re-fetches).
 *
 * Order of operations:
 *   1. Passthrough every canonical calendar param from the explicit
 *      `source`, or `window.location.search` by default.
 *   2. Passthrough every `tax_filter[*]` entry from the URL.
 *   3. Apply `archiveContext` (sets `archive_taxonomy` /
 *      `archive_term_id` if both present).
 *   4. Apply `geoContext` (sets `lat`/`lng`/`radius`/`radius_unit`
 *      if `lat` and `lng` are present).
 *   5. Apply `overrides` last — explicit per-caller params win
 *      (e.g. day-loader sets `date_start`/`date_end` for a single
 *      day, geo-sync injects fresh `lat`/`lng` from the bounds-
 *      changed event).
 *
 * Callers that need to drop a param after building (e.g. geo-sync
 * resets `paged` on geo change) can `params.delete( 'paged' )` on
 * the returned object — the helper intentionally does not encode
 * deletion semantics so the override semantics stay simple.
 * @param opts
 * @param opts.archiveContext
 * @param opts.geoContext
 * @param opts.overrides
 * @param opts.source
 */
export function buildCalendarRequest(
	opts: {
		archiveContext?: Partial< ArchiveContext >;
		geoContext?: Partial< GeoContext >;
		overrides?: Partial< CalendarRequest >;
		source?: URLSearchParams;
	} = {}
): URLSearchParams {
	const params = new URLSearchParams();
	const urlParams = opts.source
		? new URLSearchParams( opts.source )
		: new URLSearchParams( window.location.search );

	// 1. Canonical passthrough.
	CALENDAR_PASSTHROUGH_KEYS.forEach( ( key ) => {
		const val = urlParams.get( key );
		if ( val ) {
			params.set( key, val );
		}
	} );

	// 2. Tax filters (dynamic key family).
	for ( const [ key, value ] of urlParams.entries() ) {
		if ( key.startsWith( 'tax_filter[' ) ) {
			params.append( key, value );
		}
	}

	// 3. Archive context.
	const archiveContext = opts.archiveContext ?? {};
	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}

	// 4. Geo context.
	const geoContext = opts.geoContext ?? {};
	if ( geoContext.lat && geoContext.lng ) {
		params.set( 'lat', geoContext.lat );
		params.set( 'lng', geoContext.lng );
		if ( geoContext.radius !== undefined && geoContext.radius !== null ) {
			params.set( 'radius', String( geoContext.radius ) );
		}
		if ( geoContext.radius_unit ) {
			params.set( 'radius_unit', geoContext.radius_unit );
		}
	}

	// 5. Per-caller overrides win.
	const overrides = opts.overrides ?? {};
	( Object.keys( overrides ) as ( keyof CalendarRequest )[] ).forEach(
		( key ) => {
			const val = overrides[ key ];
			if ( val !== undefined && val !== null && val !== '' ) {
				params.set( key, String( val ) );
			}
		}
	);

	return params;
}

const latestRequests = new WeakMap< HTMLElement, symbol >();

export async function fetchCalendarEvents(
	calendar: HTMLElement,
	params: URLSearchParams,
	archiveContext: Partial< ArchiveContext > = {},
	options: CalendarRequestOptions = {}
): Promise< CalendarDataResponse | null > {
	const content = calendar.querySelector( '.data-machine-events-content' );
	if ( ! content ) {
		return null;
	}
	const request = Symbol();
	latestRequests.set( calendar, request );
	const isLatest = () => latestRequests.get( calendar ) === request;
	const shouldApply = () =>
		isLatest() &&
		! options.signal?.aborted &&
		options.shouldApply?.() !== false;
	content.classList.add( 'loading' );
	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}
	if ( ! params.has( 'scope' ) && calendar.dataset.scope ) {
		params.set( 'scope', calendar.dataset.scope );
	}
	const scopeToken = calendar.dataset.scopeToken;
	if ( scopeToken ) {
		params.set( 'scope_token', scopeToken );
	}
	try {
		const data = await requestCalendarData( params, options.signal );
		if ( ! shouldApply() ) {
			return null;
		}
		renderCalendarResponse( calendar, data, params );
		if ( options.trigger ) {
			emitQueryChanged( calendar, options.trigger, params, data );
		}
		return data;
	} catch ( error ) {
		if ( shouldApply() && ( error as Error ).name !== 'AbortError' ) {
			window.console.error( 'Error fetching calendar events:', error );
			const message = document.createElement( 'p' );
			message.className = 'data-machine-events-error';
			message.setAttribute( 'role', 'alert' );
			message.textContent = 'Error loading events. Please try again.';
			content.querySelector( '.data-machine-events-error' )?.remove();
			content.prepend( message );
		}
		return null;
	} finally {
		if ( isLatest() ) {
			latestRequests.delete( calendar );
			content.classList.remove( 'loading' );
		}
	}
}

export async function requestCalendarData(
	params: URLSearchParams,
	signal?: AbortSignal
): Promise< CalendarDataResponse > {
	const response = await fetch(
		`/wp-json/datamachine/v1/events/calendar?${ params.toString() }`,
		{
			signal,
			headers: {
				Accept: 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
			},
		}
	);
	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}
	const data: CalendarDataResponse = await response.json();
	if (
		! data?.success ||
		! Array.isArray( data.events ) ||
		! data.grouping
	) {
		throw new Error( 'Invalid calendar data response' );
	}
	return data;
}

/**
 * Fetch filter options from REST API with active filters, date context,
 * archive context, and geo context.
 * @param activeFilters
 * @param dateContext
 * @param archiveContext
 * @param geoContext
 * @param requestContext
 * @param signal
 */
export async function fetchFilters(
	activeFilters: TaxFilters = {},
	dateContext: Partial< DateContext > = {},
	archiveContext: Partial< ArchiveContext > = {},
	geoContext: Partial< GeoContext > = {},
	requestContext: Partial< FilterRequestContext > = {},
	signal?: AbortSignal
): Promise< FilterResponse > {
	const params = new URLSearchParams();

	Object.entries( activeFilters ).forEach( ( [ taxonomy, termIds ] ) => {
		if ( Array.isArray( termIds ) && termIds.length > 0 ) {
			termIds.forEach( ( id ) => {
				params.append( `active[${ taxonomy }][]`, String( id ) );
			} );
		}
	} );

	if ( dateContext.date_start ) {
		params.set( 'date_start', dateContext.date_start );
	}
	if ( dateContext.date_end ) {
		params.set( 'date_end', dateContext.date_end );
	}
	if ( dateContext.past ) {
		params.set( 'past', dateContext.past );
	}

	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}

	if ( geoContext.lat && geoContext.lng ) {
		params.set( 'lat', geoContext.lat );
		params.set( 'lng', geoContext.lng );
		if ( geoContext.radius ) {
			params.set( 'radius', String( geoContext.radius ) );
		}
		if ( geoContext.radius_unit ) {
			params.set( 'radius_unit', geoContext.radius_unit );
		}
	}

	if ( requestContext.event_search ) {
		params.set( 'event_search', requestContext.event_search );
	}
	if ( requestContext.scope ) {
		params.set( 'scope', requestContext.scope );
	}
	if ( requestContext.scope_token ) {
		params.set( 'scope_token', requestContext.scope_token );
	}

	const apiUrl = `/wp-json/datamachine/v1/events/filters?${ params.toString() }`;

	const response = await fetch( apiUrl, {
		method: 'GET',
		signal,
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			// See note on the calendar fetch above. Identifies this
			// call as an XHR so the BrowserNavigationGuard does not
			// redirect / 404 it. Issue #297.
			'X-Requested-With': 'XMLHttpRequest',
		},
	} );

	if ( ! response.ok ) {
		throw new Error( 'Failed to fetch filters' );
	}

	return response.json();
}
