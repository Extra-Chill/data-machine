/**
 * Pipelines App Root Component
 *
 * Container component that manages the entire pipeline interface state and data.
 * @pattern Container - Fetches all pipeline-related data and manages global state
 */

/**
 * WordPress dependencies
 */
import { useEffect, useCallback, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner, Notice, Button } from '@wordpress/components';
/**
 * Internal dependencies
 */
import { usePipelines, useCreatePipeline } from './queries/pipelines';
import { useFlows } from './queries/flows';
/**
 * External dependencies
 */
import { useSettings } from '@shared/queries/settings';
import { useUIStore } from './stores/uiStore';
import PipelineCard from './components/pipelines/PipelineCard';
import PipelineSelector from './components/pipelines/PipelineSelector';
import ModalManager from './components/shared/ModalManager';
import ChatToggle from './components/chat/ChatToggle';
import ChatSidebar from './components/chat/ChatSidebar';
import AgentSwitcher from '@shared/components/AgentSwitcher';
import { MODAL_TYPES } from './utils/constants';
import { isSameId } from './utils/ids';

/**
 * Root application component
 *
 * @return {React.ReactElement} Application component
 */
export default function PipelinesApp() {
	// UI state from Zustand
	const {
		selectedPipelineId,
		setSelectedPipelineId,
		openModal,
		isChatOpen,
	} = useUIStore();

	// Check if Zustand has finished hydrating from localStorage
	const hasHydrated = useUIStore.persist.hasHydrated();

	// Flows pagination state
	const [ flowsPage, setFlowsPage ] = useState( 1 );

	// Reset page when pipeline changes
	useEffect( () => {
		setFlowsPage( 1 );
	}, [ selectedPipelineId ] );

	// Data from TanStack Query
	const {
		data: pipelines = [],
		isLoading: pipelinesLoading,
		error: pipelinesError,
	} = usePipelines();
	const { data: settingsData } = useSettings();
	const flowsPerPage = settingsData?.settings?.flows_per_page ?? 20;

	const {
		data: flowsData,
		isLoading: flowsLoading,
		error: flowsError,
	} = useFlows( selectedPipelineId, {
		page: flowsPage,
		perPage: flowsPerPage,
	} );
	const flows = useMemo( () => flowsData?.flows ?? [], [ flowsData ] );
	const flowsTotal = flowsData?.total ?? 0;

	const createPipelineMutation = useCreatePipeline( {
		onSuccess: ( pipelineId ) => {
			setSelectedPipelineId( pipelineId );
		},
	} );
	// Find selected pipeline from pipelines array
	const selectedPipeline = pipelines?.find( ( p ) =>
		isSameId( p.pipeline_id, selectedPipelineId )
	);
	const selectedPipelineLoading = false; // No separate loading for selected pipeline
	const selectedPipelineError = null; // No separate error for selected pipeline

	const [ isCreatingPipeline, setIsCreatingPipeline ] = useState( false );

	/**
	 * Set selected pipeline when pipelines load or when selected pipeline is deleted.
	 * Waits for Zustand hydration AND pipelines query to complete before applying default selection.
	 */
	useEffect( () => {
		if ( ! hasHydrated || pipelinesLoading ) {
			return;
		}

		if ( pipelines.length > 0 && ! selectedPipelineId ) {
			setSelectedPipelineId( pipelines[ 0 ].pipeline_id );
		} else if ( pipelines.length > 0 && selectedPipelineId ) {
			// Check if selected pipeline still exists, if not, select next available
			const selectedPipelineExists = pipelines.some( ( p ) =>
				isSameId( p.pipeline_id, selectedPipelineId )
			);
			if ( ! selectedPipelineExists ) {
				setSelectedPipelineId( pipelines[ 0 ].pipeline_id );
			}
		} else if ( pipelines.length === 0 ) {
			// No pipelines available
			setSelectedPipelineId( null );
		}
	}, [
		pipelines,
		selectedPipelineId,
		setSelectedPipelineId,
		hasHydrated,
		pipelinesLoading,
	] );

	/**
	 * Handle creating a new pipeline
	 */
	const handleAddNewPipeline = useCallback( async () => {
		setIsCreatingPipeline( true );
		try {
			await createPipelineMutation.mutateAsync( 'New Pipeline' );
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Error creating pipeline:', error );
		} finally {
			setIsCreatingPipeline( false );
		}
	}, [ createPipelineMutation ] );

	/**
	 * Fallback: Use first pipeline if selectedPipeline is null
	 */
	const displayPipeline = selectedPipeline || pipelines[ 0 ];

	/**
	 * Determine main content based on state
	 */
	const renderMainContent = () => {
		// Loading state
		if ( pipelinesLoading || selectedPipelineLoading || flowsLoading ) {
			return (
				<div className="datamachine-pipelines-loading">
					<Spinner />
					<p>{ __( 'Loading pipelines…', 'data-machine' ) }</p>
				</div>
			);
		}

		// Error state
		if ( pipelinesError || selectedPipelineError || flowsError ) {
			return (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ pipelinesError ||
							selectedPipelineError ||
							flowsError }
					</p>
				</Notice>
			);
		}

		// Empty state
		if ( pipelines.length === 0 ) {
			return (
				<div className="datamachine-empty-state">
					<Notice status="info" isDismissible={ false }>
						<p>
							{ __(
								'No pipelines found. Create your first pipeline to get started, or ask the chat to help you build one.',
								'data-machine'
							) }
						</p>
					</Notice>
					<div className="datamachine-empty-state-actions">
						<Button
							variant="primary"
							onClick={ handleAddNewPipeline }
							disabled={ isCreatingPipeline }
							isBusy={ isCreatingPipeline }
						>
							{ __( 'Create First Pipeline', 'data-machine' ) }
						</Button>
					</div>
				</div>
			);
		}

		// Loading pipeline details
		if ( ! selectedPipeline && selectedPipelineId ) {
			return (
				<div className="datamachine-pipelines-loading">
					<Spinner />
					<p>{ __( 'Loading pipeline details…', 'data-machine' ) }</p>
				</div>
			);
		}

		// Normal state with pipelines
		return (
			<>
				<PipelineSelector />

				<PipelineCard
					pipeline={ displayPipeline }
					flows={ flows }
					flowsTotal={ flowsTotal }
					flowsPage={ flowsPage }
					flowsPerPage={ flowsPerPage }
					onFlowsPageChange={ setFlowsPage }
				/>

				<ModalManager />
			</>
		);
	};

	/**
	 * Main render - layout wrapper always present for chat access
	 */
	return (
		<div className="datamachine-pipelines-layout">
			<div className="datamachine-pipelines-main">
				{ /* Header with Add Pipeline, Import/Export, and Chat toggle */ }
				<div className="datamachine-header--flex-space-between">
					<Button
						variant="primary"
						onClick={ handleAddNewPipeline }
						disabled={ isCreatingPipeline }
						isBusy={ isCreatingPipeline }
					>
						{ __( 'Add New Pipeline', 'data-machine' ) }
					</Button>
					<div className="datamachine-header__right">
						<AgentSwitcher />
						<Button
							variant="secondary"
							onClick={ () =>
								openModal( MODAL_TYPES.IMPORT_EXPORT )
							}
						>
							{ __( 'Import / Export', 'data-machine' ) }
						</Button>
						<ChatToggle />
					</div>
				</div>

				{ renderMainContent() }
			</div>

			{ isChatOpen && <ChatSidebar /> }
		</div>
	);
}
