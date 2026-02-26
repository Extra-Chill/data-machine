/**
 * Location input module for the Calendar block.
 *
 * Handles:
 * - "Near Me" browser geolocation
 * - Address input with Nominatim autocomplete (debounced)
 * - Radius selector
 * - Persistence via filter-state's geo localStorage
 *
 * On any location change, triggers the provided onChange callback
 * which causes a full-page navigation (same pattern as other filters).
 */

import { getFilterState } from './filter-state.js';

/**
 * Nominatim search endpoint (public, rate-limited to 1 req/sec)
 */
const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const DEBOUNCE_MS = 400;
const MIN_QUERY_LENGTH = 3;

/**
 * Initialize location input for a calendar instance
 * @param {HTMLElement} calendar - The calendar wrapper element
 * @param {Function} onChange - Callback when location changes (triggers navigation)
 */
export function initLocationInput(calendar, onChange) {
    const container = calendar.querySelector('.datamachine-events-location-input');
    if (!container) {return;}

    if (container.dataset.dmLocationInit === 'true') {return;}
    container.dataset.dmLocationInit = 'true';

    const filterState = getFilterState(calendar);
    const searchInput = container.querySelector('.datamachine-events-location-search');
    const nearMeBtn = container.querySelector('.datamachine-events-nearme-btn');
    const clearBtn = container.querySelector('.datamachine-events-location-clear-btn');
    const autocompleteEl = container.querySelector('.datamachine-events-location-autocomplete');
    const radiusSelect = container.querySelector('.datamachine-events-radius-select');

    if (!searchInput) {return;}

    // Restore label from localStorage if geo is stored but URL doesn't have lat/lng
    const storedGeo = filterState.getStoredGeo();
    if (storedGeo.label && !searchInput.value) {
        searchInput.value = storedGeo.label;
    }

    // Debounced autocomplete
    let debounceTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = searchInput.value.trim();

        if (query.length < MIN_QUERY_LENGTH) {
            hideAutocomplete(autocompleteEl);
            return;
        }

        debounceTimer = setTimeout(async () => {
            const results = await searchNominatim(query);
            renderAutocomplete(autocompleteEl, results, (result) => {
                applyLocation(searchInput, filterState, result.lat, result.lon, result.display_name, radiusSelect, onChange);
                hideAutocomplete(autocompleteEl);
            });
        }, DEBOUNCE_MS);
    });

    // Near Me button
    if (nearMeBtn) {
        nearMeBtn.addEventListener('click', function() {
            if (!navigator.geolocation) {
                nearMeBtn.textContent = 'Not supported';
                return;
            }

            nearMeBtn.disabled = true;
            nearMeBtn.classList.add('datamachine-nearme-loading');

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    nearMeBtn.disabled = false;
                    nearMeBtn.classList.remove('datamachine-nearme-loading');

                    const lat = position.coords.latitude.toFixed(6);
                    const lng = position.coords.longitude.toFixed(6);

                    applyLocation(searchInput, filterState, lat, lng, 'Near Me', radiusSelect, onChange);
                },
                (error) => {
                    nearMeBtn.disabled = false;
                    nearMeBtn.classList.remove('datamachine-nearme-loading');
                    console.warn('Geolocation error:', error.message);
                },
                { enableHighAccuracy: false, timeout: 10000 }
            );
        });
    }

    // Clear button
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchInput.dataset.geoLat = '';
            searchInput.dataset.geoLng = '';
            filterState.clearGeoStorage();
            onChange();
        });
    }

    // Radius change triggers navigation
    if (radiusSelect) {
        radiusSelect.addEventListener('change', function() {
            // Only trigger if we have an active geo location
            if (searchInput.dataset.geoLat && searchInput.dataset.geoLng) {
                // Update stored geo with new radius
                filterState.saveGeoToStorage({
                    lat: searchInput.dataset.geoLat,
                    lng: searchInput.dataset.geoLng,
                    radius: parseInt(radiusSelect.value, 10),
                    radius_unit: radiusSelect.dataset.radiusUnit || 'mi',
                    label: searchInput.value
                });
                onChange();
            }
        });
    }

    // Close autocomplete on outside click
    document.addEventListener('click', function(e) {
        if (!container.contains(e.target)) {
            hideAutocomplete(autocompleteEl);
        }
    });

    // Close autocomplete on Escape
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideAutocomplete(autocompleteEl);
        }
    });
}

/**
 * Destroy location input listeners
 * @param {HTMLElement} calendar
 */
export function destroyLocationInput(calendar) {
    const container = calendar.querySelector('.datamachine-events-location-input');
    if (container) {
        container.dataset.dmLocationInit = 'false';
    }
}

/**
 * Apply a location selection: update input, save to storage, trigger navigation
 */
function applyLocation(searchInput, filterState, lat, lng, label, radiusSelect, onChange) {
    searchInput.value = label;
    searchInput.dataset.geoLat = lat;
    searchInput.dataset.geoLng = lng;

    const radius = radiusSelect ? parseInt(radiusSelect.value, 10) : 25;
    const radiusUnit = radiusSelect?.dataset.radiusUnit || 'mi';

    filterState.saveGeoToStorage({
        lat: lat,
        lng: lng,
        radius: radius,
        radius_unit: radiusUnit,
        label: label
    });

    onChange();
}

/**
 * Search Nominatim for address autocomplete
 * @param {string} query
 * @return {Promise<Array>}
 */
async function searchNominatim(query) {
    try {
        const params = new URLSearchParams({
            q: query,
            format: 'json',
            addressdetails: '1',
            limit: '5',
            countrycodes: 'us'
        });

        const response = await fetch(`${NOMINATIM_URL}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {return [];}

        return await response.json();
    } catch (e) {
        console.warn('Nominatim search failed:', e);
        return [];
    }
}

/**
 * Render autocomplete dropdown
 * @param {HTMLElement} autocompleteEl
 * @param {Array} results
 * @param {Function} onSelect
 */
function renderAutocomplete(autocompleteEl, results, onSelect) {
    if (!autocompleteEl) {return;}

    if (!results.length) {
        hideAutocomplete(autocompleteEl);
        return;
    }

    autocompleteEl.innerHTML = '';
    autocompleteEl.hidden = false;

    results.forEach(result => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'datamachine-events-location-autocomplete-item';
        item.textContent = result.display_name;
        item.addEventListener('click', () => onSelect(result));
        autocompleteEl.appendChild(item);
    });
}

/**
 * Hide autocomplete dropdown
 * @param {HTMLElement} autocompleteEl
 */
function hideAutocomplete(autocompleteEl) {
    if (autocompleteEl) {
        autocompleteEl.hidden = true;
        autocompleteEl.innerHTML = '';
    }
}
