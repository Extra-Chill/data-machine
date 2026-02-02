/**
 * Pipeline Steps Container Component
 *
 * Container for pipeline step list with data flow arrows.
 */

/**
 * WordPress dependencies
 */
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import PipelineStepCard from './PipelineStepCard';
import EmptyStepCard from './EmptyStepCard';
import DataFlowArrow from '../shared/DataFlowArrow';
import { reorderPipelineSteps } from '../../utils/api';

/**
 * Pipeline Steps Container Component
 *
 * @param {Object}   props                  - Component props
 * @param {number}   props.pipelineId       - Pipeline ID
 * @param {Object}   props.pipelineConfig   - Pipeline configuration keyed by pipeline_step_id
 * @param {Function} props.onStepAdded      - Add step handler
 * @param {Function} props.onStepRemoved    - Remove step handler
 * @param {Function} props.onStepConfigured - Configure step handler
 * @return {React.ReactElement} Pipeline steps container
 */
export default function PipelineSteps( {
	pipelineId,
	pipelineConfig,
	onStepAdded,
	onStepRemoved,
	onStepConfigured,
} ) {
	/**
	 * Sort steps by execution order
	 */
	const sortedSteps = useMemo( () => {
		if ( ! pipelineConfig || typeof pipelineConfig !== 'object' ) {
			return [];
		}

		return Object.values( pipelineConfig )
			.filter( ( step ) => step.step_type )
			.sort( ( a, b ) => {
				const orderA = a.execution_order || 0;
				const orderB = b.execution_order || 0;
				return orderA - orderB;
			} );
	}, [ pipelineConfig ] );

	/**
	 * Drag & drop state
	 */
	const [ draggedIndex, setDraggedIndex ] = useState( null );

	/**
	 * Handle step drop to reorder
	 * @param dropIndex
	 */
	const handleDrop = async ( dropIndex ) => {
		if ( draggedIndex === null || draggedIndex === dropIndex ) {
			setDraggedIndex( null );
			return;
		}

		// Reorder steps array
		const newSteps = [ ...sortedSteps ];
		const [ movedStep ] = newSteps.splice( draggedIndex, 1 );
		newSteps.splice( dropIndex, 0, movedStep );

		// Save to API (optimistic update - local state updates via parent)
		try {
			await reorderPipelineSteps( pipelineId, newSteps );
		} catch ( error ) {
			console.error( 'Failed to reorder steps:', error );
		}

		setDraggedIndex( null );
	};

	/**
	 * Empty state
	 */
	if ( sortedSteps.length === 0 ) {
		return (
			<div className="datamachine-pipeline-steps-empty datamachine-steps-empty-state">
				<p className="datamachine-steps-empty-text">
					{ __(
						'No steps configured. Add your first step to get started.',
						'data-machine'
					) }
				</p>
				<EmptyStepCard
					pipelineId={ pipelineId }
					onAddStep={ onStepAdded }
				/>
			</div>
		);
	}

	/**
	 * Render steps with arrows in wrapping grid
	 * Structure: card, arrow, card, arrow, card, arrow, empty
	 */
	const renderItems = () => {
		const items = [];

		sortedSteps.forEach( ( step, index ) => {
			// Add card
			items.push(
				<div
					key={ step.pipeline_step_id }
					draggable={ true }
					onDragStart={ () => setDraggedIndex( index ) }
					onDragOver={ ( e ) => e.preventDefault() }
					onDrop={ () => handleDrop( index ) }
					className={ `datamachine-step-card-draggable ${
						draggedIndex === index
							? 'datamachine-step-dragging'
							: ''
					}` }
				>
					<PipelineStepCard
						step={ step }
						pipelineId={ pipelineId }
						pipelineConfig={ pipelineConfig }
						onDelete={ onStepRemoved }
						onConfigure={ onStepConfigured }
					/>
				</div>
			);

			// Add arrow after card (except after last card)
			if ( index < sortedSteps.length - 1 ) {
				items.push( <DataFlowArrow key={ `arrow-${ index }` } /> );
			}
		} );

		// Add arrow before empty card
		if ( sortedSteps.length > 0 ) {
			items.push( <DataFlowArrow key="arrow-empty" /> );
		}

		// Add empty card
		items.push(
			<div key="empty-card" className="datamachine-step-card-draggable">
				<EmptyStepCard
					pipelineId={ pipelineId }
					onAddStep={ onStepAdded }
				/>
			</div>
		);

		return items;
	};

	return (
		<div className="datamachine-pipeline-steps datamachine-steps-container">
			{ renderItems() }
		</div>
	);
}
