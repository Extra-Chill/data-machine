/**
 * Leaflet runtime for the Event Details venue map.
 *
 * The legacy venue map initializer consumes the global browser API, while
 * webpack keeps the dependency pinned and served from the plugin build.
 */

/**
 * External dependencies
 */
import L from 'leaflet'; // eslint-disable-line import/no-unresolved -- Installed by this block package before webpack builds.
import 'leaflet/dist/leaflet.css'; // eslint-disable-line import/no-unresolved -- Installed by this block package before webpack builds.

window.L = L;
