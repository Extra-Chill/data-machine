jest.mock( './month-grid-response-renderer', () => ( {
	renderMonthGridResponse: jest.fn(
		( calendar: HTMLElement, month: string, _data, baseUrl: string ) => {
			const grid = global.document.createElement( 'div' );
			grid.className = 'data-machine-month-grid';
			grid.dataset.month = month;
			grid.dataset.baseUrl = baseUrl;
			grid.innerHTML = `<a class="data-machine-month-grid__nav-next" data-month="2026-09" href="#">Next</a>`;
			calendar
				.querySelector( '.data-machine-month-grid' )
				?.replaceWith( grid );
		}
	),
} ) );

import { renderMonthGridResponse } from './month-grid-response-renderer';
import {
	destroyMonthGridNav,
	getMonthGridController,
	initMonthGridNav,
} from './month-grid-nav';

const mockRenderMonthGridResponse = renderMonthGridResponse as jest.Mock;
const mockFetch = jest.fn();

function successfulResponse(): Promise< Response > {
	return Promise.resolve( {
		ok: true,
		json: async () => ( { success: true } ),
	} as Response );
}

function requestedParams( call = 0 ): URLSearchParams {
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
		resolve: () => {
			resolvePromise( {
				ok: true,
				json: async () => ( { success: true } ),
			} as Response );
		},
	};
}

