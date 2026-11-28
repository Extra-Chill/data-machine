/**
 * Data Machine Events Calendar Frontend
 *
 * Module orchestration for calendar blocks. Supports two modes:
 * Hydrates the initial PHP render and updates the calendar from REST data.
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
import {
	initNavigation,
	type CalendarNavigationAction,
} from './modules/navigation';
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
import {
	buildCalendarRequest,
	fetchCalendarEvents,
} from './modules/api-client';

import {
	emitInitialQuery,
	type CalendarRequestOptions,
} from './modules/query-changed';
import type { FlatpickrInstance } from './types';

type QueryTrigger = CalendarRequestOptions[ 'trigger' ];

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
 * @param calendar
 */
function isSingleDayScope( calendar: HTMLElement ): boolean {
	const activeScope = calendar.querySelector< HTMLElement >(
		'.data-machine-events-scope-chip-active[data-scope]'
	);
	const scope = activeScope?.dataset.scope ?? calendar.dataset.scope;
	return scope === 'tonight' || scope === 'today';
}

/**
 * Keep the visual layout aligned with the active time scope.
 *
 * The server emits this class for first paint. Scope preset changes replace
 * only the content region, so the frontend must update the root class too.
 *
 * @param calendar
 */
function syncSingleDayListLayout( calendar: HTMLElement ): boolean {
	const useSingleDayList =
		! isMonthGridMode( calendar ) && isSingleDayScope( calendar );
	calendar.classList.toggle(
		'data-machine-events-single-day-list',
		useSingleDayList
	);
	return useSingleDayList;
}

const calendarInstances = new WeakMap< HTMLElement, true >();

document.addEventListener( 'DOMContentLoaded', function () {
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( initCalendarInstance );
} );

export function initCalendarInstance( calendar: HTMLElement ): void {
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
	// skip. The root layout class owns CSS while carousel initialization owns
	// controls; both are recalculated after dynamic content updates.
	const useCarousel = ! gridMode && ! syncSingleDayListLayout( calendar );

	if ( gridMode ) {
		// In grid mode the list view is the mobile fallback and the
		// progressive-render modules (lazy-render, day-loader) target
		// it. They keep working for sub-768px viewports; we just skip
		// the carousel which is list-specific UX.
		initLazyRender( calendar );
		initDayLoader( calendar );
		initMonthGridNav( calendar, function ( params ) {
			const currentFilterState = applyFilterParams( calendar, params );
			currentFilterState.saveToStorage( params );
			currentFilterState.updateFilterCountBadge();
		} );
	} else {
		initLazyRender( calendar );
		initDayLoader( calendar );
		if ( useCarousel ) {
			initCarousel( calendar );
		}
	}

	initDatePicker( calendar, function ( selectedDates ) {
		handleFilterChange(
			calendar,
			selectedDates?.length === 0 ? 'clear' : 'date_range'
		);
	} );

	initFilterModal(
		calendar,
		function () {
			handleFilterChange( calendar, 'taxonomy_filter' );
		},
		function ( params: URLSearchParams ) {
			applyFilterParams( calendar, params );
			updateCalendar( calendar, params, 'clear' );
		}
	);

	initNavigation(
		calendar,
		function ( params: URLSearchParams, action: CalendarNavigationAction ) {
			if ( action === 'today' && isMonthGridMode( calendar ) ) {
				const currentFilterState = applyFilterParams(
					calendar,
					params
				);
				currentFilterState.saveToStorage( params );

				const todayMonth = calendar.querySelector< HTMLElement >(
					'.data-machine-month-grid__nav-today'
				)?.dataset.month;
				const controller = getMonthGridController( calendar );
				if ( todayMonth && controller ) {
					void controller
						.navigateToMonth( todayMonth, params, true, false, {
							trigger: 'scope',
						} )
						.then( function ( updated ) {
							if ( updated ) {
								currentFilterState.updateFilterCountBadge();
							}
						} );
					return;
				}
			}

			if ( isMonthGridMode( calendar ) ) {
				navigateToUrl( params );
			} else {
				applyFilterParams( calendar, params );
				updateCalendar( calendar, params, 'scope' );
			}
		}
	);

	initSearchInput( calendar );

	// #373: optional in-block time-scope preset chips. No-op unless the
	// `showScopePresets` attribute rendered the chip group. Reuses the same
	// filter-change flow as search/date so there is one re-fetch path.
	initScopePresets( calendar, function () {
		handleFilterChange( calendar, 'scope' );
	} );

	// Auto-detect map block on page and enable geo sync.
	const mapSyncId = resolveMapSyncId( calendar );
	if ( mapSyncId ) {
		if ( gridMode ) {
			initGeoSync(
				calendar,
				mapSyncId,
				async function ( geo, signal, isCurrent, trigger ) {
					const params = getFilterState( calendar ).buildParams(
						getDatePicker( calendar )
					);
					params.set( 'lat', geo.lat );
					params.set( 'lng', geo.lng );
					if ( geo.radius !== undefined ) {
						params.set( 'radius', String( geo.radius ) );
					}
					if ( geo.radius_unit ) {
						params.set( 'radius_unit', geo.radius_unit );
					}
					const controller = getMonthGridController( calendar );
					return controller
						? controller.handleFilterChange( params, {
								signal,
								shouldApply: isCurrent,
								trigger,
							} )
						: false;
				}
			);
		} else {
			initGeoSync( calendar, mapSyncId );
		}
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
	emitInitialQuery( calendar );
}

function applyFilterParams(
	calendar: HTMLElement,
	params: URLSearchParams
): ReturnType< typeof getFilterState > {
	const filterState = getFilterState( calendar );
	filterState.applyParams( params, getDatePicker( calendar ) );
	return filterState;
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
			handleFilterChange( calendar, 'search' );
		}, 500 );
	} );

	const searchBtn = calendar.querySelector< HTMLElement >(
		'.data-machine-events-search-btn'
	);
	if ( searchBtn ) {
		searchBtn.addEventListener( 'click', function () {
			clearTimeout( searchTimeout );
			handleFilterChange( calendar, 'search' );
			searchInput.focus();
		} );
	}
}

