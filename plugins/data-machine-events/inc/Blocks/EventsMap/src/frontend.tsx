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
 * @package
 * @since 0.5.0
 */

/**
 * WordPress dependencies
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';

/**
 * External dependencies
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

/**
 * Internal dependencies
 */
import { fetchVenues } from './api-client';
import { createGeoAuthorityTracker, resolveInitialView } from './geo-authority';
import { TILE_URLS } from './types';
import type {
	Venue,
	MapProps,
	MapType,
	MapBounds,
	BoundsChangedEvent,
} from './types';
import type {
	GeoAuthoritySource,
	GeoAuthorityOperation,
} from './geo-authority';

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
		html += `<a href="${ escapeHtml(
			venue.url
		) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml(
			venue.name
		) }</span>`;
	}

	if ( venue.event_count > 0 ) {
		html += `<span class="venue-popup-events">${
			venue.event_count
		} upcoming event${ venue.event_count !== 1 ? 's' : '' }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml(
			venue.address
		) }</span>`;
	}

	html += '</div>';
	return html;
}

/**
 * Format YYYY-MM-DD (+ HH:MM:SS) into a short human label like
 * "Sep 23, 2099 · 8:00 PM". Falls back to the raw date if parsing fails so
 * the popup is never blank.
 *
 * @param date Event date.
 * @param time Event time.
 */
