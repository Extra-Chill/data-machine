/**
 * ModalManager Component
 *
 * Centralized modal rendering and callback logic for the pipelines page.
 * Owns all modal-to-modal navigation callbacks, eliminating prop drilling
 * through PipelinesApp.
 */

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';

/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { useUIStore } from '../../stores/uiStore';
import { useHandlers, useHandlerDetails } from '../../queries/handlers';
import { useUpdateFlowHandler, useAddFlowHandler, useRemoveFlowHandler } from '../../queries/flows';
import { MODAL_TYPES } from '../../utils/constants';
import ModalSwitch from './ModalSwitch';
import { HandlerProvider } from '../../context/HandlerProvider';

export default function ModalManager() {
	const { activeModal, modalData, openModal, closeModal } = useUIStore();
	const { data: handlers = {} } = useHandlers();
	const updateHandlerMutation = useUpdateFlowHandler();
	const addHandlerMutation = useAddFlowHandler();
	const removeHandlerMutation = useRemoveFlowHandler();

	// Fetch handler details when settings modal is open and not already seeded.
	const handlerSlug =
		activeModal === MODAL_TYPES.HANDLER_SETTINGS &&
		! modalData?.handlerDetails
			? modalData?.handlerSlug
			: null;
	const { data: handlerDetails } = useHandlerDetails( handlerSlug );

	/**
	 * Close the active modal.
	 */
	const handleSuccess = useCallback( () => {
		closeModal();
	}, [ closeModal ] );

	/**
	 * Handler selected in HandlerSelectionModal — persist to flow step,
	 * then transition to HandlerSettingsModal.
	 *
	 * When modalData.addMode is true, the handler is added alongside existing
	 * handlers via the add_handler ability instead of replacing the current one.
	 */
	const handleHandlerSelected = useCallback(
		async ( selectedHandlerSlug ) => {
			if ( modalData.addMode && modalData.handlerSlug ) {
				// Add handler mode — use abilities API.
				const result = await addHandlerMutation.mutateAsync( {
					flowStepId: modalData.flowStepId,
					handlerSlug: selectedHandlerSlug,
					settings: {},
					pipelineId: modalData.pipelineId,
					flowId: modalData.flowId,
				} );

				if ( ! result || ! result.success ) {
					const message =
						result?.message ||
						'Failed to add handler to this flow step.';
					throw new Error( message );
				}

				// Open settings for the newly added handler.
				openModal( MODAL_TYPES.HANDLER_SETTINGS, {
					...modalData,
					handlerSlug: selectedHandlerSlug,
					handlerSlugs: [
						...( modalData.handlerSlugs || [] ),
						selectedHandlerSlug,
					],
					currentSettings: {},
					addMode: false,
				} );
			} else {
				// Standard mode — replace handler.
				const result = await updateHandlerMutation.mutateAsync( {
					flowStepId: modalData.flowStepId,
					handlerSlug: selectedHandlerSlug,
					settings: {},
					pipelineId: modalData.pipelineId,
					stepType: modalData.stepType,
				} );

				if ( ! result || ! result.success ) {
					const message =
						result?.message ||
						'Failed to assign handler to this flow step.';
					throw new Error( message );
				}

				// Derive current settings from plural fields, fall back to singular.
				const stepConfig = result?.data?.step_config || {};
				const handlerConfigs = stepConfig.handler_configs || {};
				const currentSettings = handlerConfigs[selectedHandlerSlug] || stepConfig.handler_config || {};

				openModal( MODAL_TYPES.HANDLER_SETTINGS, {
					...modalData,
					handlerSlug: selectedHandlerSlug,
					currentSettings,
				} );
			}
		},
		[ openModal, modalData, updateHandlerMutation, addHandlerMutation ]
	);

	/**
	 * Navigate from HandlerSettingsModal → HandlerSelectionModal.
	 */
	const handleChangeHandler = useCallback( () => {
		openModal( MODAL_TYPES.HANDLER_SELECTION, modalData );
	}, [ openModal, modalData ] );

	/**
	 * Remove a handler from the step (multi-handler mode).
	 * Closes the modal and invalidates flow data.
	 */
	const handleRemoveHandler = useCallback(
		async ( slugToRemove ) => {
			const result = await removeHandlerMutation.mutateAsync( {
				flowStepId: modalData.flowStepId,
				handlerSlug: slugToRemove,
				pipelineId: modalData.pipelineId,
				flowId: modalData.flowId,
			} );

			if ( ! result || ! result.success ) {
				const message =
					result?.message ||
					'Failed to remove handler from this flow step.';
				throw new Error( message );
			}

			closeModal();
		},
		[ modalData, removeHandlerMutation, closeModal ]
	);

	/**
	 * Navigate from HandlerSettingsModal → OAuthModal.
	 */
	const handleOAuthConnect = useCallback(
		( oauthHandlerSlug, handlerInfo ) => {
			openModal( MODAL_TYPES.OAUTH, {
				...modalData,
				handlerSlug: oauthHandlerSlug,
				handlerInfo,
			} );
		},
		[ openModal, modalData ]
	);

	/**
	 * Navigate from OAuthModal → HandlerSettingsModal.
	 */
	const handleBackToSettings = useCallback( () => {
		openModal( MODAL_TYPES.HANDLER_SETTINGS, modalData );
	}, [ openModal, modalData ] );

	if ( ! activeModal ) {
		return null;
	}

	const baseProps = {
		onClose: closeModal,
		...modalData,
		onSuccess: handleSuccess,
		handlers,
		handlerDetails: modalData?.handlerDetails ?? handlerDetails,
		onChangeHandler: handleChangeHandler,
		onOAuthConnect: handleOAuthConnect,
		onBackToSettings: handleBackToSettings,
		onSelectHandler: handleHandlerSelected,
		onRemoveHandler: handleRemoveHandler,
	};

	return (
		<HandlerProvider>
			<ModalSwitch
				activeModal={ activeModal }
				baseProps={ baseProps }
				modalData={ modalData }
			/>
		</HandlerProvider>
	);
}
