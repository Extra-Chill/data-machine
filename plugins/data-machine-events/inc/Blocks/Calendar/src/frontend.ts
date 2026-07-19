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
import { initLoadMore, destroyLoadMore } from './modules/load-more';
import { initScopePresets } from './modules/scope-presets';

import type { FlatpickrInstance } from './types';

function isMonthGridMode( calendar: HTMLElement ): boolean {
	return calendar.getAttribute( 'data-display-mode' ) === 'month-grid';
}

/**
 * Whether the calendar is scoped to a single-day time window.
 *
 * Single-day scopes (tonight/today) render a flat vertical list — the
 * horizontal carousel is poor UX when the whole result set fits one day.
 * Mirrors the month-grid carousel skip: the `data-scope` attribute is
 * emitted server-side in render.php and read here the same way
 * `isMonthGridMode` reads `data-display-mode`. Multi-day scopes
 * (this-week/this-weekend) keep the carousel. #428.
 */
function isSingleDayScope( calendar: HTMLElement ): boolean {
	const scope = calendar.getAttribute( 'data-scope' );
	return scope === 'tonight' || scope === 'today';
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

	if ( filterState.restoreFromStorage() ) {
		return;
	}

	const gridMode = isMonthGridMode( calendar );

	// #428: skip the horizontal carousel for single-day scopes (tonight/
	// today) and render a flat vertical list instead. Mirrors the month-grid
	// skip — `useCarousel` is the single gate for carousel init/destroy so
	// both the initial mount and the content-updated re-init stay in sync.
	const useCarousel = ! gridMode && ! isSingleDayScope( calendar );

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
		if ( useCarousel ) {
			initCarousel( calendar );
		}
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

	// #373: optional in-block time-scope preset chips. No-op unless the
	// `showScopePresets` attribute rendered the chip group. Reuses the same
	// filter-change flow as search/date so there is one re-fetch path.
	initScopePresets( calendar, function () {
		handleFilterChange( calendar );
	} );

	// Auto-detect map block on page and enable geo sync.
	if ( hasMapBlockOnPage() ) {
		initGeoSync( calendar );
	}

	// Replace numbered pagination with a "Load More Events" button on
	// JS-enabled mount (issue #314, phase 2 of #298). The server still
	// emits paginate_links() as a no-JS fallback; this hydrates it into
	// the Load More UX.
	//
	// List/date-groups mode ONLY. Month-grid mode (#321) uses the
	// month-grid-nav module's prev/next-month controls and does not
	// render `.data-machine-events-pagination` server-side, so
	// `initLoadMore` would be a no-op there anyway — but gating
	// explicitly is clearer and survives future grid-mode changes
	// that might add pagination chrome.
	if ( ! gridMode ) {
		initLoadMore( calendar );
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
			if ( useCarousel ) {
				destroyCarousel( calendar );
			}
			initLazyRender( calendar );
			initDayLoader( calendar );
			if ( useCarousel ) {
				initCarousel( calendar );
			}

			// Re-hydrate Load More after a content swap (issue #314).
			// Every dynamic re-fetch path (geo-sync, scope tabs,
			// filters) re-renders the server's `paginate_links()`
			// nav into the DOM as the no-JS fallback. Without this,
			// that fresh `.data-machine-events-pagination` nav stays
			// numbered — the initial-mount `initLoadMore` already ran
			// and its WeakMap guard makes a bare re-init a no-op,
			// leaving a Load More button stranded ABOVE the
			// re-injected numbered pagination on archive pages. Tear
			// down the stale binding and re-hydrate so the freshly
			// swapped nav becomes a Load More button again. Grid mode
			// has no pagination nav, so this is a no-op there.
			//
			// CRITICAL (#158): only re-hydrate when a fresh NUMBERED
			// `.data-machine-events-pagination` nav is actually
			// present. The Load More APPEND path (load-more.ts) fires
			// this same `content-updated` event to re-init the sibling
			// modules above on the newly-appended date groups — but it
			// does NOT re-inject a numbered nav (it appended in place;
			// the working `.data-machine-events-load-more-nav` button
			// is already bound). Unconditionally running
			// destroyLoadMore + initLoadMore there tore the listener
			// off the live button, and initLoadMore then early-returned
			// because there is no `.data-machine-events-pagination` to
			// hydrate — leaving a DEAD button that froze the calendar
			// at the first appended page boundary (reported as "stuck
			// at June 9"). Gate the re-hydrate on the numbered nav so
			// the append path leaves its own working button untouched
			// while full content swaps still re-hydrate as before.
			if ( ! gridMode ) {
				const hasNumberedNav = calendar.querySelector(
					'.data-machine-events-pagination'
				);
				if ( hasNumberedNav ) {
					destroyLoadMore( calendar );
					initLoadMore( calendar );
				}
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
			destroyLoadMore( calendar );
			destroyFilterState( calendar );
		} );
} );
