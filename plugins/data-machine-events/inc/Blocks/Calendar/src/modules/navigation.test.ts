/**
 * Internal dependencies
 */
import { initNavigation } from './navigation';

describe( 'calendar empty-state recovery', () => {
	beforeEach( () => {
		window.history.replaceState( {}, '', '/events/' );
		document.body.innerHTML = `
			<div class="data-machine-events-calendar"
				data-archive-taxonomy="location"
				data-archive-term-id="12"
				data-scope-token="signed-scope">
				<div class="data-machine-events-content">
					<div class="data-machine-events-no-events">
						<button type="button" class="data-machine-events-no-events-today-link">Show events from Today</button>
					</div>
				</div>
			</div>`;
	} );

	it( 'clears temporal state while preserving result constraints', () => {
		window.history.replaceState(
			{},
			'',
			'/events/?date_start=2026-08-10&date_end=2026-08-12&past=1&paged=4&month=2026-08&scope=this-week&event_search=jam&tax_filter%5Bvenue%5D%5B%5D=42&lat=32.78&lng=-79.93&radius=50&radius_unit=mi'
		);
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const navigate = jest.fn();
		initNavigation( calendar, navigate );

		const button = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-events-no-events-today-link'
		)!;
		expect( button.type ).toBe( 'button' );
		expect( button.hidden ).toBe( false );
		button.click();

		const [ params, action ] = navigate.mock.calls[ 0 ];
		expect( action ).toBe( 'today' );
		expect( params.get( 'date_start' ) ).toBeNull();
		expect( params.get( 'date_end' ) ).toBeNull();
		expect( params.get( 'past' ) ).toBeNull();
		expect( params.get( 'paged' ) ).toBeNull();
		expect( params.get( 'month' ) ).toBeNull();
		expect( params.get( 'scope' ) ).toBe( 'current' );
		expect( params.get( 'event_search' ) ).toBe( 'jam' );
		expect( params.getAll( 'tax_filter[venue][]' ) ).toEqual( [ '42' ] );
		expect( params.get( 'lat' ) ).toBe( '32.78' );
		expect( params.get( 'lng' ) ).toBe( '-79.93' );
		expect( calendar.dataset.archiveTaxonomy ).toBe( 'location' );
		expect( calendar.dataset.scopeToken ).toBe( 'signed-scope' );
	} );

	it( 'uses delegation for dynamically replaced empty markup', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		calendar.querySelector( '.data-machine-events-no-events' )?.remove();
		const navigate = jest.fn();
		initNavigation( calendar, navigate );

		window.history.replaceState(
			{},
			'',
			'/events/?date_start=2026-08-10&event_search=artist'
		);
		calendar.querySelector( '.data-machine-events-content' )!.innerHTML = `
			<div class="data-machine-events-no-events">
				<button type="button" class="data-machine-events-no-events-today-link">Show events from Today</button>
			</div>`;
		calendar.dispatchEvent(
			new CustomEvent( 'data-machine-calendar-content-updated' )
		);
		calendar
			.querySelector< HTMLButtonElement >(
				'.data-machine-events-no-events-today-link'
			)!
			.click();

		expect( navigate ).toHaveBeenCalledTimes( 1 );
		expect( navigate.mock.calls[ 0 ][ 0 ].get( 'event_search' ) ).toBe(
			'artist'
		);
		expect( navigate.mock.calls[ 0 ][ 0 ].has( 'date_start' ) ).toBe(
			false
		);
	} );

	it.each( [
		[ 'past mode', '?past=1' ],
		[
			'explicit date bounds',
			'?date_start=2026-08-10&date_end=2026-08-12',
		],
		[ 'stale pagination', '?paged=3' ],
	] )( 'offers recovery for %s', ( _label, query ) => {
		window.history.replaceState( {}, '', `/events/${ query }` );
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initNavigation( calendar, jest.fn() );

		expect(
			calendar.querySelector< HTMLButtonElement >(
				'.data-machine-events-no-events-today-link'
			)!.hidden
		).toBe( false );
	} );

	it( 'hides a meaningless action when preserved filters are the only state', () => {
		window.history.replaceState(
			{},
			'',
			'/events/?event_search=missing&tax_filter%5Bvenue%5D%5B%5D=42&lat=32.78&lng=-79.93'
		);
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const navigate = jest.fn();
		initNavigation( calendar, navigate );

		const button = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-events-no-events-today-link'
		)!;
		expect( button.hidden ).toBe( true );
		button.click();
		expect( navigate ).not.toHaveBeenCalled();
	} );

	it( 'overrides a default scope with the explicit current sentinel', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		calendar.dataset.scope = 'tonight';
		const navigate = jest.fn();
		initNavigation( calendar, navigate );
		calendar
			.querySelector< HTMLButtonElement >(
				'.data-machine-events-no-events-today-link'
			)!
			.click();

		expect( navigate.mock.calls[ 0 ][ 0 ].get( 'scope' ) ).toBe(
			'current'
		);
	} );
} );
