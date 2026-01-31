/**
 * Carousel overflow detection, indicators, and chevron navigation.
 */

const observers = new Map();
const MAX_VISIBLE_DOTS = 12;
const DESKTOP_MAX_VISIBLE_DOTS = 36;
const DOT_WIDTH = 7;
const DOT_GAP = 8;

export function initCarousel(calendar) {
    const groups = calendar.querySelectorAll('.datamachine-date-group');

    groups.forEach(function(group) {
        const wrapper = group.querySelector('.datamachine-events-wrapper');
        if (!wrapper) {return;}

        const eventCount = parseInt(group.dataset.eventCount, 10) || 0;
        if (eventCount <= 1) {return;}

        const events = wrapper.querySelectorAll('.datamachine-event-item');

        let indicators = null;
        let chevronLeft = null;
        let chevronRight = null;
        let scrollHandler = null;

        const updateIndicators = function() {
            if (!indicators) {return;}

            const track = indicators.querySelector('.datamachine-carousel-dots-track');
            const dots = track ? track.querySelectorAll('.datamachine-carousel-dot') : [];
            if (!track || dots.length === 0) {return;}

            const wrapperRect = wrapper.getBoundingClientRect();
            const firstEventWidth = events[0]?.getBoundingClientRect().width || 0;
            const isSingleCardMode = firstEventWidth > 0 && (wrapperRect.width / firstEventWidth) < 1.5;

            if (isSingleCardMode) {
                const totalEvents = events.length;
                const useCollapsed = totalEvents > MAX_VISIBLE_DOTS;
                
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

                dots.forEach(function(dot, index) {
                    dot.classList.toggle('active', index === activeIndex);
                });

                if (useCollapsed) {
                    const halfWindow = Math.floor(MAX_VISIBLE_DOTS / 2);
                    const dotUnit = DOT_WIDTH + DOT_GAP;
                    const totalDotsWidth = totalEvents * DOT_WIDTH + (totalEvents - 1) * DOT_GAP;
                    const visibleWidth = MAX_VISIBLE_DOTS * DOT_WIDTH + (MAX_VISIBLE_DOTS - 1) * DOT_GAP;
                    const maxShift = totalDotsWidth - visibleWidth;
                    
                    let shift;
                    
                    if (activeIndex <= halfWindow) {
                        shift = 0;
                    } else if (activeIndex >= totalEvents - halfWindow) {
                        shift = maxShift;
                    } else {
                        shift = (activeIndex - halfWindow) * dotUnit;
                    }
                    
                    track.style.transform = 'translateX(-' + shift + 'px)';
                } else {
                    track.style.transform = '';
                }
            } else {
                const totalEvents = events.length;
                const useDesktopCollapsed = totalEvents > DESKTOP_MAX_VISIBLE_DOTS;

                const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
                const scrollProgress = maxScroll > 0 ? wrapper.scrollLeft / maxScroll : 0;
                const visibleCards = Math.floor(wrapperRect.width / firstEventWidth);
                const scrollableCards = totalEvents - visibleCards;

                const firstActiveIndex = Math.round(scrollProgress * scrollableCards);

                dots.forEach(function(dot, index) {
                    const isInVisibleRange = index >= firstActiveIndex && index < firstActiveIndex + visibleCards;
                    dot.classList.toggle('active', isInVisibleRange);
                });

                if (useDesktopCollapsed) {
                    const rangeMidpoint = firstActiveIndex + Math.floor(visibleCards / 2);
                    const halfWindow = Math.floor(DESKTOP_MAX_VISIBLE_DOTS / 2);
                    const dotUnit = DOT_WIDTH + DOT_GAP;
                    const totalDotsWidth = totalEvents * DOT_WIDTH + (totalEvents - 1) * DOT_GAP;
                    const visibleWidth = DESKTOP_MAX_VISIBLE_DOTS * DOT_WIDTH + (DESKTOP_MAX_VISIBLE_DOTS - 1) * DOT_GAP;
                    const maxShift = totalDotsWidth - visibleWidth;

                    let shift;
                    if (rangeMidpoint <= halfWindow) {
                        shift = 0;
                    } else if (rangeMidpoint >= totalEvents - halfWindow) {
                        shift = maxShift;
                    } else {
                        shift = (rangeMidpoint - halfWindow) * dotUnit;
                    }

                    track.style.transform = 'translateX(-' + shift + 'px)';
                } else {
                    track.style.transform = '';
                }
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
                if (indicators) {indicators.remove();}
                if (chevronLeft) {chevronLeft.remove();}
                if (chevronRight) {chevronRight.remove();}
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

            const isMobileScreen = window.matchMedia('(max-width: 768px)').matches;
            const maxDotsForViewport = isMobileScreen ? MAX_VISIBLE_DOTS : DESKTOP_MAX_VISIBLE_DOTS;
            const useViewport = eventCount > maxDotsForViewport;

            let trackParent = indicators;
            if (useViewport) {
                const viewport = document.createElement('div');
                viewport.className = isMobileScreen
                    ? 'datamachine-carousel-viewport'
                    : 'datamachine-carousel-viewport datamachine-carousel-viewport--desktop';
                indicators.appendChild(viewport);
                trackParent = viewport;
            }

            const track = document.createElement('div');
            track.className = 'datamachine-carousel-dots-track';
            trackParent.appendChild(track);

            for (let i = 0; i < eventCount; i++) {
                const dot = document.createElement('span');
                dot.className = 'datamachine-carousel-dot';
                dot.dataset.index = i;
                track.appendChild(dot);
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
            let holdInterval = null;

            const scrollByCard = function(direction) {
                const cardWidth = events[0]?.getBoundingClientRect().width || 300;
                wrapper.scrollBy({ left: cardWidth * direction, behavior: 'smooth' });
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
                if (!holdInterval) {scrollByCard(-1);}
            });
            chevronRight.addEventListener('click', function(e) {
                e.preventDefault();
                if (!holdInterval) {scrollByCard(1);}
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
            if (events[0]) {
                observer.observe(events[0]);
            }

            const existing = observers.get(calendar) || [];
            existing.push({ observer, wrapper, events });
            observers.set(calendar, existing);
        }
    });
}

export function destroyCarousel(calendar) {
    const entries = observers.get(calendar);
    if (entries) {
        entries.forEach(function({ observer, wrapper, events }) {
            observer.unobserve(wrapper);
            if (events && events[0]) {
                observer.unobserve(events[0]);
            }
            observer.disconnect();
        });
        observers.delete(calendar);
    }

    const indicators = calendar.querySelectorAll('.datamachine-carousel-indicators');
    const chevrons = calendar.querySelectorAll('.datamachine-carousel-chevron');
    
    indicators.forEach(el => el.remove());
    chevrons.forEach(el => el.remove());
}
