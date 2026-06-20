/**
 * Load More orchestrator (issue #314, phase 2 of #298)
 *
 * Replaces the bottom paginate_links() nav with a single
 * "Load More Events" button on JS-enabled mount. Clicking the button
 * fetches the next page via the data-only REST envelope
 * (`?format=data`, introduced in #301) and appends the new date
 * groups in place — no innerHTML blasts, no page reload.
 *
 * Architectural notes:
 *
 *   - This is the first real consumer of the data-only schema from
 *     #301. It uses the structured `events` / `grouping` / `pagination`
 *     payload and renders cards client-side via `renderEventCard()`,
 *     `renderDateGroup()`, `renderGapSeparator()`.
 *
 *   - The legacy `paginate_links()` HTML envelope is NOT removed —
 *     it remains the no-JS fallback. On JS mount we hydrate the
 *     existing `<nav class="data-machine-events-pagination">` into
 *     a Load More button. Browsers with JS disabled still see
 *     functional numbered pagination.
 *
 *   - Geo / archive / taxonomy filter state flows through the same
 *     `buildCalendarRequest()` helper every other calendar fetch
 *     uses. A map pan that updates `currentGeo` is automatically
 *     reflected on the next Load More — no special-case wiring.
 *
 * Open-question decisions (see PR body for rationale):
 *
 *   1. URL preservation: yes — `pushState` to `?paged=N` after each
 *      Load More so refresh / share roughly preserves position.
 *      Refreshing the URL reloads the highest-loaded page from
 *      the server alone; older pages don't come back. Acceptable.
 *
 *   2. Past-mode label flip: yes — button reads "Load Earlier
 *      Events" when `?past=1` is active. One line of conditional
 *      copy, clarifies direction.
 *
 *   3. Scroll position after append: stays put. We append below
 *      the current content; browsers don't shift scroll on append.
 *
 *   4. "All loaded" empty state: silent disappear. The events are
 *      the content; a "you reached the end" chip is chrome.
 */

import type {
	ArchiveContext,
	CalendarDataResponse,
	CalendarEventItem,
	GeoContext,
} from '../types';

import { buildCalendarRequest } from './api-client';
import { renderDateGroup } from './date-group-renderer';
import { renderEventCard } from './event-renderer';
import { renderGapSeparator } from './gap-renderer';
import { getFilterState } from './filter-state';

interface LoadMoreState {
	button: HTMLButtonElement;
	handler: ( e: Event ) => void;
	geo: GeoContext | null;
}

const instances = new WeakMap< HTMLElement, LoadMoreState >();

/**
 * Hydrate the legacy pagination nav into a Load More button and wire
 * the click handler. No-op when there's no nav (max_pages <= 1).
 *
 * Idempotent: re-init on the same calendar is a no-op.
 */
export function initLoadMore( calendar: HTMLElement ): void {
	if ( instances.has( calendar ) ) {
		return;
	}

	const replaced = replacePaginationWithLoadMore( calendar );
	if ( ! replaced ) {
		return;
	}

	const button = replaced;
	const handler = createClickHandler( calendar, button );
	button.addEventListener( 'click', handler );

	instances.set( calendar, {
		button,
		handler,
		geo: null,
	} );
}

export function destroyLoadMore( calendar: HTMLElement ): void {
	const state = instances.get( calendar );
	if ( ! state ) {
		return;
	}
	state.button.removeEventListener( 'click', state.handler );
	instances.delete( calendar );
}

/**
 * Push a geo-context update into a calendar's Load More state.
 *
 * Called by geo-sync (or future orchestrators) so that the NEXT
 * Load More click carries the current map viewport. The `geo-sync`
 * module already pushes geo into the URL via `pushState`, so
 * `buildCalendarRequest()` would pick it up anyway — this exposed
 * setter is a belt-and-suspenders path for code that doesn't go
 * through the URL.
 */
export function setLoadMoreGeo(
	calendar: HTMLElement,
	geo: GeoContext | null
): void {
	const state = instances.get( calendar );
	if ( state ) {
		state.geo = geo;
	}
}

/* ------------------------------------------------------------------ */
/*  Pagination → Load More hydration                                   */
/* ------------------------------------------------------------------ */

