const mockMapInstances: any[] = [];

jest.mock( 'leaflet', () => {
	class MockMap {
		center = { lat: 0, lng: 0 };
		zoom = 12;
		handlers = new Map< string, Set< () => void > >();
		dragging = { enable: jest.fn(), disable: jest.fn() };
		scrollWheelZoom = { enable: jest.fn(), disable: jest.fn() };
		removed = false;

		setView( [ lat, lng ]: [ number, number ], zoom: number ): this {
			const changed =
				this.center.lat !== lat ||
				this.center.lng !== lng ||
				this.zoom !== zoom;
			if ( changed && this.handlers.size > 0 ) {
				this.fire( 'movestart' );
			}
			this.center = { lat, lng };
			this.zoom = zoom;
			if ( changed && this.handlers.size > 0 ) {
				this.fire( 'moveend' );
			}
			return this;
		}

		on( names: string, handler: () => void ): this {
			names.split( ' ' ).forEach( ( name ) => {
				if ( ! this.handlers.has( name ) ) {
					this.handlers.set( name, new Set() );
				}
				this.handlers.get( name )!.add( handler );
			} );
			return this;
		}

		off( names: string, handler: () => void ): this {
			names
				.split( ' ' )
				.forEach(
					( name ) => this.handlers.get( name )?.delete( handler )
				);
			return this;
		}

		fire( name: string ): this {
			[ ...( this.handlers.get( name ) ?? [] ) ].forEach( ( handler ) =>
				handler()
			);
			return this;
		}

		getCenter(): { lat: number; lng: number } {
			return this.center;
		}

		getZoom(): number {
			return this.zoom;
		}

		getBounds() {
			return {
				getSouthWest: () => ( {
					lat: this.center.lat - 0.5,
					lng: this.center.lng - 0.5,
				} ),
				getNorthEast: () => ( {
					lat: this.center.lat + 0.5,
					lng: this.center.lng + 0.5,
				} ),
			};
		}

		invalidateSize(): this {
			this.fire( 'movestart' );
			this.fire( 'moveend' );
			return this;
		}

		addLayer(): this {
			return this;
		}

		removeLayer(): this {
			return this;
		}

		remove(): void {
			this.removed = true;
			this.handlers.clear();
		}
	}

	const cluster = () => ( {
		on: jest.fn(),
		off: jest.fn(),
		addLayers: jest.fn(),
		removeLayers: jest.fn(),
		clearLayers: jest.fn(),
		getLayers: jest.fn( () => [] ),
	} );
	const marker = () => ( {
		addTo: jest.fn().mockReturnThis(),
		bindPopup: jest.fn().mockReturnThis(),
		setPopupContent: jest.fn(),
	} );
	const leaflet = {
		map: jest.fn( () => {
			const map = new MockMap();
			mockMapInstances.push( map );
			return map;
		} ),
		tileLayer: jest.fn( () => ( { addTo: jest.fn() } ) ),
		markerClusterGroup: jest.fn( cluster ),
		marker: jest.fn( marker ),
		divIcon: jest.fn( () => ( {} ) ),
	};

	return { __esModule: true, default: leaflet };
} );
jest.mock( 'leaflet.markercluster', () => ( {} ) );

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

/**
 * Internal dependencies
 */
import { EventsMap } from './frontend';

import type { MapProps } from './types';

