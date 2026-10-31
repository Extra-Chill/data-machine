jest.mock( './api-client', () => ( {
	buildCalendarRequest: jest.fn( () => new URLSearchParams() ),
	fetchCalendarEvents: jest.fn( () => Promise.resolve() ),
} ) );

const mockUpdateUrl = jest.fn();
const mockSaveGeoToStorage = jest.fn();

jest.mock( './filter-state', () => ( {
	getFilterState: jest.fn( () => ( {
		getArchiveContext: jest.fn( () => ( {} ) ),
		updateUrl: mockUpdateUrl,
		saveGeoToStorage: mockSaveGeoToStorage,
	} ) ),
} ) );

/**
 * Internal dependencies
 */
import { fetchCalendarEvents } from './api-client';
import { destroyGeoSync, initGeoSync } from './geo-sync';

const mockFetchCalendarEvents = fetchCalendarEvents as jest.Mock;

function dispatchBounds(
	authority?: string,
	syncId = 'map-a',
	generation = 1
): void {
	document.dispatchEvent(
		new CustomEvent( 'data-machine-map-bounds-changed', {
			detail: {
				syncId,
				generation,
				bounds: { swLat: 32, swLng: -80, neLat: 33, neLng: -79 },
				zoom: 10,
				center: { lat: 32.7765, lng: -79.9311 },
				authority,
			},
		} )
	);
}

describe( 'calendar geo authority', () => {
	let calendar: HTMLElement;

	beforeEach( () => {
		jest.useFakeTimers();
		calendar = document.createElement( 'div' );
		mockUpdateUrl.mockClear();
		mockSaveGeoToStorage.mockClear();
		mockFetchCalendarEvents.mockClear();
		initGeoSync( calendar, 'map-a' );
	} );

	it( 'ignores bounds from an unrelated map instance', () => {
		dispatchBounds( 'user-interaction', 'map-b' );
		jest.advanceTimersByTime( 300 );

		expect( mockFetchCalendarEvents ).not.toHaveBeenCalled();
	} );

	it( 'routes simultaneous map events to their targeted calendars', () => {
		const calendarB = document.createElement( 'div' );
		initGeoSync( calendarB, 'map-b' );

		dispatchBounds( 'external', 'map-a', 2 );
		dispatchBounds( 'user-interaction', 'map-b', 3 );
		jest.advanceTimersByTime( 300 );

		expect( mockFetchCalendarEvents ).toHaveBeenCalledTimes( 2 );
		expect(
			new Set(
				mockFetchCalendarEvents.mock.calls.map( ( call ) => call[ 0 ] )
			)
		).toEqual( new Set( [ calendar, calendarB ] ) );
		destroyGeoSync( calendarB );
	} );

	it( 'cancels queued work during teardown', () => {
		dispatchBounds( 'external' );
		destroyGeoSync( calendar );
		jest.advanceTimersByTime( 300 );

		expect( mockFetchCalendarEvents ).not.toHaveBeenCalled();
	} );

	it( 'aborts superseded and destroyed calendar requests', () => {
		dispatchBounds( 'external', 'map-a', 4 );
		jest.advanceTimersByTime( 300 );
		const firstSignal = mockFetchCalendarEvents.mock.calls[ 0 ][ 3 ]
			.signal as AbortSignal;

		dispatchBounds( 'user-interaction', 'map-a', 5 );
		jest.advanceTimersByTime( 300 );
		const secondSignal = mockFetchCalendarEvents.mock.calls[ 1 ][ 3 ]
			.signal as AbortSignal;

		expect( firstSignal.aborted ).toBe( true );
		expect( secondSignal.aborted ).toBe( false );
		destroyGeoSync( calendar );
		expect( secondSignal.aborted ).toBe( true );
	} );

	it( 'invalidates an active response as soon as newer authority is accepted', async () => {
		let resolveOld!: () => void;
		mockFetchCalendarEvents.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveOld = () => resolve( {} );
				} )
		);
		dispatchBounds( 'external', 'map-a', 10 );
		jest.advanceTimersByTime( 300 );
		const oldOptions = mockFetchCalendarEvents.mock.calls[ 0 ][ 3 ];
		mockUpdateUrl.mockClear();
		mockSaveGeoToStorage.mockClear();

		dispatchBounds( 'user-interaction', 'map-a', 11 );
		expect( oldOptions.signal.aborted ).toBe( true );
		expect( oldOptions.shouldApply() ).toBe( false );
		resolveOld();
		await Promise.resolve();

		expect( mockFetchCalendarEvents ).toHaveBeenCalledTimes( 1 );
		expect( mockUpdateUrl ).not.toHaveBeenCalled();
		expect( mockSaveGeoToStorage ).not.toHaveBeenCalled();
	} );

	it( 'ignores replayed and out-of-order map generations', () => {
		dispatchBounds( 'external', 'map-a', 8 );
		dispatchBounds( 'user-interaction', 'map-a', 7 );
		jest.advanceTimersByTime( 300 );

		expect( mockFetchCalendarEvents ).toHaveBeenCalledTimes( 1 );
		expect( mockSaveGeoToStorage ).toHaveBeenCalledWith(
			expect.objectContaining( { lat: '32.7765' } )
		);
	} );

	afterEach( () => {
		destroyGeoSync( calendar );
		jest.useRealTimers();
	} );

	it.each( [
		[ 'denied geolocation fallback', undefined ],
		[ 'prompt/unresolved geolocation fallback', undefined ],
		[ 'timeout/unavailable geolocation fallback', undefined ],
		[ 'initial programmatic map movement', undefined ],
		[ 'unknown layout source', 'fallback' ],
	] )( 'ignores %s', ( _label, authority ) => {
		dispatchBounds( authority );
		jest.advanceTimersByTime( 300 );

		expect( mockUpdateUrl ).not.toHaveBeenCalled();
		expect( mockSaveGeoToStorage ).not.toHaveBeenCalled();
		expect( mockFetchCalendarEvents ).not.toHaveBeenCalled();
	} );

	it.each( [
		'server',
		'user-location',
		'external',
		'manual-search',
		'user-interaction',
	] )( 'persists and fetches %s geo intent', async ( authority ) => {
		dispatchBounds( authority );
		jest.advanceTimersByTime( 300 );
		await Promise.resolve();

		expect( mockUpdateUrl ).toHaveBeenCalledTimes( 1 );
		expect( mockSaveGeoToStorage ).toHaveBeenCalledWith(
			expect.objectContaining( {
				lat: '32.7765',
				lng: '-79.9311',
			} )
		);
		expect( mockFetchCalendarEvents ).toHaveBeenCalledTimes( 1 );
	} );
} );
