/**
 * REST API communication and calendar DOM updates.
 */

export async function fetchCalendarEvents(calendar, params) {
    const content = calendar.querySelector('.datamachine-events-content');
    
    content.classList.add('loading');

    try {
        const apiUrl = `/wp-json/datamachine/v1/events/calendar?${params.toString()}`;

        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const data = await response.json();

        if (data.success) {
            content.innerHTML = data.html;
            updatePagination(calendar, data.pagination);
            updateCounter(calendar, content, data.counter);
            updateNavigation(calendar, content, data.navigation);
        }

        return data;

    } catch (error) {
        console.error('Error fetching filtered events:', error);
        content.innerHTML = '<div class="datamachine-events-error"><p>Error loading events. Please try again.</p></div>';
        return { success: false, error: error.message };
    } finally {
        content.classList.remove('loading');
    }
}

/**
 * Fetch filter options from REST API with optional active filter context
 * 
 * @param {Object} activeFilters Current filter selections keyed by taxonomy slug
 * @returns {Promise<Object>} Filter data with taxonomies, dependencies, and meta
 */
export async function fetchFilters(activeFilters = {}) {
    const params = new URLSearchParams();
    
    Object.entries(activeFilters).forEach(([taxonomy, termIds]) => {
        if (Array.isArray(termIds) && termIds.length > 0) {
            termIds.forEach(id => {
                params.append(`active[${taxonomy}][]`, id);
            });
        }
    });

    const apiUrl = `/wp-json/datamachine/v1/events/filters?${params.toString()}`;

    const response = await fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    });

    if (!response.ok) {
        throw new Error('Failed to fetch filters');
    }

    return response.json();
}

function updatePagination(calendar, pagination) {
    const paginationContainer = calendar.querySelector('.datamachine-events-pagination');
    
    if (paginationContainer && pagination?.html) {
        paginationContainer.outerHTML = pagination.html;
    } else if (!paginationContainer && pagination?.html) {
        const content = calendar.querySelector('.datamachine-events-content');
        content.insertAdjacentHTML('afterend', pagination.html);
    }
}

function updateCounter(calendar, content, counter) {
    const counterContainer = calendar.querySelector('.datamachine-events-results-counter');
    
    if (counterContainer && counter) {
        counterContainer.outerHTML = counter;
    } else if (!counterContainer && counter) {
        content.insertAdjacentHTML('afterend', counter);
    }
}

function updateNavigation(calendar, content, navigation) {
    const navigationContainer = calendar.querySelector('.datamachine-events-past-navigation');
    
    if (navigationContainer && navigation?.html) {
        navigationContainer.outerHTML = navigation.html;
    } else if (!navigationContainer && navigation?.html) {
        calendar.insertAdjacentHTML('beforeend', navigation.html);
    }
}
