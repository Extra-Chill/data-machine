/**
 * Internal dependencies
 */
import { getFilterState, destroyFilterState } from './filter-state';

const STORAGE_KEY = 'data_machine_events_calendar_state';

describe( 'stored calendar filters', () => {
	let calendar: HTMLElement;

	beforeEach( () => {
		window.history.replaceState( {}, '', '/events/?paged=3' );
		window.localStorage.clear();
		document.body.innerHTML =
			'<div class="data-machine-events-calendar" data-filter-persistence="1"></div>';
		calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
	} );

	afterEach( () => {
		destroyFilterState( calendar );
	} );

	it( 'replaces the stale server page with the restored filtered URL', () => {
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
		);
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( true );
		expect( navigate ).toHaveBeenCalledWith(
			'/events/?tax_filter%5Bvenue%5D%5B%5D=42'
		);
		expect( window.location.search ).toBe( '?paged=3' );
	} );

	it( 'does nothing when there are no stored filters', () => {
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( false );
		expect( navigate ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'disabled filter UI', '0', '' ],
		[ 'consumer-scoped embed', '1', 'signed-scope' ],
	] )(
		'does not restore into a %s',
		( _context, persistence, scopeToken ) => {
			calendar.dataset.filterPersistence = persistence;
			if ( scopeToken ) {
				calendar.dataset.scopeToken = scopeToken;
			}
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
			);
			const navigate = jest.fn();

			expect(
				getFilterState( calendar ).restoreFromStorage( navigate )
			).toBe( false );
			expect( navigate ).not.toHaveBeenCalled();
		}
	);

	it.each( [
		[ 'disabled filter UI', '0', '' ],
		[ 'consumer-scoped embed', '1', 'signed-scope' ],
	] )(
		'does not save or clear shared preferences for a %s',
		( _context, persistence, scopeToken ) => {
			calendar.dataset.filterPersistence = persistence;
			if ( scopeToken ) {
				calendar.dataset.scopeToken = scopeToken;
			}
			const existing = JSON.stringify( {
				'tax_filter[festival][]': [ '7' ],
			} );
			window.localStorage.setItem( STORAGE_KEY, existing );
			const params = new URLSearchParams();
			params.append( 'tax_filter[venue][]', '42' );
			const filterState = getFilterState( calendar );

			filterState.saveToStorage( params );
			expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe(
				existing
			);

			filterState.clearStorage();
			expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe(
				existing
			);
		}
	);

	it( 'preserves explicit URL filters instead of restoring stored ones', () => {
		window.history.replaceState(
			{},
			'',
			'/events/?tax_filter%5Bfestival%5D%5B%5D=7'
		);
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { 'tax_filter[venue][]': [ '42' ] } )
		);
		const navigate = jest.fn();

		expect(
			getFilterState( calendar ).restoreFromStorage( navigate )
		).toBe( false );
		expect( navigate ).not.toHaveBeenCalled();
	} );

	it( 'applies popstate parameters back to every filter control', () => {
		document.body.innerHTML = `
			<div class="data-machine-events-calendar" data-filter-persistence="1">
				<input class="data-machine-events-search-input" value="old">
				<button class="data-machine-events-scope-chip data-machine-events-scope-chip-active" data-scope="" aria-pressed="true"></button>
				<button class="data-machine-events-scope-chip" data-scope="tonight" aria-pressed="false"></button>
				<input type="checkbox" data-taxonomy="venue" value="42">
				<input class="data-machine-events-location-search" data-geo-lat="1" data-geo-lng="2" value="Old place">
				<select class="data-machine-events-radius-select" data-radius-unit="mi"><option value="25">25</option><option value="50">50</option></select>
			</div>`;
		calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const picker = {
			selectedDates: [],
			clear: jest.fn(),
			setDate: jest.fn(),
			destroy: jest.fn(),
		};
		const params = new URLSearchParams(
			'event_search=new&scope=tonight&date_start=2026-08-10&date_end=2026-08-12&tax_filter%5Bvenue%5D%5B%5D=42&lat=32.78&lng=-79.93&radius=50&radius_unit=mi'
		);

		getFilterState( calendar ).applyParams( params, picker );
		const tonightChip = calendar.querySelector< HTMLButtonElement >(
			'[data-scope="tonight"]'
		)!;
		const venueCheckbox = calendar.querySelector< HTMLInputElement >(
			'[data-taxonomy="venue"]'
		)!;

		expect(
			calendar.querySelector< HTMLInputElement >(
				'.data-machine-events-search-input'
			)!.value
		).toBe( 'new' );
		expect( tonightChip.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( venueCheckbox.checked ).toBe( true );
		expect( picker.setDate ).toHaveBeenCalledWith(
			[ '2026-08-10', '2026-08-12' ],
			false
		);
		expect(
			calendar.querySelector< HTMLInputElement >(
				'.data-machine-events-location-search'
			)!.dataset.geoLat
		).toBe( '32.78' );
		expect(
			calendar.querySelector< HTMLSelectElement >(
				'.data-machine-events-radius-select'
			)!.value
		).toBe( '50' );
	} );

	it( 'preserves the server default scope when no scope chips exist', () => {
		calendar.dataset.scope = 'tonight';

		expect( getFilterState( calendar ).buildParams().get( 'scope' ) ).toBe(
			'tonight'
		);
	} );

	it( 'allows an active All chip to clear the server default scope', () => {
		calendar.dataset.scope = 'tonight';
		calendar.innerHTML =
			'<button class="data-machine-events-scope-chip data-machine-events-scope-chip-active" data-scope=""></button>';

		expect( getFilterState( calendar ).buildParams().has( 'scope' ) ).toBe(
			false
		);
	} );
} );
