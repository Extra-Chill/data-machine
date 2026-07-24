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

/**
 * Internal dependencies
 */
import { buildCalendarRequest } from './api-client';
import { renderMonthGridResponse } from './month-grid-response-renderer';

import type {
	ArchiveContext,
	CalendarDataResponse,
	GeoContext,
} from '../types';

const controllers = new WeakMap< HTMLElement, MonthGridController >();

class MonthGridController {
	private requestSequence = 0;
	private readonly initialMonth: string;

	constructor(
		private readonly calendar: HTMLElement,
		private readonly onPopState?: ( params: URLSearchParams ) => void
	) {
		this.initialMonth = this.getCurrentMonth();
	}

	init(): void {
		this.bindNavLinks();
		window.addEventListener( 'popstate', this.handlePopState );
	}

	destroy(): void {
		this.unbindNavLinks();
		window.removeEventListener( 'popstate', this.handlePopState );
		this.requestSequence++;
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

	private readonly handlePopState = (): void => {
		const params = new URLSearchParams( window.location.search );
		this.onPopState?.( params );
		const month = params.get( 'month' );
		void this.navigateToMonth(
			month && /^\d{4}-\d{2}$/.test( month )
				? month
				: this.initialMonth,
			params,
			false
		);
	};

	/**
	 * Public entry point called by frontend.ts when a filter changes
	 * in grid mode.
	 * @param params
	 */
	async handleFilterChange( params: URLSearchParams ): Promise< boolean > {
		return this.navigateToMonth(
			this.getCurrentMonth(),
			params,
			true,
			true
		);
	}

	/**
	 * Public entry point for callers that want to jump to a specific
	 * month (kept narrow so future external callers can re-use it).
	 * @param month
	 * @param source
	 * @param pushHistory
	 * @param syncGeoState
	 */
	async navigateToMonth(
		month: string,
		source: URLSearchParams = new URLSearchParams( window.location.search ),
		pushHistory = true,
		syncGeoState = false
	): Promise< boolean > {
		const requestId = ++this.requestSequence;
		const publicParams = this.buildPublicParams( source, month );
		const archiveContext = this.readArchiveContext();
		const geoContext = this.readGeoContext(
			publicParams,
			pushHistory && ! syncGeoState
		);

		const params = buildCalendarRequest( {
			archiveContext,
			geoContext,
			source: publicParams,
			overrides: {
				// `format=data` activates the data-only REST envelope
				// (phase 1 of #298). Grid mode is the first consumer.
			},
		} );

		// Force grid-specific params. These can't go through
		// `overrides` because they aren't in the CalendarRequest type.
		params.set( 'format', 'data' );
		params.set( 'month', month );

		// #160: re-send the opaque scope token so a consumer's server-side
		// query constraint (e.g. owner scoping) survives the prev/next
		// REST fetch. The token rides on the calendar root as
		// `data-scope-token`; the URL has no `?scope_token=` on the
		// embedded-calendar first paint, so reading it from the DOM is the
		// authoritative source. data-machine-events never interprets it.
		const scopeToken = this.readScopeToken();
		if ( scopeToken ) {
			params.set( 'scope_token', scopeToken );
		} else {
			// No token present — make sure a stale one never leaks in from
			// the URL passthrough for an unscoped calendar.
			params.delete( 'scope_token' );
		}
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
			if ( requestId !== this.requestSequence ) {
				return false;
			}

			const baseUrl = this.buildBaseUrl( publicParams );
			renderMonthGridResponse(
				this.calendar,
				month,
				data,
				baseUrl,
				publicParams
			);

			// Sync URL via pushState so prev/next history works and the
			// month is shareable. Preserve all other query params.
			if ( pushHistory ) {
				this.pushUrl( publicParams );
			}
			if ( syncGeoState ) {
				this.syncGeoState( publicParams );
			}

			this.calendar.dispatchEvent(
				new CustomEvent( 'data-machine-month-grid-updated', {
					bubbles: false,
					detail: { month },
				} )
			);
			return true;
		} catch ( error ) {
			if ( requestId === this.requestSequence ) {
				window.console.error( 'Month-grid fetch failed:', error );
			}
			return false;
		} finally {
			if ( requestId === this.requestSequence ) {
				const refreshedGrid = this.calendar.querySelector(
					'.data-machine-month-grid'
				);
				refreshedGrid?.classList.remove( 'is-loading' );
			}
		}
	}

	private buildPublicParams(
		source: URLSearchParams,
		month: string
	): URLSearchParams {
		const params = new URLSearchParams( source );
		const hasScopeControls = Boolean(
			this.calendar.querySelector(
				'.data-machine-events-scope-chip[data-scope]'
			)
		);
		if ( ! params.has( 'scope' ) && ! hasScopeControls ) {
			const defaultScope = this.calendar.dataset.scope || '';
			if ( defaultScope && defaultScope !== 'current' ) {
				params.set( 'scope', defaultScope );
			}
		}
		[
			'format',
			'archive_taxonomy',
			'archive_term_id',
			'scope_token',
			'paged',
			'past',
		].forEach( ( key ) => params.delete( key ) );
		params.set( 'month', month );
		return params;
	}

	private pushUrl( params: URLSearchParams ): void {
		const url = new URL( window.location.href );
		url.search = params.toString();
		const current = `${ window.location.pathname }${ window.location.search }${ window.location.hash }`;
		const next = `${ url.pathname }${ url.search }${ url.hash }`;
		if ( next !== current ) {
			window.history.pushState( null, '', next );
		}
	}

	private syncGeoState( params: URLSearchParams ): void {
		const geoAttributes = {
			geoLat: params.get( 'lat' ) || '',
			geoLng: params.get( 'lng' ) || '',
			geoRadius: params.get( 'radius' ) || '',
			geoRadiusUnit: params.get( 'radius_unit' ) || '',
		};
		Object.entries( geoAttributes ).forEach( ( [ key, value ] ) => {
			if ( value ) {
				this.calendar.dataset[ key ] = value;
			} else {
				delete this.calendar.dataset[ key ];
			}
		} );
	}

	/**
	 * Base URL used by the renderer for prev/next/today hrefs.
	 * Preserves every URL param except `month`/`paged`/`past`.
	 * @param params
	 */
	private buildBaseUrl( params: URLSearchParams ): string {
		const url = new URL( window.location.href );
		url.search = params.toString();
		url.searchParams.delete( 'month' );
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

	/**
	 * Read the opaque scope token emitted on the calendar root by
	 * render.php (`data-scope-token`). Empty string when absent. #160.
	 */
	private readScopeToken(): string {
		return this.calendar.getAttribute( 'data-scope-token' ) ?? '';
	}

	private readGeoContext(
		params: URLSearchParams,
		allowDomFallback: boolean
	): Partial< GeoContext > {
		const urlLat = params.get( 'lat' );
		const urlLng = params.get( 'lng' );
		if ( urlLat && urlLng ) {
			const radius = params.get( 'radius' );
			const radiusUnit = params.get( 'radius_unit' );
			return {
				lat: urlLat,
				lng: urlLng,
				radius: radius ? Number( radius ) : undefined,
				radius_unit:
					radiusUnit === 'mi' || radiusUnit === 'km'
						? radiusUnit
						: undefined,
			};
		}
		if ( ! allowDomFallback ) {
			return {};
		}
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

export function initMonthGridNav(
	calendar: HTMLElement,
	onPopState?: ( params: URLSearchParams ) => void
): void {
	if ( controllers.has( calendar ) ) {
		return;
	}
	const controller = new MonthGridController( calendar, onPopState );
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
