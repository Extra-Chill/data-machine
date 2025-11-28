/**
 * Data Machine Events Calendar Frontend
 *
 * Module orchestration for calendar blocks with REST API filtering.
 */

import './flatpickr-theme.css';

import { initCarousel, destroyCarousel } from './modules/carousel.js';
import { initDatePicker, destroyDatePicker, getDatePicker } from './modules/date-picker.js';
import { initFilterModal } from './modules/filter-modal.js';
import { initNavigation } from './modules/navigation.js';
import { fetchCalendarEvents } from './modules/api-client.js';
import { buildQueryParams, updateUrl } from './modules/state.js';

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.datamachine-events-calendar').forEach(initCalendarInstance);
});

function initCalendarInstance(calendar) {
    if (calendar.dataset.dmInitialized === 'true') return;
    calendar.dataset.dmInitialized = 'true';

    initCarousel(calendar);

    initDatePicker(calendar, function() {
        handleFilterChange(calendar);
    });

    initFilterModal(
        calendar,
        function() { handleFilterChange(calendar); },
        function() { handleFilterChange(calendar); }
    );

    initNavigation(calendar, function(params) {
        handleNavigation(calendar, params);
    });

    initSearchInput(calendar);
}

function initSearchInput(calendar) {
    const searchInput = calendar.querySelector('.datamachine-events-search-input') 
        || calendar.querySelector('[id^="datamachine-events-search-"]');
    
    if (!searchInput) return;

    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            handleFilterChange(calendar);
        }, 500);
    });

    const searchBtn = calendar.querySelector('.datamachine-events-search-btn');
    if (searchBtn) {
        searchBtn.addEventListener('click', function() {
            handleFilterChange(calendar);
            searchInput.focus();
        });
    }
}

async function handleFilterChange(calendar) {
    const datePicker = getDatePicker(calendar);
    const params = buildQueryParams(calendar, datePicker);
    updateUrl(params);
    await refreshCalendar(calendar, params);
}

async function handleNavigation(calendar, params) {
    updateUrl(params);
    await refreshCalendar(calendar, params);
}

async function refreshCalendar(calendar, params) {
    destroyCarousel(calendar);
    destroyDatePicker(calendar);

    await fetchCalendarEvents(calendar, params);

    calendar.dataset.dmInitialized = 'false';
    initCalendarInstance(calendar);
}

window.addEventListener('beforeunload', function() {
    document.querySelectorAll('.datamachine-events-calendar').forEach(function(calendar) {
        destroyDatePicker(calendar);
        destroyCarousel(calendar);
    });
});
