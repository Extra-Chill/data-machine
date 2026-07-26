/**
 * Internal dependencies
 */
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

	it( 'binds authority to a started movement generation', () => {
		const tracker = createGeoAuthorityTracker();
		const operation = tracker.prepare( 'external' );

		expect( tracker.movementEnded() ).toBeNull();
		tracker.movementStarted();
		expect( tracker.movementEnded() ).toEqual( operation );
		expect( tracker.movementEnded() ).toBeNull();
	} );

	it( 'completes authoritative no-op requests explicitly', () => {
		const tracker = createGeoAuthorityTracker();
		const operation = tracker.prepare( 'manual-search' );

		expect( tracker.completeNoop( operation.generation ) ).toEqual(
			operation
		);
		expect( tracker.movementEnded() ).toBeNull();
	} );

	it( 'clears abandoned marks before later neutral movement', () => {
		const tracker = createGeoAuthorityTracker();
		const abandoned = tracker.prepare( 'user-interaction' );
		tracker.abandon( abandoned.generation );

		tracker.movementStarted();
		expect( tracker.movementEnded() ).toBeNull();
	} );

	it( 'does not let neutral movement cancel completed authority', () => {
		const tracker = createGeoAuthorityTracker();
		const operation = tracker.activate( 'user-interaction' );

		expect( tracker.movementEnded() ).toEqual( operation );
		tracker.movementStarted();
		expect( tracker.movementEnded() ).toBeNull();
	} );

	it( 'supersedes an unstarted operation with a newer generation', () => {
		const tracker = createGeoAuthorityTracker();
		const oldOperation = tracker.prepare( 'external' );
		const currentOperation = tracker.prepare( 'manual-search' );

		expect( tracker.completeNoop( oldOperation.generation ) ).toBeNull();
		tracker.movementStarted();
		expect( tracker.movementEnded() ).toEqual( currentOperation );
	} );

	it( 'lets user intent supersede delayed initial authority', () => {
		const tracker = createGeoAuthorityTracker();
		const initial = tracker.immediate( 'server' );
		const user = tracker.activate( 'user-interaction' );

		expect( tracker.isLatest( initial.generation ) ).toBe( false );
		expect( tracker.isLatest( user.generation ) ).toBe( true );
	} );

	it( 'cancels active gesture authority before neutral movement', () => {
		const tracker = createGeoAuthorityTracker();
		const gesture = tracker.activate( 'user-interaction' );
		tracker.cancel( gesture.generation );

		tracker.movementStarted();
		expect( tracker.movementEnded() ).toBeNull();
	} );
} );
