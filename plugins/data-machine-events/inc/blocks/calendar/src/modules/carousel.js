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

            events.forEach(function(event, index) {
                const eventRect = event.getBoundingClientRect();
                const isVisible = eventRect.left >= wrapperRect.left - 10 
                    && eventRect.right <= wrapperRect.right + 10;
                dots[index].classList.toggle('active', isVisible);
            });

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
