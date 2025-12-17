/**
 * Centralized filter state management for the Calendar block.
 * 
 * Source of truth hierarchy:
 * 1. URL params (explicit, shareable)
 * 2. localStorage (persistence for taxonomy filters only)
 * 
 * Archive context is read from DOM data attributes (page-level, not user state).
 */

const STORAGE_KEY = 'datamachine_events_calendar_state';

class FilterStateManager {
    constructor(calendar) {
        this.calendar = calendar;
        this.archiveContext = this.readArchiveContext();
    }

    /**
     * Read archive context from calendar data attributes
     * @returns {Object}
     */
    readArchiveContext() {
        return {
            taxonomy: this.calendar.dataset.archiveTaxonomy || '',
            term_id: parseInt(this.calendar.dataset.archiveTermId, 10) || 0,
            term_name: this.calendar.dataset.archiveTermName || ''
        };
    }

    /**
     * Get archive context for API calls
     * @returns {Object} { taxonomy, term_id, term_name }
     */
    getArchiveContext() {
        return this.archiveContext;
    }

    /**
     * Parse taxonomy filters from URL
     * @returns {Object} { taxonomy_slug: [term_id, ...], ... }
     */
    getTaxFilters() {
        const params = new URLSearchParams(window.location.search);
        const filters = {};
        
        params.forEach((value, key) => {
            const match = key.match(/^tax_filter\[([^\]]+)\]\[(?:\d+)?\]$/);
            if (match) {
                const taxonomy = match[1];
                if (!filters[taxonomy]) {
                    filters[taxonomy] = [];
                }
                const termId = parseInt(value, 10);
                if (termId > 0 && !filters[taxonomy].includes(termId)) {
                    filters[taxonomy].push(termId);
                }
            }
        });
        
        return filters;
    }

    /**
     * Get date context from URL
     * @returns {Object} { date_start, date_end, past }
     */
    getDateContext() {
        const params = new URLSearchParams(window.location.search);
        return {
            date_start: params.get('date_start') || '',
            date_end: params.get('date_end') || '',
            past: params.get('past') || ''
        };
    }

    /**
     * Get search query from URL
     * @returns {string}
     */
    getSearchQuery() {
        const params = new URLSearchParams(window.location.search);
        return params.get('event_search') || '';
    }

    /**
     * Get current page from URL
     * @returns {number}
     */
    getCurrentPage() {
        const params = new URLSearchParams(window.location.search);
        return parseInt(params.get('paged'), 10) || 1;
    }

    /**
     * Count active taxonomy filters
     * @returns {number}
     */
    getFilterCount() {
        const filters = this.getTaxFilters();
        return Object.values(filters).reduce((sum, arr) => sum + arr.length, 0);
    }

    /**
     * Check if URL has any taxonomy filter params
     * @returns {boolean}
     */
    hasUrlFilters() {
        const params = new URLSearchParams(window.location.search);
        for (const key of params.keys()) {
            if (key.startsWith('tax_filter[')) return true;
        }
        return false;
    }

    /**
     * Build URLSearchParams from current UI state
     * Reads from: search input, date picker, modal checkboxes
     * @param {Object|null} datePicker - Flatpickr instance
     * @returns {URLSearchParams}
     */
    buildParams(datePicker = null) {
        const params = new URLSearchParams();
        
        const searchInput = this.calendar.querySelector('.datamachine-events-search-input');
        if (searchInput?.value) {
            params.set('event_search', searchInput.value);
        }
        
        if (datePicker?.selectedDates?.length > 0) {
            const startDate = datePicker.selectedDates[0];
            const endDate = datePicker.selectedDates[1] || startDate;
            
            params.set('date_start', this.formatDate(startDate));
            params.set('date_end', this.formatDate(endDate));
            
            const now = new Date();
            const endOfRange = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate(), 23, 59, 59);
            if (endOfRange < now) {
                params.set('past', '1');
            }
        }
        
        const modal = this.calendar.querySelector('.datamachine-taxonomy-modal');
        if (modal) {
            const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
            checkboxes.forEach(checkbox => {
                const taxonomy = checkbox.dataset.taxonomy;
                const termId = checkbox.value;
                if (taxonomy && termId) {
                    params.append(`tax_filter[${taxonomy}][]`, termId);
                }
            });
        }
        
        return params;
    }

    /**
     * Update URL via History API and save taxonomy filters to localStorage
     * @param {URLSearchParams} params
     */
    updateUrl(params) {
        const queryString = params.toString();
        const newUrl = queryString 
            ? `${window.location.pathname}?${queryString}` 
            : window.location.pathname;
        window.history.pushState({}, '', newUrl);
        
        this.saveToStorage(params);
    }

    /**
     * Save ONLY taxonomy filters to localStorage (not dates)
     * @param {URLSearchParams} params
     */
    saveToStorage(params) {
        try {
            const taxFilters = {};
            for (const [key, value] of params.entries()) {
                if (!key.startsWith('tax_filter[')) continue;
                
                if (!taxFilters[key]) {
                    taxFilters[key] = [];
                }
                taxFilters[key].push(value);
            }
            
            if (Object.keys(taxFilters).length > 0) {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(taxFilters));
            } else {
                localStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {
            // localStorage unavailable
        }
    }

    /**
     * Restore taxonomy filters from localStorage if URL has no filters
     * @returns {boolean} true if state was restored
     */
    restoreFromStorage() {
        if (this.hasUrlFilters()) return false;
        
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (!stored) return false;
            
            const taxFilters = JSON.parse(stored);
            const params = new URLSearchParams(window.location.search);
            
            Object.entries(taxFilters).forEach(([key, values]) => {
                if (Array.isArray(values)) {
                    values.forEach(v => params.append(key, v));
                }
            });
            
            if (params.toString() !== window.location.search.slice(1)) {
                const newUrl = `${window.location.pathname}?${params.toString()}`;
                window.history.replaceState({}, '', newUrl);
                return true;
            }
        } catch (e) {
            // localStorage unavailable or corrupted
        }
        
        return false;
    }

    /**
     * Clear localStorage
     */
    clearStorage() {
        localStorage.removeItem(STORAGE_KEY);
    }

    /**
     * Update filter count badge on the filter button
     */
    updateFilterCountBadge() {
        const filterBtn = this.calendar.querySelector(
            '.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn'
        );
        const countBadge = filterBtn?.querySelector('.datamachine-filter-count');
        
        if (!filterBtn || !countBadge) return;
        
        const count = this.getFilterCount();
        
        if (count > 0) {
            countBadge.textContent = count;
            countBadge.classList.add('visible');
            filterBtn.classList.add('datamachine-filters-active');
        } else {
            countBadge.textContent = '';
            countBadge.classList.remove('visible');
            filterBtn.classList.remove('datamachine-filters-active');
        }
    }

    /**
     * Format date as YYYY-MM-DD
     * @param {Date} date
     * @returns {string}
     */
    formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
}

const instances = new WeakMap();

/**
 * Get or create FilterStateManager instance for a calendar element
 * @param {HTMLElement} calendar
 * @returns {FilterStateManager}
 */
export function getFilterState(calendar) {
    if (!instances.has(calendar)) {
        instances.set(calendar, new FilterStateManager(calendar));
    }
    return instances.get(calendar);
}

/**
 * Destroy FilterStateManager instance for a calendar element
 * @param {HTMLElement} calendar
 */
export function destroyFilterState(calendar) {
    instances.delete(calendar);
}
