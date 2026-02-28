/**
 * Centralized filter state management for the Calendar block.
 *
 * Source of truth hierarchy:
 * 1. URL params (explicit, shareable)
 * 2. localStorage (persistence for taxonomy filters + geo location)
 *
 * Archive context is read from DOM data attributes (page-level, not user state).
 */

import type {
	ArchiveContext,
	DateContext,
	FlatpickrInstance,
	GeoContext,
	StoredGeo,
	TaxFilters,
} from '../types';

const STORAGE_KEY = 'data_machine_events_calendar_state';
const GEO_STORAGE_KEY = 'data_machine_events_geo_state';

class FilterStateManager {
	private calendar: HTMLElement;
	private archiveContext: ArchiveContext;

	constructor( calendar: HTMLElement ) {
		this.calendar = calendar;
		this.archiveContext = this.readArchiveContext();
	}

	/**
	 * Read archive context from calendar data attributes.
	 */
	private readArchiveContext(): ArchiveContext {
		const dataset = this.calendar.dataset;
		return {
			taxonomy: dataset.archiveTaxonomy || '',
			term_id: parseInt( dataset.archiveTermId || '0', 10 ) || 0,
			term_name: dataset.archiveTermName || '',
		};
	}

	/**
	 * Get archive context for API calls.
	 */
	getArchiveContext(): ArchiveContext {
		return this.archiveContext;
	}

	/**
	 * Parse taxonomy filters from URL.
	 */
	getTaxFilters(): TaxFilters {
		const params = new URLSearchParams( window.location.search );
		const filters: TaxFilters = {};

		params.forEach( ( value, key ) => {
			const match = key.match(
				/^tax_filter\[([^\]]+)\]\[(?:\d+)?\]$/
			);
			if ( match ) {
				const taxonomy = match[ 1 ];
				if ( ! filters[ taxonomy ] ) {
					filters[ taxonomy ] = [];
				}
				const termId = parseInt( value, 10 );
				if ( termId > 0 && ! filters[ taxonomy ].includes( termId ) ) {
					filters[ taxonomy ].push( termId );
				}
			}
		} );

