/**
 * REST API communication and calendar DOM updates.
 */

import type {
	ArchiveContext,
	CalendarResponse,
	DateContext,
	FilterResponse,
	GeoContext,
	TaxFilters,
} from '../types';

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
	geoContext: Partial< GeoContext > = {}
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

	const apiUrl = `/wp-json/datamachine/v1/events/filters?${ params.toString() }`;

	const response = await fetch( apiUrl, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
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
