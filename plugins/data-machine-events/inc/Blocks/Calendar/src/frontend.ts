/**
 * Data Machine Events Calendar Frontend
 *
 * Module orchestration for calendar blocks. Supports two modes:
 * - Page navigation (default): filter/pagination changes trigger full reload
 * - Geo sync (automatic): when an events-map block is present on the page,
 *   the calendar listens for map bounds changes and re-fetches via REST API
 */

/**
 * External dependencies
 */
import 'flatpickr/dist/flatpickr.css';

/**
 * Internal dependencies
 */
import './flatpickr-theme.css';

import { initCarousel, destroyCarousel } from './modules/carousel';
import {
	initDatePicker,
	destroyDatePicker,
	getDatePicker,
} from './modules/date-picker';
import { initFilterModal, destroyFilterModal } from './modules/filter-modal';
import { initNavigation } from './modules/navigation';
import { getFilterState, destroyFilterState } from './modules/filter-state';
import { initLazyRender, destroyLazyRender } from './modules/lazy-render';
import { initGeoSync, destroyGeoSync } from './modules/geo-sync';

import type { FlatpickrInstance } from './types';

document.addEventListener( 'DOMContentLoaded', function () {
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( initCalendarInstance );
} );

function initCalendarInstance( calendar: HTMLElement ): void {
	if ( calendar.dataset.dmInitialized === 'true' ) {
		return;
	}
	calendar.dataset.dmInitialized = 'true';

	const filterState = getFilterState( calendar );

	filterState.restoreFromStorage();

	initLazyRender( calendar );
	initCarousel( calendar );

	initDatePicker( calendar, function () {
		handleFilterChange( calendar );
	} );

	initFilterModal(
		calendar,
		function () {
			handleFilterChange( calendar );
		},
		function ( params: URLSearchParams ) {
			navigateToUrl( params );
		}
	);

	initNavigation( calendar, function ( params: URLSearchParams ) {
		navigateToUrl( params );
	} );

	initSearchInput( calendar );

	// Auto-detect map block on page and enable geo sync.
	if ( hasMapBlockOnPage() ) {
		initGeoSync( calendar );
	}

	filterState.updateFilterCountBadge();
}

function initSearchInput( calendar: HTMLElement ): void {
	const searchInput =
		calendar.querySelector< HTMLInputElement >(
			'.data-machine-events-search-input'
		) ||
		calendar.querySelector< HTMLInputElement >(
			'[id^="data-machine-events-search-"]'
		);

	if ( ! searchInput ) {
		return;
	}

	let searchTimeout: ReturnType< typeof setTimeout >;
	searchInput.addEventListener( 'input', function () {
		clearTimeout( searchTimeout );
		searchTimeout = setTimeout( function () {
			handleFilterChange( calendar );
		}, 500 );
	} );

	const searchBtn = calendar.querySelector< HTMLElement >(
		'.data-machine-events-search-btn'
	);
	if ( searchBtn ) {
		searchBtn.addEventListener( 'click', function () {
			handleFilterChange( calendar );
			searchInput.focus();
		} );
	}
}

/**
 * Handle filter changes by building params and navigating.
 */
function handleFilterChange( calendar: HTMLElement ): void {
	const filterState = getFilterState( calendar );
	const datePicker: FlatpickrInstance | null = getDatePicker( calendar );
	const params = filterState.buildParams( datePicker );

	filterState.saveToStorage( params );

	navigateToUrl( params );
}

/**
 * Navigate to URL with params (full page reload).
 */
function navigateToUrl( params: URLSearchParams ): void {
	const queryString = params.toString();
	const newUrl = queryString
		? `${ window.location.pathname }?${ queryString }`
		: window.location.pathname;

	window.location.href = newUrl;
}

/**
 * Check if an events-map block exists on the current page.
 * When present, the calendar auto-enables geo sync mode.
 */
function hasMapBlockOnPage(): boolean {
	return document.querySelector( '.data-machine-events-map-root' ) !== null;
}

window.addEventListener( 'beforeunload', function () {
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( function ( calendar ) {
			destroyDatePicker( calendar );
			destroyCarousel( calendar );
			destroyLazyRender( calendar );
			destroyGeoSync( calendar );
			destroyFilterState( calendar );
		} );
} );
