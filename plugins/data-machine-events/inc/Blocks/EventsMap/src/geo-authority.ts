/**
 * Sources allowed to turn a map viewport into calendar geo intent.
 */
export type GeoAuthoritySource =
	| 'server'
	| 'user-location'
	| 'external'
	| 'manual-search'
	| 'user-interaction';

export interface GeoAuthorityOperation {
	generation: number;
	source: GeoAuthoritySource;
}

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
 * Bind authority to one concrete map movement generation.
 */
export function createGeoAuthorityTracker(): {
	prepare: ( source: GeoAuthoritySource ) => GeoAuthorityOperation;
	activate: ( source: GeoAuthoritySource ) => GeoAuthorityOperation;
	movementStarted: () => void;
	movementEnded: () => GeoAuthorityOperation | null;
	completeNoop: ( generation: number ) => GeoAuthorityOperation | null;
	abandon: ( generation: number ) => void;
	cancel: ( generation: number ) => void;
	immediate: ( source: GeoAuthoritySource ) => GeoAuthorityOperation;
	isLatest: ( generation: number ) => boolean;
	destroy: () => void;
} {
	let generation = 0;
	let awaitingStart: GeoAuthorityOperation | null = null;
	let active: GeoAuthorityOperation | null = null;

	const next = ( source: GeoAuthoritySource ): GeoAuthorityOperation => ( {
		generation: ++generation,
		source,
	} );

	return {
		prepare( source ) {
			const operation = next( source );
			awaitingStart = operation;
			active = null;
			return operation;
		},
		activate( source ) {
			const operation = next( source );
			awaitingStart = null;
			active = operation;
			return operation;
		},
		movementStarted() {
			if ( awaitingStart ) {
				active = awaitingStart;
				awaitingStart = null;
			}
		},
		movementEnded() {
			const operation = active;
			active = null;
			return operation;
		},
		completeNoop( operationGeneration ) {
			if ( awaitingStart?.generation !== operationGeneration ) {
				return null;
			}
			const operation = awaitingStart;
			awaitingStart = null;
			return operation;
		},
		abandon( operationGeneration ) {
			if ( awaitingStart?.generation === operationGeneration ) {
				awaitingStart = null;
			}
		},
		cancel( operationGeneration ) {
			if ( awaitingStart?.generation === operationGeneration ) {
				awaitingStart = null;
			}
			if ( active?.generation === operationGeneration ) {
				active = null;
			}
		},
		immediate( source ) {
			return next( source );
		},
		isLatest( operationGeneration ) {
			return generation === operationGeneration;
		},
		destroy() {
			awaitingStart = null;
			active = null;
		},
	};
}
