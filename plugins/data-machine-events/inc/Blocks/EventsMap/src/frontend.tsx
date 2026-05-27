/**
 * Events Map Block Frontend
 *
 * React component rendered into the server-side container div.
 * Uses Leaflet via useRef/useEffect for map management, fetches venues
 * from the REST API, and emits custom events on bounds change.
 *
 * Performance optimizations:
 * - Marker clustering via leaflet.markercluster for dense areas
 * - Marker diffing: only add/remove changed markers on pan/zoom
 * - Viewport-based loading with debounced fetching
 *
 * @package DataMachineEvents
 * @since 0.5.0
 */

import { createRoot } from '@wordpress/element';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

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

/** Detect touch-primary devices (phones/tablets). */
function isTouchDevice(): boolean {
	return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
}

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

/**
 * Format YYYY-MM-DD (+ HH:MM:SS) into a short human label like
 * "Sep 23, 2099 · 8:00 PM". Falls back to the raw date if parsing fails so
 * the popup is never blank.
 */
function formatEventDateTime( date: string, time: string ): string {
	if ( ! date ) return '';

	// Build a date object using local time semantics. The server already
	// stored start_datetime in the site timezone, so treat it as local.
	const iso = time ? `${ date }T${ time }` : `${ date }T00:00:00`;
	const parsed = new Date( iso );

	if ( isNaN( parsed.getTime() ) ) {
		return time ? `${ date } ${ time }` : date;
	}

	const datePart = parsed.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );

	if ( ! time ) {
		return datePart;
	}

	const timePart = parsed.toLocaleTimeString( undefined, {
		hour: 'numeric',
		minute: '2-digit',
	} );

	return `${ datePart } · ${ timePart }`;
}

/**
 * Tour-route popup. Lists every upcoming show at this venue for the
 * scoped artist, chronologically. The same shape is used for first, last,
 * and middle markers — only the marker icon differs by tour position.
 */
