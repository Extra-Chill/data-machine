/**
 * Day Loader — progressive date-group loading via REST
 *
 * All deferred days start fetching immediately on page load (in display order).
 * An IntersectionObserver gates when the fetched HTML actually gets injected
 * into the DOM — if the data arrives before the user scrolls to it, it injects
 * instantly with zero perceived delay.
 *
 * This means:
 * - Day 2 starts loading right after page load (not when scrolled to)
 * - Days 3, 4, 5 start loading sequentially after day 2
 * - If the user scrolls slowly, everything is already loaded
 * - If the user scrolls fast, at most they see a brief skeleton
 */

import type { ArchiveContext } from '../types';
import { initLazyRender } from './lazy-render';
import { initCarousel } from './carousel';

const ROOT_MARGIN = '200px';

const observers = new Map< HTMLElement, IntersectionObserver >();

/** Prefetched HTML keyed by date string, waiting for scroll to inject. */
const prefetchCache = new Map< string, string >();

/** Wrappers waiting for their prefetched data. */
const pendingInject = new Map< string, HTMLElement >();

export function initDayLoader( calendar: HTMLElement ): void {
	const deferredWrappers = calendar.querySelectorAll< HTMLElement >(
		'.data-machine-events-wrapper[data-deferred="true"]'
	);

	if ( ! deferredWrappers.length ) {
		return;
	}

	const archiveContext = getArchiveContext( calendar );
	const geoContext = getGeoContext( calendar );

	// Set up IntersectionObserver to gate DOM injection (not fetching).
	const observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				const wrapper = entry.target as HTMLElement;
				const date = getDateFromWrapper( wrapper );
				observer.unobserve( wrapper );

				if ( ! date ) {
					return;
				}

				// If prefetch already completed, inject immediately.
				const cached = prefetchCache.get( date );
				if ( cached ) {
					injectDayHtml( wrapper, cached, calendar );
					prefetchCache.delete( date );
				} else {
					// Data still in flight — mark as waiting for injection.
					pendingInject.set( date, wrapper );
				}
			} );
		},
		{
			rootMargin: ROOT_MARGIN,
			threshold: 0,
		}
	);

	deferredWrappers.forEach( function ( wrapper ) {
		observer.observe( wrapper );
	} );

	observers.set( calendar, observer );

	// Start prefetching ALL deferred days immediately, in display order.
	prefetchAllDays( deferredWrappers, calendar, archiveContext, geoContext );
}

export function destroyDayLoader( calendar: HTMLElement ): void {
	const observer = observers.get( calendar );
	if ( observer ) {
		observer.disconnect();
		observers.delete( calendar );
	}
	prefetchCache.clear();
	pendingInject.clear();
}

/**
 * Prefetch all deferred days sequentially (in display order).
 * Each fetch starts as soon as the previous one completes,
 * so we don't slam the server with concurrent requests.
 */
async function prefetchAllDays(
	wrappers: NodeListOf< HTMLElement >,
	calendar: HTMLElement,
	archiveContext: Partial< ArchiveContext >,
	geoContext: GeoContextData
): Promise< void > {
	for ( const wrapper of Array.from( wrappers ) ) {
		const date = getDateFromWrapper( wrapper );
		if ( ! date ) {
			continue;
		}

		// Skip if already injected (observer fired before fetch started).
		if ( ! wrapper.hasAttribute( 'data-deferred' ) ) {
			continue;
		}

		try {
			const html = await fetchDayHtml( date, archiveContext, geoContext );

			if ( html ) {
				// Check if the observer already flagged this day as visible.
				const waiting = pendingInject.get( date );
				if ( waiting ) {
					// User already scrolled here — inject immediately.
					injectDayHtml( waiting, html, calendar );
					pendingInject.delete( date );
				} else {
					// Not visible yet — cache for when the observer fires.
					prefetchCache.set( date, html );
				}
			}
		} catch {
			// Fetch failed — leave skeleton, observer will show error on scroll.
			const waiting = pendingInject.get( date );
			if ( waiting ) {
				showError( waiting, calendar, archiveContext, geoContext );
				pendingInject.delete( date );
			}
		}
	}
}

/**
 * Fetch a single day's events HTML from REST.
 */
