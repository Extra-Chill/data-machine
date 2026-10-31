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
     * @param {Event} e Change event.
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
     * @param {boolean} show Whether to show the field.
     */
    function toggleVenueNameField(show) {
        const venueNameField = document.querySelector('[name="venue_name"]');
        if (venueNameField) {
            const fieldContainer = venueNameField.closest('.data-machine-field-wrapper, tr, .form-field');
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
     * @param {string} termId Venue term ID
     */
    function loadVenueData(termId) {
        const config = window.dmEventsVenue;
        if (!termId || !config) {
            return;
        }

        // Show loading state
        const loadingIndicator = showLoadingState();

        // Make REST API request
        fetch(config.restUrl + '/events/venues/' + termId, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': config.nonce
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
                showErrorState('Failed to load venue data. Please try again.');
            }
        })
        .catch(function() {
            hideLoadingState(loadingIndicator);
            showErrorState('Error loading venue data. Please check your connection and try again.');
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
     * Show an inline venue loading error.
     *
     * @param {string} message Error message.
     */
    function showErrorState(message) {
        const previousNotice = document.querySelector('.data-machine-events-venue-error');
        if (previousNotice) {
            previousNotice.remove();
        }

        const notice = document.createElement('div');
        notice.className = 'notice notice-error inline data-machine-events-venue-error';
        const text = document.createElement('p');
        text.textContent = message;
        notice.appendChild(text);

        if (venueSelector && venueSelector.parentNode) {
            venueSelector.parentNode.insertBefore(notice, venueSelector.nextSibling);
        }
    }

    /**
     * Get changed fields by comparing current values with originals
     *
     * @return {Object<string, string>} Object containing only changed fields
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
     * @return {Promise<boolean>} Whether venue creation can proceed.
     */
    function checkDuplicateVenue(venueName, venueAddress) {
        const config = window.dmEventsVenue;
        if (!venueName || !config) {
            return Promise.resolve(true);
        }

        // Build query params
        const params = new URLSearchParams({
            name: venueName,
            address: venueAddress || ''
        });

        return fetch(config.restUrl + '/events/venues/check-duplicate?' + params.toString(), {
            method: 'GET',
            headers: {
                'X-WP-Nonce': config.nonce
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.data && data.data.is_duplicate) {
                const message = data.data.message ||
                    'A venue with this name and address already exists. Create duplicate anyway?';

                return confirmDuplicate(message);
            }

            return true;
        })
        .catch(function() {
            // On error, allow creation (fail open)
            return true;
        });
    }

    /**
     * Ask whether a duplicate venue should be created.
     *
     * @param {string} message Confirmation message.
     * @return {Promise<boolean>} Whether duplicate creation was confirmed.
     */
    function confirmDuplicate(message) {
        return new Promise(function(resolve) {
            const dialog = document.createElement('dialog');
            dialog.className = 'data-machine-events-confirm';

            const text = document.createElement('p');
            text.textContent = message;
            dialog.appendChild(text);

            const actions = document.createElement('div');
            actions.className = 'data-machine-events-confirm-actions';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'button';
            cancelButton.textContent = 'Cancel';

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'button button-primary';
            confirmButton.textContent = 'Create Duplicate';

            actions.appendChild(cancelButton);
            actions.appendChild(confirmButton);
            dialog.appendChild(actions);
            document.body.appendChild(dialog);

            function finish(confirmed) {
                dialog.close();
                dialog.remove();
                resolve(confirmed);
            }

            cancelButton.addEventListener('click', function() {
                finish(false);
            });
            confirmButton.addEventListener('click', function() {
                finish(true);
            });
            dialog.addEventListener('cancel', function(event) {
                event.preventDefault();
                finish(false);
            });

            dialog.showModal();
            confirmButton.focus();
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-initialize when modal content is loaded (for Data Machine modals)
    document.addEventListener('data-machine-core-modal-content-loaded', init);

    // Expose functions for potential external use
    window.dmEventsVenueSelector = {
        getChangedFields,
        checkDuplicateVenue
    };

})();
