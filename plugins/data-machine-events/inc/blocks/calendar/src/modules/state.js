/**
 * URL state management and query parameter building for calendar filtering.
 */

export function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function getUrlParams() {
    return new URLSearchParams(window.location.search);
}

export function updateUrl(params) {
    const newUrl = `${window.location.pathname}?${params.toString()}`;
    window.history.pushState({}, '', newUrl);
}

export function buildQueryParams(calendar, datePicker = null) {
    const params = new URLSearchParams(window.location.search);

    params.delete('paged');

    const searchInput = calendar.querySelector('.datamachine-events-search-input') 
        || calendar.querySelector('[id^="datamachine-events-search-"]');
    
    if (searchInput && searchInput.value) {
        params.set('event_search', searchInput.value);
    } else {
        params.delete('event_search');
    }

    if (datePicker && datePicker.selectedDates.length > 0) {
        const startDate = datePicker.selectedDates[0];
        const endDate = datePicker.selectedDates[1] || startDate;

        params.set('date_start', formatDate(startDate));
        params.set('date_end', formatDate(endDate));

        const now = new Date();
        const endOfRange = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate(), 23, 59, 59);
        if (endOfRange < now) {
            params.set('past', '1');
        } else {
            params.delete('past');
        }
    } else {
        params.delete('date_start');
        params.delete('date_end');
        params.delete('past');
    }

    params.delete('tax_filter');
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (modal) {
        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        const taxFilters = {};

        checkboxes.forEach(function(checkbox) {
            const taxonomy = checkbox.getAttribute('data-taxonomy');
            const termId = checkbox.value;

            if (!taxFilters[taxonomy]) {
                taxFilters[taxonomy] = [];
            }
            taxFilters[taxonomy].push(termId);
        });

        Object.keys(taxFilters).forEach(function(taxonomy) {
            taxFilters[taxonomy].forEach(function(termId) {
                params.append(`tax_filter[${taxonomy}][]`, termId);
            });
        });
    }

    return params;
}
