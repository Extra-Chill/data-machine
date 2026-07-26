/**
 * Sources allowed to turn a map viewport into calendar geo intent.
 */
export type GeoAuthoritySource =
	| 'server'
	| 'user-location'
	| 'external'
	| 'manual-search'
	| 'user-interaction';

/**
 * A map needs coordinates to render even when no location has been chosen.
 * This fallback is deliberately neutral and never carries geo authority.
 */
export const VISUAL_FALLBACK = { lat: 0, lng: 0 } as const;

/**
 * Resolve the initial viewport and whether it represents location intent.
 *
 * Venue and visual fallbacks are layout inputs only. Explicit server
 * coordinates and a server-provided user location are authoritative.
 *
 * @param root0               Initial location candidates.
 * @param root0.center        Explicit server-provided map center.
 * @param root0.userLocation  Explicit server-provided user location.
 * @param root0.venueLocation First venue used only for visual layout.
 */
export function resolveInitialView( {
	center,
	userLocation,
	venueLocation,
}: {
	center: { lat: number; lng: number } | null;
	userLocation: { lat: number; lng: number } | null;
	venueLocation: { lat: number; lng: number } | null;
} ): {
	center: { lat: number; lng: number };
	authority: GeoAuthoritySource | null;
} {
	if ( center ) {
		return { center, authority: 'server' };
	}

	if ( userLocation ) {
		return { center: userLocation, authority: 'user-location' };
	}

	return {
		center: venueLocation ?? VISUAL_FALLBACK,
		authority: null,
	};
}

/**
 * Hold authority for exactly one completed map movement.
 */
export function createGeoAuthorityTracker(): {
	mark: ( source: GeoAuthoritySource ) => void;
	consume: () => GeoAuthoritySource | null;
} {
	let pending: GeoAuthoritySource | null = null;

	return {
		mark( source ) {
			pending = source;
		},
		consume() {
			const source = pending;
			pending = null;
			return source;
		},
	};
}