async function fetchDayHtml(
	date: string,
	archiveContext: Partial< ArchiveContext >,
	geoContext: GeoContextData
): Promise< string | null > {
	const params = new URLSearchParams();
	params.set( 'date_start', date );
	params.set( 'date_end', date );

	// Preserve current page filters from URL.
	const urlParams = new URLSearchParams( window.location.search );
	const passthrough = [ 'event_search', 'scope', 'past' ];
	passthrough.forEach( function ( key ) {
		const val = urlParams.get( key );
		if ( val ) {
			params.set( key, val );
		}
	} );

	// Preserve taxonomy filters.
	urlParams.forEach( function ( value, key ) {
		if ( key.startsWith( 'tax_filter' ) ) {
			params.append( key, value );
		}
	} );

	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}

	if ( geoContext.lat && geoContext.lng ) {
		params.set( 'lat', geoContext.lat );
		params.set( 'lng', geoContext.lng );
		if ( geoContext.radius ) {
			params.set( 'radius', geoContext.radius );
		}
		if ( geoContext.radius_unit ) {
			params.set( 'radius_unit', geoContext.radius_unit );
		}
	}

	const response = await fetch(
		`/wp-json/datamachine/v1/events/calendar?${ params.toString() }`,
		{
			method: 'GET',
			headers: { 'Content-Type': 'application/json' },
		}
	);

	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}

	const data = await response.json();

	if ( data.success && data.html ) {
		return data.html;
	}

	return null;
}

/**
 * Inject fetched HTML into a deferred wrapper.
 */
function injectDayHtml(
	wrapper: HTMLElement,
	html: string,
	calendar: HTMLElement
): void {
	const temp = document.createElement( 'div' );
	temp.innerHTML = html;
	const sourceWrapper = temp.querySelector(
		'.data-machine-events-wrapper'
	);

	if ( sourceWrapper ) {
		wrapper.innerHTML = sourceWrapper.innerHTML;
		wrapper.removeAttribute( 'data-deferred' );
		initLazyRender( calendar );
		initCarousel( calendar );
	}
}

/**
 * Show error UI in a failed wrapper with retry.
 */
function showError(
	wrapper: HTMLElement,
	calendar: HTMLElement,
	archiveContext: Partial< ArchiveContext >,
	geoContext: GeoContextData
): void {
	const date = getDateFromWrapper( wrapper );
	wrapper.innerHTML =
		'<div class="data-machine-events-error">' +
		'<p>Unable to load events. <button class="data-machine-retry-btn">Retry</button></p>' +
		'</div>';
	wrapper.removeAttribute( 'data-deferred' );

	const retryBtn = wrapper.querySelector( '.data-machine-retry-btn' );
	if ( retryBtn && date ) {
		retryBtn.addEventListener( 'click', async function () {
			wrapper.setAttribute( 'data-deferred', 'true' );
			wrapper.innerHTML =
				'<div class="data-machine-event-item data-machine-event-placeholder">' +
				'<div class="data-machine-placeholder-skeleton">' +
				'<div class="data-machine-skeleton-title"></div>' +
				'<div class="data-machine-skeleton-meta"></div>' +
				'</div></div>';
			try {
				const html = await fetchDayHtml(
					date,
					archiveContext,
					geoContext
				);
				if ( html ) {
					injectDayHtml( wrapper, html, calendar );
				}
			} catch {
				showError( wrapper, calendar, archiveContext, geoContext );
			}
		} );
	}
}

function getDateFromWrapper( wrapper: HTMLElement ): string | null {
	const dateGroup = wrapper.closest< HTMLElement >(
		'.data-machine-date-group'
	);
	return dateGroup?.dataset.date || null;
}

function getArchiveContext(
	calendar: HTMLElement
): Partial< ArchiveContext > {
	const taxonomy = calendar.dataset.archiveTaxonomy || '';
	const termId = calendar.dataset.archiveTermId || '';

	if ( taxonomy && termId ) {
		return {
			taxonomy,
			term_id: parseInt( termId, 10 ),
		};
	}

	return {};
}

interface GeoContextData {
	lat: string;
	lng: string;
	radius: string;
	radius_unit: string;
}

function getGeoContext( calendar: HTMLElement ): GeoContextData {
	return {
		lat: calendar.dataset.geoLat || '',
		lng: calendar.dataset.geoLng || '',
		radius: calendar.dataset.geoRadius || '',
		radius_unit: calendar.dataset.geoRadiusUnit || '',
	};
}
