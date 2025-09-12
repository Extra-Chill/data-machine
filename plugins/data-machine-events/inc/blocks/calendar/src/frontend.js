/**
 * Data Machine Events Calendar Frontend JavaScript
 *
 * Client-side interactivity for calendar blocks with search and filtering.
 */

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';
import { BorderRenderer } from './BorderRenderer.js';
import { BadgeRenderer } from './BadgeRenderer.js';

(function() {
    'use strict';

    // Global renderers for each calendar instance
    const borderRenderers = new Map();
    const badgeRenderers = new Map();

    document.addEventListener('DOMContentLoaded', function() {
        initializeCalendarFilters();
        initializeBorders();
        initializeBadges();
    });

    /**
     * Initialize all calendar instances with filtering capabilities
     */
    function initializeCalendarFilters() {
        const calendars = document.querySelectorAll('.dm-events-calendar');
        
        calendars.forEach(function(calendar) {
            const searchInput = calendar.querySelector('#dm-events-search');
            const dateRangeInput = calendar.querySelector('#dm-events-date-range');
            const timeButtons = calendar.querySelectorAll('.dm-events-time-btn');
            const eventsList = calendar.querySelector('#dm-events-list');
            
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
            
            timeButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const timeFilter = this.getAttribute('data-time');
                    navigateToTimeView(timeFilter);
                });
            });
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
            const artist = event.getAttribute('data-artist') || 
                          event.querySelector('.dm-event-artist')?.textContent || '';
            
            const matchesSearch = !searchTerm || 
                title.toLowerCase().includes(searchLower) ||
                venue.toLowerCase().includes(searchLower) ||
                artist.toLowerCase().includes(searchLower);
            
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
        
        // Refresh borders and badges after filtering
        refreshBorders();
        refreshBadges();
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
        
        // Refresh borders and badges after filtering
        refreshBorders();
        refreshBadges();
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
     * Initialize borders for all calendar instances
     */
    function initializeBorders() {
        const calendars = document.querySelectorAll('.dm-events-calendar.dm-events-date-grouped');
        
        calendars.forEach(function(calendar) {
            try {
                console.log('Initializing borders for calendar:', calendar);
                
                // Create border renderer for this calendar
                const borderRenderer = new BorderRenderer(calendar);
                borderRenderers.set(calendar, borderRenderer);
                
                console.log('Borders initialized successfully');
                
            } catch (error) {
                console.error('Failed to initialize borders:', error);
            }
        });
    }

    /**
     * Initialize badges for all calendar instances
     */
    function initializeBadges() {
        const calendars = document.querySelectorAll('.dm-events-calendar.dm-events-date-grouped');
        
        calendars.forEach(function(calendar) {
            try {
                console.log('Initializing badges for calendar:', calendar);
                
                // Create badge renderer for this calendar
                const badgeRenderer = new BadgeRenderer(calendar);
                badgeRenderers.set(calendar, badgeRenderer);
                
                console.log('Badges initialized successfully');
                
            } catch (error) {
                console.error('Failed to initialize badges:', error);
            }
        });
    }

    /**
     * Refresh borders for all calendars (called after filtering)
     */
    function refreshBorders() {
        borderRenderers.forEach((borderRenderer, calendar) => {
            borderRenderer.refresh();
        });
    }

    /**
     * Refresh badges for all calendars (called after filtering)
     */
    function refreshBadges() {
        badgeRenderers.forEach((badgeRenderer, calendar) => {
            badgeRenderer.refresh();
        });
    }

    /**
     * Navigate to different time view
     * 
     * @param {string} timeFilter Time filter mode ('future', 'past', 'all')
     */
    function navigateToTimeView(timeFilter) {
        const url = new URL(window.location);
        if (timeFilter === 'future' && url.searchParams.get('time_filter') !== 'future') {
            url.searchParams.delete('time_filter');
        } else if (timeFilter !== 'future') {
            url.searchParams.set('time_filter', timeFilter);
        }
        
        url.searchParams.delete('paged');
        window.location.href = url.toString();
    }

    /**
     * Cleanup renderers when page unloads
     */
    window.addEventListener('beforeunload', function() {
        borderRenderers.forEach(borderRenderer => {
            borderRenderer.cleanup();
        });
        borderRenderers.clear();
        
        badgeRenderers.forEach(badgeRenderer => {
            badgeRenderer.cleanup();
        });
        badgeRenderers.clear();
    });

})(); 