/**
 * Pipeline Header Component
 *
 * Pipeline title input with auto-save and delete button.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { updatePipelineTitle } from '../../utils/api';
import { useDeletePipeline } from '../../queries/pipelines';
import { AUTO_SAVE_DELAY } from '../../utils/constants';

/**
 * Pipeline Header Component
 *
 * @param {Object}   props                    - Component props
 * @param {number}   props.pipelineId         - Pipeline ID
 * @param {string}   props.pipelineName       - Pipeline name
 * @param {Function} props.onNameChange       - Called after successful save
 * @param {Function} props.onDelete           - Called after successful deletion
 * @param {Function} props.onOpenContextFiles - Called when context files button clicked
 * @param {Function} props.onOpenMemoryFiles  - Called when memory files button clicked
 * @return {React.ReactElement} Pipeline header
 */
export default function PipelineHeader( {
	pipelineId,
	pipelineName,
	onNameChange,
	onDelete,
	onOpenContextFiles,
	onOpenMemoryFiles,
} ) {
	const [ localName, setLocalName ] = useState( pipelineName );
	const saveTimeout = useRef( null );

	// Use mutation hook for pipeline deletion
	const deletePipelineMutation = useDeletePipeline();

	/**
	 * Sync local name with prop changes
	 */
	useEffect( () => {
		setLocalName( pipelineName );
	}, [ pipelineName ] );

	/**
	 * Save pipeline name to API (silent auto-save)
	 */
	const saveName = useCallback(
		async ( name ) => {
			if ( ! name || name === pipelineName ) {
				return;
			}

			try {
				const response = await updatePipelineTitle( pipelineId, name );

				if ( response.success && onNameChange ) {
					onNameChange( name );
				}
			} catch ( err ) {
				console.error( 'Pipeline title save failed:', err );
			}
		},
		[ pipelineId, pipelineName, onNameChange ]
	);

	/**
	 * Handle name input change with debouncing
	 */
	const handleNameChange = useCallback(
		( value ) => {
			setLocalName( value );

			// Clear existing timeout
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			// Set new timeout for debounced save
			saveTimeout.current = setTimeout( () => {
				saveName( value );
			}, AUTO_SAVE_DELAY );
		},
		[ saveName ]
	);

	/**
	 * Handle pipeline deletion
	 */
	const handleDelete = useCallback( async () => {
		const confirmed = window.confirm(
			__(
				'Are you sure you want to delete this pipeline? This action cannot be undone.',
				'data-machine'
			)
		);

		if ( ! confirmed ) {
			return;
		}

		try {
			await deletePipelineMutation.mutateAsync( pipelineId );

			// Call onDelete callback for any additional cleanup
			if ( onDelete ) {
				onDelete( pipelineId );
			}
		} catch ( err ) {
			console.error( 'Pipeline deletion error:', err );
			alert(
				__(
					'An error occurred while deleting the pipeline',
					'data-machine'
				)
			);
		}
	}, [ pipelineId, onDelete, deletePipelineMutation ] );

	/**
	 * Cleanup timeout on unmount
	 */
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	return (
		<div className="datamachine-pipeline-header">
			<div className="datamachine-header--absolute-top-right datamachine-header--flex-start">
				<Button
					variant="secondary"
					onClick={ onOpenMemoryFiles }
					icon="database"
					label={ __( 'Memory Files', 'data-machine' ) }
				/>
				<Button
					variant="secondary"
					onClick={ onOpenContextFiles }
					icon="media-document"
					label={ __( 'Context Files', 'data-machine' ) }
				/>
				<Button
					isDestructive
					variant="secondary"
					onClick={ handleDelete }
					icon="trash"
					label={ __( 'Delete Pipeline', 'data-machine' ) }
				/>
			</div>

			<TextControl
				value={ localName }
				onChange={ handleNameChange }
				placeholder={ __( 'Pipeline name', 'data-machine' ) }
				className="datamachine-pipeline-header__title-input"
			/>
		</div>
	);
}
