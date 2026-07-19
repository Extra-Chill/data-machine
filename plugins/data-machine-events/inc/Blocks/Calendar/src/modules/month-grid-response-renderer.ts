/**
 * Synchronize every month-grid presentation from one data response.
 */

import { renderDateGroup } from './date-group-renderer';
import { renderGapSeparator } from './gap-renderer';
import { renderMonthGrid } from './month-grid-renderer';

import type {
	CalendarDataCounter,
	CalendarDataPagination,
	CalendarDataResponse,
	CalendarEventItem,
} from '../types';

export function renderMonthGridResponse(
	calendar: HTMLElement,
	month: string,
	data: CalendarDataResponse,
	baseUrl: string,
	publicParams: URLSearchParams
): void {
	const grid = calendar.querySelector< HTMLElement >(
		'.data-machine-month-grid'
	);
	const newGrid = renderMonthGrid( month, data, baseUrl );
	if ( grid ) {
		grid.replaceWith( newGrid );
	} else {
		const filterBar = calendar.querySelector(
			'.data-machine-events-filter-bar'
		);
		if ( filterBar?.parentElement ) {
			filterBar.parentElement.insertBefore(
				newGrid,
				filterBar.nextSibling
			);
		} else {
			calendar.prepend( newGrid );
		}
	}

	const content = calendar.querySelector< HTMLElement >(
		'.data-machine-events-content'
	);
	if ( content ) {
		content.replaceChildren();
		if ( data.grouping.ordered_dates.length === 0 ) {
			content.innerHTML = data.empty_html;
		}
		const eventsById = new Map< number, CalendarEventItem >();
		data.events.forEach( ( event ) => eventsById.set( event.id, event ) );
		data.grouping.ordered_dates.forEach( ( date ) => {
			const occurrences = data.grouping.by_date[ date ] || [];
			if ( occurrences.length === 0 ) {
				return;
			}
			const gap = data.grouping.gaps[ date ];
			if ( gap && gap >= 2 ) {
				content.appendChild( renderGapSeparator( gap ) );
			}
			content.appendChild(
				renderDateGroup( date, occurrences, eventsById )
			);
		} );
	}

	updateCounter( calendar, content, data.counter );
	updatePagination( calendar, content, data.pagination, publicParams );

	calendar.dispatchEvent(
		new CustomEvent( 'data-machine-calendar-content-updated', {
			bubbles: false,
		} )
	);
}

function updateCounter(
	calendar: HTMLElement,
	content: HTMLElement | null,
	counter: CalendarDataCounter
): void {
	const current = calendar.querySelector< HTMLElement >(
		'.data-machine-events-results-counter'
	);
	if (
		! counter.page_start_date ||
		! counter.page_end_date ||
		counter.showing_count < 1
	) {
		current?.remove();
		return;
	}

	const replacement = document.createElement( 'div' );
	replacement.className = 'data-machine-events-results-counter';
	const start = formatCounterDate( counter.page_start_date );
	const end = formatCounterDate( counter.page_end_date );
	const range =
		counter.page_start_date === counter.page_end_date
			? start
			: `${ start } - ${ end }`;
	const label = counter.total_count === 1 ? 'Event' : 'Events';
	const count =
		counter.showing_count < counter.total_count
			? `${ counter.showing_count } of ${ counter.total_count }`
			: String( counter.showing_count );
	replacement.textContent = `Viewing ${ range } (${ count } ${ label })`;

	if ( current ) {
		current.replaceWith( replacement );
	} else {
		content?.insertAdjacentElement( 'afterend', replacement );
	}
}

function updatePagination(
	calendar: HTMLElement,
	content: HTMLElement | null,
	pagination: CalendarDataPagination,
	publicParams: URLSearchParams
): void {
	const current = calendar.querySelector< HTMLElement >(
		'.data-machine-events-pagination'
	);
	if ( pagination.total_pages <= 1 ) {
		current?.remove();
		return;
	}

	const nav = document.createElement( 'nav' );
	nav.className = 'data-machine-events-pagination';
	nav.setAttribute( 'aria-label', 'Events pagination' );
	for ( let page = 1; page <= pagination.total_pages; page++ ) {
		const link = document.createElement( 'a' );
		const params = new URLSearchParams( publicParams );
		if ( page === 1 ) {
			params.delete( 'paged' );
		} else {
			params.set( 'paged', String( page ) );
		}
		link.href = `${ window.location.pathname }?${ params.toString() }`;
		link.textContent = String( page );
		if ( page === pagination.current_page ) {
			link.classList.add( 'current' );
			link.setAttribute( 'aria-current', 'page' );
		}
		nav.appendChild( link );
	}

	if ( current ) {
		current.replaceWith( nav );
		return;
	}
	const counter = calendar.querySelector(
		'.data-machine-events-results-counter'
	);
	( counter || content )?.insertAdjacentElement( 'afterend', nav );
}

function formatCounterDate( date: string ): string {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( date );
	if ( ! match ) {
		return date;
	}
	const value = new Date(
		Number( match[ 1 ] ),
		Number( match[ 2 ] ) - 1,
		Number( match[ 3 ] )
	);
	return value.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}
