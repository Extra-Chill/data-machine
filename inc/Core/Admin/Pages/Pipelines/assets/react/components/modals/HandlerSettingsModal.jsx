/**
 * Handler Settings Modal Component
 *
 * Modal for configuring handler-specific settings for flow steps.
 * Receives complete handler configuration from API with defaults pre-merged.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { useUpdateFlowHandler } from '../../queries/flows';
import { useFormState } from '../../hooks/useFormState';
import HandlerSettingField from './handler-settings/HandlerSettingField';

import useHandlerModel from '../../hooks/useHandlerModel';

/**
 * Handler Settings Modal Component
 *
 * @param {Object}   props                 - Component props
 * @param {Function} props.onClose         - Close handler
 * @param {string}   props.flowStepId      - Flow step ID
 * @param {string}   props.handlerSlug     - Handler slug
 * @param {string}   props.stepType        - Step type
 * @param {number}   props.pipelineId      - Pipeline ID
 * @param {number}   props.flowId          - Flow ID
 * @param {Object}   props.currentSettings - Current handler settings
 * @param {Function} props.onSuccess       - Success callback
 * @param {Function} props.onChangeHandler - Change handler callback
 * @param {Function} props.onOAuthConnect  - OAuth connect callback
 * @param {Object}   props.handlers        - Global handlers metadata from PipelineContext
 * @param {Object}   props.handlerDetails  - Detailed configuration for the selected handler
 * @param {Array}    props.handlerSlugs    - All handler slugs on this step (multi-handler)
 * @param {Function} props.onRemoveHandler - Remove handler callback (multi-handler)
 * @return {React.ReactElement|null} Handler settings modal
 */
