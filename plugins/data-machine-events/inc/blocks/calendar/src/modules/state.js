/**
 * URL state utilities for calendar filtering.
 */

/**
 * Format date as YYYY-MM-DD
 * @param {Date} date
 * @returns {string}
 */
export function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Get current URL parameters
 * @returns {URLSearchParams}
 */
export function getUrlParams() {
    return new URLSearchParams(window.location.search);
}
