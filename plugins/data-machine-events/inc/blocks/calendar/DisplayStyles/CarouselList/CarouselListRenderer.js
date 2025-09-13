/**
 * CarouselListRenderer - Carousel List Display Module
 *
 * Enhances horizontal scrolling for carousel list display using universal template classes.
 * Works with the existing template system - no custom markup required.
 */
export class CarouselListRenderer {
    constructor(calendarElement) {
        this.calendar = calendarElement;
        this.carouselRows = new Map(); // Track carousel row instances
        this.resizeObserver = null;

        this.init();
    }

    /**
     * Initialize the carousel list renderer
     */
    init() {
        // Only initialize if we're in carousel mode
        if (!this.isCarouselMode()) return;

        this.initializeCarouselRows();
        this.setupResizeObserver();
        this.initializeCarouselEvents();
    }

    /**
     * Check if we're in carousel display mode
     */
    isCarouselMode() {
        // Check if carousel CSS is loaded by looking for a carousel-specific style
        const testElement = document.createElement('div');
        testElement.className = 'dm-events-calendar';
        testElement.style.display = 'none';
        document.body.appendChild(testElement);

        const dateGroup = document.createElement('div');
        dateGroup.className = 'dm-date-group';
        testElement.appendChild(dateGroup);

        const computedStyle = window.getComputedStyle(dateGroup);
        const isCarousel = computedStyle.flexDirection === 'row';

        document.body.removeChild(testElement);
        return isCarousel;
    }

    /**
     * Initialize all carousel rows (universal template date groups)
     */
    initializeCarouselRows() {
        const dateGroups = this.calendar.querySelectorAll('.dm-date-group');

        dateGroups.forEach(dateGroup => {
            this.initializeCarouselRow(dateGroup);
        });
    }

