/**
 * Pipeline Memory Files Component
 *
 * Allows selecting agent memory files to include in pipeline AI context.
 * SOUL.md is excluded (always injected separately at Priority 20).
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { CheckboxControl, Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import {
	useAgentFiles,
	usePipelineMemoryFiles,
	useUpdatePipelineMemoryFiles,
} from '../../queries/pipelines';

/**
 * Files to exclude from the memory files picker (always injected separately).
 */
const EXCLUDED_FILES = [ 'SOUL.md' ];

/**
 * Pipeline Memory Files Component
 *
 * @param {Object} props            - Component props
 * @param {number} props.pipelineId - Pipeline ID
 * @return {React.ReactElement} Memory files selector
 */
export default function PipelineMemoryFiles( { pipelineId } ) {
	const { data: agentFiles = [], isLoading: loadingAgent } =
		useAgentFiles();
	const { data: selectedFiles = [], isLoading: loadingSelected } =
		usePipelineMemoryFiles( pipelineId );
	const updateMutation = useUpdatePipelineMemoryFiles( pipelineId );

	const [ localSelected, setLocalSelected ] = useState( [] );
	const [ success, setSuccess ] = useState( null );

	// Sync local state when server data loads.
	useEffect( () => {
		setLocalSelected( selectedFiles );
	}, [ selectedFiles ] );

	const loading = loadingAgent || loadingSelected;

	// Filter out SOUL.md and non-text files.
	const availableFiles = agentFiles
		.map( ( f ) => ( typeof f === 'string' ? f : f.name || f.filename ) )
		.filter( ( name ) => name && ! EXCLUDED_FILES.includes( name ) );

	const handleToggle = ( filename, checked ) => {
		setLocalSelected( ( prev ) =>
			checked
				? [ ...prev, filename ]
				: prev.filter( ( f ) => f !== filename )
		);
	};

	const handleSave = async () => {
		setSuccess( null );
		try {
			await updateMutation.mutateAsync( localSelected );
			setSuccess(
				__( 'Memory files updated successfully!', 'data-machine' )
			);
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Memory files save error:', err );
		}
	};

	const isDirty =
		JSON.stringify( [ ...localSelected ].sort() ) !==
		JSON.stringify( [ ...selectedFiles ].sort() );

	return (
		<div className="datamachine-pipeline-memory-files">
			<h3
				style={ {
					margin: '0 0 8px 0',
					fontSize: '16px',
					fontWeight: '600',
				} }
			>
				{ __( 'Agent Memory Files', 'data-machine' ) }
			</h3>
			<p
				style={ {
					margin: '0 0 16px 0',
					color: '#757575',
					fontSize: '13px',
				} }
			>
				{ __(
					'Select agent memory files to include as AI context for this pipeline.',
					'data-machine'
				) }
			</p>

			{ success && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSuccess( null ) }
				>
					<p>{ success }</p>
				</Notice>
			) }

			{ updateMutation.isError && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ __(
							'Failed to save memory files.',
							'data-machine'
						) }
					</p>
				</Notice>
			) }

			{ loading ? (
				<div
					style={ {
						textAlign: 'center',
						padding: '20px',
						color: '#757575',
					} }
				>
					<Spinner />
				</div>
			) : availableFiles.length === 0 ? (
				<p
					style={ {
						color: '#757575',
						fontStyle: 'italic',
						padding: '12px 0',
					} }
				>
					{ __(
						'No agent memory files available. Upload files to the agent directory first.',
						'data-machine'
					) }
				</p>
			) : (
				<>
					<div
						style={ {
							display: 'flex',
							flexDirection: 'column',
							gap: '8px',
							marginBottom: '16px',
						} }
					>
						{ availableFiles.map( ( filename ) => (
							<CheckboxControl
								key={ filename }
								label={ filename }
								checked={ localSelected.includes( filename ) }
								onChange={ ( checked ) =>
									handleToggle( filename, checked )
								}
							/>
						) ) }
					</div>

					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={ ! isDirty || updateMutation.isPending }
						isBusy={ updateMutation.isPending }
					>
						{ updateMutation.isPending
							? __( 'Saving…', 'data-machine' )
							: __( 'Save Memory Files', 'data-machine' ) }
					</Button>
				</>
			) }
		</div>
	);
}