(
	globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

function props( overrides: Partial< MapProps > = {} ): MapProps {
	return {
		containerId: 'map-a-container',
		syncId: 'map-a',
		height: 400,
		zoom: 12,
		mapType: 'osm-standard',
		centerLat: null,
		centerLon: null,
		userLat: null,
		userLon: null,
		venues: [],
		taxonomy: '',
		termId: 0,
		restUrl: '',
		nonce: '',
		showLocationSearch: false,
		geocodeUrl: '',
		chronologicalRouteMode: false,
		scopeToken: '',
		...overrides,
	};
}

function renderMap( mapProps: MapProps ) {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const root = createRoot( host );
	act( () => root.render( <EventsMap { ...mapProps } /> ) );
	return { host, root, map: mockMapInstances.at( -1 ) };
}

function collectBoundsEvents(): {
	events: any[];
	remove: () => void;
} {
	const events: any[] = [];
	const handler = ( event: Event ) => {
		events.push( ( event as CustomEvent ).detail );
	};
	document.addEventListener( 'data-machine-map-bounds-changed', handler );
	return {
		events,
		remove: () =>
			document.removeEventListener(
				'data-machine-map-bounds-changed',
				handler
			),
	};
}

describe( 'EventsMap geo authority integration', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		mockMapInstances.length = 0;
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		jest.runOnlyPendingTimers();
		jest.useRealTimers();
	} );

	it.each( [ 'denied', 'prompt', 'unavailable', 'timeout' ] )(
		'keeps %s/no-center initial mount neutral',
		() => {
			const bounds = collectBoundsEvents();
			const { root, map } = renderMap( props() );

			act( () => jest.advanceTimersByTime( 1000 ) );
			expect( map.getCenter() ).toEqual( { lat: 0, lng: 0 } );
			expect( bounds.events ).toEqual( [] );
			act( () => root.unmount() );
			bounds.remove();
		}
	);

	it.each( [
		[
			'explicit/account center',
			{ centerLat: 32.7765, centerLon: -79.9311 },
			'server',
		],
		[
			'granted location',
			{ userLat: 32.7765, userLon: -79.9311 },
			'user-location',
		],
	] )(
		'emits targeted authority for %s',
		( _label, overrides, authority ) => {
			const bounds = collectBoundsEvents();
			const { root } = renderMap( props( overrides ) );

			act( () => jest.advanceTimersByTime( 200 ) );
			expect( bounds.events ).toEqual( [
				expect.objectContaining( { syncId: 'map-a', authority } ),
			] );
			act( () => root.unmount() );
			bounds.remove();
		}
	);

	it( 'handles external no-op before later neutral movement', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap(
			props( { centerLat: 32.7765, centerLon: -79.9311 } )
		);
		act( () => jest.advanceTimersByTime( 200 ) );
		bounds.events.length = 0;

		act( () => {
			document.dispatchEvent(
				new CustomEvent( 'data-machine-map-recenter', {
					detail: {
						syncId: 'map-a',
						lat: 32.7765,
						lng: -79.9311,
						zoom: 12,
					},
				} )
			);
			map.invalidateSize();
		} );

		expect( bounds.events ).toHaveLength( 1 );
		expect( bounds.events[ 0 ].authority ).toBe( 'external' );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'does not let delayed initial authority override an early user pan', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap(
			props( { centerLat: 32.7765, centerLon: -79.9311 } )
		);

		act( () => {
			map.fire( 'dragstart' );
			map.center = { lat: 40.7128, lng: -74.006 };
			map.fire( 'moveend' );
			jest.advanceTimersByTime( 200 );
		} );

		expect( bounds.events ).toEqual( [
			expect.objectContaining( {
				authority: 'user-interaction',
				center: { lat: 40.7128, lng: -74.006 },
			} ),
		] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'emits manual-search authority after successful geocoding', async () => {
		const bounds = collectBoundsEvents();
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( {
				success: true,
				results: [
					{
						lat: '32.7765',
						lon: '-79.9311',
						display_name: 'Charleston, South Carolina',
					},
				],
			} ),
		} as Response );
		const { root, host } = renderMap(
			props( { showLocationSearch: true, geocodeUrl: '/geocode' } )
		);
		const input = host.querySelector< HTMLInputElement >(
			'.data-machine-events-map-location-input'
		)!;
		const form = host.querySelector< HTMLFormElement >(
			'.data-machine-events-map-location-form'
		)!;

		await act( async () => {
			const valueSetter = Object.getOwnPropertyDescriptor(
				HTMLInputElement.prototype,
				'value'
			)!.set!;
			valueSetter.call( input, 'Charleston' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		await act( async () => {
			form.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( bounds.events.at( -1 ) ).toEqual(
			expect.objectContaining( {
				syncId: 'map-a',
				authority: 'manual-search',
				center: { lat: 32.7765, lng: -79.9311 },
			} )
		);
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'emits deliberate user pan without neutral debounce cancellation', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap( props() );
		act( () => jest.advanceTimersByTime( 200 ) );

		act( () => {
			map.fire( 'dragstart' );
			map.center = { lat: 40.7128, lng: -74.006 };
			map.fire( 'moveend' );
			map.invalidateSize();
		} );

		expect( bounds.events ).toEqual( [
			expect.objectContaining( {
				syncId: 'map-a',
				authority: 'user-interaction',
				center: { lat: 40.7128, lng: -74.006 },
			} ),
		] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'does not apply box zoom Escape cancellation to a moved drag', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap( props() );

		act( () => {
			map.fire( 'dragstart' );
			map.fire( 'move' );
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape' } )
			);
			map.center = { lat: 40.7128, lng: -74.006 };
			map.fire( 'moveend' );
		} );

		expect( bounds.events ).toEqual( [
			expect.objectContaining( {
				authority: 'user-interaction',
				center: { lat: 40.7128, lng: -74.006 },
			} ),
		] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'emits deliberate user zoom authority', () => {
		const bounds = collectBoundsEvents();
		const { root, host, map } = renderMap( props() );
		const zoomButton = document.createElement( 'button' );
		zoomButton.className = 'leaflet-control-zoom-in';
		host.querySelector( '.data-machine-events-map' )!.append( zoomButton );

		act( () => {
			zoomButton.click();
			map.fire( 'movestart' );
			map.zoom = 13;
			map.fire( 'moveend' );
		} );

		expect( bounds.events ).toEqual( [
			expect.objectContaining( {
				syncId: 'map-a',
				authority: 'user-interaction',
				zoom: 13,
			} ),
		] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'cancels an unmoved drag before its trailing moveend', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap( props() );

		act( () => {
			map.fire( 'dragstart' );
			map.fire( 'dragend' );
			map.fire( 'moveend' );
		} );

		expect( bounds.events ).toEqual( [] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'cancels box zoom authority through Leaflet Escape handling', () => {
		const bounds = collectBoundsEvents();
		const { root, map } = renderMap( props() );

		act( () => {
			map.fire( 'boxzoomstart' );
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape' } )
			);
			map.invalidateSize();
		} );

		expect( bounds.events ).toEqual( [] );
		act( () => root.unmount() );
		bounds.remove();
	} );

	it( 'targets one of two maps and tears listeners down on unmount', () => {
		const bounds = collectBoundsEvents();
		const first = renderMap( props() );
		const second = renderMap(
			props( { containerId: 'map-b-container', syncId: 'map-b' } )
		);

		act( () => {
			document.dispatchEvent(
				new CustomEvent( 'data-machine-map-recenter', {
					detail: { syncId: 'map-b', lat: 34.05, lng: -118.24 },
				} )
			);
		} );
		expect( first.map.getCenter() ).toEqual( { lat: 0, lng: 0 } );
		expect( second.map.getCenter() ).toEqual( {
			lat: 34.05,
			lng: -118.24,
		} );
		expect( bounds.events.at( -1 ).syncId ).toBe( 'map-b' );

		act( () => second.root.unmount() );
		const count = bounds.events.length;
		document.dispatchEvent(
			new CustomEvent( 'data-machine-map-recenter', {
				detail: { syncId: 'map-b', lat: 1, lng: 1 },
			} )
		);
		act( () => jest.runOnlyPendingTimers() );
		expect( bounds.events ).toHaveLength( count );
		expect( second.map.removed ).toBe( true );
		act( () => first.root.unmount() );
		bounds.remove();
	} );
} );
