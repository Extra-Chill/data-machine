/**
 * Events Map Block Frontend
 *
 * React component rendered into the server-side container div.
 * Uses Leaflet via useRef/useEffect for map management, fetches venues
 * from the REST API, and emits custom events on bounds change.
 *
 * @package DataMachineEvents
 * @since 0.5.0
 */

import { createRoot } from '@wordpress/element';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import {
	useState,
	useEffect,
	useRef,
	useCallback,
} from '@wordpress/element';

import { fetchVenues } from './api-client';
import { TILE_URLS } from './types';
import type {
	Venue,
	MapProps,
	MapType,
	MapBounds,
	BoundsChangedEvent,
} from './types';

import './frontend.css';

/* ---------- helpers ---------- */

function escapeHtml( text: string ): string {
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

function buildPopupHtml( venue: Venue ): string {
	let html = '<div class="venue-popup">';

	if ( venue.url ) {
		html += `<a href="${ escapeHtml( venue.url ) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml( venue.name ) }</span>`;
	}

	if ( venue.event_count > 0 ) {
		html += `<span class="venue-popup-events">${ venue.event_count } upcoming event${ venue.event_count !== 1 ? 's' : '' }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml( venue.address ) }</span>`;
	}

	html += '</div>';
	return html;
}

function createVenueIcon(): L.DivIcon {
	return L.divIcon( {
		html: '<span style="font-size: 28px; line-height: 1; display: block;">📍</span>',
		className: 'emoji-marker',
		iconSize: [ 28, 28 ],
		iconAnchor: [ 14, 28 ],
		popupAnchor: [ 0, -28 ],
	} );
}

function createUserLocationIcon(): L.DivIcon {
	return L.divIcon( {
		html: '<span class="user-location-dot"></span>',
		className: 'user-location-marker',
		iconSize: [ 16, 16 ],
		iconAnchor: [ 8, 8 ],
	} );
}

function getBoundsFromMap( map: L.Map ): MapBounds {
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

function dispatchBoundsChanged( map: L.Map ): void {
	const bounds = getBoundsFromMap( map );
	const center = map.getCenter();

	const detail: BoundsChangedEvent = {
		bounds,
		zoom: map.getZoom(),
		center: { lat: center.lat, lng: center.lng },
	};

	document.dispatchEvent(
		new CustomEvent( 'datamachine-map-bounds-changed', { detail } ),
	);
}

/* ---------- debounce ---------- */

function debounce<T extends ( ...args: unknown[] ) => void>(
	fn: T,
	ms: number,
): ( ...args: Parameters<T> ) => void {
	let timer: ReturnType<typeof setTimeout>;
	return ( ...args: Parameters<T> ) => {
		clearTimeout( timer );
		timer = setTimeout( () => fn( ...args ), ms );
	};
}

/* ---------- React component ---------- */

function EventsMap( props: MapProps ): JSX.Element | null {
	const {
		containerId,
		height,
		zoom,
		mapType,
		dynamic,
		centerLat,
		centerLon,
		userLat,
		userLon,
		venues: initialVenues,
		taxonomy,
		termId,
		restUrl,
		nonce,
	} = props;

	const mapRef = useRef<L.Map | null>( null );
	const markersRef = useRef<L.Marker[]>( [] );
	const userMarkerRef = useRef<L.Marker | null>( null );
	const containerRef = useRef<HTMLDivElement | null>( null );

	const [ venues, setVenues ] = useState<Venue[]>( initialVenues );
	const [ loading, setLoading ] = useState( false );

	const hasCenter = centerLat !== null && centerLon !== null;
	const hasUserLocation = userLat !== null && userLon !== null;

	/* --- fetch venues from REST API --- */
	const loadVenues = useCallback(
		async ( bounds?: MapBounds ) => {
			if ( ! restUrl ) return;

			setLoading( true );
			try {
				const result = await fetchVenues( restUrl, nonce, {
					bounds,
					taxonomy: taxonomy || undefined,
					termId: termId || undefined,
				} );
				setVenues( result.venues );
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Events map: failed to fetch venues', err );
			} finally {
				setLoading( false );
			}
		},
		[ restUrl, nonce, taxonomy, termId ],
	);

	/* --- debounced bounds handler --- */
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const debouncedFetch = useCallback(
		debounce( ( map: L.Map ) => {
			const bounds = getBoundsFromMap( map );
			loadVenues( bounds );
			dispatchBoundsChanged( map );
		}, 500 ),
		[ loadVenues ],
	);

	/* --- initialize map --- */
	useEffect( () => {
		const el = containerRef.current;
		if ( ! el || mapRef.current ) return;

		const initialLat = hasCenter
			? centerLat!
			: venues.length > 0
			? venues[ 0 ].lat
			: 30.2672; // fallback: Austin, TX
		const initialLon = hasCenter
			? centerLon!
			: venues.length > 0
			? venues[ 0 ].lon
			: -97.7431;

		const map = L.map( el, {
			scrollWheelZoom: false,
			boxZoom: true,
		} ).setView( [ initialLat, initialLon ], zoom );

		// Ctrl/Cmd + scroll to zoom.
		el.addEventListener(
			'wheel',
			( e: WheelEvent ) => {
				if ( e.ctrlKey || e.metaKey ) {
					e.preventDefault();
					map.scrollWheelZoom.enable();
				}
			},
			{ passive: false },
		);
		map.on( 'mouseout', () => map.scrollWheelZoom.disable() );

		// Tile layer.
		const tileUrl = TILE_URLS[ mapType ] || TILE_URLS[ 'osm-standard' ];
		L.tileLayer( tileUrl, {
			attribution: '',
			maxZoom: 18,
			minZoom: 8,
		} ).addTo( map );

		mapRef.current = map;

		// Listen for move/zoom when dynamic loading is enabled.
		if ( dynamic ) {
			map.on( 'moveend', () => debouncedFetch( map ) );
		} else {
			// Static mode: still dispatch bounds changed events.
			map.on( 'moveend', () => dispatchBoundsChanged( map ) );
		}

		// Force a resize check after mount.
		setTimeout( () => map.invalidateSize(), 100 );

		// If dynamic mode and no initial venues, fetch on mount.
		if ( dynamic && initialVenues.length === 0 ) {
			// Small delay so map is fully sized first.
			setTimeout( () => {
				const bounds = getBoundsFromMap( map );
				loadVenues( bounds );
			}, 200 );
		}

		return () => {
			map.remove();
			mapRef.current = null;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	/* --- update markers when venues change --- */
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map ) return;

		// Clear existing venue markers.
		markersRef.current.forEach( ( m ) => map.removeLayer( m ) );
		markersRef.current = [];

		const icon = createVenueIcon();
		const newMarkers: L.Marker[] = [];

		venues.forEach( ( venue ) => {
			if ( ! venue.lat || ! venue.lon ) return;

			const marker = L.marker( [ venue.lat, venue.lon ], { icon } )
				.addTo( map )
				.bindPopup( buildPopupHtml( venue ) );

			newMarkers.push( marker );
		} );

		markersRef.current = newMarkers;

		// Fit bounds to markers (only on non-dynamic or first load).
		if ( ! dynamic || initialVenues.length > 0 ) {
			const allMarkers = [
				...newMarkers,
				...( userMarkerRef.current ? [ userMarkerRef.current ] : [] ),
			];

			if ( hasUserLocation && allMarkers.length > 1 ) {
				// Near-me mode: center on user at zoom 13.
				map.setView( [ userLat!, userLon! ], 13 );
			} else if ( allMarkers.length > 1 ) {
				const group = L.featureGroup( allMarkers );
				map.fitBounds( group.getBounds().pad( 0.1 ) );
			} else if (
				newMarkers.length === 1 &&
				! hasCenter
			) {
				map.setView( [ venues[ 0 ].lat, venues[ 0 ].lon ], 13 );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ venues ] );

	/* --- user location marker --- */
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map || ! hasUserLocation ) return;

		if ( userMarkerRef.current ) {
			map.removeLayer( userMarkerRef.current );
		}

		const icon = createUserLocationIcon();
		const marker = L.marker( [ userLat!, userLon! ], { icon } )
			.addTo( map )
			.bindPopup(
				'<div class="venue-popup"><span class="venue-popup-name">You are here</span></div>',
			);

		userMarkerRef.current = marker;

		return () => {
			if ( userMarkerRef.current ) {
				map.removeLayer( userMarkerRef.current );
				userMarkerRef.current = null;
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ userLat, userLon ] );

	return (
		<div
			id={ containerId }
			ref={ containerRef }
			className="datamachine-events-map"
			style={ { height: `${ height }px` } }
			aria-label="Events map"
			role="application"
		/>
	);
}

/* ---------- mount ---------- */

function parseMapProps( container: HTMLElement ): MapProps {
	const data = container.dataset;

	const parseOptionalFloat = ( val?: string ): number | null => {
		if ( ! val || val === '' ) return null;
		const n = parseFloat( val );
		return isNaN( n ) ? null : n;
	};

	let initialVenues: Venue[] = [];
	try {
		initialVenues = JSON.parse( data.venues || '[]' );
	} catch {
		initialVenues = [];
	}

	return {
		containerId: container.id || `dm-events-map-${ Date.now() }`,
		height: parseInt( data.height || '400', 10 ),
		zoom: parseInt( data.zoom || '12', 10 ),
		mapType: ( data.mapType || 'osm-standard' ) as MapType,
		dynamic: data.dynamic === '1' || data.dynamic === 'true',
		centerLat: parseOptionalFloat( data.centerLat ),
		centerLon: parseOptionalFloat( data.centerLon ),
		userLat: parseOptionalFloat( data.userLat ),
		userLon: parseOptionalFloat( data.userLon ),
		venues: initialVenues,
		taxonomy: data.taxonomy || '',
		termId: parseInt( data.termId || '0', 10 ),
		restUrl: data.restUrl || '',
		nonce: data.nonce || '',
	};
}

function initEventsMap(): void {
	const containers = document.querySelectorAll<HTMLElement>(
		'.datamachine-events-map-root',
	);

	containers.forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) return;
		container.dataset.initialized = '1';

		const props = parseMapProps( container );
		const root = createRoot( container );
		root.render( <EventsMap { ...props } /> );
	} );
}

// Initialize on DOM ready.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initEventsMap );
} else {
	initEventsMap();
}

// Re-initialize for dynamically injected content.
document.addEventListener( 'datamachine-events-loaded', () => {
	initEventsMap();
} );
