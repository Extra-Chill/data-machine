/**
 * WebhookUrlField Component
 *
 * Reusable URL input field with validation, auto-save (debounced),
 * saving indicator, and error handling. Works for webhook URLs,
 * API endpoints, and any URL-type field.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { AUTO_SAVE_DELAY } from '../../utils/constants';

/**
 * Validate URL format
 *
 * @param {string} url - URL to validate
 * @return {boolean} Whether URL is valid
 */
const isValidUrl = ( url ) => {
	if ( ! url || ! url.trim() ) {
		return true; // Empty is valid (not required by this component)
	}

	try {
		const parsed = new URL( url );
		return [ 'http:', 'https:' ].includes( parsed.protocol );
	} catch {
		return false;
	}
};

/**
 * WebhookUrlField Component
 *
 * @param {Object}   props             - Component props
 * @param {string}   props.value       - Current field value
 * @param {Function} props.onChange    - Change handler (for immediate updates)
 * @param {Function} props.onSave      - Save handler (async, returns { success, message? })
 * @param {string}   props.label       - Field label
 * @param {string}   props.placeholder - Placeholder text
 * @param {string}   props.help        - Help text (overridden when saving/error)
 * @param {boolean}  props.disabled    - Whether the field is disabled
 * @param {boolean}  props.required    - Whether the field is required
 * @param {string}   props.className   - Additional CSS class
 * @return {React.ReactElement} Webhook URL field component
 */
export default function WebhookUrlField( {
	value = '',
	onChange,
	onSave,
	label = __( 'Webhook URL', 'data-machine' ),
	placeholder = __( 'https://example.com/webhook', 'data-machine' ),
	help,
	disabled = false,
	required = false,
	className = '',
} ) {
	const [ localValue, setLocalValue ] = useState( value );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ validationError, setValidationError ] = useState( null );
	const saveTimeout = useRef( null );
	const lastSavedValue = useRef( value );

	/**
	 * Sync local value with external value changes
	 */
	useEffect( () => {
		setLocalValue( value );
		lastSavedValue.current = value;
	}, [ value ] );

	/**
	 * Save value to API
	 */
	const saveValue = useCallback(
		async ( newValue ) => {
			if ( ! onSave ) {
				return;
			}

			// Skip if unchanged
			if ( newValue === lastSavedValue.current ) {
				return;
			}

			// Validate URL before saving
			if ( ! isValidUrl( newValue ) ) {
				setValidationError(
					__( 'Please enter a valid URL', 'data-machine' )
				);
				return;
			}

			// Check required
			if ( required && ! newValue.trim() ) {
				setValidationError(
					__( 'URL is required', 'data-machine' )
				);
				return;
			}

			setValidationError( null );
			setIsSaving( true );
			setError( null );

			try {
				const result = await onSave( newValue );

				if ( result?.success === false ) {
					setError(
						result.message ||
							__( 'Failed to save', 'data-machine' )
					);
					// Revert to last saved value on error
					setLocalValue( lastSavedValue.current );
				} else {
					lastSavedValue.current = newValue;
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'WebhookUrlField save error:', err );
				setError(
					err.message || __( 'An error occurred', 'data-machine' )
				);
				// Revert to last saved value on error
				setLocalValue( lastSavedValue.current );
			} finally {
				setIsSaving( false );
			}
		},
		[ onSave, required ]
	);

	/**
	 * Handle value change with debouncing
	 */
	const handleChange = useCallback(
		( newValue ) => {
			setLocalValue( newValue );
			setValidationError( null );

			// Call immediate onChange if provided
			if ( onChange ) {
				onChange( newValue );
			}

			// Clear existing timeout
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			// Set new timeout for debounced save
			if ( onSave ) {
				saveTimeout.current = setTimeout( () => {
					saveValue( newValue );
				}, AUTO_SAVE_DELAY );
			}
		},
		[ onChange, onSave, saveValue ]
	);

	/**
	 * Cleanup timeout on unmount
	 */
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	/**
	 * Get help text with saving indicator
	 */
	const getHelpText = () => {
		if ( validationError ) {
			return validationError;
		}
		if ( isSaving ) {
			return __( 'Saving…', 'data-machine' );
		}
		return help;
	};

	return (
		<div className={ `datamachine-webhook-url-field ${ className }` }>
			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<TextControl
				label={ label }
				value={ localValue }
				onChange={ handleChange }
				placeholder={ placeholder }
				help={ getHelpText() }
				disabled={ disabled || isSaving }
				type="url"
				className={ validationError ? 'has-error' : '' }
			/>
		</div>
	);
}
