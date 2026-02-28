/**
 * URL state utilities for calendar filtering.
 */

/**
 * Format date as YYYY-MM-DD
 */
export function formatDate( date: Date ): string {
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
}

/**
 * Get current URL parameters
 */
export function getUrlParams(): URLSearchParams {
	return new URLSearchParams( window.location.search );
}
