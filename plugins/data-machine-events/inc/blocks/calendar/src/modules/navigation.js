/**
 * Past/upcoming navigation and pagination link handling.
 */

export function initNavigation(calendar, onNavigate) {
    initPastUpcomingButtons(calendar, onNavigate);
    initPaginationLinks(calendar, onNavigate);
}

function initPastUpcomingButtons(calendar, onNavigate) {
    const navContainer = calendar.querySelector('.datamachine-events-past-navigation');
    if (!navContainer) return;

    navContainer.addEventListener('click', function(e) {
        const pastBtn = e.target.closest('.datamachine-events-past-btn');
        const upcomingBtn = e.target.closest('.datamachine-events-upcoming-btn');

        if (pastBtn || upcomingBtn) {
            e.preventDefault();

            const params = new URLSearchParams(window.location.search);
            params.delete('paged');

            if (pastBtn) {
                params.set('past', '1');
            } else {
                params.delete('past');
            }

            if (onNavigate) onNavigate(params);
        }
    });
}

function initPaginationLinks(calendar, onNavigate) {
    const paginationContainer = calendar.querySelector('.datamachine-events-pagination');
    if (!paginationContainer) return;

    paginationContainer.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link) return;

        e.preventDefault();

        const url = new URL(link.href);
        const params = new URLSearchParams(url.search);

        if (onNavigate) onNavigate(params);
    });
}
