/**
 * Lazy render module for event placeholders
 *
 * Uses IntersectionObserver to hydrate event placeholders as they
 * approach the viewport during horizontal scroll.
 */

/**
 * Internal dependencies
 */
import { renderEventItem } from './event-renderer';
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
		'.data-machine-events-wrapper'
	);

	wrappers.forEach( function ( wrapper ) {
		const observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						hydratePlaceholder( entry.target as HTMLElement );
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
			'.data-machine-event-placeholder'
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
		'.data-machine-event-placeholder'
	);
	placeholders.forEach( hydratePlaceholder );
}

function hydratePlaceholder( placeholder: HTMLElement ): void {
	if (
		! placeholder.classList.contains( 'data-machine-event-placeholder' )
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
		window.console.error( 'Failed to parse event placeholder data:', e );
		return;
	}

	const display: Partial< EventDisplayVars > = data.display_vars || {};
	placeholder.removeAttribute( 'data-event-json' );
	renderEventItem(
		{
			...data,
			ticket: {
				url: display.ticket_url || '',
				is_affiliate: display.is_affiliate_ticket,
			},
		},
		display,
		placeholder
	);
}
