/**
 * Data Machine Events Calendar Filter Manager
 *
 * Dedicated module for handling taxonomy-based event filtering with modal interface.
 * Manages filter state, modal interactions, and event visibility based on taxonomy selections.
 */

export class FilterManager {
    /**
     * Initialize FilterManager for specific calendar instance
     *
     * @param {Element} calendar Calendar container element
     */
    constructor(calendar) {
        this.calendar = calendar;
        this.modal = null;
        this.activeFilters = new Map(); // taxonomy_slug -> [term_ids]
        this.originalEvents = []; // Store original event visibility
        
        this.init();
    }
    
    /**
     * Initialize filter system and bind event handlers
     */
    init() {
        console.log('DM Events FilterManager: Initializing for calendar:', this.calendar);
        
        // Find modal - it should be within this calendar instance
        this.modal = this.calendar.querySelector('#dm-taxonomy-filter-modal');
        
        if (!this.modal) {
            console.warn('DM Events FilterManager: Modal not found in calendar instance');
            return;
        }
        
        this.bindEventHandlers();
        this.storeOriginalEvents();
        
        console.log('DM Events FilterManager: Initialized successfully');
    }
    
    /**
     * Store original event states for filtering restoration
     */
    storeOriginalEvents() {
        const events = this.calendar.querySelectorAll('.dm-event-item');
        this.originalEvents = Array.from(events).map(event => ({
            element: event,
            hidden: event.classList.contains('hidden')
        }));
    }
    
