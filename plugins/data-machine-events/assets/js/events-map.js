/**
 * Events Map Block Frontend
 *
 * Initializes multi-marker Leaflet maps for the datamachine-events/events-map block.
 * Reads venue data from data attributes and renders an interactive map with
 * clickable venue markers.
 *
 * @package DataMachineEvents
 * @since 0.13.0
 */

(function() {
    'use strict';

    function initEventsMaps() {
        var containers = document.querySelectorAll('.datamachine-events-map');

        if (containers.length === 0 || typeof L === 'undefined') {
            return;
        }

        containers.forEach(function(container) {
            initMap(container);
        });
    }

    function initMap(container) {
        if (container.classList.contains('map-initialized')) {
            return;
        }

        var centerLat = parseFloat(container.getAttribute('data-center-lat'));
        var centerLon = parseFloat(container.getAttribute('data-center-lon'));
        var zoom = parseInt(container.getAttribute('data-zoom'), 10) || 12;
        var mapType = container.getAttribute('data-map-type') || 'osm-standard';
        var venuesJson = container.getAttribute('data-venues') || '[]';
        var hasCenter = !isNaN(centerLat) && !isNaN(centerLon);

        var venues = [];
        try {
            venues = JSON.parse(venuesJson);
        } catch (e) {
            venues = [];
        }

        if (venues.length === 0 && !hasCenter) {
            return;
        }

        try {
            // Set initial view — use center if provided, otherwise use first venue.
            var initialLat = hasCenter ? centerLat : venues[0].lat;
            var initialLon = hasCenter ? centerLon : venues[0].lon;

            var map = L.map(container.id, {
                scrollWheelZoom: false,
            }).setView([initialLat, initialLon], zoom);

            var tileConfigs = {
                'osm-standard': 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'carto-positron': 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                'carto-voyager': 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
                'carto-dark': 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
                'humanitarian': 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
            };

            L.tileLayer(tileConfigs[mapType] || tileConfigs['osm-standard'], {
                attribution: '',
                maxZoom: 18,
                minZoom: 8,
            }).addTo(map);

            var emojiIcon = L.divIcon({
                html: '<span style="font-size: 28px; line-height: 1; display: block;">📍</span>',
                className: 'emoji-marker',
                iconSize: [28, 28],
                iconAnchor: [14, 28],
                popupAnchor: [0, -28],
            });

            var markers = [];

            venues.forEach(function(venue) {
                if (!venue.lat || !venue.lon) {
                    return;
                }

                var marker = L.marker([venue.lat, venue.lon], { icon: emojiIcon }).addTo(map);

                var popup = '<div class="venue-popup">';
                if (venue.url) {
                    popup += '<strong><a href="' + escapeHtml(venue.url) + '">' + escapeHtml(venue.name) + '</a></strong>';
                } else {
                    popup += '<strong>' + escapeHtml(venue.name) + '</strong>';
                }
                if (venue.address) {
                    popup += '<br><small>' + escapeHtml(venue.address) + '</small>';
                }
                popup += '</div>';

                marker.bindPopup(popup);
                markers.push(marker);
            });

            // Auto-fit bounds to show all markers.
            if (markers.length > 1) {
                var group = L.featureGroup(markers);
                map.fitBounds(group.getBounds().pad(0.1));
            } else if (markers.length === 1 && !hasCenter) {
                map.setView([venues[0].lat, venues[0].lon], 13);
            }

            container.classList.add('map-initialized');

            setTimeout(function() {
                map.invalidateSize();
            }, 100);

        } catch (error) {
            console.error('Error initializing events map:', error);
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEventsMaps);
    } else {
        initEventsMaps();
    }

    // Re-initialize for dynamic content.
    document.addEventListener('datamachine-events-loaded', function() {
        var uninit = document.querySelectorAll('.datamachine-events-map:not(.map-initialized)');
        if (uninit.length > 0) {
            initEventsMaps();
        }
    });

})();