/**
 * Fetch results for the current controls without leaving the calendar page.
 * @param calendar
 * @param trigger
 */
function handleFilterChange(
	calendar: HTMLElement,
	trigger: QueryTrigger
): void {
	const filterState = getFilterState( calendar );
	const datePicker: FlatpickrInstance | null = getDatePicker( calendar );
	const params = filterState.buildParams( datePicker );
	if ( ! isMonthGridMode( calendar ) ) {
		const current = new URLSearchParams( window.location.search );
		const tier = current.get( 'venue_tier' );
		if ( tier ) {
			params.set( 'venue_tier', tier );
		}
		if ( ! params.has( 'date_start' ) && current.get( 'past' ) === '1' ) {
			params.set( 'past', '1' );
		}
		if (
			calendar.querySelector(
				'.data-machine-events-scope-chip-active[data-scope=""]'
			)
		) {
			params.set( 'scope', 'current' );
		}
	}

	filterState.saveToStorage( params );

	updateCalendar( calendar, params, trigger );
}

function updateCalendar(
	calendar: HTMLElement,
	params: URLSearchParams,
	trigger: QueryTrigger,
	pushHistory = true
): void {
	if ( handleMonthGridChange( calendar, params, trigger ) ) {
		return;
	}
	const state = getFilterState( calendar );
	const request = buildCalendarRequest( {
		source: params,
		archiveContext: state.getArchiveContext(),
	} );
	// Keep the page URL as the source for pagination, recovery, and map sync.
	if (
		pushHistory &&
		new URLSearchParams( window.location.search ).toString() !==
			params.toString()
	) {
		state.updateUrl( params );
	}
	void fetchCalendarEvents( calendar, request, {}, { trigger } ).then(
		( data ) => {
			if ( data ) {
				state.updateFilterCountBadge();
			}
		}
	);
}

function handleMonthGridChange(
	calendar: HTMLElement,
	params: URLSearchParams,
	trigger: QueryTrigger
): boolean {
	if ( ! isMonthGridMode( calendar ) ) {
		return false;
	}
	const controller = getMonthGridController( calendar );
	if ( ! controller ) {
		return false;
	}
	void controller
		.handleFilterChange( params, { trigger } )
		.then( function ( updated ) {
			if ( updated ) {
				getFilterState( calendar ).updateFilterCountBadge();
			}
		} );
	return true;
}

/**
 * Navigate to URL with params (full page reload).
 * @param params
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
 * @param calendar
 */
function resolveMapSyncId( calendar: HTMLElement ): string {
	const explicitTarget = calendar.dataset.mapSyncId || '';
	if ( explicitTarget ) {
		return explicitTarget;
	}

	const maps = document.querySelectorAll< HTMLElement >(
		'.data-machine-events-map-root'
	);
	if ( maps.length !== 1 ) {
		return '';
	}

	return maps[ 0 ].dataset.syncId || maps[ 0 ].id || '';
}

window.addEventListener( 'popstate', function () {
	const params = new URLSearchParams( window.location.search );
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( ( calendar ) => {
			if (
				calendarInstances.has( calendar ) &&
				! isMonthGridMode( calendar )
			) {
				applyFilterParams( calendar, params );
				updateCalendar( calendar, params, undefined, false );
			}
		} );
} );

window.addEventListener( 'beforeunload', function () {
	document
		.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
		.forEach( function ( calendar ) {
			destroyDatePicker( calendar );
			destroyCarousel( calendar );
			destroyFilterModal( calendar );
			destroyLazyRender( calendar );
			destroyDayLoader( calendar );
			destroyGeoSync( calendar );
			destroyMonthGridNav( calendar );
			destroyLoadMore( calendar );
			destroyFilterState( calendar );
		} );
} );