/**
 * Replace the legacy `<nav class="data-machine-events-pagination">`
 * with a Load More button wrapped in a same-shape nav. Returns the
 * new button element, or null when there's no pagination nav (single
 * page, no Load More needed).
 *
 * Page bounds are read from the server-rendered nav: the current page
 * is the `.page-numbers.current` span, and total_pages is the highest
 * numeric link / span inside the nav.
 */
function replacePaginationWithLoadMore(
	calendar: HTMLElement
): HTMLButtonElement | null {
	const nav = calendar.querySelector< HTMLElement >(
		'.data-machine-events-pagination'
	);
	if ( ! nav ) {
		return null;
	}

	const { currentPage, totalPages } = readPaginationBounds( nav );
	if ( totalPages <= 1 || currentPage >= totalPages ) {
		// Single page, or we're already on the last page — nothing to
		// load. Remove the nav so the legacy HTML doesn't render.
		nav.remove();
		return null;
	}

	const newNav = document.createElement( 'nav' );
	newNav.className = 'data-machine-events-load-more-nav';
	newNav.setAttribute( 'aria-label', 'Load more events' );

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'data-machine-events-load-more';
	button.dataset.currentPage = String( currentPage );
	button.dataset.totalPages = String( totalPages );
	button.textContent = loadMoreLabel( calendar );

	newNav.appendChild( button );
	nav.replaceWith( newNav );

	return button;
}

function readPaginationBounds( nav: HTMLElement ): {
	currentPage: number;
	totalPages: number;
} {
	// Server emits `paginate_links()` output. The "current" page is a
	// `.page-numbers.current` span; the highest-numbered link/span is
	// the total page count (paginate_links shows end_size + mid_size,
	// so the last numeric child is always the last page, unless the
	// user is already mid-pagination — in which case the "next »"
	// link's href has `paged=last` we could parse, but the simpler
	// path is reading every numeric `.page-numbers` and taking the
	// max).
	let currentPage = 1;
	let totalPages = 1;

	const currentEl = nav.querySelector< HTMLElement >(
		'.page-numbers.current'
	);
	if ( currentEl ) {
		const parsed = parseInt(
			( currentEl.textContent || '' ).trim(),
			10
		);
		if ( ! isNaN( parsed ) && parsed > 0 ) {
			currentPage = parsed;
		}
	}

	const numberNodes = nav.querySelectorAll< HTMLElement >( '.page-numbers' );
	numberNodes.forEach( ( node ) => {
		const text = ( node.textContent || '' ).trim();
		const n = parseInt( text, 10 );
		if ( ! isNaN( n ) && n > totalPages ) {
			totalPages = n;
		}
	} );

	// Fallback: if the current page IS the highest visible number,
	// look at the `next »` anchor's `paged` query param (which jumps
	// to currentPage+1 — not the real total). We can't reliably get
	// total from paginate_links HTML without an `end_size`-bound link,
	// but the default end_size=1 always emits the last page number,
	// so `totalPages` from the loop above is correct for the default
	// config. Worst case: button hides one click "too early" — user
	// clicks Load More once more, fetch returns the data, page
	// increments past total_pages and the button hides on response.
	if ( totalPages < currentPage ) {
		totalPages = currentPage + 1;
	}

	return { currentPage, totalPages };
}

/* ------------------------------------------------------------------ */
/*  Click handler                                                      */
/* ------------------------------------------------------------------ */

