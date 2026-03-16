/**
 * PromptField Component
 *
 * Reusable textarea field with auto-save (debounced), saving indicator,
 * and error handling. Works for AI system prompts, flow step user messages,
 * Agent Ping prompts, and any editable text field.
 *
 * @since 0.42.0
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { TextareaControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Auto-save delay in milliseconds.
 */
const AUTO_SAVE_DELAY = 500;

/**
 * PromptField Component
 *
 * @param {Object}   props             - Component props
 * @param {string}   props.value       - Current field value
 * @param {Function} props.onChange    - Change handler (for immediate updates)
 * @param {Function} props.onSave      - Save handler (async, returns { success, message? })
 * @param {string}   props.label       - Field label
 * @param {string}   props.placeholder - Placeholder text
 * @param {number}   props.rows        - Number of textarea rows (default: 6)
 * @param {string}   props.help        - Help text (overridden when saving)
 * @param {boolean}  props.disabled    - Whether the field is disabled
 * @param {string}   props.className   - Additional CSS class
 * @return {React.ReactElement} Prompt field component
 */
export default function PromptField( {
	value = '',
	onChange,
	onSave,
	label,
	placeholder,
	rows = 6,
	help,
	disabled = false,
	className = '',
} ) {
	const [ localValue, setLocalValue ] = useState( value );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
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

			setIsSaving( true );
			setError( null );

			try {
				const result = await onSave( newValue );

				if ( result?.success === false ) {
					setError(
						result.message || __( 'Failed to save', 'data-machine' )
					);
					// Revert to last saved value on error
					setLocalValue( lastSavedValue.current );
				} else {
					lastSavedValue.current = newValue;
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'PromptField save error:', err );
				setError(
					err.message || __( 'An error occurred', 'data-machine' )
				);
				// Revert to last saved value on error
				setLocalValue( lastSavedValue.current );
			} finally {
				setIsSaving( false );
			}
		},
		[ onSave ]
	);

	/**
	 * Handle value change with debouncing
	 */
	const handleChange = useCallback(
		( newValue ) => {
			setLocalValue( newValue );

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
		if ( isSaving ) {
			return __( 'Saving…', 'data-machine' );
		}
		return help;
	};

	return (
		<div className={ `datamachine-prompt-field ${ className }` }>
			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<TextareaControl
				label={ label }
				value={ localValue }
				onChange={ handleChange }
				placeholder={ placeholder }
				rows={ rows }
				help={ getHelpText() }
				disabled={ disabled || isSaving }
			/>
		</div>
	);
}
