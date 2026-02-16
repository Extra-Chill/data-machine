/**
 * Inline Step Config Component.
 *
 * Schema-driven inline editor for flow step handler_config fields.
 * Fetches field definitions from the handler details API and renders
 * editable fields using HandlerSettingField.
 *
 * Replaces hardcoded per-step-type field rendering in FlowStepCard.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import HandlerSettingField from '../modals/handler-settings/HandlerSettingField';
import { useHandlerDetails } from '../../queries/handlers';
import { updateFlowStepConfig } from '../../utils/api';
import { AUTO_SAVE_DELAY } from '../../utils/constants';

/**
 * InlineStepConfig Component.
 *
 * @param {Object}   props               - Component props.
 * @param {string}   props.flowStepId    - Flow step ID.
 * @param {Object}   props.handlerConfig - Current handler_config values.
 * @param {string}   props.handlerSlug   - Handler or step type slug.
 * @param {string[]} props.excludeFields - Field keys to exclude (e.g., 'prompt').
 * @param {Function} props.onError       - Error callback.
 * @return {JSX.Element|null} Inline config fields.
 */
export default function InlineStepConfig( {
	flowStepId,
	handlerConfig = {},
	handlerSlug,
	excludeFields = [],
	onError,
} ) {
	// Fetch full field schema from handler details API.
	const { data: handlerDetails } = useHandlerDetails( handlerSlug );
	const fieldSchema = handlerDetails?.settings || {};

	// Filter out excluded fields and fields with type 'info'.
	const fieldEntries = Object.entries( fieldSchema ).filter(
		( [ key, config ] ) =>
			! excludeFields.includes( key ) && config.type !== 'info'
	);

	// Local state for field values.
	const [ localValues, setLocalValues ] = useState( {} );
	const saveTimeout = useRef( null );
	const localValuesRef = useRef( localValues );

	// Initialize local values from handler config.
	useEffect( () => {
		if ( fieldEntries.length === 0 ) {
			return;
		}
		const initial = {};
		fieldEntries.forEach( ( [ key, config ] ) => {
			initial[ key ] =
				handlerConfig[ key ] ?? config.current_value ?? config.default ?? '';
		} );
		setLocalValues( initial );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ handlerSlug, JSON.stringify( handlerConfig ) ] );

	// Keep ref in sync.
	useEffect( () => {
		localValuesRef.current = localValues;
	}, [ localValues ] );

	// Cleanup on unmount.
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	/**
	 * Handle field change with debounced save.
	 */
	const handleFieldChange = useCallback(
		( fieldKey, value ) => {
			setLocalValues( ( prev ) => ( { ...prev, [ fieldKey ]: value } ) );

			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( async () => {
				try {
					const currentValues = {
						...localValuesRef.current,
						[ fieldKey ]: value,
					};
					const response = await updateFlowStepConfig( flowStepId, {
						handler_config: currentValues,
					} );
					if ( ! response?.success && onError ) {
						onError(
							response?.message || 'Failed to save settings'
						);
					}
				} catch ( err ) {
					// eslint-disable-next-line no-console
					console.error( 'Inline config save error:', err );
					if ( onError ) {
						onError( err.message || 'An error occurred' );
					}
				}
			}, AUTO_SAVE_DELAY );
		},
		[ flowStepId, onError ]
	);

	// Don't render until we have the field schema.
	if ( fieldEntries.length === 0 ) {
		return null;
	}

	return (
		<div className="datamachine-inline-step-config">
			{ fieldEntries.map( ( [ key, config ] ) => (
				<HandlerSettingField
					key={ key }
					fieldKey={ key }
					fieldConfig={ config }
					value={ localValues[ key ] ?? '' }
					onChange={ handleFieldChange }
					handlerSlug={ handlerSlug }
				/>
			) ) }
		</div>
	);
}
