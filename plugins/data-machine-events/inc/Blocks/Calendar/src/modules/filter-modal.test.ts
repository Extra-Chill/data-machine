import { destroyFilterModal, initFilterModal } from './filter-modal';
import { destroyFilterState } from './filter-state';

const mockFetch = jest.fn();

function response( name: string, count = 1 ): Response {
	return {
		ok: true,
		json: async () => ( {
			success: true,
			taxonomies: {
				venue: {
					label: 'Venues',
					terms: [
						{
							term_id: count,
							name,
							slug: name.toLowerCase(),
							event_count: count,
							children: [],
						},
					],
				},
			},
		} ),
	} as Response;
}

function deferred(): {
	promise: Promise< Response >;
	resolve: ( value: Response ) => void;
} {
	let resolve!: ( value: Response ) => void;
	return {
		promise: new Promise< Response >( ( done ) => {
			resolve = done;
		} ),
		resolve: ( value ) => resolve( value ),
	};
}

function flush(): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'dynamic filter modal requests', () => {
	let calendar: HTMLElement;

	beforeEach( () => {
		window.history.replaceState(
			{},
			'',
			'/events/?event_search=jam&scope=this-week&date_start=2026-07-19&date_end=2026-07-20'
		);
		document.body.innerHTML = `
			<div class="data-machine-events-calendar" data-scope-token="opaque.signed.token" data-geo-lat="32.78" data-geo-lng="-79.93" data-geo-radius="25" data-geo-radius-unit="mi">
				<button class="data-machine-taxonomy-filter-btn"></button>
				<div class="data-machine-taxonomy-modal">
					<div class="data-machine-filter-loading" style="display:none"></div>
					<div class="data-machine-filter-taxonomies"></div>
				</div>
			</div>`;
		calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		mockFetch.mockReset();
		global.fetch = mockFetch;
	} );

	afterEach( () => {
		destroyFilterModal( calendar );
		destroyFilterState( calendar );
		jest.restoreAllMocks();
	} );

	it( 'sends search, scope, date, geo, and opaque scope token constraints', async () => {
		mockFetch.mockResolvedValue( response( 'Matching venue' ) );
		initFilterModal( calendar, jest.fn(), jest.fn() );
		calendar.querySelector< HTMLButtonElement >( '.data-machine-taxonomy-filter-btn' )!.click();
		await flush();

		const url = new URL( mockFetch.mock.calls[ 0 ][ 0 ], window.location.origin );
		expect( url.searchParams.get( 'event_search' ) ).toBe( 'jam' );
		expect( url.searchParams.get( 'scope' ) ).toBe( 'this-week' );
		expect( url.searchParams.get( 'date_start' ) ).toBe( '2026-07-19' );
		expect( url.searchParams.get( 'date_end' ) ).toBe( '2026-07-20' );
		expect( url.searchParams.get( 'lat' ) ).toBe( '32.78' );
		expect( url.searchParams.get( 'scope_token' ) ).toBe( 'opaque.signed.token' );
	} );

	it( 'aborts the previous request and ignores its out-of-order response', async () => {
		const stale = deferred();
		const latest = deferred();
		mockFetch
			.mockImplementationOnce( () => stale.promise )
			.mockImplementationOnce( () => latest.promise );
		initFilterModal( calendar, jest.fn(), jest.fn() );
		const trigger = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-taxonomy-filter-btn'
		)!;
		trigger.click();
		trigger.click();

		const firstSignal = mockFetch.mock.calls[ 0 ][ 1 ].signal as AbortSignal;
		expect( firstSignal.aborted ).toBe( true );
		latest.resolve( response( 'Latest venue', 2 ) );
		await flush();
		stale.resolve( response( 'Stale venue', 3 ) );
		await flush();

		expect( calendar.textContent ).toContain( 'Latest venue' );
		expect( calendar.textContent ).not.toContain( 'Stale venue' );
		expect(
			calendar.querySelector< HTMLElement >( '.data-machine-filter-loading' )!
				.style.display
		).toBe( 'none' );
	} );

	it( 'retains prior options and offers retry after a refresh failure', async () => {
		mockFetch.mockResolvedValueOnce( response( 'Usable venue' ) );
		initFilterModal( calendar, jest.fn(), jest.fn() );
		calendar.querySelector< HTMLButtonElement >( '.data-machine-taxonomy-filter-btn' )!.click();
		await flush();

		mockFetch.mockRejectedValueOnce( new Error( 'network failure' ) );
		calendar.querySelector< HTMLInputElement >( '.data-machine-term-checkbox' )!.click();
		await flush();

		expect( calendar.textContent ).toContain( 'Usable venue' );
		expect( calendar.textContent ).toContain( 'Previous options are still available' );
		expect( calendar.querySelector( '.data-machine-filter-retry' ) ).not.toBeNull();
	} );
} );
