import {
	createGeoAuthorityTracker,
	resolveInitialView,
	VISUAL_FALLBACK,
} from './geo-authority';

describe( 'map geo authority', () => {
	it( 'keeps an initial no-center mount neutral', () => {
		expect(
			resolveInitialView( {
				center: null,
				userLocation: null,
				venueLocation: null,
			} )
		).toEqual( { center: VISUAL_FALLBACK, authority: null } );
	} );

	it( 'keeps venue fallback layout neutral', () => {
		expect(
			resolveInitialView( {
				center: null,
				userLocation: null,
				venueLocation: { lat: 40.7128, lng: -74.006 },
			} )
		).toEqual( {
			center: { lat: 40.7128, lng: -74.006 },
			authority: null,
		} );
	} );

	it.each( [
		[
			'explicit server coordinates',
			{ lat: 32.7765, lng: -79.9311 },
			null,
			'server',
		],
		[
			'granted user location',
			null,
			{ lat: 32.7765, lng: -79.9311 },
			'user-location',
		],
	] )(
		'recognizes %s as authoritative',
		( _label, center, userLocation, authority ) => {
			expect(
				resolveInitialView( {
					center,
					userLocation,
					venueLocation: null,
				} )
			).toEqual( {
				center: { lat: 32.7765, lng: -79.9311 },
				authority,
			} );
		}
	);

	it( 'does not let unmarked programmatic movement masquerade as intent', () => {
		const tracker = createGeoAuthorityTracker();

		expect( tracker.consume() ).toBeNull();
		tracker.mark( 'user-interaction' );
		expect( tracker.consume() ).toBe( 'user-interaction' );
		expect( tracker.consume() ).toBeNull();
	} );

	it.each( [ 'external', 'manual-search', 'user-interaction' ] as const )(
		'preserves one %s movement',
		( source ) => {
			const tracker = createGeoAuthorityTracker();
			tracker.mark( source );

			expect( tracker.consume() ).toBe( source );
			expect( tracker.consume() ).toBeNull();
		}
	);
} );
