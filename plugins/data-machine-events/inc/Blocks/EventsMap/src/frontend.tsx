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

/* ---------- Location search component ---------- */

interface GeocodeResult {
	lat: string;
	lon: string;
	display_name: string;
}

function LocationSearch( {
	geocodeUrl,
	onLocationFound,
}: {
	geocodeUrl: string;
	onLocationFound: ( lat: number, lng: number, label: string ) => void;
} ): JSX.Element {
	const [ query, setQuery ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ placeholder, setPlaceholder ] = useState(
		'Enter a city or address...',
	);

	const handleSubmit = useCallback(
		async ( e: React.FormEvent ) => {
			e.preventDefault();

			const trimmed = query.trim();
			if ( ! trimmed ) return;

			setLoading( true );
			setError( '' );

			try {
				const url = `${ geocodeUrl }?query=${ encodeURIComponent(
					trimmed,
				) }`;
				const response = await fetch( url, {
					headers: { Accept: 'application/json' },
				} );

				if ( ! response.ok ) {
					throw new Error( 'Geocoding request failed' );
				}

				const data = await response.json();

				if (
					! data.success ||
					! data.results ||
					data.results.length === 0
				) {
					setError(
						'Location not found. Try a different city or address.',
					);
					return;
				}

				const result: GeocodeResult = data.results[ 0 ];
				const lat = parseFloat( result.lat );
				const lng = parseFloat( result.lon );

				// Show resolved name as placeholder.
				const label = result.display_name
					.split( ',' )
					.slice( 0, 2 )
					.join( ',' );
				setPlaceholder( label );
				setQuery( '' );

				onLocationFound( lat, lng, label );
			} catch {
				setError(
					'Could not look up that location. Please try again.',
				);
			} finally {
				setLoading( false );
			}
		},
		[ query, geocodeUrl, onLocationFound ],
	);

	return (
		<div className="data-machine-events-map-location-search">
			<form
				className="data-machine-events-map-location-form"
				onSubmit={ handleSubmit }
				role="search"
				aria-label="Change location"
			>
				<input
					type="text"
					className="data-machine-events-map-location-input"
					placeholder={ placeholder }
					aria-label="City or address"
					autoComplete="off"
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
					disabled={ loading }
				/>
				<button
					type="submit"
					className="data-machine-events-map-location-btn"
					aria-label="Search location"
					disabled={ loading || ! query.trim() }
				>
					{ loading ? '...' : 'Go' }
				</button>
			</form>
			{ error && (
				<span className="data-machine-events-map-location-error">
					{ error }
				</span>
			) }
		</div>
	);
}

/* ---------- React component ---------- */

function EventsMap( props: MapProps ): JSX.Element | null {
	const {
		containerId,
		height,
		zoom,
		mapType,
		centerLat,
		centerLon,
		userLat,
		userLon,
		venues: initialVenues,
		taxonomy,
		termId,
		restUrl,
		nonce,
		showLocationSearch,
		geocodeUrl,
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

		// Fetch venues on pan/zoom and dispatch bounds-changed events.
		map.on( 'moveend', () => debouncedFetch( map ) );

		// Force a resize check after mount.
		setTimeout( () => map.invalidateSize(), 100 );

		// Fetch venues on mount and notify other blocks (e.g. calendar geo-sync).
		if ( initialVenues.length === 0 ) {
			// Small delay so map is fully sized first.
			setTimeout( () => {
				const bounds = getBoundsFromMap( map );
				loadVenues( bounds );
				dispatchBoundsChanged( map );
			}, 200 );
		}

		return () => {
			map.remove();
			mapRef.current = null;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	/* --- listen for external recenter requests --- */
	useEffect( () => {
		const handler = ( e: Event ) => {
			const map = mapRef.current;
			if ( ! map ) return;

			const detail = ( e as CustomEvent< {
				lat: number;
				lng: number;
				zoom?: number;
			} > ).detail;

			if ( ! detail?.lat || ! detail?.lng ) return;

			map.setView(
				[ detail.lat, detail.lng ],
				detail.zoom ?? map.getZoom(),
			);
		};

		document.addEventListener( 'datamachine-map-recenter', handler );
		return () => {
			document.removeEventListener( 'datamachine-map-recenter', handler );
		};
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

		// Fit bounds on first load when we have a user location or
		// initial venues (before the user has interacted with the map).
		if ( initialVenues.length > 0 ) {
			const allMarkers = [
				...newMarkers,
				...( userMarkerRef.current ? [ userMarkerRef.current ] : [] ),
			];

			if ( hasUserLocation && allMarkers.length > 1 ) {
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

	/* --- handle location search result --- */
	const handleLocationFound = useCallback(
		( lat: number, lng: number, _label: string ) => {
			const map = mapRef.current;
			if ( ! map ) return;

			map.setView( [ lat, lng ], 12 );

			// Update URL for shareability.
			const url = new URL( window.location.href );
			url.searchParams.set( 'lat', lat.toFixed( 6 ) );
			url.searchParams.set( 'lng', lng.toFixed( 6 ) );
			window.history.replaceState( {}, '', url.toString() );
		},
		[],
	);

	return (
		<>
			<div
				id={ containerId }
				ref={ containerRef }
				className="data-machine-events-map"
				style={ { height: `${ height }px` } }
				aria-label="Events map"
				role="application"
			/>
			{ showLocationSearch && geocodeUrl && (
				<LocationSearch
					geocodeUrl={ geocodeUrl }
					onLocationFound={ handleLocationFound }
				/>
			) }
		</>
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

	return {
		containerId: container.id || `dm-events-map-${ Date.now() }`,
		height: parseInt( data.height || '400', 10 ),
		zoom: parseInt( data.zoom || '12', 10 ),
		mapType: ( data.mapType || 'osm-standard' ) as MapType,
		centerLat: parseOptionalFloat( data.centerLat ),
		centerLon: parseOptionalFloat( data.centerLon ),
		userLat: parseOptionalFloat( data.userLat ),
		userLon: parseOptionalFloat( data.userLon ),
		venues: [],
		taxonomy: data.taxonomy || '',
		termId: parseInt( data.termId || '0', 10 ),
		restUrl: data.restUrl || '',
		nonce: data.nonce || '',
		showLocationSearch: data.showLocationSearch === '1',
		geocodeUrl: data.geocodeUrl || '',
	};
}

function initEventsMap(): void {
	const containers = document.querySelectorAll<HTMLElement>(
		'.data-machine-events-map-root',
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
document.addEventListener( 'data-machine-events-loaded', () => {
	initEventsMap();
} );
