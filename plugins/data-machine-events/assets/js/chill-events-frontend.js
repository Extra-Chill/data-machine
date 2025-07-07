/**
 * Chill Events - Frontend JavaScript
 *
 * @package ChillEvents
 * @author Chris Huber (https://chubes.net)
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Chill Events Frontend functionality
     */
    var ChillEventsFrontend = {
        
        /**
         * Initialize frontend functionality
         */
        init: function() {
            this.bindEvents();
            this.initCalendar();
            this.initFilters();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Calendar navigation
            $(document).on('click', '.calendar-nav-button', this.navigateCalendar);
            $(document).on('click', '.view-button', this.switchView);
            
            // Event filtering
            $(document).on('click', '.filter-button', this.applyFilters);
            $(document).on('click', '.clear-filters', this.clearFilters);
            $(document).on('input', '.search-input', this.debounce(this.searchEvents, 300));
            
            // Event cards
            $(document).on('click', '.calendar-event', this.showEventDetails);
            $(document).on('click', '.event-card', this.viewEventDetail);
            
            // Responsive calendar
            $(window).on('resize', this.debounce(this.handleResize, 250));
        },
        
        /**
         * Initialize calendar functionality
         */
        initCalendar: function() {
            if ($('.chill-events-calendar').length) {
                this.currentDate = new Date();
                this.currentView = 'month';
                this.renderCalendar();
            }
        },
        
        /**
         * Initialize filter functionality
         */
        initFilters: function() {
            // Set up search autocomplete
            if ($('.search-input').length) {
                this.initSearchAutocomplete();
            }
        },
        
        /**
         * Navigate calendar (prev/next)
         */
        navigateCalendar: function(e) {
            e.preventDefault();
            
            var direction = $(this).data('direction');
            var view = ChillEventsFrontend.currentView;
            
            if (direction === 'prev') {
                if (view === 'month') {
                    ChillEventsFrontend.currentDate.setMonth(ChillEventsFrontend.currentDate.getMonth() - 1);
                } else if (view === 'week') {
                    ChillEventsFrontend.currentDate.setDate(ChillEventsFrontend.currentDate.getDate() - 7);
                } else if (view === 'day') {
                    ChillEventsFrontend.currentDate.setDate(ChillEventsFrontend.currentDate.getDate() - 1);
                }
            } else if (direction === 'next') {
                if (view === 'month') {
                    ChillEventsFrontend.currentDate.setMonth(ChillEventsFrontend.currentDate.getMonth() + 1);
                } else if (view === 'week') {
                    ChillEventsFrontend.currentDate.setDate(ChillEventsFrontend.currentDate.getDate() + 7);
                } else if (view === 'day') {
                    ChillEventsFrontend.currentDate.setDate(ChillEventsFrontend.currentDate.getDate() + 1);
                }
            }
            
            ChillEventsFrontend.renderCalendar();
        },
        
        /**
         * Switch calendar view
         */
        switchView: function(e) {
            e.preventDefault();
            
            var newView = $(this).data('view');
            
            $('.view-button').removeClass('active');
            $(this).addClass('active');
            
            ChillEventsFrontend.currentView = newView;
            ChillEventsFrontend.renderCalendar();
        },
        
        /**
         * Render calendar based on current date and view
         */
        renderCalendar: function() {
            var calendarContainer = $('.calendar-content');
            
            if (!calendarContainer.length) {
                return;
            }
            
            // Show loading
            calendarContainer.html('<div class="chill-events-loading"><div class="loading-spinner"></div> Loading calendar...</div>');
            
            // AJAX request to get calendar data
            $.ajax({
                url: chillEventsFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chill_events_get_calendar',
                    date: ChillEventsFrontend.formatDate(ChillEventsFrontend.currentDate),
                    view: ChillEventsFrontend.currentView,
                    nonce: chillEventsFrontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        calendarContainer.html(response.data.html);
                        ChillEventsFrontend.updateCalendarTitle();
                    } else {
                        calendarContainer.html('<div class="no-events">Error loading calendar</div>');
                    }
                },
                error: function() {
                    calendarContainer.html('<div class="no-events">Error loading calendar</div>');
                }
            });
        },
        
        /**
         * Update calendar title
         */
        updateCalendarTitle: function() {
            var title = '';
            var date = ChillEventsFrontend.currentDate;
            var view = ChillEventsFrontend.currentView;
            
            if (view === 'month') {
                title = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            } else if (view === 'week') {
                var weekStart = new Date(date);
                weekStart.setDate(date.getDate() - date.getDay());
                var weekEnd = new Date(weekStart);
                weekEnd.setDate(weekStart.getDate() + 6);
                title = weekStart.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + 
                       ' - ' + weekEnd.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            } else if (view === 'day') {
                title = date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            }
            
            $('.calendar-title').text(title);
        },
        
        /**
         * Apply event filters
         */
        applyFilters: function(e) {
            e.preventDefault();
            
            var filters = ChillEventsFrontend.getFilterData();
            ChillEventsFrontend.loadEvents(filters);
        },
        
        /**
         * Clear all filters
         */
        clearFilters: function(e) {
            e.preventDefault();
            
            $('.filter-select').val('');
            $('.filter-input').val('');
            $('.search-input').val('');
            
            ChillEventsFrontend.loadEvents({});
        },
        
        /**
         * Search events with debouncing
         */
        searchEvents: function() {
            var searchTerm = $('.search-input').val();
            var filters = ChillEventsFrontend.getFilterData();
            filters.search = searchTerm;
            
            ChillEventsFrontend.loadEvents(filters);
        },
        
        /**
         * Get current filter data
         */
        getFilterData: function() {
            var filters = {};
            
            $('.filter-select, .filter-input').each(function() {
                var name = $(this).attr('name');
                var value = $(this).val();
                if (name && value) {
                    filters[name] = value;
                }
            });
            
            return filters;
        },
        
        /**
         * Load events with filters
         */
        loadEvents: function(filters) {
            var eventsContainer = $('.events-grid, .events-list');
            
            if (!eventsContainer.length) {
                return;
            }
            
            // Show loading
            eventsContainer.html('<div class="chill-events-loading"><div class="loading-spinner"></div> Loading events...</div>');
            
            // AJAX request to get filtered events
            $.ajax({
                url: chillEventsFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chill_events_get_events',
                    filters: filters,
                    nonce: chillEventsFrontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        eventsContainer.html(response.data.html);
                        
                        if (response.data.count === 0) {
                            eventsContainer.html('<div class="no-events">No events found matching your criteria.</div>');
                        }
                    } else {
                        eventsContainer.html('<div class="no-events">Error loading events</div>');
                    }
                },
                error: function() {
                    eventsContainer.html('<div class="no-events">Error loading events</div>');
                }
            });
        },
        
        /**
         * Show event details in popup/modal
         */
        showEventDetails: function(e) {
            e.preventDefault();
            
            var eventId = $(this).data('event-id');
            
            // Implementation will be added in Phase 18-19
            console.log('Show event details:', eventId);
        },
        
        /**
         * View event detail page
         */
        viewEventDetail: function(e) {
            // Allow clicking through to event detail page
            // This is handled by the link itself
        },
        
        /**
         * Initialize search autocomplete
         */
        initSearchAutocomplete: function() {
            var searchInput = $('.search-input');
            var autocompleteList = $('<ul class="search-autocomplete"></ul>');
            
            searchInput.after(autocompleteList);
            
            searchInput.on('input', ChillEventsFrontend.debounce(function() {
                var term = $(this).val();
                
                if (term.length < 2) {
                    autocompleteList.hide();
                    return;
                }
                
                $.ajax({
                    url: chillEventsFrontend.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'chill_events_search_autocomplete',
                        term: term,
                        nonce: chillEventsFrontend.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.suggestions.length > 0) {
                            var html = '';
                            response.data.suggestions.forEach(function(suggestion) {
                                html += '<li data-value="' + suggestion.value + '">' + suggestion.label + '</li>';
                            });
                            autocompleteList.html(html).show();
                        } else {
                            autocompleteList.hide();
                        }
                    }
                });
            }, 300));
            
            // Handle autocomplete selection
            autocompleteList.on('click', 'li', function() {
                var value = $(this).data('value');
                searchInput.val(value);
                autocompleteList.hide();
                ChillEventsFrontend.searchEvents();
            });
            
            // Hide autocomplete when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.search-input, .search-autocomplete').length) {
                    autocompleteList.hide();
                }
            });
        },
        
        /**
         * Handle window resize
         */
        handleResize: function() {
            // Adjust calendar layout for mobile
            if ($(window).width() < 768) {
                $('.calendar-grid').addClass('mobile-view');
            } else {
                $('.calendar-grid').removeClass('mobile-view');
            }
        },
        
        /**
         * Format date for API
         */
        formatDate: function(date) {
            return date.getFullYear() + '-' + 
                   String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                   String(date.getDate()).padStart(2, '0');
        },
        
        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function() {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };
    
    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        ChillEventsFrontend.init();
    });
    
    // Make available globally for debugging
    window.ChillEventsFrontend = ChillEventsFrontend;
    
})(jQuery); 