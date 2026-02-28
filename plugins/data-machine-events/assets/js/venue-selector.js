/**
 * Venue Selector for Data Machine Events
 *
 * Handles venue dropdown selection, REST API data loading, field population,
 * change tracking, and duplicate prevention for the Universal Web Scraper modal.
 *
 * @package
 * @since 1.0.0
 */

(function() {
    'use strict';

    // Store original venue values for change detection
    let originalValues = {};
    let venueSelector = null;
    let venueFields = [];

    /**
     * Initialize venue selector functionality
     */
    function init() {
        venueSelector = document.querySelector('[name="venue"]');
        if (!venueSelector) {
            return;
        }

        // Define all venue metadata fields (coordinates handled via backend geocoding)
        venueFields = [
            'venue_name',
            'venue_address',
            'venue_city',
            'venue_state',
            'venue_zip',
            'venue_country',
            'venue_phone',
            'venue_website',
            'venue_capacity'
        ];

        // Attach change event to venue dropdown
        venueSelector.addEventListener('change', handleVenueChange);

        // If a venue is already selected on load, populate its data
        if (venueSelector.value) {
            loadVenueData(venueSelector.value);
        }
    }

    /**
     * Handle venue dropdown change event
     * @param e
     */
    function handleVenueChange(e) {
        const termId = e.target.value;

        if (!termId || termId === '') {
            // "Create New Venue" selected - clear all fields
            clearVenueFields();
            toggleVenueNameField(true);
        } else {
            // Existing venue selected - load its data
            loadVenueData(termId);
            toggleVenueNameField(false);
        }
    }

    /**
     * Toggle venue_name field visibility
     * Show when creating new venue, hide when editing existing
     * @param show
     */
    function toggleVenueNameField(show) {
        const venueNameField = document.querySelector('[name="venue_name"]');
        if (venueNameField) {
            const fieldContainer = venueNameField.closest('.datamachine-field-wrapper, tr, .form-field');
            if (fieldContainer) {
                fieldContainer.style.display = show ? '' : 'none';
            }
        }
    }

    /**
     * Clear all venue metadata fields
     */
    function clearVenueFields() {
        venueFields.forEach(function(fieldName) {
            const field = document.querySelector('[name="' + fieldName + '"]');
            if (field) {
                field.value = '';
                delete field.dataset.originalValue;
            }
        });

        originalValues = {};
    }

    /**
     * Load venue data via REST API and populate fields
     *
     * @param {number} termId Venue term ID
     */
    function loadVenueData(termId) {
        if (!termId || typeof dmEventsVenue === 'undefined') {
            return;
        }

        // Show loading state
        const loadingIndicator = showLoadingState();

        // Make REST API request
        fetch(dmEventsVenue.restUrl + '/events/venues/' + termId, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': dmEventsVenue.nonce
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            hideLoadingState(loadingIndicator);

            if (data.success && data.data) {
                populateVenueFields(data.data);
            } else {
                console.error('DM Events: Failed to load venue data', data);
                alert('Failed to load venue data. Please try again.');
            }
        })
        .catch(function(error) {
            hideLoadingState(loadingIndicator);
            console.error('DM Events: Error loading venue data', error);
            alert('Error loading venue data. Please check your connection and try again.');
        });
    }

    /**
     * Populate venue fields with data and store original values
     *
     * @param {Object} venueData Venue data from REST API response
     */
    function populateVenueFields(venueData) {
        originalValues = {};

        // Map of field names to data keys (coordinates handled via backend geocoding)
        const fieldMapping = {
            'venue_name': 'name',
            'venue_address': 'address',
            'venue_city': 'city',
            'venue_state': 'state',
            'venue_zip': 'zip',
            'venue_country': 'country',
            'venue_phone': 'phone',
            'venue_website': 'website',
            'venue_capacity': 'capacity'
        };

        Object.keys(fieldMapping).forEach(function(fieldName) {
            const dataKey = fieldMapping[fieldName];
            const field = document.querySelector('[name="' + fieldName + '"]');

            if (field) {
                const value = venueData[dataKey] || '';
                field.value = value;

                // Store original value for change detection
                field.dataset.originalValue = value;
                originalValues[fieldName] = value;
            }
        });
    }

    /**
     * Show loading indicator
     *
     * @return {HTMLElement} Loading indicator element
     */
    function showLoadingState() {
        const indicator = document.createElement('div');
        indicator.className = 'data-machine-events-loading';
        indicator.innerHTML = '<span class="spinner is-active"></span> Loading venue data...';
        indicator.style.cssText = 'padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6; margin: 10px 0;';

        if (venueSelector && venueSelector.parentNode) {
            venueSelector.parentNode.insertBefore(indicator, venueSelector.nextSibling);
        }

        return indicator;
    }

    /**
     * Hide loading indicator
     *
     * @param {HTMLElement} indicator Loading indicator element
     */
    function hideLoadingState(indicator) {
        if (indicator && indicator.parentNode) {
            indicator.parentNode.removeChild(indicator);
        }
    }

    /**
     * Get changed fields by comparing current values with originals
     *
     * @return {Object} Object containing only changed fields
     */
    function getChangedFields() {
        const changes = {};

        venueFields.forEach(function(fieldName) {
            const field = document.querySelector('[name="' + fieldName + '"]');
            if (field && field.dataset.originalValue !== undefined) {
                const currentValue = field.value.trim();
                const originalValue = field.dataset.originalValue.trim();

                if (currentValue !== originalValue) {
                    changes[fieldName] = currentValue;
                }
            }
        });

        return changes;
    }

    /**
     * Check for duplicate venue before creating new one
     *
     * @param {string} venueName    Venue name
     * @param {string} venueAddress Venue address
     * @return {Promise} Promise resolving to true if can proceed, false if duplicate
     */
    function checkDuplicateVenue(venueName, venueAddress) {
        if (!venueName || typeof dmEventsVenue === 'undefined') {
            return Promise.resolve(true);
        }

        // Build query params
        const params = new URLSearchParams({
            name: venueName,
            address: venueAddress || ''
        });

        return fetch(dmEventsVenue.restUrl + '/events/venues/check-duplicate?' + params.toString(), {
            method: 'GET',
            headers: {
                'X-WP-Nonce': dmEventsVenue.nonce
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.data && data.data.is_duplicate) {
                const message = data.data.message ||
                    'A venue with this name and address already exists. Create duplicate anyway?';

                return confirm(message);
            }

            return true;
        })
        .catch(function(error) {
            console.error('DM Events: Error checking duplicate venue', error);
            // On error, allow creation (fail open)
            return true;
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-initialize when modal content is loaded (for Data Machine modals)
    document.addEventListener('datamachine-core-modal-content-loaded', init);

    // Expose functions for potential external use
    window.dmEventsVenueSelector = {
        getChangedFields,
        checkDuplicateVenue
    };

})();