export default function HandlerSettingsModal( {
	onClose,
	flowStepId,
	handlerSlug,
	handlerSlugs,
	stepType,
	pipelineId,
	flowId,
	currentSettings,
	onSuccess,
	onChangeHandler,
	onRemoveHandler,
	onOAuthConnect,
	handlers,
	handlerDetails,
} ) {
	// Presentational: Receive handler details as props
	const isLoadingSettings =
		handlerDetails === undefined || handlerDetails === null;
	const updateHandlerMutation = useUpdateFlowHandler();

	const [ settingsFields, setSettingsFields ] = useState( {} );
	const [ isEnrichingSettings, setIsEnrichingSettings ] = useState( false );

	// Track enrichment completion to prevent race conditions when handlerDetails loads
	// This ref stores a key combining handler + settings identity to detect actual changes
	const enrichmentCompleteRef = useRef( null );

	const handlerModel = useHandlerModel( handlerSlug );

	const formState = useFormState( {
		initialData: currentSettings || {},
		onSubmit: async ( data ) => {
			const settingsToSend = handlerModel
				? handlerModel.sanitizeForAPI( data, settingsFields )
				: data;

			const response = await updateHandlerMutation.mutateAsync( {
				flowStepId,
				handlerSlug,
				settings: settingsToSend,
				pipelineId,
				stepType,
			} );

			if ( ! response || ! response.success ) {
				const message =
					response?.message ||
					__( 'Failed to update handler settings', 'data-machine' );
				throw new Error( message );
			}

			if ( onSuccess ) {
				onSuccess();
			}
			onClose();
		},
	} );

	// Update settings fields when handler details load
	useEffect( () => {
		if ( handlerDetails?.settings ) {
			setSettingsFields( handlerDetails.settings );
		}
	}, [ handlerDetails ] );

	/**
	 * Initialize form when modal opens.
	 * Applies 'datamachine.handlerSettings.init' filter to allow plugins
	 * to enrich settings (e.g., fetching related data like venue details).
	 *
	 * Uses enrichmentCompleteRef to prevent duplicate enrichment when handlerDetails
	 * loads asynchronously, which would cause the effect to re-run and potentially
	 * reset already-enriched form data.
	 */
	useEffect( () => {
		const initializeForm = async () => {
			let settings = currentSettings || {};

			// Create a stable key to identify this specific settings state
			// Only re-enrich if currentSettings actually changed (not just handlerDetails loading)
			const settingsKey = JSON.stringify( currentSettings || {} );

			// Skip enrichment if we've already completed it for these exact settings
			if ( enrichmentCompleteRef.current === settingsKey ) {
				return;
			}

			setIsEnrichingSettings( true );

			try {
				settings = await applyFilters(
					'datamachine.handlerSettings.init',
					Promise.resolve( settings ),
					handlerSlug,
					handlerDetails?.settings || {}
				);

				// Mark enrichment complete for these settings
				enrichmentCompleteRef.current = settingsKey;
			} catch ( error ) {
				console.error( 'Handler settings enrichment failed:', error );
			}

			setIsEnrichingSettings( false );

			if ( handlerModel ) {
				const normalized = handlerModel.normalizeForForm(
					settings,
					handlerDetails?.settings || {}
				);
				formState.reset( normalized );
			} else {
				formState.reset( settings );
			}
		};

		initializeForm();
	}, [ currentSettings, handlerModel, handlerDetails ] );

	/**
	 * Get handler info from props
	 */
	const handlerInfo = handlers[ handlerSlug ] || {};

	/**
	 * Handle setting change with plugin hook support.
	 * Applies 'datamachine.handlerSettings.fieldChange' filter to allow plugins
	 * to react to field changes (e.g., loading venue data when dropdown changes).
	 * @param key
	 * @param value
	 */
	const handleSettingChange = async ( key, value ) => {
		formState.updateField( key, value );

		try {
			const enrichedData = await applyFilters(
				'datamachine.handlerSettings.fieldChange',
				Promise.resolve( {} ),
				key,
				value,
				handlerSlug,
				{ ...formState.data, [ key ]: value }
			);

			if ( enrichedData && Object.keys( enrichedData ).length > 0 ) {
				formState.updateData( enrichedData );
			}
		} catch ( error ) {
			console.error(
				'Handler settings field change enrichment failed:',
				error
			);
		}
	};

	return (
		<Modal
			title={
				handlerInfo.label
					? sprintf(
							__( 'Configure %s Settings', 'data-machine' ),
							handlerInfo.label
					  )
					: __( 'Configure Handler Settings', 'data-machine' )
			}
			onRequestClose={ onClose }
			className="datamachine-handler-settings-modal"
		>
			<div className="datamachine-modal-content">
				{ formState.error && (
					<div className="datamachine-modal-error notice notice-error">
						<p>{ formState.error }</p>
					</div>
				) }

				<div className="datamachine-modal-section">
					<div className="datamachine-modal-header-section">
						<div>
							<strong>
								{ __( 'Handler:', 'data-machine' ) }
							</strong>{ ' ' }
							{ handlerInfo.label || handlerSlug }
						</div>
						<Button
							variant="secondary"
							size="small"
							onClick={ onChangeHandler }
						>
							{ __( 'Change Handler', 'data-machine' ) }
						</Button>
						{ handlerSlugs?.length > 1 && onRemoveHandler && (
							<Button
								variant="secondary"
								size="small"
								isDestructive
								onClick={ () => onRemoveHandler( handlerSlug ) }
							>
								{ __( 'Remove Handler', 'data-machine' ) }
							</Button>
						) }
					</div>

					{ handlerInfo.requires_auth && (
						<div className="datamachine-modal-handler-display">
							{ handlerInfo.is_authenticated ? (
								<div className="datamachine-auth-status datamachine-auth-status--connected">
									<span className="dashicons dashicons-yes-alt"></span>
									<span>
										{ handlerInfo.account_details?.username
											? sprintf(
													__(
														'Connected as %s',
														'data-machine'
													),
													handlerInfo.account_details
														.username
											  )
											: __(
													'Account Connected',
													'data-machine'
											  ) }
									</span>
									<Button
										variant="link"
										size="small"
										onClick={ () => {
											if ( onOAuthConnect ) {
												onOAuthConnect(
													handlerSlug,
													handlerInfo
												);
											}
										} }
									>
										{ __(
											'Manage Connection',
											'data-machine'
										) }
									</Button>
								</div>
							) : (
								<Button
									variant="secondary"
									onClick={ () => {
										if ( onOAuthConnect ) {
											onOAuthConnect(
												handlerSlug,
												handlerInfo
											);
										}
									} }
								>
									{ __( 'Connect Account', 'data-machine' ) }
								</Button>
							) }
						</div>
					) }
				</div>

				{ /* Loading state while fetching settings schema or enriching */ }
				{ ( isLoadingSettings || isEnrichingSettings ) && (
					<div className="datamachine-modal-loading-state">
						<p className="datamachine-modal-loading-text">
							{ __(
								'Loading handler settings…',
								'data-machine'
							) }
						</p>
					</div>
				) }

				{ /* Render custom editor or standard fields */ }
				{ ( () => {
					if ( isLoadingSettings ) {
						return null;
					}

					const customEditor = handlerModel?.renderSettingsEditor?.( {
						currentSettings: formState.data,
						onSettingsChange: formState.updateData,
						handlerDetails,
					} );

					if ( customEditor ) {
						return customEditor;
					}

					return (
						<>
							{ Object.keys( settingsFields ).length === 0 && (
								<div className="datamachine-modal-no-config">
									<p>
										{ __(
											'No configuration options available for this handler.',
											'data-machine'
										) }
									</p>
								</div>
							) }

							{ Object.keys( settingsFields ).length > 0 && (
								<div className="datamachine-handler-settings-fields">
									{ Object.entries( settingsFields ).map(
										( [ key, config ] ) => (
											<HandlerSettingField
												key={ key }
												fieldKey={ key }
												fieldConfig={ config }
												value={
													formState.data?.[ key ] !==
													undefined
														? formState.data[ key ]
														: config.default ??
														  config.current_value ??
														  ''
												}
												onChange={ handleSettingChange }
												onBatchChange={
													formState.updateData
												}
												handlerSlug={ handlerSlug }
											/>
										)
									) }
								</div>
							) }
						</>
					);
				} )() }

				<div className="datamachine-modal-actions">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ formState.isSubmitting }
					>
						{ __( 'Cancel', 'data-machine' ) }
					</Button>

					<Button
						variant="primary"
						onClick={ formState.submit }
						disabled={ formState.isSubmitting }
						isBusy={ formState.isSubmitting }
					>
						{ formState.isSubmitting
							? __( 'Saving…', 'data-machine' )
							: __( 'Save Settings', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
