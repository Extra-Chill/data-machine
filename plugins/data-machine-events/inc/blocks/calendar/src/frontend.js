/**
 * Data Machine Events Calendar Frontend
 *
 * Module orchestration for calendar blocks with URL-based filtering.
 * All filter/pagination changes trigger full page navigation.
 */

/**
 * External dependencies
 */
import 'flatpickr/dist/flatpickr.css';
/**
 * Internal dependencies
 */
import './flatpickr-theme.css';

import { initCarousel, destroyCarousel } from './modules/carousel.js';
import { initDatePicker, destroyDatePicker, getDatePicker } from './modules/date-picker.js';
import { initFilterModal, destroyFilterModal } from './modules/filter-modal.js';
import { initLocationInput, destroyLocationInput } from './modules/location-input.js';
import { initNavigation } from './modules/navigation.js';
import { getFilterState, destroyFilterState } from './modules/filter-state.js';
import { initLazyRender, destroyLazyRender } from './modules/lazy-render.js';

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.datamachine-events-calendar').forEach(initCalendarInstance);
});

function initCalendarInstance(calendar) {
    if (calendar.dataset.dmInitialized === 'true') {return;}
    calendar.dataset.dmInitialized = 'true';

    const filterState = getFilterState(calendar);

    filterState.restoreFromStorage();

    initLazyRender(calendar);
    initCarousel(calendar);

    initDatePicker(calendar, function() {
        handleFilterChange(calendar);
    });

    initFilterModal(
        calendar,
        function() { handleFilterChange(calendar); },
        function(params) { navigateToUrl(params); }
    );

    initLocationInput(calendar, function() {
        handleFilterChange(calendar);
    });

    initNavigation(calendar, function(params) {
        navigateToUrl(params);
    });

    initSearchInput(calendar);

    filterState.updateFilterCountBadge();
}

function initSearchInput(calendar) {
    const searchInput = calendar.querySelector('.datamachine-events-search-input')
        || calendar.querySelector('[id^="datamachine-events-search-"]');

    if (!searchInput) {return;}

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

/**
 * Handle filter changes by building params and navigating
 */
function handleFilterChange(calendar) {
    const filterState = getFilterState(calendar);
    const datePicker = getDatePicker(calendar);
    const params = filterState.buildParams(datePicker);

    filterState.saveToStorage(params);

    navigateToUrl(params);
}

/**
 * Navigate to URL with params (full page reload)
 */
function navigateToUrl(params) {
    const queryString = params.toString();
    const newUrl = queryString
        ? `${window.location.pathname}?${queryString}`
        : window.location.pathname;

    window.location.href = newUrl;
}

window.addEventListener('beforeunload', function() {
    document.querySelectorAll('.datamachine-events-calendar').forEach(function(calendar) {
        destroyDatePicker(calendar);
        destroyCarousel(calendar);
        destroyLazyRender(calendar);
        destroyFilterState(calendar);
        destroyLocationInput(calendar);
    });
});
