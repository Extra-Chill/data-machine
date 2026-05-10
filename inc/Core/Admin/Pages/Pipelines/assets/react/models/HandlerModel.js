/**
 * Internal dependencies
 */
import { resolveFieldValue } from '../utils/handlerSettings';

const formatDisplayValue = ( value ) => {
	if ( value === undefined || value === null ) {
		return '';
	}

	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}

	return value;
};

export default class HandlerModel {
	constructor( slug, descriptor = {}, details = {} ) {
		this.slug = slug;
		this.descriptor = descriptor || {};
		this.details = details || {};
	}

	// Basic metadata
	getSlug() {
		return this.slug;
	}

	getLabel() {
		return this.descriptor.label || this.slug;
	}

	getDescription() {
		return this.descriptor.description || '';
	}

	requiresAuth() {
		return !! (
			this.descriptor.requires_auth || this.descriptor.requiresAuth
		);
	}

	// Build display settings used by FlowStepHandler
	// Accepts either backend-provided settingsDisplay array OR handler config values
	getDisplaySettings( settingsDisplay = null, handlerConfig = {} ) {
		const display = {};

		if ( Array.isArray( settingsDisplay ) && settingsDisplay.length > 0 ) {
			settingsDisplay.forEach( ( setting ) => {
				display[ setting.key ] = {
					label: setting.label,
					value: formatDisplayValue(
						setting.display_value || setting.value
					),
				};
			} );

			return display;
		}

		// Else, build from details.settings schema and provided handlerConfig
		const schema = this.details?.settings || {};

		Object.entries( schema ).forEach( ( [ key, config ] ) => {
			display[ key ] = {
				label: config.label || key,
				value: formatDisplayValue(
					resolveFieldValue( key, config, handlerConfig )
				),
			};
		} );

		return display;
	}

	// Normalize into form defaults (for forms)
	normalizeForForm( currentSettings = {}, settingsFields = {} ) {
		const normalized = { ...currentSettings };
		const schema = settingsFields || this.details?.settings || {};

		Object.entries( schema ).forEach( ( [ key, config ] ) => {
			if ( ! Object.prototype.hasOwnProperty.call( normalized, key ) ) {
				normalized[ key ] = resolveFieldValue(
					key,
					config,
					currentSettings
				);
			} else {
				// coerce booleans to booleans for checkboxes
				if ( config.type === 'checkbox' ) {
					normalized[ key ] = !! normalized[ key ];
				}
			}
		} );

		return normalized;
	}

	// Sanitize before sending to API
	sanitizeForAPI( data = {}, settingsFields = {} ) {
		const fields = settingsFields || this.details?.settings || {};
		const sanitized = {};

		Object.entries( data ).forEach( ( [ key, value ] ) => {
			const fieldConfig = fields[ key ];

			if ( ! fieldConfig ) {
				sanitized[ key ] = value;
				return;
			}

			switch ( fieldConfig.type ) {
				case 'checkbox':
					sanitized[ key ] = !! value;
					break;

				case 'json':
					if ( value === '' || value === undefined || value === null ) {
						sanitized[ key ] = {};
						break;
					}

					if ( typeof value === 'string' ) {
						try {
							sanitized[ key ] = JSON.parse( value );
						} catch ( error ) {
							throw new Error(
								`Invalid JSON for ${ fieldConfig.label || key }.`
							);
						}
						break;
					}

					sanitized[ key ] = value;
					break;

				case 'select':
					if ( value !== '' && ! isNaN( value ) ) {
						sanitized[ key ] = parseInt( value, 10 );
					} else {
						sanitized[ key ] = value;
					}
					break;

				case 'text':
				case 'textarea':
				default:
					sanitized[ key ] = value;
					break;
			}
		} );

		return sanitized;
	}

	// Validation hook (basic) - subclasses may override with more complex validation
	validate( formData = {} ) {
		return { valid: true, errors: {} };
	}

	// Optionally render a custom React editor for a handler (e.g., Files)
	renderSettingsEditor( props = {} ) {
		return null; // default: no custom UI
	}
}
