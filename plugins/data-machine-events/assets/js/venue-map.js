/**
 * Venue Map Display with Leaflet.js
 *
 * Initializes interactive OpenStreetMap displays for venue locations
 * in Event Details blocks. Uses 📍 emoji marker for consistency with
 * venue card icon.
 *
 * @package
 * @since 1.0.0
 */

(function() {
    'use strict';

    /**
     * Initialize all venue maps on the page
     */
    function initVenueMaps() {
        const mapContainers = document.querySelectorAll('.datamachine-venue-map');

        if (mapContainers.length === 0) {
            return;
        }

        // Check if Leaflet is loaded
        if (typeof L === 'undefined') {
            console.error('Leaflet library not loaded. Cannot initialize venue maps.');
            return;
        }

        mapContainers.forEach(container => {
            initSingleMap(container);
        });
    }

    /**
     * Initialize a single venue map
     * @param container
     */
    function initSingleMap(container) {
        // Get map data from attributes
        const lat = parseFloat(container.getAttribute('data-lat'));
        const lon = parseFloat(container.getAttribute('data-lon'));
        const venueName = container.getAttribute('data-venue-name') || 'Venue';
        const venueAddress = container.getAttribute('data-venue-address') || '';
        const mapType = container.getAttribute('data-map-type') || 'osm-standard';

        // Validate coordinates
        if (isNaN(lat) || isNaN(lon)) {
            console.warn('Invalid coordinates for venue map:', { lat, lon });
            container.innerHTML = '<p style="padding: 20px; text-align: center; color: #666;">Map unavailable (invalid coordinates)</p>';
            return;
        }

        // Check if already initialized
        if (container.classList.contains('map-initialized')) {
            return;
        }

        try {
            // Create the map
            const map = L.map(container.id).setView([lat, lon], 15);

            // Get tile layer configuration based on map type
            const tileConfigs = {
                'osm-standard': {
                    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                    attribution: 'OpenStreetMap'
                },
                'carto-positron': {
                    url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                    attribution: 'CartoDB'
                },
                'carto-voyager': {
                    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
                    attribution: 'CartoDB'
                },
                'carto-dark': {
                    url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                    attribution: 'CartoDB'
                },
                'humanitarian': {
                    url: 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
                    attribution: 'Humanitarian OpenStreetMap'
                }
            };

            const tileConfig = tileConfigs[mapType] || tileConfigs['osm-standard'];

            // Add tile layer with selected configuration
            L.tileLayer(tileConfig.url, {
                attribution: '', // Attribution handled in template
                maxZoom: 19,
                minZoom: 10
            }).addTo(map);

            // Create custom emoji marker icon
            const emojiIcon = L.divIcon({
                html: '<span style="font-size: 32px; line-height: 1; display: block;">📍</span>',
                className: 'emoji-marker',
                iconSize: [32, 32],
                iconAnchor: [16, 32], // Point of the icon which will correspond to marker's location
                popupAnchor: [0, -32] // Point from which the popup should open relative to the iconAnchor
            });

            // Add marker with emoji icon
            const marker = L.marker([lat, lon], { icon: emojiIcon }).addTo(map);

            // Create popup content
            let popupContent = `<div class="venue-popup"><strong>${escapeHtml(venueName)}</strong>`;
            if (venueAddress) {
                popupContent += `<br><small>${escapeHtml(venueAddress)}</small>`;
            }
            popupContent += '</div>';

            // Bind popup to marker
            marker.bindPopup(popupContent);

            // Mark as initialized
            container.classList.add('map-initialized');

            // Fix map sizing issues (common Leaflet problem)
            setTimeout(() => {
                map.invalidateSize();
            }, 100);

        } catch (error) {
            console.error('Error initializing venue map:', error);
            container.innerHTML = '<p style="padding: 20px; text-align: center; color: #666;">Map failed to load</p>';
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param text
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Re-initialize maps after dynamic content loads
     */
    function reinitMaps() {
        const uninitializedMaps = document.querySelectorAll('.datamachine-venue-map:not(.map-initialized)');
        if (uninitializedMaps.length > 0) {
            initVenueMaps();
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVenueMaps);
    } else {
        initVenueMaps();
    }

    // Re-initialize for dynamic content
    document.addEventListener('datamachine-events-loaded', reinitMaps);

    // Global function for manual initialization
    window.datamachineEventsInitMaps = initVenueMaps;

})();
