/**
 * Data Machine Events Calendar Frontend JavaScript
 *
 * Client-side interactivity for calendar blocks with search and filtering.
 */

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';
import FilterManager from './FilterManager.js';

(function() {
    'use strict';

    // Global display renderers and filter managers for each calendar instance
    const displayRenderers = new Map();
    const filterManagers = new Map();

    document.addEventListener('DOMContentLoaded', function() {
        initializeCalendarFilters();
        initializeDisplayRenderers();
        initializeFilterManagers();
    });

    /**
     * Initialize all calendar instances with filtering capabilities
     */
    function initializeCalendarFilters() {
        const calendars = document.querySelectorAll('.dm-events-calendar');
        
        calendars.forEach(function(calendar) {
            const searchInput = calendar.querySelector('#dm-events-search');
            const dateRangeInput = calendar.querySelector('#dm-events-date-range');
            const eventsList = calendar.querySelector('.dm-events-content');
            
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    filterEvents(calendar, this.value);
                });
                
                const searchBtn = calendar.querySelector('.dm-events-search-btn');
                if (searchBtn) {
                    searchBtn.addEventListener('click', function() {
                        searchInput.value = '';
                        filterEvents(calendar, '');
                        searchInput.focus();
                    });
                }
            }
            
            if (dateRangeInput) {
                const clearBtn = calendar.querySelector('.dm-events-date-clear-btn');
                
                const datePicker = flatpickr(dateRangeInput, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    placeholder: 'Select date range...',
                    allowInput: false,
                    clickOpens: true,
                    onChange: function(selectedDates, dateStr, instance) {
                        filterEventsByDateRange(calendar, selectedDates);
                        
                        if (selectedDates && selectedDates.length > 0) {
                            clearBtn.classList.add('visible');
                        } else {
                            clearBtn.classList.remove('visible');
                        }
                    },
                    onClear: function() {
                        filterEventsByDateRange(calendar, []);
                        clearBtn.classList.remove('visible');
                    }
                });
                
                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        datePicker.clear();
                    });
                }
            }
        });
    }


    /**
     * Filter events based on search term
     *
     * @param {Element} calendar Calendar container
     * @param {string} searchTerm Search input
     */
    function filterEvents(calendar, searchTerm) {
        const events = calendar.querySelectorAll('.dm-event-item');
        const dateGroups = calendar.querySelectorAll('.dm-date-group');
        const searchLower = searchTerm.toLowerCase();
        
        events.forEach(function(event) {
            const title = event.getAttribute('data-title') || 
                         event.querySelector('.dm-event-title')?.textContent || '';
            const venue = event.getAttribute('data-venue') || 
                         event.querySelector('.dm-event-venue')?.textContent || '';
            const badges = event.querySelector('.dm-taxonomy-badges')?.textContent || '';
            
            const matchesSearch = !searchTerm || 
                title.toLowerCase().includes(searchLower) ||
                venue.toLowerCase().includes(searchLower) ||
                badges.toLowerCase().includes(searchLower);
            
            if (matchesSearch) {
                event.classList.remove('hidden');
            } else {
                event.classList.add('hidden');
            }
        });
        
        // Hide date groups that have no visible events
        dateGroups.forEach(function(dateGroup) {
            const visibleEvents = dateGroup.querySelectorAll('.dm-event-item:not(.hidden)');
            if (visibleEvents.length === 0) {
                dateGroup.classList.add('hidden');
            } else {
                dateGroup.classList.remove('hidden');
            }
        });
        
        updateNoEventsMessage(calendar);
        
        // Refresh display renderers after filtering
        refreshDisplayRenderers();
    }

    /**
     * Filter events by date range
     * 
     * @param {Element} calendar Calendar container
     * @param {Array} selectedDates Date objects from Flatpickr
     */
    function filterEventsByDateRange(calendar, selectedDates) {
        const events = calendar.querySelectorAll('.dm-event-item');
        const dateGroups = calendar.querySelectorAll('.dm-date-group');
        
        if (!selectedDates || selectedDates.length === 0) {
            events.forEach(event => event.classList.remove('hidden'));
            dateGroups.forEach(group => group.classList.remove('hidden'));
            updateNoEventsMessage(calendar);
            return;
        }
        
        const startDate = selectedDates[0];
        const endDate = selectedDates[1] || selectedDates[0];
        
        events.forEach(function(event) {
            const eventDate = event.getAttribute('data-date');
            if (!eventDate) {
                event.classList.remove('hidden');
                return;
            }
            
            const eventDateTime = new Date(eventDate);
            const eventDateOnly = new Date(eventDateTime.getFullYear(), eventDateTime.getMonth(), eventDateTime.getDate());
            const showEvent = eventDateOnly >= startDate && eventDateOnly <= endDate;
            
            if (showEvent) {
                event.classList.remove('hidden');
            } else {
                event.classList.add('hidden');
            }
        });
        
        // Hide date groups that have no visible events
        dateGroups.forEach(function(dateGroup) {
            const visibleEvents = dateGroup.querySelectorAll('.dm-event-item:not(.hidden)');
            if (visibleEvents.length === 0) {
                dateGroup.classList.add('hidden');
            } else {
                dateGroup.classList.remove('hidden');
            }
        });
        
        updateNoEventsMessage(calendar);
        
        // Refresh display renderers after filtering
        refreshDisplayRenderers();
    }

    /**
     * Show or hide "no events found" message
     * 
     * @param {Element} calendar Calendar container
     */
    function updateNoEventsMessage(calendar) {
        const events = calendar.querySelectorAll('.dm-event-item:not(.hidden)');
        const noEventsMessage = calendar.querySelector('.dm-events-no-events');
        
        if (events.length === 0) {
            if (!noEventsMessage) {
                const message = document.createElement('div');
                message.className = 'dm-events-no-events';
                message.innerHTML = '<p>No events found matching your criteria.</p>';
                calendar.querySelector('.dm-events-content').appendChild(message);
            }
        } else {
            if (noEventsMessage) {
                noEventsMessage.remove();
            }
        }
    }

    /**
     * Reliably detect carousel list mode by checking actual CSS styles
     */
    function isCarouselListMode(calendar) {
        // Check if date groups have horizontal flex layout (carousel characteristic)
        const dateGroup = calendar.querySelector('.dm-date-group');
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
        const calendars = document.querySelectorAll('.dm-events-calendar.dm-events-date-grouped');

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
     * Initialize filter managers for taxonomy filtering
     */
    function initializeFilterManagers() {
        const calendars = document.querySelectorAll('.dm-events-calendar');
        
        calendars.forEach(function(calendar) {
            const modal = calendar.querySelector('#dm-taxonomy-filter-modal');
            
            if (modal) {
                const filterManager = new FilterManager(calendar);
                filterManagers.set(calendar, filterManager);
                
                // Listen for filter changes to refresh display renderers
                calendar.addEventListener('dmEventsFiltersChanged', function() {
                    refreshDisplayRenderers();
                });
            }
        });
    }


    /**
     * Cleanup renderers and filter managers when page unloads
     */
    window.addEventListener('beforeunload', function() {
        displayRenderers.forEach(displayRenderer => {
            displayRenderer.cleanup();
        });
        displayRenderers.clear();
        
        filterManagers.forEach(filterManager => {
            filterManager.destroy();
        });
        filterManagers.clear();
    });

})(); 