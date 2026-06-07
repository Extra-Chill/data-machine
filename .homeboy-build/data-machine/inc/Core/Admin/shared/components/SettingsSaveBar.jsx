/**
 * SettingsSaveBar Component
 *
 * Shared save button with status indicators for settings tabs.
 * Eliminates copy-pasted save UI across GeneralTab, AgentTab, etc.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';

/**
 * Hook to manage save status state with auto-clear timers.
 *
 * @param {Object}   options
 * @param {Function} options.onSave   Async save function
 * @param {Function} options.onSaved  Optional callback after successful save
 * @return {Object} Save state and handler
 */
export const useSaveStatus = ( { onSave, onSaved } = {} ) => {
	const [ saveStatus, setSaveStatus ] = useState( null );
	const [ hasChanges, setHasChanges ] = useState( false );

	const markChanged = useCallback( () => {
		setHasChanges( true );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaveStatus( 'saving' );
		try {
			await onSave();
			setSaveStatus( 'saved' );
			setHasChanges( false );
			if ( onSaved ) {
				onSaved();
			}
			setTimeout( () => setSaveStatus( null ), 2000 );
		} catch ( err ) {
			setSaveStatus( 'error' );
			setTimeout( () => setSaveStatus( null ), 3000 );
		}
	}, [ onSave, onSaved ] );

	return {
		saveStatus,
		hasChanges,
		setHasChanges,
		markChanged,
		handleSave,
	};
};

/**
 * SettingsSaveBar — renders save button + status indicators.
 *
 * @param {Object}   props
 * @param {boolean}  props.hasChanges Whether the form has unsaved changes
 * @param {string}   props.saveStatus Current save status ('saving'|'saved'|'error'|null)
 * @param {Function} props.onSave     Save handler
 */
const SettingsSaveBar = ( { hasChanges, saveStatus, onSave } ) => {
	return (
		<div className="datamachine-settings-submit">
			<button
				type="button"
				className="button button-primary"
				onClick={ onSave }
				disabled={ ! hasChanges || saveStatus === 'saving' }
			>
				{ saveStatus === 'saving' ? 'Saving...' : 'Save Changes' }
			</button>

			{ hasChanges && saveStatus !== 'saving' && (
				<span className="datamachine-unsaved-indicator">
					Unsaved changes
				</span>
			) }

			{ saveStatus === 'saved' && (
				<span className="datamachine-saved-indicator">
					Settings saved!
				</span>
			) }

			{ saveStatus === 'error' && (
				<span className="datamachine-error-indicator">
					Error saving settings
				</span>
			) }
		</div>
	);
};

export default SettingsSaveBar;
