/**
 * UI State Store
 *
 * Zustand store for managing UI state (selected pipeline, modals, chat sidebar).
 * Persists selectedPipelineId, isChatOpen, and chatSessionId to localStorage.
 */

/**
 * External dependencies
 */
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
/**
 * Internal dependencies
 */
import { isSameId, normalizeId } from '../utils/ids';

export const useUIStore = create(
	persist(
		( set, get ) => ( {
			// Pipeline selection
			selectedPipelineId: null,
			setSelectedPipelineId: ( id ) => {
				if ( id === null || id === undefined || id === '' ) {
					set( { selectedPipelineId: null } );
					return;
				}
				set( { selectedPipelineId: normalizeId( id ) } );
			},

			// Modal state
			activeModal: null,
			modalData: null,
			openModal: ( modalType, data = null ) => {
				set( { activeModal: modalType, modalData: data } );
			},
			closeModal: () => {
				set( { activeModal: null, modalData: null } );
			},

			// Chat sidebar state
			isChatOpen: false,
			chatSessionId: null,
			toggleChat: () =>
				set( ( state ) => ( { isChatOpen: ! state.isChatOpen } ) ),
			setChatOpen: ( open ) => set( { isChatOpen: open } ),
			setChatSessionId: ( id ) => set( { chatSessionId: id } ),
			clearChatSession: () => set( { chatSessionId: null } ),

			// Utility functions
			getSelectedPipelineId: () => get().selectedPipelineId,
			isModalOpen: ( modalType ) => get().activeModal === modalType,

			// Pipeline navigation helpers
			selectNextPipeline: ( pipelines ) => {
				const currentId = get().selectedPipelineId;
				if ( ! pipelines || pipelines.length === 0 ) {
					set( { selectedPipelineId: null } );
					return;
				}

				const normalizedCurrentId = normalizeId( currentId );

				// If current pipeline still exists, keep it selected
				if (
					normalizedCurrentId &&
					pipelines.some( ( p ) =>
						isSameId( p.pipeline_id, normalizedCurrentId )
					)
				) {
					return;
				}

				// Otherwise, select the first available pipeline
				set( {
					selectedPipelineId: normalizeId(
						pipelines[ 0 ].pipeline_id
					),
				} );
			},
		} ),
		{
			name: 'datamachine-ui-store',
			partialize: ( state ) => ( {
				selectedPipelineId: state.selectedPipelineId,
				isChatOpen: state.isChatOpen,
				chatSessionId: state.chatSessionId,
			} ),
			version: 2,
			merge: ( persistedState, currentState ) => ( {
				...currentState,
				...persistedState,
			} ),
		}
	)
);