function createClickHandler(
	calendar: HTMLElement,
	button: HTMLButtonElement
): ( e: Event ) => void {
	return async function ( e: Event ): Promise< void > {
		e.preventDefault();

		const currentPage = parseInt(
			button.dataset.currentPage || '1',
			10
		);
		const totalPages = parseInt(
			button.dataset.totalPages || '1',
			10
		);

		if ( currentPage >= totalPages ) {
			button.hidden = true;
			return;
		}

		// Lock the button: prevent double-clicks, signal aria-busy.
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		const idleLabel = loadMoreLabel( calendar );
		button.textContent = 'Loading\u2026';

		try {
			const nextPage = currentPage + 1;
			const data = await fetchPage( calendar, nextPage );

			if ( ! data || ! data.success ) {
				throw new Error( 'API returned success: false' );
			}

			appendPage( calendar, data );

			// Update button state from the SERVER's authoritative
			// pagination, not our local estimate. If the server says
			// we're at the last page, hide.
			const serverCurrent = data.pagination.current_page;
			const serverTotal = data.pagination.total_pages;
			button.dataset.currentPage = String( serverCurrent );
			button.dataset.totalPages = String( serverTotal );

			// pushState so refresh/share roughly preserves position.
			// We use replaceState would lose history; pushState lets
			// the back button rewind to before-Load-More. (Open
			// question 1: pushState is recommended.)
			const filterState = getFilterState( calendar );
			const urlParams = new URLSearchParams( window.location.search );
			urlParams.set( 'paged', String( serverCurrent ) );
			filterState.updateUrl( urlParams );

			if ( serverCurrent >= serverTotal ) {
				button.hidden = true;
			}

			// Notify content-updated owners (frontend.ts) so
			// lazy-render / day-loader / carousel re-init on the
			// newly-appended date groups. Same event the legacy
			// innerHTML swap path dispatches.
			calendar.dispatchEvent(
				new CustomEvent( 'data-machine-calendar-content-updated', {
					bubbles: false,
				} )
			);
		} catch ( err ) {
			// Surface the error inline; let the user retry. The
			// existing `.data-machine-events-error` styles apply.
			console.error( 'Load More failed:', err );
			button.textContent = 'Retry';
			// Leave the button clickable so retry works. Don't hide.
		} finally {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			// Only reset to idleLabel on success; retry-text stays
			// on error. Detect via textContent — sloppy but adequate.
			if ( button.textContent === 'Loading\u2026' ) {
				button.textContent = idleLabel;
			}
		}
	};
}

/* ------------------------------------------------------------------ */
/*  Fetch                                                              */
/* ------------------------------------------------------------------ */

async function fetchPage(
	calendar: HTMLElement,
	pageNumber: number
): Promise< CalendarDataResponse | null > {
	const filterState = getFilterState( calendar );
	const archiveContext: Partial< ArchiveContext > =
		filterState.getArchiveContext();

	// Prefer the runtime-pushed geo from `setLoadMoreGeo` if set,
	// then fall back to whatever the URL / filterState currently
	// holds. `buildCalendarRequest()` reads URL by default; the
	// geoContext override wins when present.
	const state = instances.get( calendar );
	const geo: Partial< GeoContext > = state?.geo ?? {};

	const params = buildCalendarRequest( {
		archiveContext,
		geoContext: geo,
		overrides: {
			paged: String( pageNumber ),
			// `format=data` is not in the CalendarRequest interface
			// (it's a transport concern, not a URL state field).
			// Append it directly after build.
		},
	} );

	// Activate the data-only envelope from #301.
	params.set( 'format', 'data' );

	const response = await fetch(
		`/wp-json/datamachine/v1/events/calendar?${ params.toString() }`,
		{
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				// BrowserNavigationGuard (#297): JS callers
				// identify themselves so the guard doesn't
				// redirect/404 them. Same header every other
				// calendar fetch sends.
				'X-Requested-With': 'XMLHttpRequest',
			},
		}
	);

	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}

	return ( await response.json() ) as CalendarDataResponse;
}

/* ------------------------------------------------------------------ */
/*  Append                                                             */
/* ------------------------------------------------------------------ */

/**
 * Append a freshly-fetched data envelope to the calendar's content
 * region. Handles the edge case where the first date of the new
 * page matches the last date of the existing rendered content: in
 * that case, append the new occurrences to the existing date
 * group's `.data-machine-events-wrapper`, not a duplicate group.
 */
