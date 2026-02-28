/**
 * Venue Address Autocomplete with Nominatim
 *
 * Provides address autocomplete functionality using OpenStreetMap's Nominatim API.
 * Complies with Nominatim usage policy: 1 request per second maximum.
 *
 * @package
 * @since 1.0.0
 */

(function() {
    'use strict';

    // Nominatim API configuration
    const NOMINATIM_API = 'https://nominatim.openstreetmap.org/search';
    const USER_AGENT = 'ExtraChill-Events/1.0 (https://extrachill.com)';
    const DEBOUNCE_DELAY = 1000; // 1 second (Nominatim usage policy)
    const CACHE_KEY = 'data_machine_events_venue_autocomplete_cache';
    const CACHE_EXPIRY = 3600000; // 1 hour in milliseconds

    let debounceTimer = null;
    let lastRequestTime = 0;
    let currentDropdown = null;
    let selectedIndex = -1;

    /**
     * Initialize autocomplete on all venue address fields
     */
    function init() {
        const addressFields = document.querySelectorAll('.venue-address-autocomplete');

        if (addressFields.length === 0) {
            return;
        }

        addressFields.forEach(field => {
            setupAutocomplete(field);
        });

        // Add attribution notice to modal footer
        addAttributionNotice();

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            if (currentDropdown && !e.target.closest('.venue-autocomplete-container')) {
                closeDropdown();
            }
        });
    }

    /**
     * Setup autocomplete for a single field
     * @param field
     */
    function setupAutocomplete(field) {
        // Wrap field in container
        const container = document.createElement('div');
        container.className = 'venue-autocomplete-container';
        field.parentNode.insertBefore(container, field);
        container.appendChild(field);

        // Create dropdown element
        const dropdown = document.createElement('div');
        dropdown.className = 'venue-autocomplete-dropdown';
        dropdown.style.display = 'none';
        container.appendChild(dropdown);

        // Store references
        field.autocompleteDropdown = dropdown;
        field.autocompleteContainer = container;

        // Get dependent field names from data attributes
        // Note: coords no longer used - geocoding handled on backend
        field.dependentFields = {
            city: field.getAttribute('data-city-field'),
            state: field.getAttribute('data-state-field'),
            zip: field.getAttribute('data-zip-field'),
            country: field.getAttribute('data-country-field')
        };

        // Add event listeners
        field.addEventListener('input', handleInput);
        field.addEventListener('keydown', handleKeydown);
    }

    /**
     * Handle input event with debouncing
     * @param e
     */
    function handleInput(e) {
        const field = e.target;
        const query = field.value.trim();

        // Clear existing timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Close dropdown if query is too short
        if (query.length < 3) {
            closeDropdown();
            return;
        }

        // Show loading state
        showLoading(field);

        // Debounce the API call
        debounceTimer = setTimeout(() => {
            searchAddress(field, query);
        }, DEBOUNCE_DELAY);
    }

    /**
     * Handle keyboard navigation
     * @param e
     */
    function handleKeydown(e) {
        const field = e.target;
        const dropdown = field.autocompleteDropdown;

        if (!dropdown || dropdown.style.display === 'none') {
            return;
        }

        const items = dropdown.querySelectorAll('.venue-autocomplete-item');

        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                updateSelection(items);
                break;

            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(items);
                break;

            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && items[selectedIndex]) {
                    items[selectedIndex].click();
                }
                break;

            case 'Escape':
                e.preventDefault();
                closeDropdown();
                break;
        }
    }

    /**
     * Update visual selection in dropdown
     * @param items
     */
    function updateSelection(items) {
        items.forEach((item, index) => {
            if (index === selectedIndex) {
                item.classList.add('selected');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('selected');
            }
        });
    }

    /**
     * Search address using Nominatim API
     * @param field
     * @param query
     */
    function searchAddress(field, query) {
        // Check cache first
        const cachedResults = getCachedResults(query);
        if (cachedResults) {
            displayResults(field, cachedResults);
            return;
        }

        // Enforce rate limiting (1 request per second)
        const now = Date.now();
        const timeSinceLastRequest = now - lastRequestTime;

        if (timeSinceLastRequest < 1000) {
            // Wait for remaining time
            setTimeout(() => {
                searchAddress(field, query);
            }, 1000 - timeSinceLastRequest);
            return;
        }

        lastRequestTime = now;

        // Build API URL
        const params = new URLSearchParams({
            format: 'json',
            addressdetails: '1',
            limit: '5',
            q: query
        });

        const url = `${NOMINATIM_API}?${params.toString()}`;

        // Make API request
        fetch(url, {
            headers: {
                'User-Agent': USER_AGENT
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Nominatim API request failed');
            }
            return response.json();
        })
        .then(data => {
            cacheResults(query, data);
            displayResults(field, data);
        })
        .catch(error => {
            console.error('Venue autocomplete error:', error);
            showError(field, 'Failed to load address suggestions. Please try again.');
        });
    }

    /**
     * Display search results in dropdown
     * @param field
     * @param results
     */
    function displayResults(field, results) {
        const dropdown = field.autocompleteDropdown;
        dropdown.innerHTML = '';
        selectedIndex = -1;

        if (!results || results.length === 0) {
            showError(field, 'No addresses found. Try a different search.');
            return;
        }

        results.forEach((place, index) => {
            const item = document.createElement('div');
            item.className = 'venue-autocomplete-item';
            item.innerHTML = `
                <div class="venue-autocomplete-address">${escapeHtml(place.display_name)}</div>
            `;

            item.addEventListener('click', () => {
                selectPlace(field, place);
            });

            dropdown.appendChild(item);
        });

        dropdown.style.display = 'block';
        currentDropdown = dropdown;
    }

    /**
     * Select a place and populate dependent fields
     * @param field
     * @param place
     */
    function selectPlace(field, place) {
        const address = place.address || {};

        // Set the address field value
        const streetAddress = buildStreetAddress(address);
        field.value = streetAddress;

        // Get dependent fields
        const deps = field.dependentFields;

        // Populate city
        if (deps.city) {
            const cityValue = address.city || address.town || address.village || address.municipality || '';
            setFieldValue(deps.city, cityValue);
        }

        // Populate state
        if (deps.state) {
            const stateValue = address.state || address.region || '';
            setFieldValue(deps.state, stateValue);
        }

        // Populate zip code
        if (deps.zip) {
            const zipValue = address.postcode || '';
            setFieldValue(deps.zip, zipValue);
        }

        // Populate country
        if (deps.country) {
            const countryValue = address.country_code ? address.country_code.toUpperCase() : '';
            setFieldValue(deps.country, countryValue);
        }

        // Note: Coordinates are handled via backend geocoding when venue is saved

        closeDropdown();
    }

    /**
     * Build street address from Nominatim address components
     * @param address
     */
    function buildStreetAddress(address) {
        const components = [];

        if (address.house_number) {
            components.push(address.house_number);
        }

        if (address.road) {
            components.push(address.road);
        } else if (address.street) {
            components.push(address.street);
        }

        return components.join(' ');
    }

    /**
     * Set value of a dependent field
     * @param fieldName
     * @param value
     */
    function setFieldValue(fieldName, value) {
        // Try multiple selector strategies to find the field
        let targetField = document.getElementById(fieldName);

        if (!targetField) {
            targetField = document.querySelector(`input[name="${fieldName}"]`);
        }

        if (!targetField) {
            targetField = document.querySelector(`input[name$="[${fieldName}]"]`);
        }

        if (targetField) {
            targetField.value = value;
            // Trigger change event for any listeners
            targetField.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /**
     * Show loading state
     * @param field
     */
    function showLoading(field) {
        const dropdown = field.autocompleteDropdown;
        dropdown.innerHTML = '<div class="venue-autocomplete-loading">Searching addresses...</div>';
        dropdown.style.display = 'block';
        currentDropdown = dropdown;
    }

    /**
     * Show error message
     * @param field
     * @param message
     */
    function showError(field, message) {
        const dropdown = field.autocompleteDropdown;
        dropdown.innerHTML = `<div class="venue-autocomplete-error">${escapeHtml(message)}</div>`;
        dropdown.style.display = 'block';
        currentDropdown = dropdown;
    }

    /**
     * Close dropdown
     */
    function closeDropdown() {
        if (currentDropdown) {
            currentDropdown.style.display = 'none';
            currentDropdown = null;
        }
        selectedIndex = -1;
    }

    /**
     * Cache results in sessionStorage
     * @param query
     * @param results
     */
    function cacheResults(query, results) {
        try {
            const cache = getCache();
            cache[query] = {
                results,
                timestamp: Date.now()
            };
            sessionStorage.setItem(CACHE_KEY, JSON.stringify(cache));
        } catch (e) {
            // SessionStorage might be full or unavailable
            console.warn('Failed to cache autocomplete results:', e);
        }
    }

    /**
     * Get cached results if available and not expired
     * @param query
     */
    function getCachedResults(query) {
        try {
            const cache = getCache();
            const cached = cache[query];

            if (cached && (Date.now() - cached.timestamp < CACHE_EXPIRY)) {
                return cached.results;
            }
        } catch (e) {
            console.warn('Failed to retrieve cached results:', e);
        }

        return null;
    }

    /**
     * Get cache object from sessionStorage
     */
    function getCache() {
        try {
            const cacheStr = sessionStorage.getItem(CACHE_KEY);
            return cacheStr ? JSON.parse(cacheStr) : {};
        } catch (e) {
            return {};
        }
    }

    /**
     * Add attribution notice to modal footer
     */
    function addAttributionNotice() {
        // Wait for modal to be present in DOM
        const checkModal = setInterval(() => {
            const settingsContainer = document.querySelector('.datamachine-settings-fields');

            if (settingsContainer) {
                clearInterval(checkModal);

                // Check if attribution already exists
                if (document.querySelector('.venue-autocomplete-attribution')) {
                    return;
                }

                const attribution = document.createElement('p');
                attribution.className = 'venue-autocomplete-attribution';
                attribution.style.cssText = 'margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #666;';
                attribution.innerHTML = 'Address autocomplete data © <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap contributors</a>';

                settingsContainer.appendChild(attribution);
            }
        }, 100);

        // Stop checking after 5 seconds
        setTimeout(() => clearInterval(checkModal), 5000);
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

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-initialize when modal opens (for dynamic content)
    document.addEventListener('datamachine-core-modal-content-loaded', init);

})();
