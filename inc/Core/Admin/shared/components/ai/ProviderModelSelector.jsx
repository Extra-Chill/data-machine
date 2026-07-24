/**
 * Provider Model Selector Component
 *
 * Shared component for selecting AI provider and model.
 * Handles loading states, defaults application, and model reset on provider change.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useMemo } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useProviders } from '@shared/queries/providers';

/**
 * Provider Model Selector Component
 *
 * @param {Object}   props                  - Component props
 * @param {string}   props.provider         - Current provider value
 * @param {string}   props.model            - Current model value
 * @param {Function} props.onProviderChange - Provider change callback
 * @param {Function} props.onModelChange    - Model change callback
 * @param {boolean}  props.disabled         - Disable both selects
 * @param {boolean}  props.applyDefaults    - Apply defaults when no values set (default: true)
 * @param {string}   props.providerLabel    - Custom provider label
 * @param {string}   props.modelLabel       - Custom model label
 * @param {string}   props.providerHelp     - Provider help text
 * @param {string}   props.modelHelp        - Model help text
 * @return {React.ReactElement} Provider model selector
 */
export default function ProviderModelSelector( {
	provider = '',
	model = '',
	onProviderChange,
	onModelChange,
	disabled = false,
	applyDefaults = true,
	providerLabel,
	modelLabel,
	providerHelp,
	modelHelp,
} ) {
	const { data: providersData, isLoading } = useProviders();

	const providers = providersData?.providers || {};
	const defaults = providersData?.defaults || {};

	// Apply defaults when data loads and no values are set
	useEffect( () => {
		if ( isLoading || ! applyDefaults ) {
			return;
		}

		if ( ! provider && defaults.provider ) {
			onProviderChange?.( defaults.provider );
		}
	}, [
		isLoading,
		provider,
		defaults.provider,
		applyDefaults,
		onProviderChange,
	] );

	// Apply default model when provider matches default and no model is set
	useEffect( () => {
		if ( isLoading || ! applyDefaults ) {
			return;
		}

		if (
			provider &&
			provider === defaults.provider &&
			! model &&
			defaults.model
		) {
			onModelChange?.( defaults.model );
		}
	}, [
		isLoading,
		provider,
		model,
		defaults.provider,
		defaults.model,
		applyDefaults,
		onModelChange,
	] );

	const providerOptions = useMemo( () => {
		const options = [
			{ value: '', label: __( 'Select Provider…', 'data-machine' ) },
		];

		Object.entries( providers ).forEach( ( [ key, providerData ] ) => {
			options.push( {
				value: key,
				label: providerData.label || key,
			} );
		} );

		return options;
	}, [ providers ] );

	const modelOptions = useMemo( () => {
		if ( ! provider || ! providers[ provider ] ) {
			return [
				{
					value: '',
					label: __( 'Select provider first…', 'data-machine' ),
				},
			];
		}

		const options = [
			{ value: '', label: __( 'Select Model…', 'data-machine' ) },
		];

		const providerData = providers[ provider ];

		if ( providerData.models ) {
			if ( Array.isArray( providerData.models ) ) {
				providerData.models.forEach( ( modelData ) => {
					options.push( {
						value: modelData.id,
						label: modelData.name || modelData.id,
					} );
				} );
			} else if ( typeof providerData.models === 'object' ) {
				Object.entries( providerData.models ).forEach(
					( [ modelId, modelLabel ] ) => {
						options.push( {
							value: modelId,
							label: modelLabel || modelId,
						} );
					}
				);
			}
		}

		return options;
	}, [ provider, providers ] );

	const handleProviderChange = ( value ) => {
		onProviderChange?.( value );
		// Reset model when provider changes
		onModelChange?.( '' );
	};

	const handleModelChange = ( value ) => {
		onModelChange?.( value );
	};

	return (
		<>
			<SelectControl
				label={ providerLabel || __( 'AI Provider', 'data-machine' ) }
				value={ provider }
				options={ providerOptions }
				onChange={ handleProviderChange }
				disabled={ disabled || isLoading }
				help={ providerHelp }
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ modelLabel || __( 'AI Model', 'data-machine' ) }
				value={ model }
				options={ modelOptions }
				onChange={ handleModelChange }
				disabled={ disabled || isLoading || ! provider }
				help={ modelHelp }
				__nextHasNoMarginBottom
			/>
		</>
	);
}
