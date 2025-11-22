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
            // Avoid double-initialization if already initialized for this DOM node
            if (calendar.dataset.dmFiltersInitialized === 'true') return;
            calendar.dataset.dmFiltersInitialized = 'true';
            let searchInput = calendar.querySelector('.datamachine-events-search-input');
            const searchId = calendar.querySelector('[id^="datamachine-events-search-"]');
            if (searchId) {
                searchInput = searchId;
            }

            let dateRangeInput = calendar.querySelector('.datamachine-events-date-range-input');
            const dateRangeId = calendar.querySelector('[id^="datamachine-events-date-range-"]');
            if (dateRangeId) {
                dateRangeInput = dateRangeId;
            }

            const filterBtn = calendar.querySelector('.datamachine-events-filter-btn, .datamachine-taxonomy-modal-trigger');
            // Read modal id for this calendar from the DOM (button attribute or matching modal)
            const btnModalId = filterBtn ? filterBtn.getAttribute('data-modal-id') : null;
            const modalIdFromDom = calendar.querySelector('.datamachine-taxonomy-modal') ? calendar.querySelector('.datamachine-taxonomy-modal').id : null;
            const modalIdResolved = btnModalId || modalIdFromDom;
            if (filterBtn) {
                // Toggle aria and controls for accessibility
                filterBtn.setAttribute('aria-controls', modalIdResolved || '');
                filterBtn.setAttribute('aria-expanded', 'false');
            }
            if (filterBtn) {
                // Toggle aria and controls for accessibility
                filterBtn.setAttribute('aria-controls', calendar.querySelector('.datamachine-taxonomy-modal') ? calendar.querySelector('.datamachine-taxonomy-modal').id : '');
                filterBtn.setAttribute('aria-expanded', 'false');
            }

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
                        // Trigger filter using current search value instead of clearing (UX expectation)
                        applyFilters(calendar);
                        searchInput.focus();
                    });
                }
            }

            // Date range picker
            if (dateRangeInput) {
                const clearBtn = calendar.querySelector('.datamachine-events-date-clear-btn');

                // Allow the server to inject the initial date start/end via data attributes
                const initialStart = dateRangeInput.getAttribute('data-date-start');
                const initialEnd = dateRangeInput.getAttribute('data-date-end');
                let defaultDate = undefined;
                if (initialStart) {
                    if (initialEnd) {
                        defaultDate = [initialStart, initialEnd];
                    } else {
                        defaultDate = initialStart;
                    }
                }

                const datePicker = flatpickr(dateRangeInput, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    placeholder: 'Select date range...',
                    allowInput: false,
                    clickOpens: true,
                    defaultDate: defaultDate,
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

                if (datePicker && datePicker.selectedDates && datePicker.selectedDates.length > 0) {
                    if (clearBtn) clearBtn.classList.add('visible');
                }

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
        const modal = calendar.querySelector('.datamachine-taxonomy-modal');
        if (!modal) return;

        // Accessibility: ensure modal container has dialog role and is labelled
        const modalContainer = modal.querySelector('.datamachine-taxonomy-modal-container');
        if (modalContainer) {
            modalContainer.setAttribute('role', 'dialog');
            modalContainer.setAttribute('aria-modal', 'true');
        }

        const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
        const closeBtn = modal.querySelector('.datamachine-modal-close, .datamachine-taxonomy-modal-close');
        const applyBtn = modal.querySelector('.datamachine-apply-filters');
        const resetBtn = modal.querySelector('.datamachine-clear-all-filters, .datamachine-reset-filters');

        // Open modal
        if (filterBtn) {
            filterBtn.addEventListener('click', function() {
                modal.classList.add('datamachine-modal-active');
                document.body.classList.add('datamachine-modal-active');
                filterBtn.setAttribute('aria-expanded', 'true');
            });
        }

        // Close modal
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                modal.classList.remove('datamachine-modal-active');
                document.body.classList.remove('datamachine-modal-active');
                if (filterBtn) { filterBtn.focus(); filterBtn.setAttribute('aria-expanded', 'false'); }
            });
        }

        // Close on backdrop click
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target.classList.contains('datamachine-taxonomy-modal-overlay')) {
                    modal.classList.remove('datamachine-modal-active');
                    document.body.classList.remove('datamachine-modal-active');
                    if (filterBtn) { filterBtn.focus(); filterBtn.setAttribute('aria-expanded', 'false'); }
                }
            });
        }

        // Close on Escape key and restore focus
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                if (modal.classList.contains('datamachine-modal-active')) {
                    modal.classList.remove('datamachine-modal-active');
                    document.body.classList.remove('datamachine-modal-active');
                    if (filterBtn) { filterBtn.focus(); filterBtn.setAttribute('aria-expanded', 'false'); }
                }
            }
        });

        // Apply filters button - calls REST API
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                applyFilters(calendar);
                modal.classList.remove('datamachine-modal-active');
                document.body.classList.remove('datamachine-modal-active');

                // Toggle filter button active state after applying
                updateFilterCount(calendar);
            });
        }

        // Reset filters button
        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                // Clear all checkboxes
                const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(cb => cb.checked = false);

                // Update filter count
                updateFilterCount(calendar);

                // Apply filters (will clear taxonomy filters)
                applyFilters(calendar);

                modal.classList.remove('datamachine-modal-active');
                document.body.classList.remove('datamachine-modal-active');
            });
        }

        // Update filter count on checkbox change
        const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateFilterCount(calendar);
            });
        });

        // Run initial update to display any pre-selected count
        updateFilterCount(calendar);
    }

    /**
     * Update filter count badge
     */
    function updateFilterCount(calendar) {
        const modal = calendar.querySelector('.datamachine-taxonomy-modal');
        if (!modal) return;

        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
        const countBadge = filterBtn ? filterBtn.querySelector('.datamachine-filter-count') : null;

        if (!countBadge) return; // Guard if template doesn't have a count badge

        if (checkboxes.length > 0) {
            countBadge.textContent = checkboxes.length;
            countBadge.classList.add('visible');
            if (filterBtn) filterBtn.classList.add('datamachine-filters-active');
            if (filterBtn) filterBtn.setAttribute('aria-expanded', 'true');
        } else {
            countBadge.classList.remove('visible');
            if (filterBtn) filterBtn.classList.remove('datamachine-filters-active');
            if (filterBtn) filterBtn.setAttribute('aria-expanded', 'false');
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
        const searchInput = calendar.querySelector('.datamachine-events-search-input');
        if (searchInput && searchInput.value) {
            params.set('event_search', searchInput.value);
        } else {
            params.delete('event_search');
        }

        // Date range
        const dateRangeInput = calendar.querySelector('.datamachine-events-date-range-input');
        if (dateRangeInput) {
            const datePicker = datePickers.get(calendar);
                if (datePicker && datePicker.selectedDates.length > 0) {
                    const startDate = datePicker.selectedDates[0];
                    const endDate = datePicker.selectedDates[1] || startDate;

                    params.set('date_start', formatDate(startDate));
                    params.set('date_end', formatDate(endDate));

                    // If the date range is entirely in the past, set the past param to 1 so server understands desired direction
                    const now = new Date();
                    const endOfRange = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate(), 23, 59, 59);
                    if (endOfRange < now) {
                        params.set('past', '1');
                    } else {
                        params.delete('past');
                    }
                } else {
                    params.delete('date_start');
                    params.delete('date_end');
                    // No date range -> clear 'past' param so server uses default upcoming unless explicitly set
                    params.delete('past');
                }
        }

        // Taxonomy filters
        params.delete('tax_filter');
        const modal = calendar.querySelector('.datamachine-taxonomy-modal');
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
            const apiUrl = `/wp-json/datamachine/v1/events/calendar?${params.toString()}`;

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
                // Destroy existing datePicker instances for this calendar (if any) to avoid duplication
                const existingDatePicker = datePickers.get(calendar);
                if (existingDatePicker) {
                    try { existingDatePicker.destroy(); } catch (e) { /* ignore */ }
                    datePickers.delete(calendar);
                }
                // Reset initialization marker so initializeCalendarFilters can re-run on this calendar
                try { delete calendar.dataset.dmFiltersInitialized; } catch (e) { calendar.dataset.dmFiltersInitialized = 'false'; }
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

                // Re-run initialization to reattach listeners & reinitialize UI within the calendar element
                initializeCalendarFilters();
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
            // Avoid double-initialization if already initialized for this DOM node
            if (calendar.dataset.dmRenderersInitialized === 'true') return;
            calendar.dataset.dmRenderersInitialized = 'true';
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
