/**
 * Month-grid navigation + filter-driven re-rendering (#318).
 *
 * Wires up the prev/next/today month links to a fetch + re-render
 * cycle, and exposes a `handleFilterChange()` hook the frontend uses
 * to re-fetch the grid when search / taxonomy / scope filters change
 * (instead of triggering a full page reload like list mode does).
 *
 * Why a dedicated controller instead of folding it into frontend.ts:
 * keeps grid-mode-only behaviour out of the list-mode path, and
 * isolates the data-only REST format (`format=data`) consumer so the
 * legacy HTML-string envelope stays the contract for list mode.
 */

import { buildCalendarRequest } from './api-client';
import { renderMonthGrid } from './month-grid-renderer';

import type {
	ArchiveContext,
	CalendarDataResponse,
	GeoContext,
} from '../types';

const controllers = new WeakMap< HTMLElement, MonthGridController >();

class MonthGridController {
	constructor( private readonly calendar: HTMLElement ) {}

	init(): void {
		this.bindNavLinks();
	}

	destroy(): void {
		this.unbindNavLinks();
	}

	private getCurrentMonth(): string {
		const grid = this.calendar.querySelector< HTMLElement >(
			'.data-machine-month-grid'
		);
		const fromAttr = grid?.getAttribute( 'data-month' ) ?? '';
		if ( fromAttr ) {
			return fromAttr;
		}
		const urlMonth = new URLSearchParams( window.location.search ).get(
			'month'
		);
		if ( urlMonth && /^\d{4}-\d{2}$/.test( urlMonth ) ) {
			return urlMonth;
		}
		const now = new Date();
		return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart(
			2,
			'0'
		) }`;
	}

	private bindNavLinks(): void {
		this.calendar.addEventListener( 'click', this.onClick );
	}

	private unbindNavLinks(): void {
		this.calendar.removeEventListener( 'click', this.onClick );
	}

	private readonly onClick = ( event: Event ): void => {
		const target = event.target as HTMLElement | null;
		if ( ! target ) {
			return;
		}
		const link = target.closest< HTMLAnchorElement >(
			'.data-machine-month-grid__nav-prev, .data-machine-month-grid__nav-next, .data-machine-month-grid__nav-today'
		);
		if ( ! link ) {
			return;
		}
		const month = link.getAttribute( 'data-month' ) ?? '';
		if ( ! month ) {
			return;
		}
		event.preventDefault();
		void this.navigateToMonth( month );
	};

	/**
	 * Public entry point called by frontend.ts when a filter changes
	 * in grid mode.
	 */
	async handleFilterChange(): Promise< void > {
		await this.navigateToMonth( this.getCurrentMonth() );
	}

	/**
	 * Public entry point for callers that want to jump to a specific
	 * month (kept narrow so future external callers can re-use it).
	 */
	async navigateToMonth( month: string ): Promise< void > {
		const archiveContext = this.readArchiveContext();
		const geoContext = this.readGeoContext();

		const params = buildCalendarRequest( {
			archiveContext,
			geoContext,
			overrides: {
				// `format=data` activates the data-only REST envelope
				// (phase 1 of #298). Grid mode is the first consumer.
			},
		} );

		// Force grid-specific params. These can't go through
		// `overrides` because they aren't in the CalendarRequest type.
		params.set( 'format', 'data' );
		params.set( 'month', month );
		// Past / paged are irrelevant in grid mode — the month IS the
		// page. Drop them so they don't pollute the cache key.
		params.delete( 'past' );
		params.delete( 'paged' );

		const grid = this.calendar.querySelector< HTMLElement >(
			'.data-machine-month-grid'
		);
		if ( grid ) {
			grid.classList.add( 'is-loading' );
		}

		try {
			const apiUrl = `/wp-json/datamachine/v1/events/calendar?${ params.toString() }`;
			const response = await fetch( apiUrl, {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
				},
			} );

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}

			const data = ( await response.json() ) as CalendarDataResponse;
			if ( ! data?.success ) {
				throw new Error( 'Calendar response not successful' );
			}

			const baseUrl = this.buildBaseUrl();
			const newGrid = renderMonthGrid( month, data, baseUrl );

			if ( grid ) {
				grid.replaceWith( newGrid );
			} else {
				// First-time mount — append after the filter bar.
				const filterBar = this.calendar.querySelector(
					'.data-machine-events-filter-bar'
				);
				if ( filterBar?.parentElement ) {
					filterBar.parentElement.insertBefore(
						newGrid,
						filterBar.nextSibling
					);
				} else {
					this.calendar.prepend( newGrid );
				}
			}

			// Sync URL via pushState so prev/next history works and the
			// month is shareable. Preserve all other query params.
			const url = new URL( window.location.href );
			url.searchParams.set( 'month', month );
			url.searchParams.delete( 'paged' );
			url.searchParams.delete( 'past' );
			window.history.pushState( null, '', url.toString() );

			this.calendar.dispatchEvent(
				new CustomEvent( 'data-machine-month-grid-updated', {
					bubbles: false,
					detail: { month },
				} )
			);
		} catch ( error ) {
			console.error( 'Month-grid fetch failed:', error );
		} finally {
			const refreshedGrid = this.calendar.querySelector(
				'.data-machine-month-grid'
			);
			refreshedGrid?.classList.remove( 'is-loading' );
		}
	}

	/**
	 * Base URL used by the renderer for prev/next/today hrefs.
	 * Preserves every URL param except `month`/`paged`/`past`.
	 */
	private buildBaseUrl(): string {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'month' );
		url.searchParams.delete( 'paged' );
		url.searchParams.delete( 'past' );
		return `${ url.pathname }${
			url.searchParams.toString() ? '?' + url.searchParams.toString() : ''
		}`;
	}

	private readArchiveContext(): Partial< ArchiveContext > {
		const taxonomy = this.calendar.getAttribute( 'data-archive-taxonomy' );
		const termId = this.calendar.getAttribute( 'data-archive-term-id' );
		if ( taxonomy && termId ) {
			return {
				taxonomy,
				term_id: Number( termId ),
			};
		}
		return {};
	}

	private readGeoContext(): Partial< GeoContext > {
		const lat = this.calendar.getAttribute( 'data-geo-lat' );
		const lng = this.calendar.getAttribute( 'data-geo-lng' );
		if ( ! lat || ! lng ) {
			return {};
		}
		const radius = this.calendar.getAttribute( 'data-geo-radius' );
		const radiusUnit = this.calendar.getAttribute( 'data-geo-radius-unit' );
		return {
			lat,
			lng,
			radius: radius ? Number( radius ) : undefined,
			radius_unit:
				radiusUnit === 'mi' || radiusUnit === 'km'
					? radiusUnit
					: undefined,
		};
	}
}

export function initMonthGridNav( calendar: HTMLElement ): void {
	if ( controllers.has( calendar ) ) {
		return;
	}
	const controller = new MonthGridController( calendar );
	controllers.set( calendar, controller );
	controller.init();
}

export function destroyMonthGridNav( calendar: HTMLElement ): void {
	const controller = controllers.get( calendar );
	if ( ! controller ) {
		return;
	}
	controller.destroy();
	controllers.delete( calendar );
}

export function getMonthGridController(
	calendar: HTMLElement
): MonthGridController | undefined {
	return controllers.get( calendar );
}

export type { MonthGridController };
