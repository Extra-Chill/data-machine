/**
 * Data Machine Events Calendar Frontend JavaScript
 *
 * Client-side interactivity for calendar blocks with search, filtering, and view toggling.
 */

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initializeCalendarFilters();
    });

    /**
     * Initialize all calendar instances with filtering capabilities
     */
    function initializeCalendarFilters() {
        const calendars = document.querySelectorAll('.dm-events-calendar');
        
        calendars.forEach(function(calendar) {
            const searchInput = calendar.querySelector('#dm-events-search');
            const dateRangeInput = calendar.querySelector('#dm-events-date-range');
            const viewButtons = calendar.querySelectorAll('.dm-events-view-btn');
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
            
            viewButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    toggleView(calendar, view);
                });
            });
            
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
        
        updateNoEventsMessage(calendar);
    }

    /**
     * Filter events by date range
     * 
     * @param {Element} calendar Calendar container
     * @param {Array} selectedDates Date objects from Flatpickr
     */
    function filterEventsByDateRange(calendar, selectedDates) {
        const events = calendar.querySelectorAll('.dm-event-item');
        
        if (!selectedDates || selectedDates.length === 0) {
            events.forEach(event => event.classList.remove('hidden'));
            updateNoEventsMessage(calendar);
            return;
        }
        
        const startDate = selectedDates[0];
        const endDate = selectedDates[1] || selectedDates[0];
        
        events.forEach(function(event) {
            const eventDate = event.getAttribute('data-date');
            if (!eventDate) {
                const dateElement = event.querySelector('.dm-event-date');
                if (!dateElement) {
                    event.classList.remove('hidden');
                    return;
                }
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
        
        updateNoEventsMessage(calendar);
    }

    /**
     * Toggle calendar display between list and grid views
     * 
     * @param {Element} calendar Calendar container
     * @param {string} view View mode ('list' or 'grid')
     */
    function toggleView(calendar, view) {
        const eventsList = calendar.querySelector('#dm-events-list');
        const viewButtons = calendar.querySelectorAll('.dm-events-view-btn');
        
        calendar.className = calendar.className.replace(/dm-events-view-\w+/, 'dm-events-view-' + view);
        
        viewButtons.forEach(function(button) {
            button.classList.remove('active');
            if (button.getAttribute('data-view') === view) {
                button.classList.add('active');
            }
        });
        
        try {
            localStorage.setItem('dm-events-view', view);
        } catch (e) {
            // Ignore localStorage errors
        }
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
     * Restore user's preferred view mode from localStorage
     */
    function restoreViewPreference() {
        try {
            const savedView = localStorage.getItem('dm-events-view');
            if (savedView) {
                const calendars = document.querySelectorAll('.dm-events-calendar');
                calendars.forEach(function(calendar) {
                    const viewButton = calendar.querySelector('[data-view="' + savedView + '"]');
                    if (viewButton) {
                        toggleView(calendar, savedView);
                    }
                });
            }
        } catch (e) {
            // Ignore localStorage errors
        }
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

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(restoreViewPreference, 100);
    });

})(); 