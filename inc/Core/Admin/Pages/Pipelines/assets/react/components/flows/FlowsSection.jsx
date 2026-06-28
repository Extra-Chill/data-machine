/**
 * Flows section component.
 */

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';
/**
 * Internal dependencies
 */
import FlowCard from './FlowCard';
import EmptyFlowCard from './EmptyFlowCard';
/**
 * External dependencies
 */
import Pagination from '@shared/components/Pagination';
import { useCreateFlow } from '../../queries/flows';

export default function FlowsSection( {
	pipelineId,
	flows,
	pipelineConfig,
	total = 0,
	page = 1,
	perPage = 20,
	onPageChange,
} ) {
	// Use mutations
	const createFlowMutation = useCreateFlow();
	const flowItems = Array.isArray( flows ) ? flows : flows?.items ?? [];
	const isLoading = ! Array.isArray( flows ) && flows?.isLoading === true;
	const hasFlows = flowItems.length > 0;

	/**
	 * Handle flow creation
	 */
	const handleAddFlow = useCallback(
		async ( pipelineIdParam ) => {
			try {
				const defaultName = __( 'New Flow', 'data-machine' );
				await createFlowMutation.mutateAsync( {
					pipelineId: pipelineIdParam,
					flowName: defaultName,
				} );
			} catch ( error ) {
				// eslint-disable-next-line no-alert, no-undef
				alert(
					__(
						'An error occurred while creating the flow',
						'data-machine'
					)
				);
			}
		},
		[ createFlowMutation ]
	);

	/**
	 * Handle flow deletion (queries will automatically refetch)
	 */
	const handleFlowDeleted = useCallback( () => {
		// Queries will automatically refetch when flow is deleted
	}, [] );

	/**
	 * Handle flow duplication (queries will automatically refetch)
	 */
	const handleFlowDuplicated = useCallback( () => {
		// Queries will automatically refetch when flow is duplicated
	}, [] );

	/**
	 * Empty state
	 */
	if ( isLoading && ! hasFlows ) {
		return (
			<div className="datamachine-flows-section datamachine-flows-section--loading">
				<h3 className="datamachine-flows-section__title">
					{ __( 'Flows', 'data-machine' ) }
				</h3>
				<div className="datamachine-pipelines-loading">
					<Spinner />
					<p>{ __( 'Loading flows…', 'data-machine' ) }</p>
				</div>
			</div>
		);
	}

	if ( ! hasFlows ) {
		return (
			<div className="datamachine-flows-section datamachine-flows-section--empty">
				<h3 className="datamachine-flows-section__title">
					{ __( 'Flows', 'data-machine' ) }
				</h3>
				<p className="datamachine-color--text-muted">
					{ __(
						'No flows configured. Add your first flow to get started.',
						'data-machine'
					) }
				</p>
				<EmptyFlowCard
					pipelineId={ pipelineId }
					onAddFlow={ handleAddFlow }
				/>
			</div>
		);
	}

	/**
	 * Render flows list
	 */
	return (
		<div className="datamachine-flows-section">
			<div className="datamachine-flows-section__header">
				<h3 className="datamachine-flows-section__title">
					{ __( 'Flows', 'data-machine' ) }{ ' ' }
					<span className="datamachine-flows-section__count">
						({ total })
					</span>
				</h3>
				{ isLoading && (
					<div className="datamachine-flows-section__loading">
						<Spinner />
						<span>{ __( 'Loading flows…', 'data-machine' ) }</span>
					</div>
				) }
			</div>

			<div className="datamachine-flows-list">
				{ flowItems.map( ( flow ) => (
					<FlowCard
						key={ flow.flow_id }
						flow={ flow }
						pipelineConfig={ pipelineConfig }
						onFlowDeleted={ handleFlowDeleted }
						onFlowDuplicated={ handleFlowDuplicated }
					/>
				) ) }

				<EmptyFlowCard
					pipelineId={ pipelineId }
					onAddFlow={ handleAddFlow }
				/>
			</div>

			<Pagination
				page={ page }
				perPage={ perPage }
				total={ total }
				onPageChange={ onPageChange }
				itemLabel={ __( 'flows', 'data-machine' ) }
			/>
		</div>
	);
}
