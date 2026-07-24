jest.mock( './modules/carousel', () => ( {
	initCarousel: jest.fn(),
	destroyCarousel: jest.fn(),
} ) );
jest.mock( './modules/date-picker', () => ( {
	initDatePicker: jest.fn(),
	destroyDatePicker: jest.fn(),
	getDatePicker: jest.fn( () => mockPicker ),
} ) );
jest.mock( './modules/filter-modal', () => ( {
	initFilterModal: jest.fn( ( _calendar, _apply, reset ) => {
		mockResetCallback = reset;
	} ),
	destroyFilterModal: jest.fn(),
} ) );
jest.mock( './modules/navigation', () => ( {
	initNavigation: jest.fn(),
} ) );
jest.mock( './modules/lazy-render', () => ( {
	initLazyRender: jest.fn(),
	destroyLazyRender: jest.fn(),
} ) );
jest.mock( './modules/day-loader', () => ( {
	initDayLoader: jest.fn(),
	destroyDayLoader: jest.fn(),
} ) );
jest.mock( './modules/load-more', () => ( {
	initLoadMore: jest.fn(),
	destroyLoadMore: jest.fn(),
} ) );
jest.mock( './modules/scope-presets', () => ( {
	initScopePresets: jest.fn(),
} ) );
jest.mock( './modules/month-grid-response-renderer', () => ( {
	renderMonthGridResponse: jest.fn(
		( calendar: HTMLElement, month: string ) => {
			const grid = global.document.createElement( 'div' );
			grid.className = 'data-machine-month-grid';
			grid.dataset.month = month;
			grid.innerHTML =
				'<a class="data-machine-month-grid__nav-next" data-month="2026-09" href="#">Next</a>';
			calendar
				.querySelector( '.data-machine-month-grid' )
				?.replaceWith( grid );
			calendar.querySelector(
				'.data-machine-events-content'
			)!.textContent = `mobile:${ month }`;
		}
	),
} ) );

/**
 * Internal dependencies
 */
import { renderMonthGridResponse } from './modules/month-grid-response-renderer';
import { initCalendarInstance } from './frontend';

import type { FlatpickrInstance } from './types';

let mockResetCallback: ( params: URLSearchParams ) => void;
const mockPicker: FlatpickrInstance = {
	selectedDates: [ new Date( 2026, 7, 10 ), new Date( 2026, 7, 12 ) ],
	clear: jest.fn(),
	setDate: jest.fn(),
	destroy: jest.fn(),
};
const mockFetch = jest.fn();
const mockRenderMonthGridResponse = renderMonthGridResponse as jest.Mock;

function successfulResponse(): Promise< Response > {
	return Promise.resolve( {
		ok: true,
		json: async () => ( { success: true } ),
	} as Response );
}

function requestedParams( call: number ): URLSearchParams {
	return new URL( mockFetch.mock.calls[ call ][ 0 ], window.location.origin )
		.searchParams;
}

function deferredResponse(): {
	promise: Promise< Response >;
	resolve: () => void;
} {
	let resolvePromise!: ( response: Response ) => void;
	const promise = new Promise< Response >( ( resolve ) => {
		resolvePromise = resolve;
	} );
	return {
		promise,
		resolve: () =>
			resolvePromise( {
				ok: true,
				json: async () => ( { success: true } ),
			} as Response ),
	};
}

function calendarMarkup( withMap = false, mode = 'month-grid' ): string {
	return `
		${ withMap ? '<div class="data-machine-events-map-root"></div>' : '' }
		<div class="data-machine-events-calendar" data-display-mode="${ mode }"
			data-scope="tonight" data-scope-token="opaque-token"
			data-archive-taxonomy="location" data-archive-term-id="12">
			<div class="data-machine-events-filter-bar">
				<input class="data-machine-events-search-input" value="new search">
				<button class="data-machine-events-search-btn"></button>
				<div class="data-machine-taxonomy-filters-inline">
					<input type="checkbox" data-taxonomy="venue" value="42" checked>
				</div>
			</div>
			<div class="data-machine-month-grid" data-month="2026-08"></div>
			<div class="data-machine-events-content">old mobile</div>
		</div>`;
}

async function flush(): Promise< void > {
	await Promise.resolve();
	await Promise.resolve();
}

