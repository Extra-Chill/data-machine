/**
 * Carousel overflow detection, indicators, and chevron navigation.
 */

const observers = new Map();
const MAX_MOBILE_DOTS = 12;

function updateCollapsedDots(dots, activeIndex, totalEvents) {
    const visibleDots = MAX_MOBILE_DOTS;
    const halfWindow = Math.floor(visibleDots / 2);
    
    let windowStart = activeIndex - halfWindow;
    let windowEnd = activeIndex + halfWindow;
    
    if (windowStart < 0) {
        windowStart = 0;
        windowEnd = visibleDots - 1;
    }
    if (windowEnd >= totalEvents) {
        windowEnd = totalEvents - 1;
        windowStart = Math.max(0, totalEvents - visibleDots);
    }
    
    dots.forEach(function(dot, dotIndex) {
        if (dotIndex >= visibleDots) {
            dot.style.display = 'none';
            return;
        }
        
        dot.style.display = '';
        const eventIndex = windowStart + dotIndex;
        const distanceFromActive = Math.abs(eventIndex - activeIndex);
        
        dot.classList.remove('active', 'dot-small', 'dot-medium');
        
        if (distanceFromActive === 0) {
            dot.classList.add('active');
        } else if (distanceFromActive === 1) {
            dot.classList.add('dot-medium');
        } else {
            dot.classList.add('dot-small');
        }
    });
}

export function initCarousel(calendar) {
    const groups = calendar.querySelectorAll('.datamachine-date-group');

    groups.forEach(function(group) {
        const wrapper = group.querySelector('.datamachine-events-wrapper');
        if (!wrapper) return;

        const eventCount = parseInt(group.dataset.eventCount, 10) || 0;
        if (eventCount <= 1) return;

        const events = wrapper.querySelectorAll('.datamachine-event-item');

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
                const totalEvents = events.length;
                const useCollapsed = totalEvents > MAX_MOBILE_DOTS;
                
                let activeIndex = 0;
                let maxVisibleArea = 0;

                events.forEach(function(event, index) {
                    const eventRect = event.getBoundingClientRect();
                    const visibleLeft = Math.max(eventRect.left, wrapperRect.left);
                    const visibleRight = Math.min(eventRect.right, wrapperRect.right);
                    const visibleWidth = Math.max(0, visibleRight - visibleLeft);
                    
                    if (visibleWidth > maxVisibleArea) {
                        maxVisibleArea = visibleWidth;
                        activeIndex = index;
                    }
                });

                if (useCollapsed) {
                    updateCollapsedDots(dots, activeIndex, totalEvents);
                } else {
                    dots.forEach(function(dot, index) {
                        dot.style.display = '';
                        dot.classList.remove('dot-small', 'dot-medium');
                        dot.classList.toggle('active', index === activeIndex);
                    });
                }
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

            for (let i = 0; i < eventCount; i++) {
                const dot = document.createElement('span');
                dot.className = 'datamachine-carousel-dot';
                dot.dataset.index = i;
                indicators.appendChild(dot);
            }

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
