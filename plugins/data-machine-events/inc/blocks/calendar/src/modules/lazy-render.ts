/**
 * Lazy render module for event placeholders
 *
 * Uses IntersectionObserver to hydrate event placeholders as they
 * approach the viewport during horizontal scroll.
 */

import type { EventDisplayVars, EventPlaceholderData } from '../types';

interface LazyObserverEntry {
	observer: IntersectionObserver;
	wrapper: HTMLElement;
}

const observers = new Map< HTMLElement, LazyObserverEntry[] >();
const ROOT_MARGIN = '200px';

export function initLazyRender( calendar: HTMLElement ): void {
	if ( typeof IntersectionObserver === 'undefined' ) {
		hydrateAllPlaceholders( calendar );
		return;
	}

	const wrappers = calendar.querySelectorAll< HTMLElement >(
		'.datamachine-events-wrapper'
	);

	wrappers.forEach( function ( wrapper ) {
		const observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						hydratePlaceholder(
							entry.target as HTMLElement
						);
						observer.unobserve( entry.target );
					}
				} );
			},
			{
				root: wrapper,
				rootMargin: ROOT_MARGIN,
				threshold: 0,
			}
		);

		const placeholders = wrapper.querySelectorAll< HTMLElement >(
			'.datamachine-event-placeholder'
		);
		placeholders.forEach( function ( placeholder ) {
			observer.observe( placeholder );
		} );

		const existing = observers.get( calendar ) || [];
		existing.push( { observer, wrapper } );
		observers.set( calendar, existing );
	} );
}

export function destroyLazyRender( calendar: HTMLElement ): void {
	const entries = observers.get( calendar );
	if ( entries ) {
		entries.forEach( function ( { observer } ) {
			observer.disconnect();
		} );
		observers.delete( calendar );
	}
}

function hydrateAllPlaceholders( calendar: HTMLElement ): void {
	const placeholders = calendar.querySelectorAll< HTMLElement >(
		'.datamachine-event-placeholder'
	);
	placeholders.forEach( hydratePlaceholder );
}

function hydratePlaceholder( placeholder: HTMLElement ): void {
	if (
		! placeholder.classList.contains( 'datamachine-event-placeholder' )
	) {
		return;
	}

	const jsonData = placeholder.dataset.eventJson;
	if ( ! jsonData ) {
		return;
	}

	let data: EventPlaceholderData;
	try {
		data = JSON.parse( jsonData ) as EventPlaceholderData;
	} catch ( e ) {
		console.error( 'Failed to parse event placeholder data:', e );
		return;
	}

	const displayVars: Partial< EventDisplayVars > = data.display_vars || {};
	const formattedTimeDisplay = displayVars.formatted_time_display || '';
	const performerName = displayVars.performer_name || '';
	const showPerformer = displayVars.show_performer !== false;
	const multiDayLabel = displayVars.multi_day_label || '';
	const venueName = displayVars.venue_name || '';
	const isoStartDate = displayVars.iso_start_date || '';
	const ticketUrl = displayVars.ticket_url || '';
	const showTicketLink = displayVars.show_ticket_link !== false;

	const itemClasses = [ 'datamachine-event-item' ];
	if ( displayVars.is_continuation ) {
		itemClasses.push( 'datamachine-event-continuation' );
	}
	if ( displayVars.is_multi_day ) {
		itemClasses.push( 'datamachine-event-multi-day' );
	}

	let timeHtml = '';
	if ( formattedTimeDisplay ) {
		timeHtml =
			'<div class="datamachine-event-time">' +
			'<span class="dashicons dashicons-clock"></span>' +
			escapeHtml( formattedTimeDisplay );
		if ( multiDayLabel ) {
			timeHtml +=
				'<span class="datamachine-event-multi-day-label">' +
				escapeHtml( multiDayLabel ) +
				'</span>';
		}
		timeHtml += '</div>';
	}

	let performerHtml = '';
	if ( showPerformer && performerName ) {
		performerHtml =
			'<div class="datamachine-event-performer">' +
			'<span class="dashicons dashicons-admin-users"></span>' +
			escapeHtml( performerName ) +
			'</div>';
	}

	const html =
		'<div class="datamachine-event-link">' +
		( data.badges_html || '' ) +
		'<h4 class="datamachine-event-title">' +
		'<a href="' +
		escapeAttr( data.permalink ) +
		'">' +
		escapeHtml( data.title ) +
		'</a>' +
		'</h4>' +
		'<div class="datamachine-event-meta">' +
		timeHtml +
		performerHtml +
		'<a href="' +
		escapeAttr( data.permalink ) +
		'" class="' +
		escapeAttr(
			data.button_classes || 'datamachine-more-info-button'
		) +
		'">More Info</a>' +
		'</div>' +
		'</div>';

	placeholder.className = itemClasses.join( ' ' );
	placeholder.removeAttribute( 'data-event-json' );
	placeholder.setAttribute( 'data-title', data.title );
	placeholder.setAttribute( 'data-venue', venueName );
	placeholder.setAttribute( 'data-performer', performerName );
	placeholder.setAttribute( 'data-date', isoStartDate );
	placeholder.setAttribute( 'data-ticket-url', ticketUrl );
	placeholder.setAttribute(
		'data-has-tickets',
		showTicketLink && ticketUrl ? 'true' : 'false'
	);
	placeholder.innerHTML = html;
}

function escapeHtml( str: string ): string {
	if ( ! str ) {
		return '';
	}
	const div = document.createElement( 'div' );
	div.textContent = str;
	return div.innerHTML;
}

function escapeAttr( str: string ): string {
	if ( ! str ) {
		return '';
	}
	return str
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}
