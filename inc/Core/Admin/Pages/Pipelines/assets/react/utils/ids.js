/**
 * ID Comparison Utilities
 *
 * Normalizes ID comparisons to prevent type coercion issues.
 * PHP APIs return integers, but cached/stored values may be strings.
 */

/**
 * Compare two IDs for equality (type-safe)
 *
 * @param {string|number} a - First ID
 * @param {string|number} b - Second ID
 * @return {boolean} True if IDs match
 */
export const isSameId = ( a, b ) => {
	if ( a == null || b == null ) {
		return false;
	}
	return String( a ) === String( b );
};

/**
 * Check if an ID exists in an array (type-safe)
 *
 * @param {Array<string|number>} ids - Array of IDs
 * @param {string|number}        id  - ID to find
 * @return {boolean} True if ID exists in array
 */
export const includesId = ( ids, id ) => {
	if ( ! Array.isArray( ids ) || id == null ) {
		return false;
	}
	return ids.some( ( item ) => String( item ) === String( id ) );
};

/**
 * Normalize an ID to string format
 *
 * @param {string|number} id - ID to normalize
 * @return {string|null} Normalized string ID or null
 */
export const normalizeId = ( id ) => {
	if ( id == null || id === '' ) {
		return null;
	}
	return String( id );
};
