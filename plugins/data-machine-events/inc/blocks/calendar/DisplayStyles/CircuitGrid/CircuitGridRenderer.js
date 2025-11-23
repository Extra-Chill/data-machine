/**
 * CircuitGridRenderer - Circuit Grid Display Module
 *
 * Complete circuit grid display functionality including border rendering and badge positioning.
 * Isolated module that handles all circuit grid specific behavior.
 */
import { BadgeRenderer } from './BadgeRenderer.js';
import { ColorManager } from '../ColorManager.js';

export class CircuitGridRenderer {
    constructor(calendarElement) {
        this.calendar = calendarElement;
        this.svgContainer = null;
        this.borders = new Map(); // Track rendered borders by day
        this.badgeRenderer = null; // Circuit grid specific badge renderer
        this.resizeObserver = null;
        this.resizeDebounceTimer = null;

        this.init();
    }

    /**
     * Initialize the circuit grid renderer
     */
    init() {
        this.findSVGContainer();
        this.initializeBadgeRenderer();
        this.setupResizeObserver();
        this.renderAllBorders();
        this.initializeGridEvents();
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
     * Check if current viewport is tablet (769px-1199px)
     *
     * @returns {boolean} True if tablet viewport
     */
    isTabletView() {
        return window.innerWidth > 768 && window.innerWidth <= 1199;
    }

    /**
     * Initialize badge renderer for circuit grid
     */
    initializeBadgeRenderer() {
        try {
            this.badgeRenderer = new BadgeRenderer(this.calendar);
        } catch (error) {
            console.error('Failed to initialize BadgeRenderer for circuit grid:', error);
        }
    }

    /**
     * Initialize grid-specific event handlers
     */
    initializeGridEvents() {
        // Circuit grid specific event handlers - simplified without border lighting
        const eventCards = this.calendar.querySelectorAll('.datamachine-events-content .datamachine-event-link');
        
        // Additional grid-specific interactivity can be added here if needed
    }

    /**
     * Find existing SVG container created by PHP
     */
    findSVGContainer() {
        this.svgContainer = this.calendar.querySelector('.datamachine-border-overlay');

        if (!this.svgContainer) {
            console.error('CircuitGridRenderer: SVG container not found. Expected .datamachine-border-overlay element in calendar.');
            return false;
        }

        // Clear any existing borders
        this.svgContainer.innerHTML = '';

        return true;
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
            this.renderAllBorders();
        }, 300);
    }


    /**
     * Detect day groups by analyzing DOM structure and CSS classes
     * 
     * @returns {Map<string, Object>} Map of day names to group data with events and colors
     */
    /**
     * Detect day groups by analyzing DOM structure and CSS classes
     *
     * @returns {Array<Object>} Array of group data objects { dateKey, dayName, events, color }
     */
    detectDayGroups() {
        const groupsMap = new Map();
        const dayGroupElements = this.calendar.querySelectorAll('.datamachine-events-content .datamachine-date-group');

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

            // Fallback: derive dayName from class for grouping
            const dayClass = Array.from(groupElement.classList).find(cls => cls.startsWith('datamachine-day-'));
            const dayName = dayClass ? dayClass.replace('datamachine-day-', '') : 'day';

            // Find visible events in this group instance
            const events = Array.from(groupElement.querySelectorAll('.datamachine-event-item:not(.hidden)'));

            if (!dateKey || events.length === 0) {
                // Skip groups without date or events
                return;
            }

            // Initialize aggregator for this dateKey if needed
            if (!groupsMap.has(dateKey)) {
                groupsMap.set(dateKey, {
                    dateKey,
                    dayName,
                    events: []
                });
            }

            const agg = groupsMap.get(dateKey);
            agg.events.push(...events);
        });

        // Convert map values to array. Ensure events array is unique and deterministic.
        return Array.from(groupsMap.values()).map(group => {
            // Remove duplicate event elements (same DOM node may be present in multiple groupElements)
            const uniqueEvents = Array.from(new Set(group.events));
            // Sort by data-date (ISO) and then by DOM position to keep deterministic order
            uniqueEvents.sort((a, b) => {
                const aDate = a.getAttribute('data-date') || '';
                const bDate = b.getAttribute('data-date') || '';
                if (aDate !== bDate) return aDate.localeCompare(bDate);
                
                // fallback to compare offsetTop/Left for stable ordering
                const aRect = a.getBoundingClientRect();
                const bRect = b.getBoundingClientRect();
                if (aRect.top !== bRect.top) return aRect.top - bRect.top;
                return aRect.left - bRect.left;
            });

            return {
                dateKey: group.dateKey,
                dayName: group.dayName,
                events: uniqueEvents
            };
        });
    }

    /**
     * Calculate bounding box for event group relative to calendar content area
     * 
     * @param {HTMLElement[]} events Array of event DOM elements
     * @returns {Object|null} Bounding box with left, top, width, height or null if no events
     */
    calculateGroupBounds(events) {
        if (events.length === 0) return null;

        let minLeft = Infinity;
        let minTop = Infinity;
        let maxRight = -Infinity;
        let maxBottom = -Infinity;

        events.forEach(event => {
            const rect = event.getBoundingClientRect();
            const contentRect = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect();
            
            // Calculate relative position within the content area (where SVG overlay is positioned)
            const left = rect.left - contentRect.left;
            const top = rect.top - contentRect.top;
            const right = left + rect.width;
            const bottom = top + rect.height;

            minLeft = Math.min(minLeft, left);
            minTop = Math.min(minTop, top);
            maxRight = Math.max(maxRight, right);
            maxBottom = Math.max(maxBottom, bottom);
        });

        return {
            left: minLeft - 8, // Add padding
            top: minTop - 8,
            width: maxRight - minLeft + 16,
            height: maxBottom - minTop + 16
        };
    }


    /**
     * Render SVG border element for day group using shape data
     * 
     * @param {string} dayName Day identifier (e.g., 'monday', 'tuesday')
     * @param {Object} shape Shape definition with type, dimensions, and path data
     * @param {string} color CSS color value for border stroke
     */
    /**
     * Render SVG border element for day group using shape data
     * 
     * @param {string} dayName Day identifier (e.g., 'monday', 'tuesday')
     * @param {Object} shape Shape definition with type, dimensions, and path data
     * @param {string} color CSS color value for border stroke
     * @param {number} groupIndex Unique index for this group occurrence
     */
    renderGroupBorder(dayName, shape, groupKey = '') {
        if (!shape || !this.svgContainer) return;

        // Normalize groupKey into a safe identifier for use in data attributes
        const safeKey = String(groupKey).replace(/[^a-z0-9-_]/gi, '-');

        // Use a unique data-day value to avoid overwriting other same-day groups
        const dataDayAttr = `${dayName}-${safeKey}`;

        // Remove existing border for this specific day-group if present
        const existingBorder = this.svgContainer.querySelector(`[data-day="${dataDayAttr}"]`);
        if (existingBorder) {
            existingBorder.remove();
        }

        // Use ColorManager to set fill/stroke via CSS custom property references
        let element;

        if (shape.type === 'path') {
            element = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            element.setAttribute('d', shape.path);
            element.setAttribute('fill', 'none');
            element.setAttribute('stroke-width', '3.25');
            element.setAttribute('stroke-opacity', '0.8');
            ColorManager.applyToElement(element, dayName, { useVar: true });
        } else {
            element = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            element.setAttribute('x', shape.left);
            element.setAttribute('y', shape.top);
            element.setAttribute('width', shape.width);
            element.setAttribute('height', shape.height);
            element.setAttribute('fill', 'none');
            element.setAttribute('stroke-width', '3.25');
            element.setAttribute('stroke-linejoin', 'round');
            element.setAttribute('stroke-linecap', 'round');
            element.setAttribute('stroke-opacity', '0.8');
            element.setAttribute('rx', shape.borderRadius || 8);
            ColorManager.applyToElement(element, dayName, { useVar: true });
        }

        element.setAttribute('data-day', dataDayAttr);

        // apply both day-specific and group-specific classes so existing CSS still matches
        // e.g., datamachine-border-monday datamachine-border-group-monday
        element.className.baseVal = `datamachine-border-${dayName} datamachine-border-group-${dayName}`;

        this.svgContainer.appendChild(element);

        // Store border keyed by unique dataDayAttr
        this.borders.set(dataDayAttr, element);
    }

    /**
     * Extract grid configuration from CSS custom properties and calculate responsive layout
     *
     * @returns {Object} Grid settings with cellWidth, gap, eventsPerRow, borderRadius
     */
    getActiveGridSettings() {
        const styles = getComputedStyle(document.documentElement);
        const borderRadius = parseInt(styles.getPropertyValue('--datamachine-border-radius')) || 8;

        // Get actual container width
        const containerWidth = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect().width;

        // Determine which CSS variables to use based on viewport
        let cellWidth, gap;

        if (this.isMobileView()) {
            // Mobile: single column, full width
            cellWidth = containerWidth;
            gap = 16; // 1rem
        } else if (this.isTabletView()) {
            // Tablet: use tablet-specific variables
            cellWidth = parseInt(styles.getPropertyValue('--datamachine-grid-cell-width-tablet')) || 240;
            gap = parseInt(styles.getPropertyValue('--datamachine-grid-gap-tablet')) || 20;
        } else {
            // Desktop: use default variables
            cellWidth = parseInt(styles.getPropertyValue('--datamachine-grid-cell-width'));
            gap = parseInt(styles.getPropertyValue('--datamachine-grid-gap'));
        }

        // Calculate events per row using same logic as CSS grid
        const eventsPerRow = Math.floor((containerWidth + gap) / (cellWidth + gap));

        // Calculate safe padding to prevent border overlap
        // Ensure at least 4px total space between borders (2px per side)
        const padding = Math.min(8, Math.max(2, (gap / 2) - 2));

        return {
            cellWidth,
            gap,
            containerWidth,
            eventsPerRow: Math.max(1, eventsPerRow), // Ensure at least 1
            borderRadius,
            padding
        };
    }

    /**
     * Calculate grid position for event element within calendar layout
     * 
     * @param {HTMLElement} event Event DOM element
     * @param {Object} gridSettings Grid configuration from getActiveGridSettings
     * @returns {Object} Position data with column, row, left, top, width, height
     */
    calculateEventGridPosition(event, gridSettings) {
        const rect = event.getBoundingClientRect();
        const contentRect = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect();
        
        // Get relative position within content area
        const relativeLeft = rect.left - contentRect.left;
        const relativeTop = rect.top - contentRect.top;
        
        // Calculate grid position based on cell dimensions
        const { cellWidth, gap } = gridSettings;
        const cellWithGap = cellWidth + gap;
        
        const gridColumn = Math.floor((relativeLeft + gap/2) / cellWithGap);
        const gridRow = Math.floor((relativeTop + gap/2) / (180 + gap)); // Using fixed height from CSS
        
        return {
            column: gridColumn,
            row: gridRow,
            left: relativeLeft,
            top: relativeTop,
            width: rect.width,
            height: rect.height
        };
    }

    /**
     * Generate optimal border shape based on event arrangement and grid constraints
     * 
     * Analyzes event count and positioning to determine:
     * - Single event: simple rectangle
     * - Horizontal line: events in one row
     * - Perfect rectangle: complete rows
     * - Inverted L-shape: wrapped events with partial final row
     * 
     * @param {HTMLElement[]} events Array of event DOM elements to group
     * @returns {Object|null} Shape definition for SVG rendering or null if no events
     */
    generateShapeForEvents(events) {
        if (!events || events.length === 0) return null;

        const gridSettings = this.getActiveGridSettings();
        const eventCount = events.length;
        const eventsPerRow = gridSettings.eventsPerRow;

        // Single event
        if (eventCount === 1) {
            return this.generateSingleEventShape(events[0]);
        }

        // Check actual row span first - don't assume based on count
        const eventPositions = events.map(event => this.calculateEventGridPosition(event, gridSettings));
        const rowsSpanned = new Set(eventPositions.map(pos => pos.row)).size;

        if (rowsSpanned === 1) {
            // Actually on one row
            return this.drawHorizontalLines(events);
        } else {
            // Multiple rows - check patterns
            if (this.detectSplitEvents(events, eventsPerRow)) {
                return this.drawSplitGroups(events, eventsPerRow);
            }
            
            // Standard multi-row patterns
            const completeRows = Math.floor(eventCount / eventsPerRow);
            const remainingEvents = eventCount % eventsPerRow;
            
            if (remainingEvents === 0) {
                // Perfect rectangle - all rows complete
                return this.generateRectangleShape(events, eventsPerRow);
            } else {
                // L-shaped cutouts
                return this.drawCutouts(events, eventsPerRow);
            }
        }
    }

    /**
     * Generate single event shape with badge gap
     */
    generateSingleEventShape(event) {
        const gridSettings = this.getActiveGridSettings();
        const padding = gridSettings.padding;
        const rect = event.getBoundingClientRect();
        const contentRect = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect();
        
        const bounds = {
            left: rect.left - contentRect.left - padding,
            top: rect.top - contentRect.top - padding,
            width: rect.width + (padding * 2),
            height: rect.height + (padding * 2)
        };
        
        return this.createBorderPathWithGap(bounds, gridSettings.borderRadius);
    }

    /**
     * Generate path with gap for badge on top border
     * 
     * @param {HTMLElement[]} events Array of event elements in horizontal line
     * @param {number} padding Border padding in pixels (unused, uses gridSettings)
     * @returns {Object} Path shape definition with badge gap
     */
    drawHorizontalLines(events) {
        const gridSettings = this.getActiveGridSettings();
        const padding = gridSettings.padding;
        let minLeft = Infinity;
        let minTop = Infinity;
        let maxRight = -Infinity;
        let maxBottom = -Infinity;
        
        const contentRect = this.calendar.querySelector('.datamachine-events-content').getBoundingClientRect();
        
        events.forEach(event => {
            const rect = event.getBoundingClientRect();
            const left = rect.left - contentRect.left;
            const top = rect.top - contentRect.top;
            const right = left + rect.width;
            const bottom = top + rect.height;

            minLeft = Math.min(minLeft, left);
            minTop = Math.min(minTop, top);
            maxRight = Math.max(maxRight, right);
            maxBottom = Math.max(maxBottom, bottom);
        });

        const bounds = {
            left: minLeft - padding,
            top: minTop - padding,
            width: maxRight - minLeft + (2 * padding),
            height: maxBottom - minTop + (2 * padding)
        };

        return this.createBorderPathWithGap(bounds, gridSettings.borderRadius);
    }

    /**
     * Create border path with horizontal gap on top border for badge
     * 
     * @param {Object} bounds Bounding box with left, top, width, height
     * @param {number} borderRadius Corner radius for rounded corners
     * @returns {Object} Path shape definition with badge gap
     */
    createBorderPathWithGap(bounds, borderRadius = 8) {
        const curves = this.drawCurves();
        const styles = getComputedStyle(document.documentElement);
        const offsetX = parseInt(styles.getPropertyValue('--datamachine-badge-offset-x')) || 12;
        const badgeGapWidth = 140; // Width of gap for badge
        const gapStart = bounds.left + offsetX;
        const gapEnd = gapStart + badgeGapWidth;
        
        // Create path with gap in top border for badge
        const path = [
            // Start at top-left corner (after radius)
            `M ${bounds.left + borderRadius} ${bounds.top}`,
            
            // Top border left side (up to gap)
            `L ${gapStart} ${bounds.top}`,
            
            // Move to right side of gap (skip the gap where badge sits)
            `M ${gapEnd} ${bounds.top}`,
            
            // Top border right side (from gap to corner)
            `L ${bounds.left + bounds.width - borderRadius} ${bounds.top}`,
            curves.externalTopRight(bounds.left + bounds.width, bounds.top + borderRadius),
            
            // Right border
            `L ${bounds.left + bounds.width} ${bounds.top + bounds.height - borderRadius}`,
            curves.externalBottomRight(bounds.left + bounds.width - borderRadius, bounds.top + bounds.height),
            
            // Bottom border
            `L ${bounds.left + borderRadius} ${bounds.top + bounds.height}`,
            curves.externalBottomLeft(bounds.left, bounds.top + bounds.height - borderRadius),
            
            // Left border
            `L ${bounds.left} ${bounds.top + borderRadius}`,
            curves.externalTopLeft(bounds.left + borderRadius, bounds.top)
        ];
        
        return {
            type: 'path',
            path: path.join(' '),
            bounds: bounds
        };
    }

    /**
     * Detect if events are split across rows (same day on opposite sides of calendar)
     * 
     * @param {HTMLElement[]} events Array of event elements to analyze
     * @param {number} eventsPerRow Maximum events per row from grid calculation
     * @returns {boolean} True if events form a split pattern requiring connector line
     */
    detectSplitEvents(events, eventsPerRow) {
        if (events.length < 2) return false;
        
        const gridSettings = this.getActiveGridSettings();
        const eventPositions = events.map(event => this.calculateEventGridPosition(event, gridSettings));
        
        // Group events by row
        const eventsByRow = {};
        eventPositions.forEach(pos => {
            if (!eventsByRow[pos.row]) {
                eventsByRow[pos.row] = [];
            }
            eventsByRow[pos.row].push(pos);
        });
        
        const rows = Object.keys(eventsByRow).map(Number).sort((a, b) => a - b);
        
        // Check 2-row patterns for split events
        if (rows.length === 2) {
            const firstRowEvents = eventsByRow[rows[0]];
            const lastRowEvents = eventsByRow[rows[1]];
            
            // Get column positions for each row
            const firstRowColumns = firstRowEvents.map(e => e.column);
            const lastRowColumns = lastRowEvents.map(e => e.column);
            
            // Check for vertical adjacency (column overlap)
            const hasVerticalOverlap = firstRowColumns.some(col => 
                lastRowColumns.includes(col)
            );
            
            // If NO vertical overlap, these events need a horizontal connector
            if (!hasVerticalOverlap) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate split event borders with horizontal connector line
     * 
     * Connects events from the same day that are on opposite sides of the calendar
     * with a horizontal line running between rows to avoid overlap with other borders.
     * 
     * @param {HTMLElement[]} events Array of split event elements
     * @param {number} eventsPerRow Maximum events per row from grid calculation
     * @param {number} padding Border padding in pixels (unused, uses gridSettings)
     * @returns {Object} Path shape definition with connector line
     */
    drawSplitGroups(events, eventsPerRow) {
        const gridSettings = this.getActiveGridSettings();
        const padding = gridSettings.padding;
        const eventPositions = events.map(event => this.calculateEventGridPosition(event, gridSettings));
        const curves = this.drawCurves();
        
        // Group events by row
        const eventsByRow = {};
        eventPositions.forEach(pos => {
            if (!eventsByRow[pos.row]) {
                eventsByRow[pos.row] = [];
            }
            eventsByRow[pos.row].push(pos);
        });
        
        const rows = Object.keys(eventsByRow).map(Number).sort((a, b) => a - b);
        const firstRowEvents = eventsByRow[rows[0]];
        const lastRowEvents = eventsByRow[rows[rows.length - 1]];
        
        // Calculate bounds for top and bottom groups
        const topBounds = this.calculateGroupBounds(firstRowEvents.map(pos => {
            // Convert position back to element for bounds calculation
            return events.find(event => {
                const eventPos = this.calculateEventGridPosition(event, gridSettings);
                return eventPos.row === pos.row && eventPos.column === pos.column;
            });
        }).filter(Boolean));
        
        const bottomBounds = this.calculateGroupBounds(lastRowEvents.map(pos => {
            return events.find(event => {
                const eventPos = this.calculateEventGridPosition(event, gridSettings);
                return eventPos.row === pos.row && eventPos.column === pos.column;
            });
        }).filter(Boolean));
        
        if (!topBounds || !bottomBounds) return null;
        
        // Create combined path with connector line
        const path = [];
        
        // Top group border (rounded rectangle)
        path.push(
            `M ${topBounds.left + padding} ${topBounds.top}`,
            `L ${topBounds.left + topBounds.width - padding} ${topBounds.top}`,
            curves.externalTopRight(topBounds.left + topBounds.width, topBounds.top + padding),
            `L ${topBounds.left + topBounds.width} ${topBounds.top + topBounds.height - padding}`,
            curves.externalBottomRight(topBounds.left + topBounds.width - padding, topBounds.top + topBounds.height),
            `L ${topBounds.left + padding} ${topBounds.top + topBounds.height}`,
            curves.externalBottomLeft(topBounds.left, topBounds.top + topBounds.height - padding),
            `L ${topBounds.left} ${topBounds.top + padding}`,
            curves.externalTopLeft(topBounds.left + padding, topBounds.top)
        );
        
        // Horizontal connector line with angled ends to run in middle of gap
        // Connect directly to corner centers of rounded borders
        const cornerRadius = 8;
        const angleOffset = 10; // How far to angle outward for middle gap positioning
        const gapMiddleY = (topBounds.top + topBounds.height + bottomBounds.top) / 2;
        
        // Connect to corner centers (no overlap extension needed)
        const topCornerX = topBounds.left + cornerRadius;
        const bottomCornerX = bottomBounds.left + bottomBounds.width - cornerRadius;
        
        path.push(
            // Start from bottom corner center of top group
            `M ${topCornerX} ${topBounds.top + topBounds.height}`,
            // Angle outward to middle of gap
            `L ${topCornerX - angleOffset} ${gapMiddleY}`,
            // Horizontal line across middle of gap
            `L ${bottomCornerX + angleOffset} ${gapMiddleY}`,
            // Angle inward to connect to top corner center of bottom group
            `L ${bottomCornerX} ${bottomBounds.top}`
        );
        
        // Bottom group border (rounded rectangle)
        path.push(
            `M ${bottomBounds.left + padding} ${bottomBounds.top}`,
            `L ${bottomBounds.left + bottomBounds.width - padding} ${bottomBounds.top}`,
            curves.externalTopRight(bottomBounds.left + bottomBounds.width, bottomBounds.top + padding),
            `L ${bottomBounds.left + bottomBounds.width} ${bottomBounds.top + bottomBounds.height - padding}`,
            curves.externalBottomRight(bottomBounds.left + bottomBounds.width - padding, bottomBounds.top + bottomBounds.height),
            `L ${bottomBounds.left + padding} ${bottomBounds.top + bottomBounds.height}`,
            curves.externalBottomLeft(bottomBounds.left, bottomBounds.top + bottomBounds.height - padding),
            `L ${bottomBounds.left} ${bottomBounds.top + padding}`,
            curves.externalTopLeft(bottomBounds.left + padding, bottomBounds.top)
        );
        
        return {
            type: 'path',
            path: path.join(' '),
            bounds: {
                left: Math.min(topBounds.left, bottomBounds.left),
                top: topBounds.top,
                width: Math.max(topBounds.left + topBounds.width, bottomBounds.left + bottomBounds.width) - Math.min(topBounds.left, bottomBounds.left),
                height: (bottomBounds.top + bottomBounds.height) - topBounds.top
            }
        };
    }

    /**
     * Generate L-shaped cutout borders for events that wrap across rows
     * 
     * Separates coordinate calculation from arc generation for easier debugging.
     * Uses drawCurves() factory for consistent corner rounding.
     * 
     * @param {HTMLElement[]} events Event elements to encompass
     * @param {number} eventsPerRow Maximum events per row from grid calculation
     * @param {number} padding Border padding in pixels (unused, uses gridSettings)
     * @returns {Object} Path shape definition with SVG path string and bounds
     */
    drawCutouts(events, eventsPerRow) {
        const gridSettings = this.getActiveGridSettings();
        const padding = gridSettings.padding;
        const eventPositions = events.map(event => this.calculateEventGridPosition(event, gridSettings));
        const curves = this.drawCurves();
        
        // Group events by row
        const eventsByRow = {};
        eventPositions.forEach(pos => {
            if (!eventsByRow[pos.row]) {
                eventsByRow[pos.row] = [];
            }
            eventsByRow[pos.row].push(pos);
        });
        
        const rows = Object.keys(eventsByRow).map(Number).sort((a, b) => a - b);
        const firstRow = eventsByRow[rows[0]];
        const lastRow = eventsByRow[rows[rows.length - 1]];
        
        // Calculate L-shape coordinate boundaries
        const coords = {
            topRowLeft: Math.min(...firstRow.map(pos => pos.left)),
            topRowRight: Math.max(...firstRow.map(pos => pos.left + pos.width)),
            topRowTop: Math.min(...firstRow.map(pos => pos.top)),
            topRowBottom: Math.max(...firstRow.map(pos => pos.top + pos.height)),
            
            bottomRowLeft: Math.min(...lastRow.map(pos => pos.left)),
            bottomRowRight: Math.max(...lastRow.map(pos => pos.left + pos.width)),
            bottomRowTop: Math.min(...lastRow.map(pos => pos.top)),
            bottomRowBottom: Math.max(...lastRow.map(pos => pos.top + pos.height))
        };
        
        // Build L-shaped path using coordinate boundaries and curve factory
        const cornerRadius = 8;
        const path = [
            // Start at top-left with radius offset
            `M ${coords.topRowLeft - padding + cornerRadius} ${coords.topRowTop - padding}`,
            
            // Top edge to top-right corner
            `L ${coords.topRowRight + padding - cornerRadius} ${coords.topRowTop - padding}`,
            curves.externalTopRight(coords.topRowRight + padding, coords.topRowTop - padding + cornerRadius),
            
            // Right edge down to junction corner
            `L ${coords.topRowRight + padding} ${coords.topRowBottom + padding - cornerRadius}`,
            curves.externalBottomRight(coords.topRowRight + padding - cornerRadius, coords.topRowBottom + padding),
            
            // Horizontal line across to cutout corner start
            `L ${coords.bottomRowRight + padding + cornerRadius} ${coords.topRowBottom + padding}`,
            
            // Arc from horizontal (going left) to vertical (going down) - creates proper top-left corner
            `A 8 8 0 0 0 ${coords.bottomRowRight + padding} ${coords.topRowBottom + padding + cornerRadius}`,
            
            // Down to bottom-right corner
            `L ${coords.bottomRowRight + padding} ${coords.bottomRowBottom + padding - cornerRadius}`,
            curves.externalBottomRight(coords.bottomRowRight + padding - cornerRadius, coords.bottomRowBottom + padding),
            
            // Bottom edge left
            `L ${coords.bottomRowLeft - padding + cornerRadius} ${coords.bottomRowBottom + padding}`,
            curves.externalBottomLeft(coords.bottomRowLeft - padding, coords.bottomRowBottom + padding - cornerRadius),
            
            // Up left side to junction
            `L ${coords.bottomRowLeft - padding} ${coords.topRowBottom + padding}`,
            `L ${coords.topRowLeft - padding} ${coords.topRowBottom + padding}`,
            
            // Up to top-left corner
            `L ${coords.topRowLeft - padding} ${coords.topRowTop - padding + cornerRadius}`,
            curves.externalTopLeft(coords.topRowLeft - padding + cornerRadius, coords.topRowTop - padding)
        ].join(' ');
        
        return {
            type: 'path',
            path: path,
            bounds: {
                left: Math.min(coords.topRowLeft, coords.bottomRowLeft) - padding,
                top: coords.topRowTop - padding,
                width: Math.max(coords.topRowRight, coords.bottomRowRight) - Math.min(coords.topRowLeft, coords.bottomRowLeft) + 2 * padding,
                height: coords.bottomRowBottom - coords.topRowTop + 2 * padding
            }
        };
    }

    /**
     * Generate rectangle shape for complete rows with badge gap
     */
    generateRectangleShape(events, eventsPerRow) {
        const gridSettings = this.getActiveGridSettings();
        const padding = gridSettings.padding;
        const eventPositions = events.map(event => this.calculateEventGridPosition(event, gridSettings));
        
        // Find the bounds of all events
        const left = Math.min(...eventPositions.map(pos => pos.left));
        const right = Math.max(...eventPositions.map(pos => pos.left + pos.width));
        const top = Math.min(...eventPositions.map(pos => pos.top));
        const bottom = Math.max(...eventPositions.map(pos => pos.top + pos.height));
        
        const bounds = {
            left: left - padding,
            top: top - padding,
            width: right - left + 2 * padding,
            height: bottom - top + 2 * padding
        };
        
        return this.createBorderPathWithGap(bounds, gridSettings.borderRadius);
    }

    /**
     * Centralized arc generation factory for consistent corner rounding
     * 
     * @returns {Object} Arc generator functions for different corner types
     */
    drawCurves() {
        const radius = 8;
        
        return {
            // External corners (convex) - sweep = 1 for clockwise rounding
            externalTopRight: (x, y) => `A ${radius} ${radius} 0 0 1 ${x} ${y}`,
            externalTopLeft: (x, y) => `A ${radius} ${radius} 0 0 1 ${x} ${y}`,
            externalBottomRight: (x, y) => `A ${radius} ${radius} 0 0 1 ${x} ${y}`,
            externalBottomLeft: (x, y) => `A ${radius} ${radius} 0 0 1 ${x} ${y}`,
            
            // Internal cutouts (concave) - sweep = 0 for counter-clockwise cutouts
            internalTopRight: (x, y) => `A ${radius} ${radius} 0 0 0 ${x} ${y}`,
            internalTopLeft: (x, y) => `A ${radius} ${radius} 0 0 0 ${x} ${y}`,
            internalBottomRight: (x, y) => `A ${radius} ${radius} 0 0 0 ${x} ${y}`,
            internalBottomLeft: (x, y) => `A ${radius} ${radius} 0 0 0 ${x} ${y}`
        };
    }

    /**
     * Render borders for all visible day groups
     */
    renderAllBorders() {
        if (!this.svgContainer) return;

        // Clear existing borders
        this.clearBorders();

        // Detect day groups and render borders
        const dayGroups = this.detectDayGroups();

        // dayGroups is an array of groupData objects
        dayGroups.forEach((groupData) => {
            const shape = this.generateShapeForEvents(groupData.events);
            if (shape) {
                // Pass dayName and stable dateKey so renderGroupBorder creates one border per date
                this.renderGroupBorder(groupData.dayName, shape, groupData.dateKey);
            }
        });
    }

    /**
     * Clear all borders
     */
    clearBorders() {
        if (this.svgContainer) {
            this.svgContainer.innerHTML = '';
        }
        this.borders.clear();
    }

    /**
     * Refresh borders and badges (called after filtering)
     */
    refresh() {
        // Small delay to allow DOM updates from filtering
        setTimeout(() => {
            this.renderAllBorders();
            // Refresh badge renderer for circuit grid
            if (this.badgeRenderer) {
                this.badgeRenderer.refresh();
            }
        }, 50);
    }

    /**
     * Cleanup
     */
    cleanup() {
        this.clearBorders();

        // Cleanup badge renderer
        if (this.badgeRenderer) {
            this.badgeRenderer.cleanup();
            this.badgeRenderer = null;
        }

        // Disconnect ResizeObserver
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }

        // Clear debounce timer
        clearTimeout(this.resizeDebounceTimer);
        
        // Don't remove the container since it's part of PHP template
        this.svgContainer = null;
        this.borders.clear();
    }
}