function appendPage(
	calendar: HTMLElement,
	data: CalendarDataResponse
): void {
	const content = calendar.querySelector< HTMLElement >(
		'.data-machine-events-content'
	);
	if ( ! content ) {
		return;
	}

	const eventsById = new Map< number, CalendarEventItem >();
	data.events.forEach( ( event ) => {
		eventsById.set( event.id, event );
	} );

	const orderedDates = data.grouping.ordered_dates || [];
	const gaps = data.grouping.gaps || {};
	const byDate = data.grouping.by_date || {};

	orderedDates.forEach( ( date, index ) => {
		const occurrences = byDate[ date ] || [];
		if ( occurrences.length === 0 ) {
			return;
		}

		// Find an existing date group for this date. If present, we
		// extend it instead of creating a duplicate. Gaps for an
		// already-present date are skipped — the server only sees
		// gap relative to the previous page's tail, but client has
		// already drawn that boundary.
		const existingGroup = content.querySelector< HTMLElement >(
			`.data-machine-date-group[data-date="${ cssEscape( date ) }"]`
		);

		if ( existingGroup ) {
			const wrapper = existingGroup.querySelector< HTMLElement >(
				'.data-machine-events-wrapper'
			);
			if ( wrapper ) {
				occurrences.forEach( ( occurrence ) => {
					const event = eventsById.get( occurrence.post_id );
					if ( ! event ) {
						return;
					}
					const card = renderEventCard( event, occurrence );
					wrapper.appendChild( card );
				} );
				// Update the count badge.
				const newCount = wrapper.querySelectorAll(
					'.data-machine-event-item'
				).length;
				existingGroup.dataset.eventCount = String( newCount );
				const countSpan = existingGroup.querySelector(
					'.data-machine-day-event-count'
				);
				if ( countSpan ) {
					countSpan.textContent =
						newCount === 1
							? `${ newCount } event`
							: `${ newCount } events`;
				}
			}
			return;
		}

		// New date: render gap separator (only when this isn't the
		// FIRST date of the new page replacing the existing tail —
		// gaps[date] is keyed by the date itself, not the previous,
		// so a non-first new date with a gap entry is always a
		// legitimate "gap N days later" between this date and the
		// previous one we just rendered).
		if ( gaps[ date ] && gaps[ date ] >= 2 ) {
			// Skip the gap separator for the FIRST appended date
			// when we're piggybacking on an existing group above —
			// otherwise the chip lands directly under the merged
			// group with no visual gap above it. The check is
			// "does the prior date in the new page's ordered list
			// exist as an existing group?"; if so, the gap is
			// already implicitly handled by the merged group's
			// position.
			const isFirstNewDate = index === 0;
			const priorDateInPage = isFirstNewDate
				? null
				: orderedDates[ index - 1 ];
			const priorRendered = priorDateInPage
				? content.querySelector(
						`.data-machine-date-group[data-date="${ cssEscape(
							priorDateInPage
						) }"]`
				  )
				: content.querySelector(
						'.data-machine-date-group:last-of-type'
				  );

			if ( priorRendered ) {
				content.appendChild( renderGapSeparator( gaps[ date ] ) );
			}
		}

		const group = renderDateGroup( date, occurrences, eventsById );
		content.appendChild( group );
	} );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function loadMoreLabel( calendar: HTMLElement ): string {
	return isPastMode( calendar ) ? 'Load Earlier Events' : 'Load More Events';
}

function isPastMode( calendar: HTMLElement ): boolean {
	// Single source of truth: the URL. The past/upcoming toggle
	// (#299) is a full-page navigation so the URL is always in sync
	// with the rendered mode by the time Load More mounts.
	const params = new URLSearchParams( window.location.search );
	const past = params.get( 'past' );
	if ( past && past !== '0' && past !== '' ) {
		return true;
	}
	// Fallback to filterState in case future code drifts URL and
	// stored past flag.
	const dateContext = getFilterState( calendar ).getDateContext();
	return Boolean( dateContext.past && dateContext.past !== '0' );
}

/**
 * Minimal CSS.escape polyfill for the values we put inside the
 * `[data-date="..."]` selector. `Y-m-d` strings only contain
 * digits + hyphens, which are safe — but we sanitize defensively in
 * case the server ever ships a date in a different shape.
 */
function cssEscape( value: string ): string {
	if ( typeof window !== 'undefined' && typeof window.CSS !== 'undefined' && typeof window.CSS.escape === 'function' ) {
		return window.CSS.escape( value );
	}
	return value.replace( /[^a-zA-Z0-9_-]/g, ( ch ) => '\\' + ch );
}
