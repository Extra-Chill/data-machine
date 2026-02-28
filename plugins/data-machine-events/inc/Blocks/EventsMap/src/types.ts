/**
 * Events Map Block Type Definitions
 *
 * Shared interfaces for the events-map block editor and frontend.
 *
 * @package DataMachineEvents
 * @since 0.5.0
 */

/**
 * Venue data returned from the REST API.
 */
export interface Venue {
	term_id: number;
	name: string;
	slug: string;
	lat: number;
	lon: number;
	address: string;
	url: string;
	event_count: number;
	distance?: number;
}

/**
 * Response shape from GET /datamachine/v1/events/venues.
 */
export interface VenueListResponse {
	venues: Venue[];
	total: number;
	center: { lat: number; lng: number } | null;
	radius: number;
}

/**
 * Map tile provider configuration.
 */
export type MapType =
	| 'osm-standard'
	| 'carto-positron'
	| 'carto-voyager'
	| 'carto-dark'
	| 'humanitarian';

/**
 * Block attributes stored in block.json.
 */
export interface MapAttributes {
	height: number;
	zoom: number;
	mapType: MapType;
}

/**
 * Props for the frontend map container, read from data attributes on the root div.
 */
export interface MapProps {
	containerId: string;
	height: number;
	zoom: number;
	mapType: MapType;
	centerLat: number | null;
	centerLon: number | null;
	userLat: number | null;
	userLon: number | null;
	venues: Venue[];
	taxonomy: string;
	termId: number;
	restUrl: string;
	nonce: string;
}

/**
 * Viewport bounds for the map, used to fetch venues within view.
 */
export interface MapBounds {
	swLat: number;
	swLng: number;
	neLat: number;
	neLng: number;
}

/**
 * Custom event dispatched when map bounds change.
 */
export interface BoundsChangedEvent {
	bounds: MapBounds;
	zoom: number;
	center: { lat: number; lng: number };
}

/**
 * Tile URL templates keyed by MapType.
 */
export const TILE_URLS: Record<MapType, string> = {
	'osm-standard': 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
	'carto-positron': 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
	'carto-voyager': 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
	'carto-dark': 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
	'humanitarian': 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
};