describe( 'month-grid state synchronization', () => {
	let calendar: HTMLElement;

	beforeEach( () => {
		window.history.replaceState(
			{},
			'',
			'/events/?event_search=old&month=2026-07'
		);
		document.body.innerHTML = `
			<div class="data-machine-events-calendar"
				data-archive-taxonomy="location"
				data-archive-term-id="12">
				<div class="data-machine-month-grid" data-month="2026-08">
					<a class="data-machine-month-grid__nav-next" data-month="2026-09" href="#">Next</a>
				</div>
			</div>`;
		calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		mockFetch.mockReset();
		mockFetch.mockImplementation( successfulResponse );
		global.fetch = mockFetch;
		mockRenderMonthGridResponse.mockClear();
	} );

	afterEach( () => {
		destroyMonthGridNav( calendar );
		jest.restoreAllMocks();
	} );

	it( 'uses one current parameter set for filters, request, links, and URL', async () => {
		initMonthGridNav( calendar );
		const params = new URLSearchParams();
		params.set( 'event_search', 'new search' );
		params.set( 'date_start', '2026-08-10' );
		params.set( 'date_end', '2026-08-12' );
		params.set( 'scope', 'this-week' );
		params.append( 'tax_filter[venue][]', '42' );
		params.set( 'lat', '32.78' );
		params.set( 'lng', '-79.93' );
		params.set( 'radius', '50' );
		params.set( 'radius_unit', 'mi' );

		await getMonthGridController( calendar )!.handleFilterChange( params );

		const request = requestedParams();
		expect( request.get( 'event_search' ) ).toBe( 'new search' );
		expect( request.get( 'date_start' ) ).toBe( '2026-08-10' );
		expect( request.get( 'date_end' ) ).toBe( '2026-08-12' );
		expect( request.get( 'scope' ) ).toBe( 'this-week' );
		expect( request.getAll( 'tax_filter[venue][]' ) ).toEqual( [ '42' ] );
		expect( request.get( 'lat' ) ).toBe( '32.78' );
		expect( request.get( 'lng' ) ).toBe( '-79.93' );
		expect( request.get( 'archive_taxonomy' ) ).toBe( 'location' );
		expect( request.get( 'archive_term_id' ) ).toBe( '12' );
		expect( request.get( 'month' ) ).toBe( '2026-08' );
		expect( request.get( 'format' ) ).toBe( 'data' );

		expect( window.location.search ).toContain( 'event_search=new+search' );
		expect( window.location.search ).toContain( 'month=2026-08' );
		expect( window.location.search ).not.toContain( 'event_search=old' );
		expect( window.location.search ).not.toContain( 'archive_' );
		expect( mockRenderMonthGridResponse.mock.calls[ 0 ][ 3 ] ).toContain(
			'event_search=new+search'
		);
	} );

	it( 'clears stale filters without adding duplicate history entries', async () => {
		const pushState = jest.spyOn( window.history, 'pushState' );
		calendar.dataset.geoLat = '1';
		calendar.dataset.geoLng = '2';
		initMonthGridNav( calendar );

		await getMonthGridController( calendar )!.handleFilterChange(
			new URLSearchParams()
		);

		expect( requestedParams().get( 'event_search' ) ).toBeNull();
		expect( requestedParams().get( 'lat' ) ).toBeNull();
		expect( window.location.search ).toBe( '?month=2026-08' );
		expect( pushState ).toHaveBeenCalledTimes( 1 );
		expect( calendar.dataset.geoLat ).toBeUndefined();
		await getMonthGridController( calendar )!.handleFilterChange(
			new URLSearchParams()
		);
		expect( pushState ).toHaveBeenCalledTimes( 1 );

		calendar
			.querySelector< HTMLAnchorElement >(
				'.data-machine-month-grid__nav-next'
			)!
			.click();
		await Promise.resolve();
		await Promise.resolve();
		expect( requestedParams( 2 ).get( 'lat' ) ).toBeNull();
		expect( pushState ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'preserves synchronized filters during subsequent month navigation', async () => {
		initMonthGridNav( calendar );
		await getMonthGridController( calendar )!.handleFilterChange(
			new URLSearchParams( 'event_search=jam&scope=this-weekend' )
		);

		calendar
			.querySelector< HTMLAnchorElement >(
				'.data-machine-month-grid__nav-next'
			)!
			.click();
		await Promise.resolve();
		await Promise.resolve();

		const request = requestedParams( 1 );
		expect( request.get( 'event_search' ) ).toBe( 'jam' );
		expect( request.get( 'scope' ) ).toBe( 'this-weekend' );
		expect( request.get( 'month' ) ).toBe( '2026-09' );
	} );

	it( 'keeps embedded scope tokens in requests and out of public URLs', async () => {
		calendar.dataset.scopeToken = 'signed-consumer-scope';
		initMonthGridNav( calendar );

		await getMonthGridController( calendar )!.handleFilterChange(
			new URLSearchParams( 'event_search=artist' )
		);

		expect( requestedParams().get( 'scope_token' ) ).toBe(
			'signed-consumer-scope'
		);
		expect( window.location.search ).not.toContain( 'scope_token' );
	} );

	it( 're-fetches popstate without pushing another history entry', async () => {
		const onPopState = jest.fn();
		const pushState = jest.spyOn( window.history, 'pushState' );
		initMonthGridNav( calendar, onPopState );
		window.history.replaceState(
			{},
			'',
			'/events/?event_search=back&scope=tonight&month=2026-06'
		);

		window.dispatchEvent( new PopStateEvent( 'popstate' ) );
		await Promise.resolve();
		await Promise.resolve();

		expect( onPopState ).toHaveBeenCalledWith(
			expect.objectContaining( {} )
		);
		expect( requestedParams().get( 'event_search' ) ).toBe( 'back' );
		expect( requestedParams().get( 'month' ) ).toBe( '2026-06' );
		expect( pushState ).not.toHaveBeenCalled();
	} );

	it( 'ignores a stale response when rapid filter requests overlap', async () => {
		const stale = deferredResponse();
		const latest = deferredResponse();
		mockFetch
			.mockImplementationOnce( () => stale.promise )
			.mockImplementationOnce( () => latest.promise );
		initMonthGridNav( calendar );
		const controller = getMonthGridController( calendar )!;

		const staleRequest = controller.handleFilterChange(
			new URLSearchParams( 'event_search=stale' )
		);
		const latestRequest = controller.handleFilterChange(
			new URLSearchParams( 'event_search=latest' )
		);
		latest.resolve();
		await latestRequest;
		stale.resolve();
		await staleRequest;

		expect( mockRenderMonthGridResponse ).toHaveBeenCalledTimes( 1 );
		expect( window.location.search ).toContain( 'event_search=latest' );
		expect( window.location.search ).not.toContain( 'event_search=stale' );
	} );
} );
