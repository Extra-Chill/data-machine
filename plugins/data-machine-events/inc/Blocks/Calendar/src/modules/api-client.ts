/**
 * REST API communication and calendar DOM updates.
 */

import type {
	ArchiveContext,
	CalendarRequest,
	CalendarResponse,
	DateContext,
	FilterResponse,
	FilterRequestContext,
	GeoContext,
	TaxFilters,
} from '../types';

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

export async function fetchCalendarEvents(
	calendar: HTMLElement,
	params: URLSearchParams,
	archiveContext: Partial< ArchiveContext > = {}
): Promise< CalendarResponse > {
	const content = calendar.querySelector< HTMLElement >(
		'.data-machine-events-content'
	);

	if ( ! content ) {
		return { success: false, html: '', pagination: null, counter: null, navigation: null };
	}

	content.classList.add( 'loading' );

	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}

	try {
		const apiUrl = `/wp-json/datamachine/v1/events/calendar?${ params.toString() }`;

		const response = await fetch( apiUrl, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				// Identifies this as an XHR/fetch call so the
				// server-side BrowserNavigationGuard lets the JSON
				// response through. Direct browser navigations
				// (address bar, middle-click on a stale anchor) do
				// not send this header and get redirected to the
				// canonical archive URL or a 404 — never raw JSON.
				// See Extra-Chill/data-machine-events#297.
				'X-Requested-With': 'XMLHttpRequest',
			},
		} );

		if ( ! response.ok ) {
			throw new Error( 'Network response was not ok' );
		}

		const data: CalendarResponse = await response.json();

		if ( data.success ) {
			content.innerHTML = data.html;
			updatePagination( calendar, data.pagination );
			updateCounter( calendar, content, data.counter );
			updateNavigation( calendar, content, data.navigation );

			// Notify lifecycle owners (frontend.ts) that the content region was
			// replaced, so they can re-init dynamic modules (lazy-render,
			// day-loader, carousel) on the new DOM. This is the single point
			// of truth for content-swap notifications — every module that
			// touches `.data-machine-events-content` should rely on this event,
			// not drive its own destroy/init cycle.
			calendar.dispatchEvent(
				new CustomEvent( 'data-machine-calendar-content-updated', {
					bubbles: false,
				} )
			);
		}

		return data;
	} catch ( error ) {
		console.error( 'Error fetching filtered events:', error );
		content.innerHTML =
			'<div class="data-machine-events-error"><p>Error loading events. Please try again.</p></div>';
		return {
			success: false,
			html: '',
			pagination: null,
			counter: null,
			navigation: null,
		};
	} finally {
		content.classList.remove( 'loading' );
	}
}

/**
 * Fetch filter options from REST API with active filters, date context,
 * archive context, and geo context.
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

/* ------------------------------------------------------------------ */
/*  DOM update helpers                                                 */
/* ------------------------------------------------------------------ */

function updatePagination(
	calendar: HTMLElement,
	pagination: { html: string } | null
): void {
	// A prior Load More hydration (issue #314) may have replaced the
	// numbered `.data-machine-events-pagination` nav with a
	// `.data-machine-events-load-more-nav`. Remove that stale button
	// so the fresh server-rendered pagination nav can be re-injected
	// and then re-hydrated by `initLoadMore` on the content-updated
	// event. Without this, the selector below misses the converted
	// nav and we leak a duplicate, producing a Load More button
	// stacked above numbered pagination on archive re-fetches.
	const loadMoreNav = calendar.querySelector(
		'.data-machine-events-load-more-nav'
	);
	if ( loadMoreNav ) {
		loadMoreNav.remove();
	}

	const paginationContainer = calendar.querySelector(
		'.data-machine-events-pagination'
	);

	if ( pagination?.html ) {
		if ( paginationContainer ) {
			paginationContainer.outerHTML = pagination.html;
		} else {
			const content = calendar.querySelector(
				'.data-machine-events-content'
			);
			content?.insertAdjacentHTML( 'afterend', pagination.html );
		}
	} else if ( paginationContainer ) {
		paginationContainer.remove();
	}
}

function updateCounter(
	calendar: HTMLElement,
	content: HTMLElement,
	counter: string | null
): void {
	const counterContainer = calendar.querySelector(
		'.data-machine-events-results-counter'
	);

	if ( counterContainer && counter ) {
		counterContainer.outerHTML = counter;
	} else if ( ! counterContainer && counter ) {
		content.insertAdjacentHTML( 'afterend', counter );
	}
}

function updateNavigation(
	calendar: HTMLElement,
	content: HTMLElement,
	navigation: { html: string } | null
): void {
	const navigationContainer = calendar.querySelector(
		'.data-machine-events-past-navigation'
	);

	if ( navigationContainer && navigation?.html ) {
		navigationContainer.outerHTML = navigation.html;
	} else if ( ! navigationContainer && navigation?.html ) {
		calendar.insertAdjacentHTML( 'beforeend', navigation.html );
	}
}
