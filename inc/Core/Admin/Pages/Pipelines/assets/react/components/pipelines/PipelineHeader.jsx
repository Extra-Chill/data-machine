/**
 * Pipeline Header Component
 *
 * Pipeline title input with auto-save and delete button.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	TextControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
/**
 * Internal dependencies
 */
import { updatePipelineTitle } from '../../utils/api';
import { useDeletePipeline } from '../../queries/pipelines';
/**
 * External dependencies
 */
import useDebouncedAutosave from '@shared/hooks/useDebouncedAutosave';
import ConfirmationModal from '@shared/components/ConfirmationModal';

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
	const [ isDeleteConfirmOpen, setIsDeleteConfirmOpen ] = useState( false );
	const [ deleteError, setDeleteError ] = useState( null );

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
				window.console.error( 'Pipeline title save failed:', err );
			}
		},
		[ pipelineId, pipelineName, onNameChange ]
	);
	const scheduleSaveName = useDebouncedAutosave( saveName );

	/**
	 * Handle name input change with debouncing
	 */
	const handleNameChange = useCallback(
		( value ) => {
			setLocalName( value );
			scheduleSaveName( value );
		},
		[ scheduleSaveName ]
	);

	/**
	 * Handle pipeline deletion
	 */
	const handleDelete = useCallback( async () => {
		setIsDeleteConfirmOpen( false );
		setDeleteError( null );
		try {
			await deletePipelineMutation.mutateAsync( pipelineId );

			// Call onDelete callback for any additional cleanup
			if ( onDelete ) {
				onDelete( pipelineId );
			}
		} catch ( err ) {
			window.console.error( 'Pipeline deletion error:', err );
			setDeleteError(
				__(
					'An error occurred while deleting the pipeline',
					'data-machine'
				)
			);
		}
	}, [ pipelineId, onDelete, deletePipelineMutation ] );

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
					onClick={ () => setIsDeleteConfirmOpen( true ) }
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
			{ deleteError && (
				<Notice status="error" onRemove={ () => setDeleteError( null ) }>
					{ deleteError }
				</Notice>
			) }
			{ isDeleteConfirmOpen && (
				<ConfirmationModal
					onConfirm={ handleDelete }
					onCancel={ () => setIsDeleteConfirmOpen( false ) }
				>
					{ __(
						'Are you sure you want to delete this pipeline? This action cannot be undone.',
						'data-machine'
					) }
				</ConfirmationModal>
			) }
		</div>
	);
}
