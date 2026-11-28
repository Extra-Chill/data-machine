/** Resolve deferred day shells from the structured calendar endpoint. */

import { buildCalendarRequest, requestCalendarData } from './api-client';
import { initCarousel } from './carousel';
import { renderEventCard } from './event-renderer';

const instances = new WeakMap< HTMLElement, () => void >();

export function initDayLoader( calendar: HTMLElement ): void {
	const wrappers = calendar.querySelectorAll< HTMLElement >(
		'.data-machine-events-wrapper[data-deferred="true"]'
	);
	if ( ! wrappers.length || instances.has( calendar ) ) {
		return;
	}
	const controller = new AbortController();
	const reveals = new Map< Element, () => void >();
	const observer =
		typeof IntersectionObserver === 'undefined'
			? null
			: new IntersectionObserver(
					( entries ) => {
						for ( const entry of entries ) {
							if ( entry.isIntersecting ) {
								observer?.unobserve( entry.target );
								reveals.get( entry.target )?.();
								reveals.delete( entry.target );
							}
						}
					},
					{ rootMargin: '200px' }
			  );
	instances.set( calendar, () => {
		controller.abort();
		observer?.disconnect();
		reveals.clear();
	} );

	const params = buildCalendarRequest( {
		archiveContext: {
			taxonomy: calendar.dataset.archiveTaxonomy || '',
			term_id: Number( calendar.dataset.archiveTermId ),
		},
		geoContext: {
			lat: calendar.dataset.geoLat || '',
			lng: calendar.dataset.geoLng || '',
			radius: Number( calendar.dataset.geoRadius || 25 ),
			radius_unit: calendar.dataset.geoRadiusUnit === 'km' ? 'km' : 'mi',
		},
		overrides: { scope_token: calendar.dataset.scopeToken || '' },
	} );
	params.delete( 'paged' );

	async function prefetch(): Promise< void > {
		for ( const wrapper of wrappers ) {
			if ( controller.signal.aborted ) {
				return;
			}
			const group = wrapper.closest< HTMLElement >(
				'.data-machine-date-group'
			);
			const date = group?.dataset.date;
			if ( ! group || ! date ) {
				continue;
			}
			let visible = ! observer;
			let apply: ( () => void ) | undefined;
			reveals.set( wrapper, () => {
				visible = true;
				if ( ! controller.signal.aborted ) {
					apply?.();
				}
			} );
			observer?.observe( wrapper );
			if ( ! observer ) {
				reveals.delete( wrapper );
			}
			const dayParams = new URLSearchParams( params );
			dayParams.set( 'date_start', date );
			dayParams.set( 'date_end', date );
			const load = async (): Promise< void > => {
				try {
					const data = await requestCalendarData(
						dayParams,
						controller.signal
					);
					apply = () => {
						const events = new Map(
							data.events.map( ( event ) => [ event.id, event ] )
						);
						const cards = (
							data.grouping.by_date[ date ] || []
						).flatMap( ( occurrence ) => {
							const event = events.get( occurrence.post_id );
							return event
								? [ renderEventCard( event, occurrence ) ]
								: [];
						} );
						wrapper.replaceChildren( ...cards );
						delete wrapper.dataset.deferred;
						if (
							calendar.dataset.displayMode !== 'month-grid' &&
							! calendar.classList.contains(
								'data-machine-events-single-day-list'
							)
						) {
							initCarousel( calendar, [ group ] );
						}
					};
				} catch {
					apply = () => {
						const error = document.createElement( 'p' );
						error.className = 'data-machine-events-error';
						error.textContent = 'Unable to load events. ';
						const retry = document.createElement( 'button' );
						retry.type = 'button';
						retry.className = 'data-machine-retry-btn';
						retry.textContent = 'Retry';
						retry.addEventListener( 'click', () => {
							retry.disabled = true;
							void load();
						} );
						error.append( retry );
						wrapper.replaceChildren( error );
					};
				}
				if ( visible && ! controller.signal.aborted ) {
					apply();
				}
			};
			await load();
		}
	}
	void prefetch();
}

export function destroyDayLoader( calendar: HTMLElement ): void {
	instances.get( calendar )?.();
	instances.delete( calendar );
}
