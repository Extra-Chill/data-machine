/**
 * BadgeRenderer - Day badge positioning and management for event groups
 * Handles day label badges that integrate with border system
 */
export class BadgeRenderer {
    constructor(calendarElement) {
        this.calendar = calendarElement;
        this.badges = new Map(); // Track positioned badges by day
        this.resizeObserver = null;
        this.resizeDebounceTimer = null;
        
        this.init();
    }

    /**
     * Initialize the badge renderer
     */
    init() {
        this.setupResizeObserver();
        this.renderAllBadges();
    }

    /**
     * Setup ResizeObserver for viewport changes
     */
    setupResizeObserver() {
        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                this.debouncedRecalculate();
            });
            
            // Observe the calendar container
            this.resizeObserver.observe(this.calendar);
        }
    }

    /**
     * Debounced recalculation to prevent excessive updates
     */
    debouncedRecalculate() {
        clearTimeout(this.resizeDebounceTimer);
        this.resizeDebounceTimer = setTimeout(() => {
            this.renderAllBadges();
        }, 300);
    }

    /**
     * Detect day groups and their badges
     * 
     * @returns {Array<Object>} Array of group data objects { dayName, groupElement, events, badge, color, index }
     */
    detectDayGroups() {
        const groups = [];
        
        // Find all day group containers
        const dayGroupElements = this.calendar.querySelectorAll('.datamachine-date-group');
        
        let groupIndex = 0;
        dayGroupElements.forEach(groupElement => {
            // Extract day from class name (e.g., datamachine-day-saturday -> saturday)
            const dayClass = Array.from(groupElement.classList).find(cls => cls.startsWith('datamachine-day-'));
            if (!dayClass) return;
            
            const dayName = dayClass.replace('datamachine-day-', '');
            const events = groupElement.querySelectorAll('.datamachine-event-item:not(.hidden)'); // Only visible events
            const badge = groupElement.querySelector('.datamachine-day-badge');
            
            if (events.length > 0 && badge) {
                groups.push({
                    dayName,
                    groupElement,
                    events: Array.from(events),
                    badge,
                    color: `var(--datamachine-day-${dayName})`,
                    index: groupIndex++
                });
            }
        });
        
        return groups;
    }

    /**
     * Find the chronologically first event in a group based on data-date timestamp
     * 
     * @param {HTMLElement[]} events Array of event DOM elements
     * @returns {HTMLElement|null} First chronological event or null if no events
     */
    findFirstChronologicalEvent(events) {
        if (events.length === 0) return null;
        if (events.length === 1) return events[0];

        // Sort events by their data-date timestamp (ISO format)
        const sortedEvents = [...events].sort((a, b) => {
            const dateA = a.getAttribute('data-date');
            const dateB = b.getAttribute('data-date');
            
            // Handle missing dates by putting them last
            if (!dateA && !dateB) return 0;
            if (!dateA) return 1;
            if (!dateB) return -1;
            
            // Compare ISO date strings (they sort lexically)
            return dateA.localeCompare(dateB);
        });

        return sortedEvents[0];
    }

    /**
     * Position day badge exactly on the top border of the first chronological event
     * 
     * @param {HTMLElement} badge Day badge DOM element
     * @param {HTMLElement} firstEvent First chronological event element
     * @param {string} dayName Day identifier for positioning logic
     */
    positionDayBadge(badge, firstEvent, dayName) {
        if (!badge || !firstEvent) return;

        const styles = getComputedStyle(document.documentElement);
        const offsetX = parseInt(styles.getPropertyValue('--datamachine-badge-offset-x')) || 12;
        
        // Get position of first event relative to content area
        const eventRect = firstEvent.getBoundingClientRect();
        const contentRect = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect();
        
        const eventLeft = eventRect.left - contentRect.left;
        const eventTop = eventRect.top - contentRect.top;
        
        // Position badge on top border of first event with padding and offset
        const badgeLeft = eventLeft + offsetX - 8; // Account for event padding
        const badgeTop = eventTop - 8; // Account for event padding
        
        badge.style.left = `${badgeLeft}px`;
        badge.style.top = `${badgeTop}px`;
        badge.classList.add('positioned');
        
        this.badges.set(dayName, badge);
    }

    /**
     * Clear all badge positioning
     */
    clearBadges() {
        const badges = this.calendar.querySelectorAll('.datamachine-day-badge.positioned');
        badges.forEach(badge => {
            badge.classList.remove('positioned');
            badge.style.left = '';
            badge.style.top = '';
        });
        this.badges.clear();
    }

    /**
     * Render all day badges positioned on the first chronological event of each day
     */
    renderAllBadges() {
        // Clear existing badge positioning
        this.clearBadges();

        // Detect day groups and position badges
        const dayGroups = this.detectDayGroups();
        
        // dayGroups is an array of groupData objects
        dayGroups.forEach((groupData) => {
            const firstEvent = this.findFirstChronologicalEvent(groupData.events);
            if (firstEvent) {
                // Use a unique key per group (dayName-index) so multiple same-day groups are tracked separately
                const groupKey = `${groupData.dayName}-${groupData.index}`;
                this.positionDayBadge(groupData.badge, firstEvent, groupKey);
            }
        });
    }

    /**
     * Refresh badge positioning (called after filtering)
     */
    refresh() {
        // Small delay to allow DOM updates from filtering
        setTimeout(() => {
            this.renderAllBadges();
        }, 50);
    }

    /**
     * Cleanup
     */
    cleanup() {
        this.clearBadges();
        
        // Disconnect ResizeObserver
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }
        
        // Clear debounce timer
        clearTimeout(this.resizeDebounceTimer);
    }
}