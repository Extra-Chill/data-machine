/**
 * Day Loader — progressive date-group loading via REST
 *
 * Observes deferred date-group containers (data-deferred="true") and
 * fetches their events from the REST API as they approach the viewport.
 * Each day is an independent loading unit identified by the parent
 * date-group's data-date attribute.
 */

import type { ArchiveContext } from '../types';
import { initLazyRender } from './lazy-render';
import { initCarousel } from './carousel';

const ROOT_MARGIN = '600px';

const observers = new Map< HTMLElement, IntersectionObserver >();

export function initDayLoader( calendar: HTMLElement ): void {
	const deferredWrappers = calendar.querySelectorAll< HTMLElement >(
		'.data-machine-events-wrapper[data-deferred="true"]'
	);

	if ( ! deferredWrappers.length ) {
		return;
	}

	const archiveContext = getArchiveContext( calendar );
	const geoContext = getGeoContext( calendar );

	const observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					loadDayEvents(
						entry.target as HTMLElement,
						calendar,
						archiveContext,
						geoContext
					);
					observer.unobserve( entry.target );
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
}

export function destroyDayLoader( calendar: HTMLElement ): void {
	const observer = observers.get( calendar );
	if ( observer ) {
		observer.disconnect();
		observers.delete( calendar );
	}
}

async function loadDayEvents(
	wrapper: HTMLElement,
	calendar: HTMLElement,
	archiveContext: Partial< ArchiveContext >,
	geoContext: { lat: string; lng: string; radius: string; radius_unit: string }
): Promise< void > {
	const dateGroup = wrapper.closest< HTMLElement >(
		'.data-machine-date-group'
	);
	if ( ! dateGroup ) {
		return;
	}

	const date = dateGroup.dataset.date;
	if ( ! date ) {
		return;
	}

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

	// Archive context (location/artist/venue page).
	if ( archiveContext.taxonomy && archiveContext.term_id ) {
		params.set( 'archive_taxonomy', archiveContext.taxonomy );
		params.set( 'archive_term_id', String( archiveContext.term_id ) );
	}

	// Geo context (near-me page).
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

	try {
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
			// The REST response contains full date-group HTML for a single day.
			// Extract the events-wrapper contents and inject into our container.
			const temp = document.createElement( 'div' );
			temp.innerHTML = data.html;
			const sourceWrapper = temp.querySelector(
				'.data-machine-events-wrapper'
			);

			if ( sourceWrapper ) {
				wrapper.innerHTML = sourceWrapper.innerHTML;
				wrapper.removeAttribute( 'data-deferred' );

				// Re-init lazy render and carousel for the new content.
				initLazyRender( calendar );
				initCarousel( calendar );
			}
		}
	} catch ( error ) {
		// Show inline error instead of skeletons.
		wrapper.innerHTML =
			'<div class="data-machine-events-error">' +
			'<p>Unable to load events. <button class="data-machine-retry-btn">Retry</button></p>' +
			'</div>';
		wrapper.removeAttribute( 'data-deferred' );

		// Attach retry handler.
		const retryBtn = wrapper.querySelector( '.data-machine-retry-btn' );
		if ( retryBtn ) {
			retryBtn.addEventListener( 'click', function () {
				wrapper.setAttribute( 'data-deferred', 'true' );
				wrapper.innerHTML =
					'<div class="data-machine-event-item data-machine-event-placeholder">' +
					'<div class="data-machine-placeholder-skeleton">' +
					'<div class="data-machine-skeleton-title"></div>' +
					'<div class="data-machine-skeleton-meta"></div>' +
					'</div></div>';
				loadDayEvents( wrapper, calendar, archiveContext, geoContext );
			} );
		}
	}
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

function getGeoContext( calendar: HTMLElement ): {
	lat: string;
	lng: string;
	radius: string;
	radius_unit: string;
} {
	return {
		lat: calendar.dataset.geoLat || '',
		lng: calendar.dataset.geoLng || '',
		radius: calendar.dataset.geoRadius || '',
		radius_unit: calendar.dataset.geoRadiusUnit || '',
	};
}
