/**
 * Data Machine Events Calendar Frontend JavaScript
 *
 * Progressive enhancement for calendar blocks with REST API filtering.
 */

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

(function() {
    'use strict';

    // Global display renderers for each calendar instance
    const displayRenderers = new Map();
    const datePickers = new Map();

    document.addEventListener('DOMContentLoaded', function() {
        initializeCalendarFilters();
        initializeDisplayRenderers();
    });

    /**
     * Initialize all calendar instances with REST API filtering
     */
    function initializeCalendarFilters() {
        const calendars = document.querySelectorAll('.datamachine-events-calendar');

        calendars.forEach(function(calendar) {
            const searchInput = calendar.querySelector('#datamachine-events-search');
            const dateRangeInput = calendar.querySelector('#datamachine-events-date-range');
            const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn');

            // Search input - debounced REST API call
            // 500ms debounce balances responsiveness with server load
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        applyFilters(calendar);
                    }, 500); // 500ms delay prevents excessive API requests while typing
                });

                const searchBtn = calendar.querySelector('.datamachine-events-search-btn');
                if (searchBtn) {
                    searchBtn.addEventListener('click', function() {
                        searchInput.value = '';
                        applyFilters(calendar);
                        searchInput.focus();
                    });
                }
            }

            // Date range picker
            if (dateRangeInput) {
                const clearBtn = calendar.querySelector('.datamachine-events-date-clear-btn');

                const datePicker = flatpickr(dateRangeInput, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    placeholder: 'Select date range...',
                    allowInput: false,
                    clickOpens: true,
                    onChange: function(selectedDates, dateStr, instance) {
                        applyFilters(calendar);

                        if (selectedDates && selectedDates.length > 0) {
                            clearBtn.classList.add('visible');
                        } else {
                            clearBtn.classList.remove('visible');
                        }
                    },
                    onClear: function() {
                        applyFilters(calendar);
                        clearBtn.classList.remove('visible');
                    }
                });

                datePickers.set(calendar, datePicker);

                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        datePicker.clear();
                    });
                }
            }

            // Taxonomy filter modal
            if (filterBtn) {
                initializeFilterModal(calendar);
            }
        });
    }

    /**
     * Initialize taxonomy filter modal
     */
    function initializeFilterModal(calendar) {
        const modal = calendar.querySelector('#datamachine-taxonomy-filter-modal');
        if (!modal) return;

        const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn');
        const closeBtn = modal.querySelector('.datamachine-modal-close');
        const applyBtn = modal.querySelector('.datamachine-apply-filters');
        const resetBtn = modal.querySelector('.datamachine-reset-filters');

        // Open modal
        filterBtn.addEventListener('click', function() {
            modal.classList.add('visible');
            document.body.classList.add('datamachine-modal-open');
        });

        // Close modal
        closeBtn.addEventListener('click', function() {
            modal.classList.remove('visible');
            document.body.classList.remove('datamachine-modal-open');
        });

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('visible');
                document.body.classList.remove('datamachine-modal-open');
            }
        });

        // Apply filters button - calls REST API
        applyBtn.addEventListener('click', function() {
            applyFilters(calendar);
            modal.classList.remove('visible');
            document.body.classList.remove('datamachine-modal-open');
        });

        // Reset filters button
        resetBtn.addEventListener('click', function() {
            // Clear all checkboxes
            const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);

            // Update filter count
            updateFilterCount(calendar);

            // Apply filters (will clear taxonomy filters)
            applyFilters(calendar);

            modal.classList.remove('visible');
            document.body.classList.remove('datamachine-modal-open');
        });

        // Update filter count on checkbox change
        const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateFilterCount(calendar);
            });
        });
    }

    /**
     * Update filter count badge
     */
    function updateFilterCount(calendar) {
        const modal = calendar.querySelector('#datamachine-taxonomy-filter-modal');
        if (!modal) return;

        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn');
        const countBadge = filterBtn.querySelector('.datamachine-filter-count');

        if (checkboxes.length > 0) {
            countBadge.textContent = checkboxes.length;
            countBadge.classList.add('visible');
        } else {
            countBadge.classList.remove('visible');
        }
    }

    /**
     * Build query parameters from current filter state
     */
    function buildQueryParams(calendar) {
        const params = new URLSearchParams(window.location.search);

        // Remove pagination (always start at page 1 when filtering)
        params.delete('paged');

        // Search query
        const searchInput = calendar.querySelector('#datamachine-events-search');
        if (searchInput && searchInput.value) {
            params.set('event_search', searchInput.value);
        } else {
            params.delete('event_search');
        }

        // Date range
        const dateRangeInput = calendar.querySelector('#datamachine-events-date-range');
        if (dateRangeInput) {
            const datePicker = datePickers.get(calendar);
            if (datePicker && datePicker.selectedDates.length > 0) {
                const startDate = datePicker.selectedDates[0];
                const endDate = datePicker.selectedDates[1] || startDate;

                params.set('date_start', formatDate(startDate));
                params.set('date_end', formatDate(endDate));
            } else {
                params.delete('date_start');
                params.delete('date_end');
            }
        }

        // Taxonomy filters
        params.delete('tax_filter');
        const modal = calendar.querySelector('#datamachine-taxonomy-filter-modal');
        if (modal) {
            const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
            const taxFilters = {};

            checkboxes.forEach(function(checkbox) {
                const taxonomy = checkbox.getAttribute('data-taxonomy');
                const termId = checkbox.value;

                if (!taxFilters[taxonomy]) {
                    taxFilters[taxonomy] = [];
                }
                taxFilters[taxonomy].push(termId);
            });

            // Add taxonomy filters to params
            Object.keys(taxFilters).forEach(function(taxonomy) {
                taxFilters[taxonomy].forEach(function(termId) {
                    params.append(`tax_filter[${taxonomy}][]`, termId);
                });
            });
        }

        return params;
    }

    /**
     * Format date for URL parameters (YYYY-MM-DD)
     */
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /**
     * Apply filters by calling REST API
     */
    async function applyFilters(calendar) {
        const params = buildQueryParams(calendar);
        const content = calendar.querySelector('.datamachine-events-content');

        // Show loading state
        content.classList.add('loading');

        try {
            // Build REST API URL
            const apiUrl = `/wp-json/datamachine-events/v1/calendar?${params.toString()}`;

            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await response.json();

            if (data.success) {
                // Update calendar content
                content.innerHTML = data.html;

                // Update pagination
                const paginationContainer = calendar.querySelector('.datamachine-events-pagination');
                if (paginationContainer && data.pagination.html) {
                    paginationContainer.outerHTML = data.pagination.html;
                }

                // Update results counter
                const counterContainer = calendar.querySelector('.datamachine-events-results-counter');
                if (counterContainer && data.counter) {
                    counterContainer.outerHTML = data.counter;
                }

                // Update navigation
                const navigationContainer = calendar.querySelector('.datamachine-events-past-navigation');
                if (navigationContainer && data.navigation.html) {
                    navigationContainer.outerHTML = data.navigation.html;
                }

                // Update URL with History API for shareable filter states
                // pushState enables back/forward browser navigation without page reload
                const newUrl = `${window.location.pathname}?${params.toString()}`;
                window.history.pushState({}, '', newUrl);

                // Refresh display renderers
                refreshDisplayRenderers();
            }

        } catch (error) {
            console.error('Error fetching filtered events:', error);
            // Show error message
            content.innerHTML = '<div class="datamachine-events-error"><p>Error loading events. Please try again.</p></div>';
        } finally {
            content.classList.remove('loading');
        }
    }

    /**
     * Reliably detect carousel list mode by checking actual CSS styles
     *
     * Carousel detection requires checking computed CSS properties rather than
     * class names because CSS is loaded dynamically based on display settings.
     * Circuit Grid uses vertical flex layout, Carousel List uses horizontal scrolling.
     *
     * @param {HTMLElement} calendar - Calendar container element
     * @returns {boolean} True if carousel list mode is active
     */
    function isCarouselListMode(calendar) {
        // Check if date groups have horizontal flex layout (carousel characteristic)
        const dateGroup = calendar.querySelector('.datamachine-date-group');
        if (!dateGroup) return false;

        const computedStyle = window.getComputedStyle(dateGroup);
        const isHorizontalFlex = computedStyle.flexDirection === 'row';
        const hasOverflowScroll = computedStyle.overflowX === 'scroll' || computedStyle.overflowX === 'auto';

        // Additional check: carousel list CSS loaded
        const carouselListCSS = document.querySelector('link[href*="carousel-list.css"]');

        return isHorizontalFlex && hasOverflowScroll && carouselListCSS;
    }

    /**
     * Initialize display renderers based on display type
     */
    function initializeDisplayRenderers() {
        const calendars = document.querySelectorAll('.datamachine-events-calendar.datamachine-events-date-grouped');

        calendars.forEach(function(calendar) {
            try {
                // Check for explicit carousel list mode first (most reliable)
                if (isCarouselListMode(calendar)) {
                    console.log('Carousel List mode detected - no JavaScript renderer needed');
                    return; // Exit early - carousel list is CSS-only
                }

                // Only initialize Circuit Grid if explicitly not in carousel mode
                const circuitGridCSS = document.querySelector('link[href*="circuit-grid.css"]');
                if (circuitGridCSS) {
                    console.log('Circuit Grid mode detected - initializing renderer');
                    initializeCircuitGrid(calendar);
                } else {
                    console.log('No display renderer needed');
                }

            } catch (error) {
                console.error('Failed to initialize display renderer:', error);
            }
        });
    }

    /**
     * Initialize Circuit Grid renderer for calendar
     */
    async function initializeCircuitGrid(calendar) {
        try {
            // Dynamically import Circuit Grid Renderer
            const { CircuitGridRenderer } = await import('../DisplayStyles/CircuitGrid/CircuitGridRenderer.js');

            console.log('Initializing Circuit Grid renderer for calendar:', calendar);

            const circuitGridRenderer = new CircuitGridRenderer(calendar);
            displayRenderers.set(calendar, circuitGridRenderer);

            console.log('Circuit Grid renderer initialized successfully');

        } catch (error) {
            console.error('Failed to load Circuit Grid Renderer:', error);
        }
    }


    /**
     * Refresh display renderers for all calendars (called after filtering)
     */
    function refreshDisplayRenderers() {
        displayRenderers.forEach((displayRenderer, calendar) => {
            displayRenderer.refresh();
        });
    }


    /**
     * Cleanup renderers when page unloads
     */
    window.addEventListener('beforeunload', function() {
        displayRenderers.forEach(displayRenderer => {
            displayRenderer.cleanup();
        });
        displayRenderers.clear();

        datePickers.forEach(datePicker => {
            datePicker.destroy();
        });
        datePickers.clear();
    });

})();