function buildTourRoutePopupHtml( venue: Venue ): string {
	let html = '<div class="venue-popup venue-popup--tour-route">';

	if ( venue.url ) {
		html += `<a href="${ escapeHtml( venue.url ) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml( venue.name ) }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml( venue.address ) }</span>`;
	}

	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length > 0 ) {
		html += '<ul class="venue-popup-shows">';
		for ( const show of shows ) {
			const label = formatEventDateTime( show.start_date, show.start_time );
			const title = show.title || label || 'Event';
			if ( show.permalink ) {
				html += `<li><a href="${ escapeHtml( show.permalink ) }">${ escapeHtml( title ) }</a>`;
			} else {
				html += `<li><span>${ escapeHtml( title ) }</span>`;
			}
			if ( label && label !== title ) {
				html += ` <span class="venue-popup-show-date">${ escapeHtml( label ) }</span>`;
			}
			html += '</li>';
		}
		html += '</ul>';
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

/**
 * Tour-route marker. `position` flags first/last for distinct color
 * treatment; middle stops fall through to the default pin look but in
 * the tour-route className so site CSS can theme them as a set.
 *
 * Colors picked for high contrast against OSM tiles:
 *   - first = green  (#22c55e)
 *   - last  = red    (#ef4444)
 *   - middle = slate (#475569)
 *
 * v1 keeps numbered badges out of scope (per #310 design notes); revisit
 * once Chris weighs in on the live render.
 */
function createTourRouteIcon( position: 'first' | 'last' | 'middle' ): L.DivIcon {
	const color =
		position === 'first'
			? '#22c55e'
			: position === 'last'
				? '#ef4444'
				: '#475569';

	const html = `<span class="tour-route-pin tour-route-pin--${ position }" style="background:${ color };"></span>`;

	return L.divIcon( {
		html,
		className: `tour-route-marker tour-route-marker--${ position }`,
		iconSize: [ 22, 22 ],
		iconAnchor: [ 11, 22 ],
		popupAnchor: [ 0, -22 ],
	} );
}

/**
 * Earliest start_datetime (date + time) for a venue, as a sortable
 * "YYYY-MM-DD HH:MM:SS" string. Used to order venues chronologically when
 * drawing the tour-route polyline. Returns null when no events were
 * attached (which means we should skip the venue from the route).
 */
function earliestEventKey( venue: Venue ): string | null {
	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length === 0 ) return null;

	// The REST response already sorts ascending per venue, so shows[0] is
	// the earliest. Defensive guard for callers that might re-order.
	let earliest = '';
	for ( const show of shows ) {
		const key = `${ show.start_date || '' } ${ show.start_time || '' }`.trim();
		if ( ! key ) continue;
		if ( ! earliest || key < earliest ) {
			earliest = key;
		}
	}
	return earliest || null;
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
		new CustomEvent( 'data-machine-map-bounds-changed', { detail } ),
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
		tourRouteMode,
	} = props;

	const mapRef = useRef<L.Map | null>( null );
	const clusterGroupRef = useRef<L.MarkerClusterGroup | null>( null );
	const markerMapRef = useRef<Map<number, L.Marker>>( new Map() );
	const userMarkerRef = useRef<L.Marker | null>( null );
	// Single L.polyline holding the chronological tour route. Recreated
	// from scratch whenever venues change so we don't manage segment-level
	// diffing — the route is at most a few dozen points.
	const tourPolylineRef = useRef<L.Polyline | null>( null );
	// Tracks whether the tour-route effect has already fit bounds once.
	// Without this we'd re-fit on every bounds-change refetch and trap
	// the user inside the route.
	const tourFitOnceRef = useRef<boolean>( false );
	const containerRef = useRef<HTMLDivElement | null>( null );
	const gestureOverlayRef = useRef<HTMLDivElement | null>( null );
	const gestureTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(
		null,
	);

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
					// Tour-route popups and ordering need the per-venue
					// upcoming events array. Other contexts stay on the
					// lean default response shape.
					includeEvents: tourRouteMode || undefined,
				} );
				setVenues( result.venues );
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Events map: failed to fetch venues', err );
			} finally {
				setLoading( false );
			}
		},
		[ restUrl, nonce, taxonomy, termId, tourRouteMode ],
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

		const isTouch = isTouchDevice();

		const map = L.map( el, {
			scrollWheelZoom: false,
			boxZoom: true,
			// On touch devices: disable dragging so single-finger
			// scrolls the page. Users pinch-zoom or use two fingers.
			dragging: ! isTouch,
			tap: ! isTouch,
		} ).setView( [ initialLat, initialLon ], zoom );

		if ( isTouch ) {
			// Show gesture hint when user tries single-finger drag.
			const showGestureHint = () => {
				const overlay = gestureOverlayRef.current;
				if ( ! overlay ) return;

				overlay.style.opacity = '1';

				if ( gestureTimeoutRef.current ) {
					clearTimeout( gestureTimeoutRef.current );
				}
				gestureTimeoutRef.current = setTimeout( () => {
					overlay.style.opacity = '0';
				}, 1500 );
			};

			el.addEventListener( 'touchstart', ( e: TouchEvent ) => {
				if ( e.touches.length === 1 ) {
					showGestureHint();
				} else if ( e.touches.length >= 2 ) {
					// Two-finger gesture — enable dragging temporarily.
					map.dragging.enable();
				}
			}, { passive: true } );

			el.addEventListener( 'touchend', () => {
				// Re-disable dragging after gesture ends.
				map.dragging.disable();
			}, { passive: true } );
		} else {
			// Desktop: Ctrl/Cmd + scroll to zoom.
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
		}

		// Tile layer.
		const tileUrl = TILE_URLS[ mapType ] || TILE_URLS[ 'osm-standard' ];
		L.tileLayer( tileUrl, {
			attribution: '',
			maxZoom: 18,
			minZoom: 8,
		} ).addTo( map );

		// Initialize marker cluster group.
		const clusterGroup = L.markerClusterGroup( {
			maxClusterRadius: 25,
			spiderfyOnMaxZoom: true,
			showCoverageOnHover: false,
			zoomToBoundsOnClick: true,
			disableClusteringAtZoom: 14,
			chunkedLoading: true,
			chunkInterval: 100,
			chunkDelay: 10,
		} );
		map.addLayer( clusterGroup );
		clusterGroupRef.current = clusterGroup;

		mapRef.current = map;

		// Fetch venues on pan/zoom and dispatch bounds-changed events.
		// Tour-route mode is artist-scoped: the full set is already small
		// and refetching by viewport would drop venues that the user just
		// panned away from, mid-route. Skip the moveend refetch for it.
		if ( ! tourRouteMode ) {
			map.on( 'moveend', () => debouncedFetch( map ) );
		}

		// Force a resize check after mount.
		setTimeout( () => map.invalidateSize(), 100 );

		// Fetch venues on mount and notify other blocks (e.g. calendar geo-sync).
		if ( initialVenues.length === 0 ) {
			// Small delay so map is fully sized first.
			setTimeout( () => {
				// Tour-route mode wants the full set of artist venues
				// regardless of the default viewport, then it auto-fits
				// to those points. Passing bounds here would clip the
				// route on first paint.
				const bounds = tourRouteMode ? undefined : getBoundsFromMap( map );
				loadVenues( bounds );
				dispatchBoundsChanged( map );
			}, 200 );
		}

		return () => {
			map.remove();
			mapRef.current = null;
			clusterGroupRef.current = null;
			tourPolylineRef.current = null;
			tourFitOnceRef.current = false;
			markerMapRef.current.clear();
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

		document.addEventListener( 'data-machine-map-recenter', handler );
		return () => {
			document.removeEventListener( 'data-machine-map-recenter', handler );
		};
	}, [] );

	/* --- listen for external user-location updates (e.g. geolocation) --- */
	useEffect( () => {
		const handler = ( e: Event ) => {
			const map = mapRef.current;
			if ( ! map ) return;

			const detail = ( e as CustomEvent< {
				lat: number;
				lng: number;
			} > ).detail;

			if ( ! detail?.lat || ! detail?.lng ) return;

			// Remove old user marker if present.
			if ( userMarkerRef.current ) {
				map.removeLayer( userMarkerRef.current );
			}

			const icon = createUserLocationIcon();
			const marker = L.marker( [ detail.lat, detail.lng ], { icon } )
				.addTo( map )
				.bindPopup(
					'<div class="venue-popup"><span class="venue-popup-name">You are here</span></div>',
				);

			userMarkerRef.current = marker;
		};

		document.addEventListener(
			'data-machine-map-set-user-location',
			handler,
		);
		return () => {
			document.removeEventListener(
				'data-machine-map-set-user-location',
				handler,
			);
		};
	}, [] );

	/* --- update markers when venues change (with diffing) --- */
	useEffect( () => {
		const map = mapRef.current;
		const clusterGroup = clusterGroupRef.current;
		if ( ! map || ! clusterGroup ) return;

		// Always tear down any previously-drawn tour polyline first. Whether
		// or not this redraw ends up creating a new one, the stale one must
		// not linger across filter changes / bounds refetches.
		if ( tourPolylineRef.current ) {
			map.removeLayer( tourPolylineRef.current );
			tourPolylineRef.current = null;
		}

		// ============================================================
		// Tour-route mode: chronological polyline + first/last styling.
		// Simpler than the diffing path — every redraw clears markers and
		// re-creates them because tour position (first/last/middle) is a
		// function of the whole set, not the individual venue. The route
		// is bounded by upcoming events for a single artist, so cardinality
		// is small (typically <30).
		// ============================================================
		if ( tourRouteMode ) {
			const currentMarkers = markerMapRef.current;
			if ( currentMarkers.size > 0 ) {
				clusterGroup.clearLayers();
				currentMarkers.clear();
			}

			// Keep only venues with coordinates AND attached events.
			// Venues without events have no position in the route and
			// would clutter the map with orphan pins.
			const routeVenues = venues
				.filter( ( v ) => v.lat && v.lon && ( v.upcoming_events_at_venue?.length ?? 0 ) > 0 )
				.map( ( v ) => ( { venue: v, key: earliestEventKey( v ) } ) )
				.filter( ( entry ): entry is { venue: Venue; key: string } => entry.key !== null )
				.sort( ( a, b ) => ( a.key < b.key ? -1 : a.key > b.key ? 1 : 0 ) )
				.map( ( entry ) => entry.venue );

			// Per #310: <2 distinct venues = no route. The host plugin
			// (extrachill-events artist-map.php) also gates this server-side,
			// but enforce it here too so the block stays self-contained when
			// rendered directly via shortcode/REST.
			if ( routeVenues.length < 2 ) {
				return;
			}

			// Polyline coords with consecutive-duplicate collapsing. Two
			// chronologically-adjacent shows at the same venue (residency)
			// should NOT cause a self-loop segment. We collapse on
			// term_id, not on lat/lng, because two venue terms can share
			// coordinates (e.g. data-quality dupes).
			const orderedLatLngs: L.LatLngExpression[] = [];
			let lastTermId = -1;
			for ( const v of routeVenues ) {
				if ( v.term_id === lastTermId ) continue;
				orderedLatLngs.push( [ v.lat, v.lon ] );
				lastTermId = v.term_id;
			}

			// Draw the polyline BEFORE the cluster group so markers paint
			// on top. addLayer is idempotent ordering-wise — earlier
			// addLayer = lower z-stack.
			if ( orderedLatLngs.length >= 2 ) {
				const polyline = L.polyline( orderedLatLngs, {
					color: '#2563eb',
					weight: 3,
					opacity: 0.7,
					className: 'tour-route-polyline',
				} );
				polyline.addTo( map );
				tourPolylineRef.current = polyline;
			}

			// Build markers with position-aware icons + multi-date popups.
			const markersToAdd: L.Marker[] = [];
			routeVenues.forEach( ( venue, idx ) => {
				const position: 'first' | 'last' | 'middle' =
					idx === 0
						? 'first'
						: idx === routeVenues.length - 1
							? 'last'
							: 'middle';

				const marker = L.marker( [ venue.lat, venue.lon ], {
					icon: createTourRouteIcon( position ),
				} ).bindPopup( buildTourRoutePopupHtml( venue ) );

				currentMarkers.set( venue.term_id, marker );
				markersToAdd.push( marker );
			} );

			if ( markersToAdd.length > 0 ) {
				clusterGroup.addLayers( markersToAdd );
			}

			// Fit bounds to the full route on the FIRST successful render.
			// Subsequent refetches (filter changes, bounds events) keep
			// the current viewport so the user isn't yanked around.
			if ( ! tourFitOnceRef.current ) {
				const latlngs = orderedLatLngs.length > 0
					? orderedLatLngs
					: routeVenues.map( ( v ) => [ v.lat, v.lon ] as L.LatLngExpression );
				if ( latlngs.length > 0 ) {
					const bounds = L.latLngBounds( latlngs );
					map.fitBounds( bounds.pad( 0.15 ) );
					tourFitOnceRef.current = true;
				}
			}

			return;
		}

		// ============================================================
		// Default (non-tour-route) mode — preserved verbatim.
		// ============================================================
		const icon = createVenueIcon();
		const currentMarkers = markerMapRef.current;
		const newVenueIds = new Set<number>();

		// Collect new venues that need markers.
		const markersToAdd: L.Marker[] = [];

		venues.forEach( ( venue ) => {
			if ( ! venue.lat || ! venue.lon ) return;

			newVenueIds.add( venue.term_id );

			// Check if marker already exists for this venue.
			const existing = currentMarkers.get( venue.term_id );
			if ( existing ) {
				// Update popup content if event count might have changed.
				existing.setPopupContent( buildPopupHtml( venue ) );
				return;
			}

			// Create new marker.
			const marker = L.marker( [ venue.lat, venue.lon ], { icon } )
				.bindPopup( buildPopupHtml( venue ) );
			currentMarkers.set( venue.term_id, marker );
			markersToAdd.push( marker );
		} );

		// Remove markers for venues no longer in the dataset.
		const markersToRemove: L.Marker[] = [];
		currentMarkers.forEach( ( marker, termId ) => {
			if ( ! newVenueIds.has( termId ) ) {
				markersToRemove.push( marker );
				currentMarkers.delete( termId );
			}
		} );

		// Batch update the cluster group.
		if ( markersToRemove.length > 0 ) {
			clusterGroup.removeLayers( markersToRemove );
		}
		if ( markersToAdd.length > 0 ) {
			clusterGroup.addLayers( markersToAdd );
		}

		// Fit bounds on first load when we have a user location or
		// initial venues (before the user has interacted with the map).
		if ( initialVenues.length > 0 ) {
			const allLayers = clusterGroup.getLayers() as L.Marker[];

			if ( hasUserLocation && allLayers.length > 0 ) {
				map.setView( [ userLat!, userLon! ], 13 );
			} else if ( allLayers.length > 1 ) {
				const group = L.featureGroup( allLayers );
				map.fitBounds( group.getBounds().pad( 0.1 ) );
			} else if (
				allLayers.length === 1 &&
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
			<div className="data-machine-events-map-container">
				<div
					id={ containerId }
					ref={ containerRef }
					className="data-machine-events-map"
					style={ { height: `${ height }px` } }
					aria-label="Events map"
					role="application"
				/>
				<div
					ref={ gestureOverlayRef }
					className="data-machine-events-map-gesture-overlay"
					aria-hidden="true"
				>
					Use two fingers to move the map
				</div>
			</div>
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
		tourRouteMode: data.tourRouteMode === '1',
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