		return filters;
	}

	/**
	 * Get date context from URL.
	 */
	getDateContext(): DateContext {
		const params = new URLSearchParams( window.location.search );
		return {
			date_start: params.get( 'date_start' ) || '',
			date_end: params.get( 'date_end' ) || '',
			past: params.get( 'past' ) || '',
		};
	}

	/**
	 * Get geo context from URL, falling back to data attributes, then localStorage.
	 */
	getGeoContext(): GeoContext {
		const params = new URLSearchParams( window.location.search );

		// Priority 1: URL params (shareable links)
		const urlLat = params.get( 'lat' ) || '';
		const urlLng = params.get( 'lng' ) || '';
		if ( urlLat && urlLng ) {
			return {
				lat: urlLat,
				lng: urlLng,
				radius: parseInt( params.get( 'radius' ) || '25', 10 ) || 25,
				radius_unit:
					( params.get( 'radius_unit' ) as GeoContext[ 'radius_unit' ] ) ||
					'mi',
			};
		}

		// Priority 2: Server-rendered data attributes
		const dataLat = this.calendar.dataset.geoLat || '';
		const dataLng = this.calendar.dataset.geoLng || '';
		if ( dataLat && dataLng ) {
			return {
				lat: dataLat,
				lng: dataLng,
				radius:
					parseInt( this.calendar.dataset.geoRadius || '25', 10 ) ||
					25,
				radius_unit:
					( this.calendar.dataset.geoRadiusUnit as GeoContext[ 'radius_unit' ] ) ||
					'mi',
			};
		}

		// Priority 3: localStorage (persisted user preference)
		return this.getStoredGeo();
	}

	/**
	 * Check if geo filter is active.
	 */
	hasGeoFilter(): boolean {
		const geo = this.getGeoContext();
		return !! ( geo.lat && geo.lng );
	}

	/**
	 * Get search query from URL.
	 */
	getSearchQuery(): string {
		const params = new URLSearchParams( window.location.search );
		return params.get( 'event_search' ) || '';
	}

	/**
	 * Get current page from URL.
	 */
	getCurrentPage(): number {
		const params = new URLSearchParams( window.location.search );
		return parseInt( params.get( 'paged' ) || '1', 10 ) || 1;
	}

	/**
	 * Count active taxonomy filters.
	 */
	getFilterCount(): number {
		const filters = this.getTaxFilters();
		return Object.values( filters ).reduce(
			( sum, arr ) => sum + arr.length,
			0
		);
	}

	/**
	 * Check if URL has any taxonomy filter params.
	 */
	hasUrlFilters(): boolean {
		const params = new URLSearchParams( window.location.search );
		for ( const key of params.keys() ) {
			if ( key.startsWith( 'tax_filter[' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build URLSearchParams from current UI state.
	 * Reads from: search input, date picker, filter checkboxes, location input.
	 */
	buildParams( datePicker: FlatpickrInstance | null = null ): URLSearchParams {
		const params = new URLSearchParams();

		const searchInput =
			this.calendar.querySelector< HTMLInputElement >(
				'.data-machine-events-search-input'
			);
		if ( searchInput?.value ) {
			params.set( 'event_search', searchInput.value );
		}

		if ( datePicker?.selectedDates?.length && datePicker.selectedDates.length > 0 ) {
			const startDate = datePicker.selectedDates[ 0 ];
			const endDate = datePicker.selectedDates[ 1 ] || startDate;

			params.set( 'date_start', this.formatDate( startDate ) );
			params.set( 'date_end', this.formatDate( endDate ) );

			const now = new Date();
			const endOfRange = new Date(
				endDate.getFullYear(),
				endDate.getMonth(),
				endDate.getDate(),
				23,
				59,
				59
			);
			if ( endOfRange < now ) {
				params.set( 'past', '1' );
			}
		}

		// Taxonomy filters — read from inline collapsible (or modal for backward compat)
		const filtersContainer =
			this.calendar.querySelector< HTMLElement >(
				'.datamachine-taxonomy-filters-inline'
			) ||
			this.calendar.querySelector< HTMLElement >(
				'.datamachine-taxonomy-modal'
			);
		if ( filtersContainer ) {
			const checkboxes =
				filtersContainer.querySelectorAll< HTMLInputElement >(
					'input[type="checkbox"]:checked'
				);
			checkboxes.forEach( ( checkbox ) => {
				const taxonomy = checkbox.dataset.taxonomy;
				const termId = checkbox.value;
				if ( taxonomy && termId ) {
					params.append( `tax_filter[${ taxonomy }][]`, termId );
				}
			} );
		}

		// Geo location — read from location input data attributes
		const locationInput = this.calendar.querySelector< HTMLInputElement >(
			'.data-machine-events-location-search'
		);
		if ( locationInput ) {
			const lat = locationInput.dataset.geoLat || '';
			const lng = locationInput.dataset.geoLng || '';
			if ( lat && lng ) {
				params.set( 'lat', lat );
				params.set( 'lng', lng );

				const radiusSelect =
					this.calendar.querySelector< HTMLSelectElement >(
						'.data-machine-events-radius-select'
					);
				if ( radiusSelect ) {
					params.set( 'radius', radiusSelect.value );
					params.set(
						'radius_unit',
						radiusSelect.dataset.radiusUnit || 'mi'
					);
				}
			}
		}

		return params;
	}

	/**
	 * Update URL via History API and save state to localStorage.
	 */
	updateUrl( params: URLSearchParams ): void {
		const queryString = params.toString();
		const newUrl = queryString
			? `${ window.location.pathname }?${ queryString }`
			: window.location.pathname;
		window.history.pushState( {}, '', newUrl );

		this.saveToStorage( params );
	}

	/**
	 * Save taxonomy filters to localStorage (not dates or geo — geo has its own storage).
	 */
	saveToStorage( params: URLSearchParams ): void {
		try {
			const taxFilters: Record< string, string[] > = {};
			for ( const [ key, value ] of params.entries() ) {
				if ( ! key.startsWith( 'tax_filter[' ) ) {
					continue;
				}

				if ( ! taxFilters[ key ] ) {
					taxFilters[ key ] = [];
				}
				taxFilters[ key ].push( value );
			}

			if ( Object.keys( taxFilters ).length > 0 ) {
				localStorage.setItem(
					STORAGE_KEY,
					JSON.stringify( taxFilters )
				);
			} else {
				localStorage.removeItem( STORAGE_KEY );
			}
		} catch {
			// localStorage unavailable
		}
	}

	/**
	 * Save geo state to localStorage.
	 */
	saveGeoToStorage( geo: StoredGeo ): void {
		try {
			if ( geo.lat && geo.lng ) {
				localStorage.setItem(
					GEO_STORAGE_KEY,
					JSON.stringify( geo )
				);
			} else {
				localStorage.removeItem( GEO_STORAGE_KEY );
			}
		} catch {
			// localStorage unavailable
		}
	}

	/**
	 * Get stored geo state from localStorage.
	 */
	getStoredGeo(): StoredGeo {
		try {
			const stored = localStorage.getItem( GEO_STORAGE_KEY );
			if ( stored ) {
				return JSON.parse( stored ) as StoredGeo;
			}
		} catch {
			// localStorage unavailable or corrupted
		}
		return { lat: '', lng: '', radius: 25, radius_unit: 'mi', label: '' };
	}

	/**
	 * Clear geo state from localStorage.
	 */
	clearGeoStorage(): void {
		try {
			localStorage.removeItem( GEO_STORAGE_KEY );
		} catch {
			// localStorage unavailable
		}
	}

	/**
	 * Restore taxonomy filters from localStorage if URL has no filters.
	 */
	restoreFromStorage(): boolean {
		if ( this.hasUrlFilters() ) {
			return false;
		}

		try {
			const stored = localStorage.getItem( STORAGE_KEY );
			if ( ! stored ) {
				return false;
			}

			const taxFilters: Record< string, string[] > = JSON.parse( stored );
			const params = new URLSearchParams( window.location.search );

			Object.entries( taxFilters ).forEach( ( [ key, values ] ) => {
				if ( Array.isArray( values ) ) {
					values.forEach( ( v ) => params.append( key, v ) );
				}
			} );

			if ( params.toString() !== window.location.search.slice( 1 ) ) {
				const newUrl = `${ window.location.pathname }?${ params.toString() }`;
				window.history.replaceState( {}, '', newUrl );
				return true;
			}
		} catch {
			// localStorage unavailable or corrupted
		}

		return false;
	}

	/**
	 * Clear localStorage.
	 */
	clearStorage(): void {
		localStorage.removeItem( STORAGE_KEY );
	}

	/**
	 * Update filter count badge on the filter button.
	 */
	updateFilterCountBadge(): void {
		const filterBtn = this.calendar.querySelector< HTMLElement >(
			'.datamachine-taxonomy-toggle, .datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .data-machine-events-filter-btn'
		);
		const countBadge = filterBtn?.querySelector< HTMLElement >(
			'.datamachine-filter-count'
		);

		if ( ! filterBtn || ! countBadge ) {
			return;
		}

		const count = this.getFilterCount();

		if ( count > 0 ) {
			filterBtn.hidden = false;
			countBadge.textContent = String( count );
			countBadge.classList.add( 'visible' );
			filterBtn.classList.add( 'datamachine-filters-active' );
			return;
		}

		countBadge.textContent = '';
		countBadge.classList.remove( 'visible' );
		filterBtn.classList.remove( 'datamachine-filters-active' );

		if ( filterBtn.dataset.hideWhenInactive === '1' ) {
			filterBtn.hidden = true;
		}
	}

	/**
	 * Format date as YYYY-MM-DD.
	 */
	private formatDate( date: Date ): string {
		const year = date.getFullYear();
		const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
		const day = String( date.getDate() ).padStart( 2, '0' );
		return `${ year }-${ month }-${ day }`;
	}
}

const instances = new WeakMap< HTMLElement, FilterStateManager >();

/**
 * Get or create FilterStateManager instance for a calendar element.
 */
export function getFilterState( calendar: HTMLElement ): FilterStateManager {
	if ( ! instances.has( calendar ) ) {
		instances.set( calendar, new FilterStateManager( calendar ) );
	}
	return instances.get( calendar )!;
}

/**
 * Destroy FilterStateManager instance for a calendar element.
 */
export function destroyFilterState( calendar: HTMLElement ): void {
	instances.delete( calendar );
}
