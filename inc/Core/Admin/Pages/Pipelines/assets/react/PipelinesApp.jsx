/**
 * Pipelines App Root Component
 *
 * Container component that manages the entire pipeline interface state and data.
 * Pattern: Container - Fetches all pipeline-related data and manages global state
 */

/**
 * WordPress dependencies
 */
import {
	useEffect,
	useCallback,
	useState,
	useMemo,
	lazy,
	Suspense,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner, Notice, Button } from '@wordpress/components';
/**
 * Internal dependencies
 */
import {
	usePipelines,
	usePipeline,
	useCreatePipeline,
} from './queries/pipelines';
import { useFlows } from './queries/flows';
/**
 * External dependencies
 */
import { useUIStore } from './stores/uiStore';
import PipelineCard from './components/pipelines/PipelineCard';
import PipelineSelector from './components/pipelines/PipelineSelector';
import ChatToggle from './components/chat/ChatToggle';
import AgentSwitcher from '@shared/components/AgentSwitcher';
import { MODAL_TYPES } from './utils/constants';
import { isSameId } from './utils/ids';

const ModalManager = lazy( () => import( './components/shared/ModalManager' ) );
const ChatSidebar = lazy( () => import( './components/chat/ChatSidebar' ) );

const getErrorMessage = ( error ) => {
	if ( ! error ) {
		return null;
	}

	return error.message || String( error );
};

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
		activeModal,
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
	const flowsPerPage = window.dataMachineConfig?.flowsPerPage ?? 20;

	const {
		data: flowsData,
		isLoading: flowsLoading,
		error: flowsError,
	} = useFlows( selectedPipelineId, {
		page: flowsPage,
		perPage: flowsPerPage,
	} );
	const [ previousFlowsData, setPreviousFlowsData ] = useState( {
		pipelineId: null,
		data: null,
	} );

	useEffect( () => {
		setPreviousFlowsData( {
			pipelineId: selectedPipelineId,
			data: null,
		} );
	}, [ selectedPipelineId ] );

	useEffect( () => {
		if ( flowsData ) {
			setPreviousFlowsData( {
				pipelineId: selectedPipelineId,
				data: flowsData,
			} );
		}
	}, [ flowsData, selectedPipelineId ] );

	const previousFlowsForSelectedPipeline = isSameId(
		previousFlowsData.pipelineId,
		selectedPipelineId
	)
		? previousFlowsData.data
		: null;
	const displayFlowsData =
		flowsData || ( flowsLoading ? previousFlowsForSelectedPipeline : null );
	const flows = useMemo( () => {
		return {
			items: displayFlowsData?.flows ?? [],
			isLoading: flowsLoading,
		};
	}, [ displayFlowsData, flowsLoading ] );
	const flowsTotal = displayFlowsData?.total ?? 0;

	const createPipelineMutation = useCreatePipeline( {
		onSuccess: ( pipelineId ) => {
			setSelectedPipelineId( pipelineId );
		},
	} );

	// Resolve the selected pipeline: prefer the list cache, fall back to a
	// single-pipeline fetch so the admin page works even when the selection
	// isn't in the current selector search results (or the list is paginated).
	const selectedFromList = pipelines?.find( ( p ) =>
		isSameId( p.pipeline_id, selectedPipelineId )
	);
	const {
		data: fetchedSelectedPipeline,
		isLoading: fetchedSelectedLoading,
		error: fetchedSelectedError,
	} = usePipeline( selectedPipelineId || null );
	const selectedPipeline = fetchedSelectedPipeline || selectedFromList;
	const selectedPipelineLoading =
		selectedPipelineId && fetchedSelectedLoading;
	const selectedPipelineError = fetchedSelectedError;

	const [ isCreatingPipeline, setIsCreatingPipeline ] = useState( false );

	/**
	 * Set selected pipeline when pipelines load or when selected pipeline is deleted.
	 * Waits for Zustand hydration AND pipelines query to complete before applying default selection.
	 *
	 * The selection is only cleared when the pipeline is confirmed to not exist
	 * anywhere — not just missing from the paginated list. This lets the admin
	 * hold a selection beyond the first page of pipelines without it getting
	 * auto-reset on every reload.
	 */
	useEffect( () => {
		if ( ! hasHydrated || pipelinesLoading ) {
			return;
		}

		if ( pipelines.length > 0 && ! selectedPipelineId ) {
			setSelectedPipelineId( pipelines[ 0 ].pipeline_id );
			return;
		}

		if ( pipelines.length === 0 && ! selectedPipelineId ) {
			return;
		}

		// Selection confirmed to exist if it's either in the list cache or
		// the single-pipeline fetch resolved to a record.
		const existsInList = pipelines.some( ( p ) =>
			isSameId( p.pipeline_id, selectedPipelineId )
		);
		const existsOnServer = !! fetchedSelectedPipeline;

		if ( existsInList || existsOnServer || fetchedSelectedLoading ) {
			return;
		}

		// Only reach here once the single-pipeline fetch has resolved to null.
		if ( pipelines.length > 0 ) {
			setSelectedPipelineId( pipelines[ 0 ].pipeline_id );
		} else {
			setSelectedPipelineId( null );
		}
	}, [
		pipelines,
		selectedPipelineId,
		setSelectedPipelineId,
		hasHydrated,
		pipelinesLoading,
		fetchedSelectedPipeline,
		fetchedSelectedLoading,
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
		const errorMessage = getErrorMessage(
			pipelinesError || selectedPipelineError || flowsError
		);

		// Loading state
		if ( pipelinesLoading || selectedPipelineLoading ) {
			return (
				<div className="datamachine-pipelines-loading">
					<Spinner />
					<p>{ __( 'Loading pipelines…', 'data-machine' ) }</p>
				</div>
			);
		}

		// Error state
		if ( errorMessage ) {
			return (
				<Notice status="error" isDismissible={ false }>
					<p>{ errorMessage }</p>
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

				{ activeModal && (
					<Suspense fallback={ null }>
						<ModalManager />
					</Suspense>
				) }
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

			{ isChatOpen && (
				<Suspense fallback={ null }>
					<ChatSidebar />
				</Suspense>
			) }
		</div>
	);
}
