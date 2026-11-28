jest.mock( './modules/carousel', () => ( {
	initCarousel: jest.fn(),
	destroyCarousel: jest.fn(),
} ) );
jest.mock( './modules/date-picker', () => ( {
	initDatePicker: jest.fn(
		( _calendar: HTMLElement, onChange: () => void ) => {
			mockDateChangeCallback = onChange;
			return mockPicker;
		}
	),
	destroyDatePicker: jest.fn(),
	getDatePicker: jest.fn( () => mockPicker ),
} ) );
jest.mock( './modules/filter-modal', () => ( {
	initFilterModal: jest.fn( ( _calendar, apply, reset ) => {
		mockApplyCallback = apply;
		mockResetCallback = reset;
	} ),
	destroyFilterModal: jest.fn(),
} ) );
jest.mock( './modules/navigation', () => ( {
	...jest.requireActual( './modules/navigation' ),
	initNavigation: jest.fn( ( _calendar, navigate ) => {
		mockNavigationCallback = navigate;
	} ),
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
	initScopePresets: jest.fn( ( _calendar, change ) => {
		mockScopeCallback = change;
	} ),
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
import { initCarousel } from './modules/carousel';

import type { FlatpickrInstance } from './types';
import type { CalendarQueryChangedDetail } from './modules/query-changed';

const queryEvent = 'data-machine-calendar-query-changed';
const queryListener = jest.fn();

function queryDetails(): CalendarQueryChangedDetail[] {
	return queryListener.mock.calls.map( ( [ event ] ) => event.detail );
}

let mockApplyCallback: () => void;
let mockScopeCallback: () => void;
let mockResetCallback: ( params: URLSearchParams ) => void;
let mockDateChangeCallback: () => void;
let mockNavigationCallback: (
	params: URLSearchParams,
	action: 'past' | 'upcoming' | 'today'
) => void;
const mockPicker: FlatpickrInstance = {
	selectedDates: [ new Date( 2026, 7, 10 ), new Date( 2026, 7, 12 ) ],
	clear: jest.fn(),
	setDate: jest.fn(),
	destroy: jest.fn(),
};
const mockFetch = jest.fn();
const mockRenderMonthGridResponse = renderMonthGridResponse as jest.Mock;
const mockInitCarousel = initCarousel as jest.Mock;

function successfulResponse(): Promise< Response > {
	return Promise.resolve( {
		ok: true,
		json: async () => ( {
			success: true,
			events: [],
			grouping: {},
			counter: { total_count: 7 },
		} ),
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
				json: async () => ( {
					success: true,
					events: [],
					grouping: {},
				} ),
			} as Response ),
	};
}

function calendarMarkup(
	withMap = false,
	mode = 'month-grid',
	scope = 'tonight'
): string {
	return `
		${
			withMap
				? '<div id="map-a" class="data-machine-events-map-root" data-sync-id="map-a"></div>'
				: ''
		}
		<div class="data-machine-events-calendar" data-display-mode="${ mode }"
			data-scope="${ scope }" data-scope-token="opaque-token"
			data-archive-taxonomy="location" data-archive-term-id="12">
			<div class="data-machine-events-filter-bar">
				<input class="data-machine-events-search-input" value="new search">
				<button class="data-machine-events-search-btn"></button>
				<div class="data-machine-taxonomy-filters-inline">
					<input type="checkbox" data-taxonomy="venue" value="42" checked>
				</div>
			</div>
			<div class="data-machine-month-grid" data-month="2026-08">
				<a class="data-machine-month-grid__nav-today" data-month="2026-07" href="#">Today</a>
			</div>
			<div class="data-machine-events-content">old mobile</div>
		</div>`;
}

async function flush(): Promise< void > {
	for ( let i = 0; i < 8; i++ ) {
		await Promise.resolve();
	}
}

describe( 'calendar frontend month-grid integration', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', '/events/' );
		document.body.innerHTML = calendarMarkup();
		queryListener.mockClear();
		document.body.addEventListener( queryEvent, queryListener );
		mockFetch.mockReset();
		mockFetch.mockImplementation( successfulResponse );
		global.fetch = mockFetch;
		mockRenderMonthGridResponse.mockClear();
		mockInitCarousel.mockClear();
		mockPicker.selectedDates = [
			new Date( 2026, 7, 10 ),
			new Date( 2026, 7, 12 ),
		];
	} );

	afterEach( () => {
		document.body.removeEventListener( queryEvent, queryListener );
		jest.useRealTimers();
	} );

	it( 'reports the initial snapshot once without fetching', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const snapshot = {
			query: {
				scope: 'tonight',
				date_start: '',
				date_end: '',
				has_search: false,
				event_search: '',
				tax_filter: {},
				paged: 1,
				month: '2026-08',
				past: false,
			},
			archive: { taxonomy: 'location', term_id: 12 },
			result_count: 11,
		};
		calendar.dataset.initialQuery = JSON.stringify( snapshot );
		initCalendarInstance( calendar );
		initCalendarInstance( calendar );

		expect( mockFetch ).not.toHaveBeenCalled();
		expect( queryDetails() ).toEqual( [
			{ trigger: 'initial', ...snapshot },
		] );
	} );

	it.each( [ 'scope', 'taxonomy_filter' ] )(
		'reports %s after its controls apply',
		async ( trigger ) => {
			const calendar = document.querySelector< HTMLElement >(
				'.data-machine-events-calendar'
			)!;
			initCalendarInstance( calendar );
			( trigger === 'scope' ? mockScopeCallback : mockApplyCallback )();
			await flush();

			expect( queryDetails() ).toEqual( [
				expect.objectContaining( { trigger, result_count: 7 } ),
			] );
		}
	);

	it( 'marks single-day scopes as vertical lists without carousel controls', () => {
		document.body.innerHTML = calendarMarkup(
			false,
			'date-groups',
			'tonight'
		);
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;

		initCalendarInstance( calendar );

		expect(
			calendar.classList.contains( 'data-machine-events-single-day-list' )
		).toBe( true );
		expect( mockInitCarousel ).not.toHaveBeenCalled();
	} );

	it( 'keeps multi-day date groups in carousel layout', () => {
		document.body.innerHTML = calendarMarkup(
			false,
			'date-groups',
			'this-week'
		);
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;

		initCalendarInstance( calendar );

		expect(
			calendar.classList.contains( 'data-machine-events-single-day-list' )
		).toBe( false );
		expect( mockInitCarousel ).toHaveBeenCalledWith( calendar );
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
		expect( queryDetails() ).toEqual( [
			{
				trigger: 'search',
				query: {
					scope: 'tonight',
					date_start: '2026-08-10',
					date_end: '2026-08-12',
					has_search: true,
					event_search: 'new search',
					tax_filter: { venue: [ 42 ] },
					paged: 1,
					month: '2026-08',
					past: false,
				},
				archive: { taxonomy: 'location', term_id: 12 },
				result_count: 7,
			},
		] );
		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toBe( 'mobile:2026-08' );
	} );

	it( 'applies one completed picker range through the month-grid controller', async () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );

		mockDateChangeCallback();
		await flush();

		expect( mockFetch ).toHaveBeenCalledTimes( 1 );
		const request = requestedParams( 0 );
		expect( request.get( 'date_start' ) ).toBe( '2026-08-10' );
		expect( request.get( 'date_end' ) ).toBe( '2026-08-12' );
		expect( queryDetails().map( ( detail ) => detail.trigger ) ).toEqual( [
			'date_range',
		] );
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
		expect( queryDetails().map( ( detail ) => detail.trigger ) ).toEqual( [
			'clear',
			'pagination',
		] );
	} );

	it( 'routes Today recovery through the grid controller and synchronizes controls', async () => {
		window.history.replaceState(
			{},
			'',
			'/events/?date_start=2026-08-10&past=1&paged=3&event_search=jam&tax_filter%5Bvenue%5D%5B%5D=42'
		);
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );
		const params = new URLSearchParams(
			'event_search=jam&tax_filter%5Bvenue%5D%5B%5D=42&scope=current'
		);

		mockNavigationCallback( params, 'today' );
		await flush();

		const request = requestedParams( 0 );
		expect( request.get( 'month' ) ).toBe( '2026-07' );
		expect( request.get( 'event_search' ) ).toBe( 'jam' );
		expect( request.getAll( 'tax_filter[venue][]' ) ).toEqual( [ '42' ] );
		expect( request.get( 'scope_token' ) ).toBe( 'opaque-token' );
		expect( request.has( 'date_start' ) ).toBe( false );
		expect( request.has( 'past' ) ).toBe( false );
		expect( request.has( 'paged' ) ).toBe( false );
		expect( mockPicker.setDate ).toHaveBeenCalledWith( [], false );
		expect( window.location.search ).toContain( 'month=2026-07' );
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
					syncId: 'map-a',
					generation: 1,
					bounds: {
						swLat: 32,
						swLng: -80,
						neLat: 33,
						neLng: -79,
					},
					zoom: 10,
					center: { lat: 32.78, lng: -79.93 },
					authority: 'user-interaction',
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
		expect( queryDetails().map( ( detail ) => detail.trigger ) ).toEqual( [
			'search',
		] );
	} );

	it( 'renders list-mode geo updates from structured data', async () => {
		jest.useFakeTimers();
		document.body.innerHTML = calendarMarkup( true, 'date-groups' );
		mockFetch.mockResolvedValueOnce( {
			ok: true,
			json: async () => ( {
				success: true,
				events: [],
				grouping: { ordered_dates: [], by_date: {}, gaps: {} },
				pagination: { current_page: 1, total_pages: 1 },
				counter: { showing_count: 0 },
				navigation: { has_past: true },
			} ),
		} as Response );
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCalendarInstance( calendar );

		document.dispatchEvent(
			new CustomEvent( 'data-machine-map-bounds-changed', {
				detail: {
					syncId: 'map-a',
					generation: 1,
					bounds: {
						swLat: 32,
						swLng: -80,
						neLat: 33,
						neLng: -79,
					},
					zoom: 10,
					center: { lat: 32.78, lng: -79.93 },
					authority: 'user-interaction',
				},
			} )
		);
		jest.advanceTimersByTime( 300 );
		await flush();

		expect( requestedParams( 0 ).has( 'format' ) ).toBe( false );
		expect( queryDetails() ).toEqual( [
			expect.objectContaining( {
				trigger: 'geo',
				archive: { taxonomy: 'location', term_id: 12 },
				result_count: 0,
			} ),
		] );
		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toContain( 'No events found.' );
		const past = calendar.querySelector< HTMLAnchorElement >(
			'.data-machine-events-past-btn'
		)!;
		expect( new URL( past.href ).pathname ).toBe( '/events/' );
		expect( new URL( past.href ).search ).toBe( '?scope=tonight&past=1' );
		expect( mockRenderMonthGridResponse ).not.toHaveBeenCalled();
	} );
} );
