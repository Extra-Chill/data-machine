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
import { useState, useCallback, useRef, useEffect, useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import HandlerSettingField from '../modals/handler-settings/HandlerSettingField';
import { useHandlerDetails } from '../../queries/handlers';
import { useUpdateFlowStepConfig } from '../../queries/flows';
/**
 * External dependencies
 */
import useDebouncedAutosave from '@shared/hooks/useDebouncedAutosave';

const EMPTY_EXCLUDED_FIELDS = [];

/**
 * InlineStepConfig Component.
 *
 * @param {Object}   props               - Component props.
 * @param {string}   props.flowStepId    - Flow step ID.
 * @param {Object}   props.handlerConfig - Current handler_config values.
 * @param {string}   props.handlerSlug   - Handler or step type slug.
 * @param {string[]} props.excludeFields - Field keys to exclude (e.g., 'prompt').
 * @param {Function} props.onError       - Error callback.
 * @param {number}   props.pipelineId    - Pipeline ID (for cache invalidation).
 * @param {number}   props.flowId        - Flow ID (for cache invalidation).
 * @return {React.ReactElement|null} Inline config fields.
 */
export default function InlineStepConfig( {
	flowStepId,
	handlerConfig = {},
	handlerSlug,
	excludeFields = EMPTY_EXCLUDED_FIELDS,
	onError,
	pipelineId,
	flowId,
} ) {
	// Fetch full field schema from handler details API.
	const { data: handlerDetails } = useHandlerDetails( handlerSlug );
	// Filter out excluded fields and fields with type 'info'.
	const fieldEntries = useMemo(
		() =>
			Object.entries( handlerDetails?.settings || {} ).filter(
				( [ key, config ] ) =>
					! excludeFields.includes( key ) && config.type !== 'info'
			),
		[ handlerDetails?.settings, excludeFields ]
	);

	// Derive initial values from handlerConfig + field schema.
	// Re-derives when handlerSlug, schema, or handlerConfig changes.
	const initialValues = useMemo( () => {
		if ( fieldEntries.length === 0 ) {
			return {};
		}
		const values = {};
		fieldEntries.forEach( ( [ key, config ] ) => {
			values[ key ] =
				handlerConfig[ key ] ?? config.current_value ?? config.default ?? '';
		} );
		return values;
	}, [ fieldEntries, handlerConfig ] );

	// Local state for controlled inputs, initialized from derived values.
	const [ localValues, setLocalValues ] = useState( initialValues );
	const localValuesRef = useRef( localValues );

	const updateConfigMutation = useUpdateFlowStepConfig();

	// Reset local values when initialValues change (handler/config change).
	useEffect( () => {
		if ( Object.keys( initialValues ).length > 0 ) {
			setLocalValues( initialValues );
		}
	}, [ initialValues ] );

	// Keep ref in sync.
	useEffect( () => {
		localValuesRef.current = localValues;
	}, [ localValues ] );

	const saveField = useCallback(
		async ( fieldKey, value ) => {
			try {
				const currentValues = {
					...localValuesRef.current,
					[ fieldKey ]: value,
				};
				const response = await updateConfigMutation.mutateAsync( {
					flowStepId,
					config: { handler_config: currentValues },
					pipelineId,
					flowId,
				} );
				if ( ! response?.success && onError ) {
					onError( response?.message || 'Failed to save settings' );
				}
			} catch ( err ) {
				window.console.error( 'Inline config save error:', err );
				if ( onError ) {
					onError( err.message || 'An error occurred' );
				}
			}
		},
		[ flowStepId, pipelineId, flowId, onError, updateConfigMutation ]
	);
	const scheduleSaveField = useDebouncedAutosave( saveField );

	/**
	 * Handle field change with debounced save.
	 */
	const handleFieldChange = useCallback(
		( fieldKey, value ) => {
			setLocalValues( ( prev ) => ( { ...prev, [ fieldKey ]: value } ) );
			scheduleSaveField( fieldKey, value );
		},
		[ scheduleSaveField ]
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
