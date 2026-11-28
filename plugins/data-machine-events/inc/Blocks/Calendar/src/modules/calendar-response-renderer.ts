/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CalendarDataResponse } from '../types';
import { renderDateGroup } from './date-group-renderer';
import { renderGapSeparator } from './gap-renderer';
import { destroyDayLoader } from './day-loader';
import { destroyLazyRender } from './lazy-render';
import { destroyCarousel, initCarousel } from './carousel';
import { destroyLoadMore, initLoadMore } from './load-more';
import { buildPastToggleParams, syncTodayRecovery } from './navigation';

export function renderCalendarResponse(
	calendar: HTMLElement,
	data: CalendarDataResponse,
	params: URLSearchParams,
	gridMode = false
): void {
	const content = calendar.querySelector< HTMLElement >(
		'.data-machine-events-content'
	);
	if ( ! content ) {
		return;
	}
	const fragment = document.createDocumentFragment();
	const events = new Map(
		data.events.map( ( event ) => [ event.id, event ] )
	);
	for ( const date of data.grouping.ordered_dates ) {
		const occurrences = data.grouping.by_date[ date ];
		if ( ! occurrences?.length ) {
			continue;
		}
		const gap = data.grouping.gaps[ date ];
		if ( gap >= 2 ) {
			fragment.append( renderGapSeparator( gap ) );
		}
		fragment.append( renderDateGroup( date, occurrences, events ) );
	}
	if ( ! fragment.hasChildNodes() ) {
		const empty = document.createElement( 'div' );
		empty.className = 'data-machine-events-no-events';
		const message = document.createElement( 'p' );
		message.textContent = __( 'No events found.', 'data-machine-events' );
		const action = document.createElement( 'p' );
		const today = document.createElement( 'button' );
		today.type = 'button';
		today.className = 'data-machine-events-no-events-today-link';
		today.textContent = __(
			'Show events from Today',
			'data-machine-events'
		);
		action.append( today );
		empty.append( message, action );
		fragment.append( empty );
	}

	// Tear down initial-render observers once. REST cards are complete nodes,
	// so neither lazy rendering nor deferred-day fetching needs to restart.
	destroyDayLoader( calendar );
	destroyLazyRender( calendar );
	destroyCarousel( calendar );
	destroyLoadMore( calendar );
	content.replaceChildren( fragment );
	const counter = renderCounter( data.counter );
	replaceRegion(
		calendar,
		'.data-machine-events-results-counter',
		counter,
		content
	);
	replaceRegion(
		calendar,
		'.data-machine-events-pagination, .data-machine-events-load-more-nav',
		renderPagination( data.pagination, params ),
		counter || content
	);
	replaceRegion(
		calendar,
		'.data-machine-events-past-navigation',
		gridMode ? null : renderNavigation( data.navigation, params )
	);
	syncTodayRecovery( calendar, params );

	const scope = params.get( 'scope' ) || calendar.dataset.scope;
	const singleDay =
		! gridMode && ( scope === 'today' || scope === 'tonight' );
	calendar.classList.toggle(
		'data-machine-events-single-day-list',
		singleDay
	);
	if ( ! gridMode ) {
		if ( ! singleDay ) {
			initCarousel( calendar );
		}
		initLoadMore( calendar, data.pagination );
	}
}

function replaceRegion(
	calendar: HTMLElement,
	selector: string,
	node: HTMLElement | null,
	after?: HTMLElement
): void {
	const current = calendar.querySelector( selector );
	if ( current ) {
		if ( node ) {
			current.replaceWith( node );
		} else {
			current.remove();
		}
	} else if ( node ) {
		if ( after ) {
			after.after( node );
		} else {
			calendar.append( node );
		}
	}
}

function renderCounter(
	counter: CalendarDataResponse[ 'counter' ]
): HTMLElement | null {
	const {
		showing_count: showing,
		total_count: total,
		page_start_date: start,
		page_end_date: end,
	} = counter;
	if ( ! start || ! end || showing < 1 ) {
		return null;
	}
	const counterNode = document.createElement( 'div' );
	counterNode.className = 'data-machine-events-results-counter';
	const range =
		start === end
			? formatDate( start )
			: `${ formatDate( start ) } - ${ formatDate( end ) }`;
	const count =
		showing < total
			? sprintf(
					/* translators: %1$d: visible events, %2$d: total events. */
					__( '%1$d of %2$d', 'data-machine-events' ),
					showing,
					total
			  )
			: String( showing );
	counterNode.textContent = sprintf(
		/* translators: %1$s: date range, %2$s: event count, %3$s: event label. */
		__( 'Viewing %1$s (%2$s %3$s)', 'data-machine-events' ),
		range,
		count,
		_n( 'Event', 'Events', total, 'data-machine-events' )
	);
	return counterNode;
}

function formatDate( date: string ): string {
	const [ year, month, day ] = date.split( '-' ).map( Number );
	return new Date( year, month - 1, day ).toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}

function renderPagination(
	pagination: CalendarDataResponse[ 'pagination' ],
	params: URLSearchParams
): HTMLElement | null {
	const { current_page: current, total_pages: total } = pagination;
	if ( total <= 1 ) {
		return null;
	}
	const nav = document.createElement( 'nav' );
	nav.className = 'data-machine-events-pagination';
	nav.setAttribute(
		'aria-label',
		__( 'Events pagination', 'data-machine-events' )
	);
	const visible = [
		...new Set( [ 1, current - 1, current, current + 1, total ] ),
	].filter( ( page ) => page > 0 && page <= total );
	visible.forEach( ( page ) => {
		const link = document.createElement( 'a' );
		const query = new URLSearchParams( params );
		if ( page === 1 ) {
			query.delete( 'paged' );
		} else {
			query.set( 'paged', String( page ) );
		}
		link.className = 'page-numbers';
		link.href = publicCalendarUrl( query );
		link.textContent = String( page );
		if ( page === current ) {
			link.classList.add( 'current' );
			link.setAttribute( 'aria-current', 'page' );
		}
		nav.append( link );
	} );
	return nav;
}

function renderNavigation(
	navigation: CalendarDataResponse[ 'navigation' ],
	params: URLSearchParams
): HTMLElement | null {
	const {
		show_past: past,
		has_past: hasPast,
		has_future: hasFuture,
	} = navigation;
	if ( past ? ! hasFuture : ! hasPast ) {
		return null;
	}
	const nav = document.createElement( 'div' );
	nav.className = 'data-machine-events-past-navigation';
	const link = document.createElement( 'a' );
	link.className = past
		? 'data-machine-events-upcoming-btn'
		: 'data-machine-events-past-btn';
	link.textContent = past
		? __( 'Upcoming Events →', 'data-machine-events' )
		: __( '← Past Events', 'data-machine-events' );
	link.href = publicCalendarUrl( buildPastToggleParams( params, ! past ) );
	nav.append( link );
	return nav;
}

function publicCalendarUrl( params: URLSearchParams ): string {
	const url = new URL( window.location.href );
	const query = new URLSearchParams( params );
	[ 'archive_taxonomy', 'archive_term_id', 'format', 'scope_token' ].forEach(
		( key ) => query.delete( key )
	);
	url.search = query.toString();
	return url.href;
}
