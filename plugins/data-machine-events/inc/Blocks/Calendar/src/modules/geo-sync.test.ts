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

import { fetchCalendarEvents } from './api-client';
import { destroyGeoSync, initGeoSync } from './geo-sync';

const mockFetchCalendarEvents = fetchCalendarEvents as jest.Mock;

function dispatchBounds( authority?: string ): void {
	document.dispatchEvent(
		new CustomEvent( 'data-machine-map-bounds-changed', {
			detail: {
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
		initGeoSync( calendar );
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
