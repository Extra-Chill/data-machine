/**
 * Carousel overflow detection, indicators, and chevron navigation.
 */

const observers = new Map();

export function initCarousel(calendar) {
    const groups = calendar.querySelectorAll('.datamachine-date-group');

    groups.forEach(function(group) {
        const wrapper = group.querySelector('.datamachine-events-wrapper');
        if (!wrapper) return;

        const events = wrapper.querySelectorAll('.datamachine-event-item');
        if (events.length <= 1) return;

        let indicators = null;
        let chevronLeft = null;
        let chevronRight = null;
        let scrollHandler = null;

        const updateIndicators = function() {
            if (!indicators) return;

            const wrapperRect = wrapper.getBoundingClientRect();
            const dots = indicators.querySelectorAll('.datamachine-carousel-dot');
            
            // Detect single-card mode (mobile) vs multi-card mode (desktop)
            const firstEventWidth = events[0]?.getBoundingClientRect().width || 0;
            const isSingleCardMode = firstEventWidth > 0 && (wrapperRect.width / firstEventWidth) < 1.5;

            if (isSingleCardMode) {
                // Mobile: "most visible card wins" - only one dot active
                let maxVisibleIndex = 0;
                let maxVisibleArea = 0;

                events.forEach(function(event, index) {
                    const eventRect = event.getBoundingClientRect();
                    const visibleLeft = Math.max(eventRect.left, wrapperRect.left);
                    const visibleRight = Math.min(eventRect.right, wrapperRect.right);
                    const visibleWidth = Math.max(0, visibleRight - visibleLeft);
                    
                    if (visibleWidth > maxVisibleArea) {
                        maxVisibleArea = visibleWidth;
                        maxVisibleIndex = index;
                    }
                });

                dots.forEach(function(dot, index) {
                    dot.classList.toggle('active', index === maxVisibleIndex);
                });
            } else {
                // Desktop: use scroll position to determine active dot range
                const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
                const scrollProgress = maxScroll > 0 ? wrapper.scrollLeft / maxScroll : 0;
                const visibleCards = Math.floor(wrapperRect.width / firstEventWidth);
                const totalCards = events.length;
                const scrollableCards = totalCards - visibleCards;

                const firstActiveIndex = Math.round(scrollProgress * scrollableCards);

                dots.forEach(function(dot, index) {
                    const isInVisibleRange = index >= firstActiveIndex && index < firstActiveIndex + visibleCards;
                    dot.classList.toggle('active', isInVisibleRange);
                });
            }

            const atStart = wrapper.scrollLeft <= 5;
            const atEnd = wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - 5;
            chevronLeft.classList.toggle('hidden', atStart);
            chevronRight.classList.toggle('hidden', atEnd);
        };

        const setupIndicators = function() {
            const hasOverflow = wrapper.scrollWidth > wrapper.clientWidth;

            indicators = group.querySelector('.datamachine-carousel-indicators');
            chevronLeft = group.querySelector('.datamachine-carousel-chevron-left');
            chevronRight = group.querySelector('.datamachine-carousel-chevron-right');

            if (!hasOverflow) {
                if (indicators) indicators.remove();
                if (chevronLeft) chevronLeft.remove();
                if (chevronRight) chevronRight.remove();
                indicators = null;
                chevronLeft = null;
                chevronRight = null;
                if (scrollHandler) {
                    wrapper.removeEventListener('scroll', scrollHandler);
                    scrollHandler = null;
                }
                return;
            }

            if (!indicators) {
                indicators = document.createElement('div');
                indicators.className = 'datamachine-carousel-indicators';
                group.appendChild(indicators);
            }
            indicators.innerHTML = '';

            events.forEach(function(_, index) {
                const dot = document.createElement('span');
                dot.className = 'datamachine-carousel-dot';
                dot.dataset.index = index;
                indicators.appendChild(dot);
            });

            if (!chevronLeft) {
                chevronLeft = document.createElement('span');
                chevronLeft.className = 'datamachine-carousel-chevron datamachine-carousel-chevron-left';
                chevronLeft.textContent = '‹';
                group.appendChild(chevronLeft);
            }

            if (!chevronRight) {
                chevronRight = document.createElement('span');
                chevronRight.className = 'datamachine-carousel-chevron datamachine-carousel-chevron-right';
                chevronRight.textContent = '›';
                group.appendChild(chevronRight);
            }

            // Chevron click/hold navigation
            const firstEventWidth = events[0]?.getBoundingClientRect().width || 300;
            let holdInterval = null;

            const scrollByCard = function(direction) {
                wrapper.scrollBy({ left: firstEventWidth * direction, behavior: 'smooth' });
            };

            const startHold = function(direction) {
                scrollByCard(direction);
                holdInterval = setInterval(function() {
                    scrollByCard(direction);
                }, 300);
            };

            const stopHold = function() {
                if (holdInterval) {
                    clearInterval(holdInterval);
                    holdInterval = null;
                }
            };

            // Click handlers
            chevronLeft.addEventListener('click', function(e) {
                e.preventDefault();
                if (!holdInterval) scrollByCard(-1);
            });
            chevronRight.addEventListener('click', function(e) {
                e.preventDefault();
                if (!holdInterval) scrollByCard(1);
            });

            // Hold handlers (mouse)
            chevronLeft.addEventListener('mousedown', function() { startHold(-1); });
            chevronRight.addEventListener('mousedown', function() { startHold(1); });

            // Hold handlers (touch)
            chevronLeft.addEventListener('touchstart', function(e) { e.preventDefault(); startHold(-1); }, { passive: false });
            chevronRight.addEventListener('touchstart', function(e) { e.preventDefault(); startHold(1); }, { passive: false });

            // Stop handlers
            ['mouseup', 'mouseleave'].forEach(function(event) {
                chevronLeft.addEventListener(event, stopHold);
                chevronRight.addEventListener(event, stopHold);
            });
            ['touchend', 'touchcancel'].forEach(function(event) {
                chevronLeft.addEventListener(event, stopHold);
                chevronRight.addEventListener(event, stopHold);
            });

            if (!scrollHandler) {
                scrollHandler = updateIndicators;
                wrapper.addEventListener('scroll', scrollHandler);
            }

            updateIndicators();
        };

        requestAnimationFrame(setupIndicators);

        if (typeof ResizeObserver !== 'undefined') {
            const observer = new ResizeObserver(function() {
                requestAnimationFrame(setupIndicators);
            });
            observer.observe(wrapper);
            
            const existing = observers.get(calendar) || [];
            existing.push({ observer, wrapper });
            observers.set(calendar, existing);
        }
    });
}

export function destroyCarousel(calendar) {
    const entries = observers.get(calendar);
    if (entries) {
        entries.forEach(function({ observer, wrapper }) {
            observer.unobserve(wrapper);
            observer.disconnect();
        });
        observers.delete(calendar);
    }

    const indicators = calendar.querySelectorAll('.datamachine-carousel-indicators');
    const chevrons = calendar.querySelectorAll('.datamachine-carousel-chevron');
    
    indicators.forEach(el => el.remove());
    chevrons.forEach(el => el.remove());
}