function formatEventDateTime( date: string, time: string ): string {
	if ( ! date ) {
		return '';
	}

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
 * Chronological-route popup. Lists every upcoming show at this venue for the
 * scoped taxonomy term, chronologically. The same shape is used for first,
 * last, and middle markers — only the marker icon differs by route position.
 *
 * @param venue Venue whose events should be rendered.
 */
function buildChronologicalRoutePopupHtml( venue: Venue ): string {
	let html = '<div class="venue-popup venue-popup--chronological-route">';

	if ( venue.url ) {
		html += `<a href="${ escapeHtml(
			venue.url
		) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml(
			venue.name
		) }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml(
			venue.address
		) }</span>`;
	}

	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length > 0 ) {
		html += '<ul class="venue-popup-shows">';
		for ( const show of shows ) {
			const label = formatEventDateTime(
				show.start_date,
				show.start_time
			);
			const title = show.title || label || 'Event';
			if ( show.permalink ) {
				html += `<li><a href="${ escapeHtml(
					show.permalink
				) }">${ escapeHtml( title ) }</a>`;
			} else {
				html += `<li><span>${ escapeHtml( title ) }</span>`;
			}
			if ( label && label !== title ) {
				html += ` <span class="venue-popup-show-date">${ escapeHtml(
					label
				) }</span>`;
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
 * Chronological-route marker. `position` flags first/last for distinct color
 * treatment; middle stops fall through to the default pin look but in the
 * chronological-route className so site CSS can theme them as a set.
 *
 * Colors picked for high contrast against OSM tiles:
 *   - first = green  (#22c55e)
 *   - last  = red    (#ef4444)
 *   - middle = slate (#475569)
 *
 * v1 keeps numbered badges out of scope (per #310 design notes); revisit
 * once Chris weighs in on the live render.
 *
 * @param position Position of the venue in the route.
 */
function createChronologicalRouteIcon(
	position: 'first' | 'last' | 'middle'
): L.DivIcon {
	let color = '#475569';
	if ( position === 'first' ) {
		color = '#22c55e';
	} else if ( position === 'last' ) {
		color = '#ef4444';
	}

	const html = `<span class="chronological-route-pin chronological-route-pin--${ position }" style="background:${ color };"></span>`;

	return L.divIcon( {
		html,
		className: `chronological-route-marker chronological-route-marker--${ position }`,
		iconSize: [ 22, 22 ],
		iconAnchor: [ 11, 22 ],
		popupAnchor: [ 0, -22 ],
	} );
}

/**
 * Earliest start_datetime (date + time) for a venue, as a sortable
 * "YYYY-MM-DD HH:MM:SS" string. Used to order venues chronologically when
 * drawing the chronological-route polyline. Returns null when no events
 * were attached (which means we should skip the venue from the route).
 *
 * @param venue Venue whose earliest event should be found.
 */
function earliestEventKey( venue: Venue ): string | null {
	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length === 0 ) {
		return null;
	}

	// The REST response already sorts ascending per venue, so shows[0] is
	// the earliest. Defensive guard for callers that might re-order.
	let earliest = '';
	for ( const show of shows ) {
		const key = `${ show.start_date || '' } ${
			show.start_time || ''
		}`.trim();
		if ( ! key ) {
			continue;
		}
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

function dispatchBoundsChanged(
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

function moveWithAuthority(
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

function isTargetedToMap(
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

/* ---------- debounce ---------- */

function createDebounce(
	fn: ( map: L.Map ) => void,
	ms: number
): { schedule: ( map: L.Map ) => void; cancel: () => void } {
	let timer: ReturnType< typeof setTimeout > | null = null;
	return {
		schedule( map ) {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( () => {
				timer = null;
				fn( map );
			}, ms );
		},
		cancel() {
			if ( timer ) {
				clearTimeout( timer );
				timer = null;
			}
		},
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
		'Enter a city or address...'
	);
	const requestRef = useRef< AbortController | null >( null );

	useEffect( () => {
		return () => requestRef.current?.abort();
	}, [] );

	const handleSubmit = useCallback(
		async ( e: React.FormEvent ) => {
			e.preventDefault();

			const trimmed = query.trim();
			if ( ! trimmed ) {
				return;
			}

			setLoading( true );
			setError( '' );
			requestRef.current?.abort();
			const controller = new AbortController();
			requestRef.current = controller;

			try {
				const url = `${ geocodeUrl }?query=${ encodeURIComponent(
					trimmed
				) }`;
				const response = await fetch( url, {
					headers: { Accept: 'application/json' },
					signal: controller.signal,
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
						'Location not found. Try a different city or address.'
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
			} catch ( fetchError ) {
				if ( ( fetchError as Error ).name === 'AbortError' ) {
					return;
				}
				setError(
					'Could not look up that location. Please try again.'
				);
			} finally {
				if ( requestRef.current === controller ) {
					requestRef.current = null;
					setLoading( false );
				}
			}
		},
		[ query, geocodeUrl, onLocationFound ]
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

export function EventsMap( props: MapProps ): JSX.Element | null {
	const {
		containerId,
		syncId,
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
		chronologicalRouteMode,
		scopeToken,
	} = props;

	const mapRef = useRef< L.Map | null >( null );
	const clusterGroupRef = useRef< L.MarkerClusterGroup | null >( null );
	const markerMapRef = useRef< Map< number, L.Marker > >( new Map() );
	const userMarkerRef = useRef< L.Marker | null >( null );
	const geoAuthorityRef = useRef( createGeoAuthorityTracker() );
	const venueRequestRef = useRef< AbortController | null >( null );
	// Single L.polyline holding the chronological route. Recreated from
	// scratch whenever venues change so we don't manage segment-level
	// diffing — the route is at most a few dozen points.
	const chronologicalRoutePolylineRef = useRef< L.Polyline | null >( null );
	// Tracks whether the chronological-route effect has already fit bounds
	// once. Without this we'd re-fit on every bounds-change refetch and
	// trap the user inside the route.
	const chronologicalRouteFitOnceRef = useRef< boolean >( false );
	const containerRef = useRef< HTMLDivElement | null >( null );
	const gestureOverlayRef = useRef< HTMLDivElement | null >( null );
	const gestureTimeoutRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);

	const [ venues, setVenues ] = useState< Venue[] >( initialVenues );
	const hasCenter = centerLat !== null && centerLon !== null;
	const hasUserLocation = userLat !== null && userLon !== null;

	/* --- fetch venues from REST API --- */
	const loadVenues = useCallback(
		async ( bounds?: MapBounds ) => {
			if ( ! restUrl ) {
				return;
			}

			venueRequestRef.current?.abort();
			const controller = new AbortController();
			venueRequestRef.current = controller;

			try {
				const result = await fetchVenues( restUrl, nonce, {
					signal: controller.signal,
					bounds,
					taxonomy: taxonomy || undefined,
					termId: termId || undefined,
					// Chronological-route popups and ordering need the
					// per-venue upcoming events array. Other contexts stay
					// on the lean default response shape.
					includeEvents: chronologicalRouteMode || undefined,
					// #160: re-send the opaque scope token so a consumer's
					// server-side venue scoping survives this REST fetch
					// (mount + every pan/zoom). Without it the public
					// endpoint returns the full venue set.
					scopeToken: scopeToken || undefined,
				} );
				if ( venueRequestRef.current === controller ) {
					setVenues( result.venues );
				}
			} catch ( err ) {
				if ( ( err as Error ).name === 'AbortError' ) {
					return;
				}
				// eslint-disable-next-line no-console
				console.error( 'Events map: failed to fetch venues', err );
			} finally {
				if ( venueRequestRef.current === controller ) {
					venueRequestRef.current = null;
				}
			}
		},
		[ restUrl, nonce, taxonomy, termId, chronologicalRouteMode, scopeToken ]
	);

	/* --- debounced bounds handler --- */
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const debouncedFetch = useCallback(
		createDebounce( ( map: L.Map ) => {
			const bounds = getBoundsFromMap( map );
			loadVenues( bounds );
		}, 500 ),
		[ loadVenues ]
	);

	/* --- initialize map --- */
	useEffect( () => {
		const el = containerRef.current;
		if ( ! el || mapRef.current ) {
			return;
		}

		const markerMap = markerMapRef.current;
		const initialView = resolveInitialView( {
			center: hasCenter ? { lat: centerLat!, lng: centerLon! } : null,
			userLocation: hasUserLocation
				? { lat: userLat!, lng: userLon! }
				: null,
			venueLocation:
				venues.length > 0
					? { lat: venues[ 0 ].lat, lng: venues[ 0 ].lon }
					: null,
		} );

		const isTouch = isTouchDevice();

		const map = L.map( el, {
			scrollWheelZoom: false,
			boxZoom: true,
			// On touch devices: disable dragging so single-finger
			// scrolls the page. Users pinch-zoom or use two fingers.
			dragging: ! isTouch,
			tap: ! isTouch,
		} as L.MapOptions ).setView(
			[ initialView.center.lat, initialView.center.lng ],
			zoom
		);

		const authority = geoAuthorityRef.current;
		const initialOperation = initialView.authority
			? authority.immediate( initialView.authority )
			: null;
		const interactionAbandonTimers = new Set<
			ReturnType< typeof setTimeout >
		>();
		const mountTimers = new Set< ReturnType< typeof setTimeout > >();
		const cleanupCallbacks: Array< () => void > = [];
		let activeGestureGeneration: number | null = null;
		let activeGestureMoved = false;
		let activeGestureType: 'drag' | 'boxzoom' | null = null;
		const prepareUserInteraction = () => {
			const operation = authority.prepare( 'user-interaction' );
			const timer = setTimeout( () => {
				authority.abandon( operation.generation );
				interactionAbandonTimers.delete( timer );
			}, 750 );
			interactionAbandonTimers.add( timer );
		};
		const activateUserInteraction = ( type: 'drag' | 'boxzoom' ) => {
			activeGestureMoved = false;
			activeGestureType = type;
			activeGestureGeneration =
				authority.activate( 'user-interaction' ).generation;
		};
		const handleDragStart = () => activateUserInteraction( 'drag' );
		const handleBoxZoomStart = () => activateUserInteraction( 'boxzoom' );
		const cancelActiveGesture = () => {
			if ( activeGestureGeneration !== null ) {
				authority.cancel( activeGestureGeneration );
				activeGestureGeneration = null;
				activeGestureMoved = false;
				activeGestureType = null;
			}
		};
		const handleMove = () => {
			if ( activeGestureGeneration !== null ) {
				activeGestureMoved = true;
			}
		};
		const handleDragEnd = () => {
			if ( activeGestureGeneration !== null && ! activeGestureMoved ) {
				cancelActiveGesture();
			}
		};
		const handleMoveStart = () => authority.movementStarted();
		const handleMoveEnd = () => {
			if ( ! chronologicalRouteMode ) {
				debouncedFetch.schedule( map );
			}
			const operation = authority.movementEnded();
			if ( operation ) {
				if ( activeGestureGeneration === operation.generation ) {
					activeGestureGeneration = null;
					activeGestureMoved = false;
					activeGestureType = null;
				}
				dispatchBoundsChanged( map, syncId, operation );
			}
		};
		const handleKeyDown = ( event: KeyboardEvent ) => {
			if (
				[
					'ArrowUp',
					'ArrowDown',
					'ArrowLeft',
					'ArrowRight',
					'+',
					'-',
					'=',
				].includes( event.key )
			) {
				prepareUserInteraction();
			}
		};
		const handleDocumentKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' && activeGestureType === 'boxzoom' ) {
				cancelActiveGesture();
			}
		};
		const handleClick = ( event: MouseEvent ) => {
			const target = event.target as Element | null;
			if (
				target?.closest(
					'.leaflet-control-zoom-in, .leaflet-control-zoom-out, .marker-cluster'
				)
			) {
				prepareUserInteraction();
			}
		};
		const handleDoubleClick = () => prepareUserInteraction();

		map.on( 'movestart', handleMoveStart );
		map.on( 'move', handleMove );
		map.on( 'moveend', handleMoveEnd );
		map.on( 'dragstart', handleDragStart );
		map.on( 'boxzoomstart', handleBoxZoomStart );
		map.on( 'dragend', handleDragEnd );
		el.addEventListener( 'dblclick', handleDoubleClick, true );
		el.addEventListener( 'keydown', handleKeyDown, true );
		el.addEventListener( 'click', handleClick, true );
		document.addEventListener( 'keydown', handleDocumentKeyDown, true );

		if ( isTouch ) {
			// Show gesture hint when user tries single-finger drag.
			const showGestureHint = () => {
				const overlay = gestureOverlayRef.current;
				if ( ! overlay ) {
					return;
				}

				overlay.style.opacity = '1';

				if ( gestureTimeoutRef.current ) {
					clearTimeout( gestureTimeoutRef.current );
				}
				gestureTimeoutRef.current = setTimeout( () => {
					overlay.style.opacity = '0';
				}, 1500 );
			};

			const handleTouchStart = ( e: TouchEvent ) => {
				if ( e.touches.length === 1 ) {
					showGestureHint();
				} else if ( e.touches.length >= 2 ) {
					// Two-finger gesture — enable dragging temporarily.
					prepareUserInteraction();
					map.dragging.enable();
				}
			};

			const handleTouchEnd = () => map.dragging.disable();
			el.addEventListener( 'touchstart', handleTouchStart, {
				passive: true,
			} );
			el.addEventListener( 'touchend', handleTouchEnd, {
				passive: true,
			} );
			cleanupCallbacks.push( () => {
				el.removeEventListener( 'touchstart', handleTouchStart );
				el.removeEventListener( 'touchend', handleTouchEnd );
			} );
		} else {
			// Desktop: Ctrl/Cmd + scroll to zoom.
			const handleWheel = ( e: WheelEvent ) => {
				if ( e.ctrlKey || e.metaKey ) {
					e.preventDefault();
					prepareUserInteraction();
					map.scrollWheelZoom.enable();
				}
			};
			const handleMouseOut = () => map.scrollWheelZoom.disable();
			el.addEventListener( 'wheel', handleWheel, { passive: false } );
			map.on( 'mouseout', handleMouseOut );
			cleanupCallbacks.push( () => {
				el.removeEventListener( 'wheel', handleWheel );
				map.off( 'mouseout', handleMouseOut );
			} );
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
		// Chronological-route mode operates on a bounded set (one taxonomy
		// term's events) — the full set is already small and refetching by
		// viewport would drop venues that the user just panned away from,
		// mid-route. Skip the moveend refetch for it.
		// Force a resize check after mount.
		const resizeTimer = setTimeout( () => map.invalidateSize(), 100 );

		// Collapsible support: when the block is rendered inside a
		// collapsible region, the container can be hidden (display:none /
		// [hidden]) at the moment Leaflet first lays out, which leaves the
		// map sized to a zero-height box and renders gray tiles. The mount
		// layer (initEventsMap) defers React mount until the FIRST expand so
		// init always happens with real dimensions; for every SUBSEQUENT
		// expand it dispatches `data-machine-map-invalidate-size` at this
		// container so Leaflet recomputes tiles. We listen for that here,
		// mirroring the existing recenter/set-user-location event pattern.
		const handleInvalidateSize = () => {
			// rAF so we read the post-layout size after the collapsed class
			// / [hidden] attribute has been removed by the toggle handler.
			window.requestAnimationFrame( () => map.invalidateSize() );
		};
		el.addEventListener(
			'data-machine-map-invalidate-size',
			handleInvalidateSize
		);

		// Fetch venues on mount and notify other blocks (e.g. calendar geo-sync).
		if ( initialVenues.length === 0 ) {
			// Small delay so map is fully sized first.
			const mountTimer = setTimeout( () => {
				// Chronological-route mode wants the full bounded set
				// regardless of the default viewport, then it auto-fits
				// to those points. Passing bounds here would clip the
				// route on first paint.
				const bounds = chronologicalRouteMode
					? undefined
					: getBoundsFromMap( map );
				loadVenues( bounds );
				if (
					initialOperation &&
					authority.isLatest( initialOperation.generation ) &&
					map.getCenter().lat === initialView.center.lat &&
					map.getCenter().lng === initialView.center.lng
				) {
					dispatchBoundsChanged( map, syncId, initialOperation );
				}
			}, 200 );
			mountTimers.add( mountTimer );
		}

		return () => {
			clearTimeout( resizeTimer );
			mountTimers.forEach( clearTimeout );
			mountTimers.clear();
			interactionAbandonTimers.forEach( clearTimeout );
			interactionAbandonTimers.clear();
			if ( gestureTimeoutRef.current ) {
				clearTimeout( gestureTimeoutRef.current );
				gestureTimeoutRef.current = null;
			}
			debouncedFetch.cancel();
			venueRequestRef.current?.abort();
			venueRequestRef.current = null;
			authority.destroy();
			map.off( 'movestart', handleMoveStart );
			map.off( 'move', handleMove );
			map.off( 'moveend', handleMoveEnd );
			map.off( 'dragstart', handleDragStart );
			map.off( 'boxzoomstart', handleBoxZoomStart );
			map.off( 'dragend', handleDragEnd );
			el.removeEventListener( 'dblclick', handleDoubleClick, true );
			el.removeEventListener( 'keydown', handleKeyDown, true );
			el.removeEventListener( 'click', handleClick, true );
			document.removeEventListener(
				'keydown',
				handleDocumentKeyDown,
				true
			);
			cleanupCallbacks.forEach( ( cleanup ) => cleanup() );
			el.removeEventListener(
				'data-machine-map-invalidate-size',
				handleInvalidateSize
			);
			map.remove();
			mapRef.current = null;
			clusterGroupRef.current = null;
			chronologicalRoutePolylineRef.current = null;
			chronologicalRouteFitOnceRef.current = false;
			markerMap.clear();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	/* --- listen for external recenter requests --- */
	useEffect( () => {
		const handler = ( e: Event ) => {
			const map = mapRef.current;
			if ( ! map ) {
				return;
			}

			const detail = (
				e as CustomEvent< {
					lat: number;
					lng: number;
					zoom?: number;
					syncId?: string;
					authority?: 'external' | 'user-location';
				} >
			 ).detail;

			if (
				! detail ||
				! Number.isFinite( detail.lat ) ||
				! Number.isFinite( detail.lng ) ||
				! isTargetedToMap( detail.syncId, syncId )
			) {
				return;
			}

			moveWithAuthority(
				map,
				syncId,
				geoAuthorityRef.current,
				detail.authority ?? 'external',
				detail.lat,
				detail.lng,
				detail.zoom ?? map.getZoom()
			);
		};

		document.addEventListener( 'data-machine-map-recenter', handler );
		return () => {
			document.removeEventListener(
				'data-machine-map-recenter',
				handler
			);
		};
	}, [ syncId ] );

	/* --- listen for external user-location updates (e.g. geolocation) --- */
	useEffect( () => {
		const handler = ( e: Event ) => {
			const map = mapRef.current;
			if ( ! map ) {
				return;
			}

			const detail = (
				e as CustomEvent< {
					lat: number;
					lng: number;
					syncId?: string;
				} >
			 ).detail;

			if (
				! detail ||
				! Number.isFinite( detail.lat ) ||
				! Number.isFinite( detail.lng ) ||
				! isTargetedToMap( detail.syncId, syncId )
			) {
				return;
			}

			// Remove old user marker if present.
			if ( userMarkerRef.current ) {
				map.removeLayer( userMarkerRef.current );
			}

			const icon = createUserLocationIcon();
			const marker = L.marker( [ detail.lat, detail.lng ], { icon } )
				.addTo( map )
				.bindPopup(
					'<div class="venue-popup"><span class="venue-popup-name">You are here</span></div>'
				);

			userMarkerRef.current = marker;
		};

		document.addEventListener(
			'data-machine-map-set-user-location',
			handler
		);
		return () => {
			document.removeEventListener(
				'data-machine-map-set-user-location',
				handler
			);
		};
	}, [ syncId ] );

	/* --- update markers when venues change (with diffing) --- */
	useEffect( () => {
		const map = mapRef.current;
		const clusterGroup = clusterGroupRef.current;
		if ( ! map || ! clusterGroup ) {
			return;
		}

		// Always tear down any previously-drawn route polyline first.
		// Whether or not this redraw ends up creating a new one, the stale
		// one must not linger across filter changes / bounds refetches.
		if ( chronologicalRoutePolylineRef.current ) {
			map.removeLayer( chronologicalRoutePolylineRef.current );
			chronologicalRoutePolylineRef.current = null;
		}

		// ============================================================
		// Chronological-route mode: polyline ordered by event date with
		// first/last styling. Simpler than the diffing path — every redraw
		// clears markers and re-creates them because route position (first/
		// last/middle) is a function of the whole set, not the individual
		// venue. The route is bounded by upcoming events for a single
		// taxonomy term, so cardinality is small (typically <30).
		// ============================================================
		if ( chronologicalRouteMode ) {
			const currentMarkers = markerMapRef.current;
			if ( currentMarkers.size > 0 ) {
				clusterGroup.clearLayers();
				currentMarkers.clear();
			}

			// Keep only venues with coordinates AND attached events.
			// Venues without events have no position in the route and
			// would clutter the map with orphan pins.
			const routeVenues = venues
				.filter(
					( v ) =>
						v.lat &&
						v.lon &&
						( v.upcoming_events_at_venue?.length ?? 0 ) > 0
				)
				.map( ( v ) => ( { venue: v, key: earliestEventKey( v ) } ) )
				.filter(
					( entry ): entry is { venue: Venue; key: string } =>
						entry.key !== null
				)
				.sort( ( a, b ) => {
					if ( a.key === b.key ) {
						return 0;
					}
					return a.key < b.key ? -1 : 1;
				} )
				.map( ( entry ) => entry.venue );

			// Per #310: <2 distinct venues = no route. Host plugins also
			// gate this server-side, but enforce it here too so the block
			// stays self-contained when rendered directly via shortcode/REST.
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
				if ( v.term_id === lastTermId ) {
					continue;
				}
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
					className: 'chronological-route-polyline',
				} );
				polyline.addTo( map );
				chronologicalRoutePolylineRef.current = polyline;
			}

			// Build markers with position-aware icons + multi-date popups.
			const markersToAdd: L.Marker[] = [];
			routeVenues.forEach( ( venue, idx ) => {
				let position: 'first' | 'last' | 'middle' = 'middle';
				if ( idx === 0 ) {
					position = 'first';
				} else if ( idx === routeVenues.length - 1 ) {
					position = 'last';
				}

				const marker = L.marker( [ venue.lat, venue.lon ], {
					icon: createChronologicalRouteIcon( position ),
				} ).bindPopup( buildChronologicalRoutePopupHtml( venue ) );

				currentMarkers.set( venue.term_id, marker );
				markersToAdd.push( marker );
			} );

			if ( markersToAdd.length > 0 ) {
				clusterGroup.addLayers( markersToAdd );
			}

			// Fit bounds to the full route and any explicit map center on the
			// FIRST successful render. The center anchors the viewport
			// without becoming a stop in the chronological route polyline.
			// Subsequent refetches (filter changes, bounds events) keep
			// the current viewport so the user isn't yanked around.
			if ( ! chronologicalRouteFitOnceRef.current ) {
				const latlngs = [
					...( orderedLatLngs.length > 0
						? orderedLatLngs
						: routeVenues.map(
								( v ) => [ v.lat, v.lon ] as L.LatLngExpression
						  ) ),
				];
				if ( hasCenter ) {
					latlngs.push( [ centerLat!, centerLon! ] );
				}
				if ( latlngs.length > 0 ) {
					const bounds = L.latLngBounds( latlngs );
					map.fitBounds( bounds.pad( 0.15 ) );
					chronologicalRouteFitOnceRef.current = true;
				}
			}

			return;
		}

		// ============================================================
		// Default (non-chronological-route) mode — preserved verbatim.
		// ============================================================
		const icon = createVenueIcon();
		const currentMarkers = markerMapRef.current;
		const newVenueIds = new Set< number >();

		// Collect new venues that need markers.
		const markersToAdd: L.Marker[] = [];

		venues.forEach( ( venue ) => {
			if ( ! venue.lat || ! venue.lon ) {
				return;
			}

			newVenueIds.add( venue.term_id );

			// Check if marker already exists for this venue.
			const existing = currentMarkers.get( venue.term_id );
			if ( existing ) {
				// Update popup content if event count might have changed.
				existing.setPopupContent( buildPopupHtml( venue ) );
				return;
			}

			// Create new marker.
			const marker = L.marker( [ venue.lat, venue.lon ], {
				icon,
			} ).bindPopup( buildPopupHtml( venue ) );
			currentMarkers.set( venue.term_id, marker );
			markersToAdd.push( marker );
		} );

		// Remove markers for venues no longer in the dataset.
		const markersToRemove: L.Marker[] = [];
		currentMarkers.forEach( ( marker, venueTermId ) => {
			if ( ! newVenueIds.has( venueTermId ) ) {
				markersToRemove.push( marker );
				currentMarkers.delete( venueTermId );
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
			} else if ( allLayers.length === 1 && ! hasCenter ) {
				map.setView( [ venues[ 0 ].lat, venues[ 0 ].lon ], 13 );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ venues ] );

	/* --- user location marker --- */
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map || ! hasUserLocation ) {
			return;
		}

		if ( userMarkerRef.current ) {
			map.removeLayer( userMarkerRef.current );
		}

		const icon = createUserLocationIcon();
		const marker = L.marker( [ userLat!, userLon! ], { icon } )
			.addTo( map )
			.bindPopup(
				'<div class="venue-popup"><span class="venue-popup-name">You are here</span></div>'
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
		( lat: number, lng: number ) => {
			const map = mapRef.current;
			if ( ! map ) {
				return;
			}

			moveWithAuthority(
				map,
				syncId,
				geoAuthorityRef.current,
				'manual-search',
				lat,
				lng,
				12
			);

			// Update URL for shareability.
			const url = new URL( window.location.href );
			url.searchParams.set( 'lat', lat.toFixed( 6 ) );
			url.searchParams.set( 'lng', lng.toFixed( 6 ) );
			window.history.replaceState( {}, '', url.toString() );
		},
		[ syncId ]
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
	const generatedId = container.id || `dm-events-map-${ Date.now() }`;

	const parseOptionalFloat = ( val?: string ): number | null => {
		if ( ! val || val === '' ) {
			return null;
		}
		const n = parseFloat( val );
		return isNaN( n ) ? null : n;
	};

	return {
		containerId: generatedId,
		syncId: data.syncId || generatedId,
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
		chronologicalRouteMode: data.chronologicalRouteMode === '1',
		scopeToken: data.scopeToken || '',
	};
}

/**
 * Mount the React map into its root container exactly once.
 *
 * Idempotent via the `initialized` dataset flag so the deferred-expand path
 * and the normal path can both call it safely.
 *
 * @param container Map root container.
 */
function mountMap( container: HTMLElement ): void {
	if ( container.dataset.initialized === '1' ) {
		return;
	}
	container.dataset.initialized = '1';

	const props = parseMapProps( container );
	const root = createRoot( container );
	root.render( <EventsMap { ...props } /> );
}

/**
 * Wire the collapsible toggle for a map root, when the block opted in
 * (`data-collapsible="1"` from render.php). Generic collapse/expand behavior:
 *
 * - The toggle is a real server-rendered <button> (accessible, keyboard
 *   operable, aria-expanded reflecting state) found via `data-toggle-id`.
 * - Leaflet must not initialize inside a zero-height (hidden) container or it
 *   renders gray tiles. So when the region starts collapsed we DEFER the React
 *   mount until the first expand; otherwise we mount immediately and only
 *   invalidate size on subsequent expands.
 *
 * Returns true when the map's mount is being managed here (collapsed defer),
 * so the caller skips its own immediate mount.
 *
 * @param container Map root container.
 * @return Whether the immediate mount should be skipped (deferred to expand).
 */
function setupCollapsible( container: HTMLElement ): boolean {
	if ( container.dataset.collapsible !== '1' ) {
		return false;
	}
	if ( container.dataset.collapsibleBound === '1' ) {
		// Already wired; report current defer state.
		return container.dataset.initialized !== '1';
	}
	container.dataset.collapsibleBound = '1';

	const toggleId = container.dataset.toggleId || '';
	const regionId = container.dataset.regionId || '';
	const toggle = toggleId ? document.getElementById( toggleId ) : null;
	const region = regionId ? document.getElementById( regionId ) : null;
	const wrapper = container.closest(
		'.data-machine-events-map-collapsible'
	) as HTMLElement | null;

	// If the expected markup is missing, fall back to non-collapsible
	// behavior so the map still renders.
	if ( ! toggle || ! region ) {
		return false;
	}

	const startCollapsed = container.dataset.defaultCollapsed === '1';

	const setExpanded = ( expanded: boolean ) => {
		toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		if ( wrapper ) {
			wrapper.classList.toggle( 'is-collapsed', ! expanded );
		}
		if ( expanded ) {
			region.removeAttribute( 'hidden' );
		} else {
			region.setAttribute( 'hidden', '' );
		}

		const showLabel = toggle.dataset.labelShow;
		const hideLabel = toggle.dataset.labelHide;
		const label = expanded ? hideLabel : showLabel;
		if ( label ) {
			toggle.textContent = label;
		}

		if ( expanded ) {
			// Mount on first expand (deferred init), else just re-measure.
			if ( container.dataset.initialized !== '1' ) {
				mountMap( container );
			} else {
				container.dispatchEvent(
					new CustomEvent( 'data-machine-map-invalidate-size' )
				);
			}
		}
	};

	toggle.addEventListener( 'click', () => {
		const expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
		setExpanded( ! expanded );
	} );

	// When it starts collapsed, defer the React mount until first expand so
	// Leaflet never initializes in a zero-height container.
	return startCollapsed;
}

function initEventsMap(): void {
	const containers = document.querySelectorAll< HTMLElement >(
		'.data-machine-events-map-root'
	);

	containers.forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) {
			return;
		}

		const deferMount = setupCollapsible( container );
		if ( deferMount ) {
			return;
		}

		mountMap( container );
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
