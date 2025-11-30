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
                // Desktop: activate dot for each card >50% visible
                events.forEach(function(event, index) {
                    const eventRect = event.getBoundingClientRect();
                    const visibleLeft = Math.max(eventRect.left, wrapperRect.left);
                    const visibleRight = Math.min(eventRect.right, wrapperRect.right);
                    const visibleWidth = Math.max(0, visibleRight - visibleLeft);
                    const visibilityRatio = visibleWidth / eventRect.width;
                    
                    dots[index].classList.toggle('active', visibilityRatio > 0.5);
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