describe( 'calendar frontend month-grid integration', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', '/events/' );
		document.body.innerHTML = calendarMarkup();
		mockFetch.mockReset();
		mockFetch.mockImplementation( successfulResponse );
		global.fetch = mockFetch;
		mockRenderMonthGridResponse.mockClear();
		mockPicker.selectedDates = [
			new Date( 2026, 7, 10 ),
			new Date( 2026, 7, 12 ),
		];
	} );

	afterEach( () => {
		window.dispatchEvent( new Event( 'beforeunload' ) );
		jest.useRealTimers();
	} );

	it( 'submits real controls, default scope, archive, and opaque token', async () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );

		calendar
			.querySelector< HTMLButtonElement >(
				'.data-machine-events-search-btn'
			)!
			.click();
		await flush();

		const request = requestedParams( 0 );
		expect( request.get( 'event_search' ) ).toBe( 'new search' );
		expect( request.get( 'date_start' ) ).toBe( '2026-08-10' );
		expect( request.get( 'date_end' ) ).toBe( '2026-08-12' );
		expect( request.getAll( 'tax_filter[venue][]' ) ).toEqual( [ '42' ] );
		expect( request.get( 'scope' ) ).toBe( 'tonight' );
		expect( request.get( 'archive_taxonomy' ) ).toBe( 'location' );
		expect( request.get( 'scope_token' ) ).toBe( 'opaque-token' );
		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toBe( 'mobile:2026-08' );
	} );

	it( 'keeps reset, month navigation, and popstate on the controller path', async () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );
		mockResetCallback( new URLSearchParams() );
		await flush();
		expect( requestedParams( 0 ).get( 'event_search' ) ).toBeNull();
		expect( requestedParams( 0 ).get( 'scope' ) ).toBe( 'tonight' );

		calendar
			.querySelector< HTMLAnchorElement >(
				'.data-machine-month-grid__nav-next'
			)!
			.click();
		await flush();
		expect( requestedParams( 1 ).get( 'month' ) ).toBe( '2026-09' );

		window.history.replaceState(
			{},
			'',
			'/events/?event_search=back&month=2026-07'
		);
		window.dispatchEvent( new PopStateEvent( 'popstate' ) );
		await flush();
		expect( requestedParams( 2 ).get( 'event_search' ) ).toBe( 'back' );
		expect( requestedParams( 2 ).get( 'month' ) ).toBe( '2026-07' );
	} );

	it( 'sequences overlapping geo and filter requests globally', async () => {
		jest.useFakeTimers();
		document.body.innerHTML = calendarMarkup( true );
		const geo = deferredResponse();
		const filter = deferredResponse();
		mockFetch
			.mockImplementationOnce( () => geo.promise )
			.mockImplementationOnce( () => filter.promise );
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );

		document.dispatchEvent(
			new CustomEvent( 'data-machine-map-bounds-changed', {
				detail: {
					bounds: { swLat: 32, swLng: -80, neLat: 33, neLng: -79 },
					zoom: 10,
					center: { lat: 32.78, lng: -79.93 },
				},
			} )
		);
		jest.advanceTimersByTime( 300 );
		calendar
			.querySelector< HTMLButtonElement >(
				'.data-machine-events-search-btn'
			)!
			.click();

		filter.resolve();
		await flush();
		geo.resolve();
		await flush();

		expect( requestedParams( 0 ).get( 'lat' ) ).toBe( '32.78' );
		expect( requestedParams( 1 ).get( 'event_search' ) ).toBe(
			'new search'
		);
		expect( mockRenderMonthGridResponse ).toHaveBeenCalledTimes( 1 );
		expect( window.location.search ).toContain( 'event_search=new+search' );
		expect( window.location.search ).not.toContain( 'lat=' );
	} );

	it( 'leaves list-mode geo updates on the existing HTML response path', async () => {
		jest.useFakeTimers();
		document.body.innerHTML = calendarMarkup( true, 'date-groups' );
		mockFetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				html: '<p>updated list</p>',
				pagination: null,
				counter: null,
				navigation: null,
			} ),
		} as Response );
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );

		document.dispatchEvent(
			new CustomEvent( 'data-machine-map-bounds-changed', {
				detail: {
					bounds: { swLat: 32, swLng: -80, neLat: 33, neLng: -79 },
					zoom: 10,
					center: { lat: 32.78, lng: -79.93 },
				},
			} )
		);
		jest.advanceTimersByTime( 300 );
		await flush();

		expect( requestedParams( 0 ).has( 'format' ) ).toBe( false );
		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toBe( 'updated list' );
		expect( mockRenderMonthGridResponse ).not.toHaveBeenCalled();
	} );
} );
