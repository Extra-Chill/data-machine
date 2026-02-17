/**
 * External dependencies
 */
import React from 'react';
/**
 * WordPress dependencies
 */
import { Modal, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { MODAL_TYPES } from '../../utils/constants';
import {
	ImportExportModal,
	StepSelectionModal,
	ConfigureStepModal,
	FlowScheduleModal,
	FlowQueueModal,
	HandlerSelectionModal,
	HandlerSettingsModal,
	OAuthAuthenticationModal,
	ContextFilesModal,
} from '../modals';

export default function ModalSwitch( { activeModal, baseProps } ) {
	if ( ! activeModal ) {
		return null;
	}

	switch ( activeModal ) {
		case MODAL_TYPES.IMPORT_EXPORT:
			return <ImportExportModal { ...baseProps } />;

		case MODAL_TYPES.STEP_SELECTION:
			return <StepSelectionModal { ...baseProps } />;

		case MODAL_TYPES.CONFIGURE_STEP:
			return (
				<ConfigureStepModal
					key={ baseProps.pipelineStepId }
					{ ...baseProps }
				/>
			);

		case MODAL_TYPES.FLOW_SCHEDULE:
			return <FlowScheduleModal { ...baseProps } />;

		case MODAL_TYPES.FLOW_QUEUE:
			return <FlowQueueModal { ...baseProps } />;

		case MODAL_TYPES.HANDLER_SELECTION:
			return (
				<HandlerSelectionModal
					{ ...baseProps }
					handlers={ baseProps.handlers }
					existingHandlerSlugs={ baseProps.addMode ? ( baseProps.handlerSlugs || [] ) : [] }
				/>
			);

		case MODAL_TYPES.HANDLER_SETTINGS:
			return (
				<HandlerSettingsModal
					{ ...baseProps }
					handlerDetails={ baseProps.handlerDetails }
					onChangeHandler={ baseProps.onChangeHandler }
					onOAuthConnect={ baseProps.onOAuthConnect }
				/>
			);

		case MODAL_TYPES.OAUTH:
			return <OAuthAuthenticationModal { ...baseProps } />;

		case MODAL_TYPES.CONTEXT_FILES:
			return <ContextFilesModal { ...baseProps } />;

		default:
			console.warn( `Unknown modal type: ${ activeModal }` );
			return null;
	}
}
