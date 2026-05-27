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
import { initDayLoader, destroyDayLoader } from './modules/day-loader';
import { initGeoSync, destroyGeoSync } from './modules/geo-sync';
import {
	initMonthGridNav,
	destroyMonthGridNav,
	getMonthGridController,
} from './modules/month-grid-nav';

import type { FlatpickrInstance } from './types';

function isMonthGridMode( calendar: HTMLElement ): boolean {
	return calendar.getAttribute( 'data-display-mode' ) === 'month-grid';
}

const calendarInstances = new WeakMap< HTMLElement, true >();

document.addEventListener( 'DOMContentLoaded', function () {
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( initCalendarInstance );
} );

function initCalendarInstance( calendar: HTMLElement ): void {
	if ( calendarInstances.has( calendar ) ) {
		return;
	}
	calendarInstances.set( calendar, true );

	const filterState = getFilterState( calendar );

	filterState.restoreFromStorage();

	const gridMode = isMonthGridMode( calendar );

	if ( gridMode ) {
		// In grid mode the list view is the mobile fallback and the
		// progressive-render modules (lazy-render, day-loader) target
		// it. They keep working for sub-768px viewports; we just skip
		// the carousel which is list-specific UX.
		initLazyRender( calendar );
		initDayLoader( calendar );
		initMonthGridNav( calendar );
	} else {
		initLazyRender( calendar );
		initDayLoader( calendar );
		initCarousel( calendar );
	}

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

	// Single owner for dynamic-module lifecycle when `.data-machine-events-content`
	// is replaced. Fired by api-client.fetchCalendarEvents after every innerHTML
	// swap (geo-sync re-fetch, pagination, scope switching, etc.). Modules that
	// touch the content region must NOT drive their own destroy/init cycle —
	// they register here, and only here. This is the architectural fix for the
	// race where geo-sync forgot to re-init day-loader after a content swap,
	// leaving deferred date shells permanently un-hydrated on location archives.
	calendar.addEventListener(
		'data-machine-calendar-content-updated',
		function () {
			destroyLazyRender( calendar );
			destroyDayLoader( calendar );
			if ( ! gridMode ) {
				destroyCarousel( calendar );
			}
			initLazyRender( calendar );
			initDayLoader( calendar );
			if ( ! gridMode ) {
				initCarousel( calendar );
			}
		}
	);
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
 *
 * In list mode (default) a full page reload picks up the new filter
 * set server-side. In grid mode (#318) the month-grid controller
 * re-fetches the data envelope and swaps the grid in place so the
 * visible month doesn't reset.
 */
function handleFilterChange( calendar: HTMLElement ): void {
	const filterState = getFilterState( calendar );
	const datePicker: FlatpickrInstance | null = getDatePicker( calendar );
	const params = filterState.buildParams( datePicker );

	filterState.saveToStorage( params );

	if ( isMonthGridMode( calendar ) ) {
		const controller = getMonthGridController( calendar );
		if ( controller ) {
			void controller.handleFilterChange();
			return;
		}
	}

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
			destroyDayLoader( calendar );
			destroyGeoSync( calendar );
			destroyMonthGridNav( calendar );
			destroyFilterState( calendar );
		} );
} );
