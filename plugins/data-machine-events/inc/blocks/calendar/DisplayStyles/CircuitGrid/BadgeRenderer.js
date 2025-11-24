/**
 * BadgeRenderer - Day badge positioning and management for event groups
 * Handles day label badges that integrate with border system
 */
import { ColorManager } from '../ColorManager.js';

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
     * Check if current viewport is mobile (≤768px)
     *
     * @returns {boolean} True if mobile viewport
     */
    isMobileView() {
        return window.innerWidth <= 768;
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
        // Aggregate badges by dateKey (data-date) so only one badge per logical date
        const groupsMap = new Map();
        const dayGroupElements = this.calendar.querySelectorAll('.datamachine-date-group');

        dayGroupElements.forEach(groupElement => {
            // Prefer explicit data-date attribute on group container
            let dateKey = groupElement.getAttribute('data-date');

            // If group container lacks data-date, attempt to read from first event
            if (!dateKey) {
                const firstEvent = groupElement.querySelector('.datamachine-event-item:not(.hidden)');
                if (firstEvent) {
                    dateKey = firstEvent.getAttribute('data-date') || null;
                }
            }

            // Extract dayName for color fallback
            const dayClass = Array.from(groupElement.classList).find(cls => cls.startsWith('datamachine-day-'));
            const dayName = dayClass ? dayClass.replace('datamachine-day-', '') : 'day';

            const events = Array.from(groupElement.querySelectorAll('.datamachine-event-item:not(.hidden)'));
            const badge = groupElement.querySelector('.datamachine-day-badge');

            if (!dateKey || events.length === 0) return;

            if (!groupsMap.has(dateKey)) {
                groupsMap.set(dateKey, {
                    dateKey,
                    dayName,
                    events: [],
                    badge: badge || null
                });
            }

            const agg = groupsMap.get(dateKey);
            // Append events; keep first non-null badge as representative
            agg.events.push(...events);
            if (!agg.badge && badge) agg.badge = badge;
        });

        // Build array and deduplicate events
        return Array.from(groupsMap.values()).map(group => {
            const uniqueEvents = Array.from(new Set(group.events));
            uniqueEvents.sort((a, b) => {
                const aDate = a.getAttribute('data-date') || '';
                const bDate = b.getAttribute('data-date') || '';
                if (aDate !== bDate) return aDate.localeCompare(bDate);
                const aRect = a.getBoundingClientRect();
                const bRect = b.getBoundingClientRect();
                if (aRect.top !== bRect.top) return aRect.top - bRect.top;
                return aRect.left - bRect.left;
            });

            return {
                dateKey: group.dateKey,
                dayName: group.dayName,
                events: uniqueEvents,
                badge: group.badge
            };
        });
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
    positionDayBadge(badge, firstEvent, groupKey) {
        if (!badge || !firstEvent) return;

        // Skip absolute positioning on mobile - CSS handles static positioning
        if (this.isMobileView()) {
            badge.classList.add('positioned'); // Still mark as visible
            return;
        }

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

        // Use stable groupKey (dateKey) to track badges per logical date
        const safeKey = String(groupKey || '').replace(/[^a-z0-9-_]/gi, '-');
        this.badges.set(safeKey, badge);
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
                // Use stable dateKey as groupKey so badges are unique per logical date
                const groupKey = groupData.dateKey || `${groupData.dayName}`;
                this.positionDayBadge(groupData.badge, firstEvent, groupKey);

                // Apply color styling for badge using ColorManager (use var refs so root.css remains single source)
                if (groupData.badge) {
                    // Set background using the solid color var to match border
                    groupData.badge.style.setProperty('background', ColorManager.getStrokeVar(groupData.dayName));
                    // Use white text for contrast against solid background
                    groupData.badge.style.setProperty('color', 'white');
                }
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