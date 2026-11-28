/**
 * Handler settings field helpers
 */

const coerceByType = ( fieldConfig = {}, value ) => {
	if ( fieldConfig.type === 'checkbox' ) {
		return !! value;
	}
	return value;
};

export const resolveFieldValue = (
	fieldKey,
	fieldConfig = {},
	settings = {}
) => {
	if ( Object.prototype.hasOwnProperty.call( settings, fieldKey ) ) {
		return coerceByType( fieldConfig, settings[ fieldKey ] );
	}

	if ( fieldConfig.default !== undefined ) {
		return coerceByType( fieldConfig, fieldConfig.default );
	}

	return coerceByType(
		fieldConfig,
		fieldConfig.type === 'checkbox' ? false : ''
	);
};

/**
 * Sanitize handler settings payload with proper type coercion.
 *
 * Ensures values are coerced to correct types based on field schema.
 * Critical for handlers that expect specific types (e.g., integer user IDs).
 *
 * @param {Object} settings       Current settings values
 * @param {Object} settingsFields Field schema definitions
 * @return {Object} Sanitized settings with proper types
 */
export default { resolveFieldValue };
