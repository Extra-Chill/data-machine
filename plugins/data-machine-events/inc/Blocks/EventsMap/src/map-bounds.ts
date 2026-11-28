/**
 * Events Map Block: viewport bounds extraction and geo-authority dispatch.
 *
 * Pure helpers extracted from frontend.tsx: reading the current viewport
 * bounds off a Leaflet map, emitting bounds-changed events bound to a geo
 * authority operation, moving the map with authority, and resolving which
 * map an external event targets.
 *
 * @package
 */

/**
 * External dependencies
 */
import L from 'leaflet';

/**
 * Internal dependencies
 */
import type { MapBounds, BoundsChangedEvent } from './types';
import type {
	GeoAuthoritySource,
	GeoAuthorityOperation,
} from './geo-authority';
import { createGeoAuthorityTracker } from './geo-authority';

export function getBoundsFromMap( map: L.Map ): MapBounds {
	const bounds = map.getBounds();
	const sw = bounds.getSouthWest();
	const ne = bounds.getNorthEast();
	return {
		swLat: sw.lat,
		swLng: sw.lng,
		neLat: ne.lat,
		neLng: ne.lng,
	};
}

export function dispatchBoundsChanged(
	map: L.Map,
	syncId: string,
	operation: GeoAuthorityOperation
): void {
	const bounds = getBoundsFromMap( map );
	const center = map.getCenter();

	const detail: BoundsChangedEvent = {
		syncId,
		generation: operation.generation,
		bounds,
		zoom: map.getZoom(),
		center: { lat: center.lat, lng: center.lng },
		authority: operation.source,
	};

	document.dispatchEvent(
		new CustomEvent( 'data-machine-map-bounds-changed', { detail } )
	);
}

export function moveWithAuthority(
	map: L.Map,
	syncId: string,
	tracker: ReturnType< typeof createGeoAuthorityTracker >,
	source: GeoAuthoritySource,
	lat: number,
	lng: number,
	zoom: number
): void {
	const operation = tracker.prepare( source );
	map.setView( [ lat, lng ], zoom );
	const noOp = tracker.completeNoop( operation.generation );
	if ( noOp ) {
		dispatchBoundsChanged( map, syncId, noOp );
	}
}

export function isTargetedToMap(
	targetSyncId: string | undefined,
	syncId: string
): boolean {
	if ( targetSyncId ) {
		return targetSyncId === syncId;
	}

	return (
		document.querySelectorAll( '.data-machine-events-map-root' ).length ===
		1
	);
}