    /**
     * Bind all event handlers for modal and filter functionality
     */
    bindEventHandlers() {
        // Modal trigger buttons
        const modalTriggers = this.calendar.querySelectorAll('.dm-taxonomy-modal-trigger');
        modalTriggers.forEach(trigger => {
            trigger.addEventListener('click', (e) => this.handleModalOpen(e));
        });
        
        // Modal close handlers
        const overlay = this.modal.querySelector('.dm-taxonomy-modal-overlay');
        const closeButton = this.modal.querySelector('.dm-taxonomy-modal-close');
        
        if (overlay) {
            overlay.addEventListener('click', () => this.closeModal());
        }
        
        if (closeButton) {
            closeButton.addEventListener('click', () => this.closeModal());
        }
        
        // Clear all filters button
        const clearAllButton = this.modal.querySelector('.dm-clear-all-filters');
        if (clearAllButton) {
            clearAllButton.addEventListener('click', () => this.clearAllFilters());
        }
        
        // Apply filters button
        const applyButton = this.modal.querySelector('.dm-apply-filters');
        if (applyButton) {
            applyButton.addEventListener('click', () => this.applyFilters());
        }
        
        // Escape key handler
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal.classList.contains('dm-modal-active')) {
                this.closeModal();
            }
        });
        
        // Checkbox change handlers for real-time preview (optional)
        const checkboxes = this.modal.querySelectorAll('.dm-term-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => this.updateFilterState());
        });
    }
    
    /**
     * Handle modal opening with proper focus management
     *
     * @param {Event} e Click event
     */
    handleModalOpen(e) {
        console.log('DM Events FilterManager: Opening modal');
        e.preventDefault();
        e.stopPropagation();
        
        this.openModal();
    }
    
    /**
     * Open modal with focus trap and accessibility
     */
    openModal() {
        this.modal.classList.add('dm-modal-active');
        document.body.style.overflow = 'hidden';
        
        // Focus first interactive element
        const firstFocusable = this.modal.querySelector('input, button');
        if (firstFocusable) {
            firstFocusable.focus();
        }
        
        // Restore previous filter selections
        this.restoreFilterSelections();
    }
    
    /**
     * Close modal and cleanup
     */
    closeModal() {
        this.modal.classList.remove('dm-modal-active');
        document.body.style.overflow = '';
    }
    
    /**
     * Update internal filter state based on checkbox selections
     */
    updateFilterState() {
        const checkboxes = this.modal.querySelectorAll('.dm-term-checkbox:checked');
        this.activeFilters.clear();
        
        checkboxes.forEach(checkbox => {
            const taxonomy = checkbox.getAttribute('data-taxonomy');
            const termId = parseInt(checkbox.value);
            
            if (!this.activeFilters.has(taxonomy)) {
                this.activeFilters.set(taxonomy, []);
            }
            
            this.activeFilters.get(taxonomy).push(termId);
        });
        
        // Update filter button indicator
        this.updateFilterButtonIndicator();
    }
    
    /**
     * Apply selected filters to events and close modal
     */
    applyFilters() {
        console.log('DM Events FilterManager: Applying filters');
        
        this.updateFilterState();
        this.filterEvents();
        this.closeModal();
        
        // Trigger display renderer refresh if needed
        this.refreshDisplayRenderers();
    }
    
    /**
     * Clear all filter selections and restore all events
     */
    clearAllFilters() {
        console.log('DM Events FilterManager: Clearing all filters');
        
        // Clear checkbox selections
        const checkboxes = this.modal.querySelectorAll('.dm-term-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        // Clear active filters
        this.activeFilters.clear();
        
        // Show all events
        this.showAllEvents();
        
        // Update filter button indicator
        this.updateFilterButtonIndicator();
        
        // Refresh display renderers
        this.refreshDisplayRenderers();
    }
    
    /**
     * Core event filtering logic based on active taxonomy selections
     */
    filterEvents() {
        const events = this.calendar.querySelectorAll('.dm-event-item');
        const dateGroups = this.calendar.querySelectorAll('.dm-date-group');
        
        if (this.activeFilters.size === 0) {
            this.showAllEvents();
            return;
        }
        
        // Filter events based on taxonomy selections
        events.forEach(event => {
            const shouldShow = this.eventMatchesFilters(event);
            
            if (shouldShow) {
                event.classList.remove('hidden');
            } else {
                event.classList.add('hidden');
            }
        });
        
        // Hide date groups with no visible events
        dateGroups.forEach(dateGroup => {
            const visibleEvents = dateGroup.querySelectorAll('.dm-event-item:not(.hidden)');
            if (visibleEvents.length === 0) {
                dateGroup.classList.add('hidden');
            } else {
                dateGroup.classList.remove('hidden');
            }
        });
        
        // Update "no events" message
        this.updateNoEventsMessage();
    }
    
    /**
     * Check if event matches current filter criteria
     *
     * @param {Element} event Event element to test
     * @returns {boolean} True if event should be visible
     */
    eventMatchesFilters(event) {
        // If no filters active, show all events
        if (this.activeFilters.size === 0) {
            return true;
        }
        
        // Get event's taxonomy badges for filtering
        const taxonomyBadges = event.querySelectorAll('.dm-taxonomy-badge');
        
        if (taxonomyBadges.length === 0) {
            return false; // No taxonomies assigned, hide if filters are active
        }
        
        // Check each taxonomy filter (AND logic between taxonomies, OR logic within taxonomy)
        for (const [taxonomySlug, termIds] of this.activeFilters.entries()) {
            let taxonomyMatches = false;
            
            taxonomyBadges.forEach(badge => {
                const badgeTaxonomy = badge.getAttribute('data-taxonomy');
                const badgeTermSlug = badge.getAttribute('data-term');
                
                if (badgeTaxonomy === taxonomySlug) {
                    // Find term ID from term slug
                    const checkbox = this.modal.querySelector(
                        `[data-taxonomy="${taxonomySlug}"][data-term-slug="${badgeTermSlug}"]`
                    );
                    
                    if (checkbox) {
                        const termId = parseInt(checkbox.value);
                        if (termIds.includes(termId)) {
                            taxonomyMatches = true;
                        }
                    }
                }
            });
            
            // If any taxonomy filter doesn't match, event should be hidden (AND logic)
            if (!taxonomyMatches) {
                return false;
            }
        }
        
        return true; // All taxonomy filters matched
    }
    
    /**
     * Show all events (restore original visibility state)
     */
    showAllEvents() {
        this.originalEvents.forEach(({ element, hidden }) => {
            if (hidden) {
                element.classList.add('hidden');
            } else {
                element.classList.remove('hidden');
            }
        });
        
        // Show all date groups
        const dateGroups = this.calendar.querySelectorAll('.dm-date-group');
        dateGroups.forEach(group => group.classList.remove('hidden'));
        
        this.updateNoEventsMessage();
    }
    
    /**
     * Update "no events found" message based on visible events
     */
    updateNoEventsMessage() {
        const visibleEvents = this.calendar.querySelectorAll('.dm-event-item:not(.hidden)');
        const noEventsMessage = this.calendar.querySelector('.dm-events-no-events');
        const eventsContent = this.calendar.querySelector('.dm-events-content');
        
        if (visibleEvents.length === 0) {
            if (!noEventsMessage && eventsContent) {
                const message = document.createElement('div');
                message.className = 'dm-events-no-events';
                message.innerHTML = '<p>No events found matching your filter criteria.</p>';
                eventsContent.appendChild(message);
            }
        } else {
            if (noEventsMessage) {
                noEventsMessage.remove();
            }
        }
    }
    
    /**
     * Update filter button to show active filter count
     */
    updateFilterButtonIndicator() {
        const triggerButton = this.calendar.querySelector('.dm-taxonomy-modal-trigger');
        
        if (!triggerButton) return;
        
        const activeCount = Array.from(this.activeFilters.values())
            .reduce((total, termIds) => total + termIds.length, 0);
        
        // Update button text to show active filter count
        const buttonText = triggerButton.querySelector('span:not(.dashicons)');
        if (buttonText) {
            if (activeCount > 0) {
                buttonText.textContent = `Filter (${activeCount})`;
                triggerButton.classList.add('dm-filters-active');
            } else {
                buttonText.textContent = 'Filter';
                triggerButton.classList.remove('dm-filters-active');
            }
        }
    }
    
    /**
     * Restore previous filter selections in modal
     */
    restoreFilterSelections() {
        // Clear all selections first
        const checkboxes = this.modal.querySelectorAll('.dm-term-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        // Restore active selections
        for (const [taxonomySlug, termIds] of this.activeFilters.entries()) {
            termIds.forEach(termId => {
                const checkbox = this.modal.querySelector(
                    `[data-taxonomy="${taxonomySlug}"][value="${termId}"]`
                );
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        }
    }
    
    /**
     * Refresh display renderers after filtering (integration point)
     */
    refreshDisplayRenderers() {
        // Trigger custom event for external display renderer integration
        const refreshEvent = new CustomEvent('dmEventsFiltersChanged', {
            detail: {
                calendar: this.calendar,
                activeFilters: this.activeFilters
            }
        });
        
        this.calendar.dispatchEvent(refreshEvent);
    }
    
    /**
     * Get current active filters state
     *
     * @returns {Object} Current filter state for persistence
     */
    getActiveFilters() {
        const filters = {};
        for (const [taxonomy, termIds] of this.activeFilters.entries()) {
            filters[taxonomy] = [...termIds];
        }
        return filters;
    }
    
    /**
     * Set active filters from external state
     *
     * @param {Object} filters Filter state to restore
     */
    setActiveFilters(filters) {
        this.activeFilters.clear();
        
        for (const [taxonomy, termIds] of Object.entries(filters)) {
            if (Array.isArray(termIds) && termIds.length > 0) {
                this.activeFilters.set(taxonomy, [...termIds]);
            }
        }
        
        this.updateFilterButtonIndicator();
        this.filterEvents();
    }
    
    /**
     * Cleanup method for removing event listeners
     */
    destroy() {
        // Remove any global event listeners if needed
        // This method can be called when calendar is removed from DOM
        console.log('DM Events FilterManager: Cleaned up');
    }
}

export default FilterManager;