    /**
     * Initialize carousel-specific event handlers
     */
    initializeCarouselEvents() {
        // Enhanced hover effects for carousel cards
        const eventCards = this.calendar.querySelectorAll('.dm-event-link');

        eventCards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                this.highlightCarouselCard(card);
            });

            card.addEventListener('mouseleave', () => {
                this.resetCarouselHighlight(card);
            });
        });
    }

    /**
     * Initialize a single carousel row (date group) with enhanced scrolling
     */
    initializeCarouselRow(dateGroup) {
        // The date group itself is now the scrolling container
        const eventsRow = dateGroup;

        if (!eventsRow) return;

        const rowInstance = {
            eventsRow: dateGroup,
            dateGroup
        };

        // Store reference to this row
        const dateKey = dateGroup.getAttribute('data-date') || Math.random().toString();
        this.carouselRows.set(dateKey, rowInstance);

        // Touch gesture support for mobile
        this.setupTouchGestures(eventsRow);

        // Keyboard navigation
        eventsRow.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                e.preventDefault();
                this.scrollCarousel(eventsRow, 'prev');
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                this.scrollCarousel(eventsRow, 'next');
            }
        });

        // Enhanced mouse wheel horizontal scrolling
        eventsRow.addEventListener('wheel', (e) => {
            if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
                // Horizontal wheel movement - let it scroll naturally
                return;
            } else if (e.shiftKey) {
                // Shift + vertical wheel = horizontal scroll
                e.preventDefault();
                eventsRow.scrollLeft += e.deltaY;
            }
        }, { passive: false });

        // Add focus support for keyboard navigation
        eventsRow.setAttribute('tabindex', '0');
        eventsRow.setAttribute('role', 'region');
        eventsRow.setAttribute('aria-label', 'Events carousel - use arrow keys to scroll');

        // Add scroll indicators when there's overflow
        this.addScrollIndicators(eventsRow);
    }

    /**
     * Scroll carousel by one card width
     */
    scrollCarousel(eventsRow, direction) {
        const cardWidth = 280; // Match CSS flex-basis
        const gap = 24; // Match CSS gap (1.5rem = 24px)
        const scrollAmount = direction === 'next' ? cardWidth + gap : -(cardWidth + gap);

        eventsRow.scrollBy({
            left: scrollAmount,
            behavior: 'smooth'
        });
    }

    /**
     * Add visual scroll indicators
     */
    addScrollIndicators(eventsRow) {
        // Add gradient indicators for scrollable content
        const updateScrollIndicators = () => {
            const scrollLeft = eventsRow.scrollLeft;
            const maxScroll = eventsRow.scrollWidth - eventsRow.clientWidth;
            const threshold = 10; // Increased threshold for better responsiveness
            const hasLeftScroll = scrollLeft > threshold;
            const hasRightScroll = scrollLeft < maxScroll - threshold;

            // Add data attributes for CSS styling
            eventsRow.setAttribute('data-scroll-left', hasLeftScroll);
            eventsRow.setAttribute('data-scroll-right', hasRightScroll);

            // Add CSS classes for better performance
            eventsRow.classList.toggle('dm-scroll-left-active', hasLeftScroll);
            eventsRow.classList.toggle('dm-scroll-right-active', hasRightScroll);
        };

        // Store the update function for external access
        eventsRow.updateScrollIndicators = updateScrollIndicators;

        // Update on scroll (debounced for performance)
        let scrollTimeout;
        eventsRow.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(updateScrollIndicators, 16); // ~60fps
        });

        // Initial state with multiple timing checks
        setTimeout(updateScrollIndicators, 50);  // Quick check
        setTimeout(updateScrollIndicators, 200); // Layout complete check

        // Update on resize
        if (typeof ResizeObserver !== 'undefined') {
            const resizeObserver = new ResizeObserver(() => {
                // Small delay to allow layout adjustments
                setTimeout(updateScrollIndicators, 50);
            });
            resizeObserver.observe(eventsRow);

            // Store observer for cleanup
            const rowInstance = this.carouselRows.get(eventsRow.getAttribute('data-date'));
            if (rowInstance) {
                rowInstance.resizeObserver = resizeObserver;
            }
        }

        // Also observe the container for size changes
        if (typeof ResizeObserver !== 'undefined') {
            const containerObserver = new ResizeObserver(() => {
                setTimeout(updateScrollIndicators, 50);
            });
            containerObserver.observe(eventsRow.parentElement);

            // Store for cleanup
            const rowInstance = this.carouselRows.get(eventsRow.getAttribute('data-date'));
            if (rowInstance) {
                rowInstance.containerObserver = containerObserver;
            }
        }
    }

    /**
     * Setup touch gestures for horizontal scrolling
     */
    setupTouchGestures(eventsRow) {
        let startX = 0;
        let scrollStart = 0;
        let isDragging = false;
        let startTime = 0;
        let lastMoveX = 0;
        
        eventsRow.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            scrollStart = eventsRow.scrollLeft;
            startTime = Date.now();
            lastMoveX = startX;
            isDragging = true;
            
            // Add momentum tracking
            eventsRow.style.scrollBehavior = 'auto';
        }, { passive: true });
        
        eventsRow.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            
            const currentX = e.touches[0].clientX;
            const deltaX = startX - currentX;
            eventsRow.scrollLeft = scrollStart + deltaX;
            
            lastMoveX = currentX;
        }, { passive: true });
        
        eventsRow.addEventListener('touchend', (e) => {
            if (!isDragging) return;
            
            isDragging = false;
            
            // Restore smooth scrolling
            eventsRow.style.scrollBehavior = 'smooth';
            
            // Add momentum scrolling for fast swipes
            const endTime = Date.now();
            const timeElapsed = endTime - startTime;
            const distanceMoved = lastMoveX - startX;
            
            if (timeElapsed < 300 && Math.abs(distanceMoved) > 50) {
                // Fast swipe detected, add momentum
                const velocity = distanceMoved / timeElapsed;
                const momentum = velocity * 200; // Adjust multiplier for feel
                
                eventsRow.scrollBy({
                    left: -momentum,
                    behavior: 'smooth'
                });
            }
        }, { passive: true });
    }

    /**
     * Highlight carousel card on hover
     */
    highlightCarouselCard(card) {
        // Add subtle highlight effect
        card.style.transform = 'translateY(-4px)';
        card.style.boxShadow = '0 6px 20px rgba(0, 0, 0, 0.15)';
    }

    /**
     * Reset carousel card highlight
     */
    resetCarouselHighlight(card) {
        // Reset to default hover state
        card.style.transform = '';
        card.style.boxShadow = '';
    }

    /**
     * Setup ResizeObserver for viewport changes
     */
    setupResizeObserver() {
        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                this.updateAllButtonStates();
            });
            
            // Observe the calendar container
            this.resizeObserver.observe(this.calendar);
        }
    }

    /**
     * Update scroll indicators for all carousel rows
     */
    updateAllScrollIndicators() {
        this.carouselRows.forEach((rowInstance) => {
            const { eventsRow } = rowInstance;
            const updateFn = eventsRow.updateScrollIndicators;
            if (typeof updateFn === 'function') {
                updateFn();
            }
        });
    }

    /**
     * Refresh carousel functionality (called after filtering)
     */
    refresh() {
        // Small delay to allow DOM updates from filtering
        setTimeout(() => {
            this.cleanup();
            this.init();
        }, 50);
    }

    /**
     * Scroll to first visible event in each row
     */
    scrollToStart() {
        this.carouselRows.forEach((rowInstance) => {
            const { eventsRow } = rowInstance;
            eventsRow.scrollTo({
                left: 0,
                behavior: 'smooth'
            });
        });
    }

    /**
     * Get carousel statistics for debugging
     */
    getStats() {
        const stats = {
            totalRows: this.carouselRows.size,
            rows: []
        };

        this.carouselRows.forEach((rowInstance, dateKey) => {
            const { eventsRow, dateGroup } = rowInstance;
            const eventCards = eventsRow.querySelectorAll('.dm-event-item');

            stats.rows.push({
                date: dateKey,
                eventCount: eventCards.length,
                scrollWidth: eventsRow.scrollWidth,
                clientWidth: eventsRow.clientWidth,
                isScrollable: eventsRow.scrollWidth > eventsRow.clientWidth,
                hasScrollLeft: eventsRow.getAttribute('data-scroll-left') === 'true',
                hasScrollRight: eventsRow.getAttribute('data-scroll-right') === 'true'
            });
        });

        return stats;
    }

    /**
     * Cleanup
     */
    cleanup() {
        // Cleanup individual row observers
        this.carouselRows.forEach((rowInstance) => {
            if (rowInstance.resizeObserver) {
                rowInstance.resizeObserver.disconnect();
            }
            if (rowInstance.containerObserver) {
                rowInstance.containerObserver.disconnect();
            }
        });

        // Disconnect main ResizeObserver
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }

        // Clear carousel rows
        this.carouselRows.clear();
    }
}