/**
 * Chill Events Calendar Frontend JavaScript
 * 
 * Handles search, filtering, and view toggling functionality
 */

import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

(function() {
    'use strict';

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeCalendarFilters();
    });

    function initializeCalendarFilters() {
        const calendars = document.querySelectorAll('.chill-events-calendar');
        
        calendars.forEach(function(calendar) {
            const searchInput = calendar.querySelector('#chill-events-search');
            const dateRangeInput = calendar.querySelector('#chill-events-date-range');
            const viewButtons = calendar.querySelectorAll('.chill-events-view-btn');
            const eventsList = calendar.querySelector('#chill-events-list');
            
            // Initialize search functionality
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    filterEvents(calendar, this.value);
                });
                
                // Clear search button
                const searchBtn = calendar.querySelector('.chill-events-search-btn');
                if (searchBtn) {
                    searchBtn.addEventListener('click', function() {
                        searchInput.value = '';
                        filterEvents(calendar, '');
                        searchInput.focus();
                    });
                }
            }
            
            // Initialize date range picker with flatpickr
            if (dateRangeInput) {
                console.log('Initializing flatpickr for date range picker');
                const clearBtn = calendar.querySelector('.chill-events-date-clear-btn');
                
                const datePicker = flatpickr(dateRangeInput, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    placeholder: 'Select date range...',
                    allowInput: false,
                    clickOpens: true,
                    onChange: function(selectedDates, dateStr, instance) {
                        console.log('Date range changed:', selectedDates, dateStr);
                        filterEventsByDateRange(calendar, selectedDates);
                        
                        // Show/hide clear button based on selection
                        if (selectedDates && selectedDates.length > 0) {
                            clearBtn.classList.add('visible');
                        } else {
                            clearBtn.classList.remove('visible');
                        }
                    },
                    onClear: function() {
                        console.log('Date range cleared');
                        filterEventsByDateRange(calendar, []);
                        clearBtn.classList.remove('visible');
                    }
                });
                
                // Initialize clear button functionality
                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        datePicker.clear();
                        console.log('Clear button clicked');
                    });
                }
                
                console.log('Flatpickr initialized:', datePicker);
            } else {
                console.log('Date range input not found');
            }
            
            // Initialize view toggle
            viewButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    toggleView(calendar, view);
                });
            });
        });
    }

    function filterEvents(calendar, searchTerm) {
        const events = calendar.querySelectorAll('.chill-event-item');
        const searchLower = searchTerm.toLowerCase();
        
        events.forEach(function(event) {
            const title = event.getAttribute('data-title') || '';
            const venue = event.getAttribute('data-venue') || '';
            const artist = event.getAttribute('data-artist') || '';
            
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

    function filterEventsByDateRange(calendar, selectedDates) {
        const events = calendar.querySelectorAll('.chill-event-item');
        
        if (!selectedDates || selectedDates.length === 0) {
            // No date filter - show all events
            events.forEach(event => event.classList.remove('hidden'));
            updateNoEventsMessage(calendar);
            return;
        }
        
        const startDate = selectedDates[0];
        const endDate = selectedDates[1] || selectedDates[0]; // If only one date selected, use it as both start and end
        
        events.forEach(function(event) {
            const eventDate = event.getAttribute('data-date');
            if (!eventDate) {
                event.classList.remove('hidden');
                return;
            }
            
            const eventDateTime = new Date(eventDate);
            const eventDateOnly = new Date(eventDateTime.getFullYear(), eventDateTime.getMonth(), eventDateTime.getDate());
            
            // Check if event date falls within the selected range (inclusive)
            const showEvent = eventDateOnly >= startDate && eventDateOnly <= endDate;
            
            if (showEvent) {
                event.classList.remove('hidden');
            } else {
                event.classList.add('hidden');
            }
        });
        
        updateNoEventsMessage(calendar);
    }

    function toggleView(calendar, view) {
        const eventsList = calendar.querySelector('#chill-events-list');
        const viewButtons = calendar.querySelectorAll('.chill-events-view-btn');
        
        // Update calendar class
        calendar.className = calendar.className.replace(/chill-events-view-\w+/, 'chill-events-view-' + view);
        
        // Update button states
        viewButtons.forEach(function(button) {
            button.classList.remove('active');
            if (button.getAttribute('data-view') === view) {
                button.classList.add('active');
            }
        });
        
        // Store view preference in localStorage
        try {
            localStorage.setItem('chill-events-view', view);
        } catch (e) {
            // Ignore localStorage errors
        }
    }

    function updateNoEventsMessage(calendar) {
        const events = calendar.querySelectorAll('.chill-event-item:not(.hidden)');
        const noEventsMessage = calendar.querySelector('.chill-events-no-events');
        
        if (events.length === 0) {
            if (!noEventsMessage) {
                const message = document.createElement('div');
                message.className = 'chill-events-no-events';
                message.innerHTML = '<p>No events found matching your criteria.</p>';
                calendar.querySelector('.chill-events-content').appendChild(message);
            }
        } else {
            if (noEventsMessage) {
                noEventsMessage.remove();
            }
        }
    }

    // Restore view preference from localStorage
    function restoreViewPreference() {
        try {
            const savedView = localStorage.getItem('chill-events-view');
            if (savedView) {
                const calendars = document.querySelectorAll('.chill-events-calendar');
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

    // Call restore function after initialization
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(restoreViewPreference, 100);
    });

})(); 