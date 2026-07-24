/**
 * Pipeline Context Files Section Component
 *
 * Complete section for managing pipeline context files with upload and table display.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import {
	useContextFiles,
	useUploadContextFile,
	useDeleteContextFile,
} from '../../queries/pipelines';
import FileUploadDropzone from '../shared/FileUploadDropzone';
import ContextFilesTable from './context-files/ContextFilesTable';

/**
 * Pipeline Context Files Section Component
 *
 * @param {Object} props            - Component props
 * @param {number} props.pipelineId - Pipeline ID
 * @return {React.ReactElement} Context files section
 */
export default function PipelineContextFiles( { pipelineId } ) {
	const [ success, setSuccess ] = useState( null );
	const [ error, setError ] = useState( null );

	const {
		data: files = [],
		isLoading: loading,
	} = useContextFiles( pipelineId );

	const uploadMutation = useUploadContextFile();
	const deleteMutation = useDeleteContextFile();

	const uploading = uploadMutation.isPending;
	const deleting = deleteMutation.isPending;

	/**
	 * Handle file upload
	 *
	 * @param {File} file - File to upload
	 */
	const handleFileSelected = async ( file ) => {
		setError( null );
		setSuccess( null );

		try {
			const response = await uploadMutation.mutateAsync( {
				pipelineId,
				file,
			} );

			if ( response.success ) {
				setSuccess(
					__( 'File uploaded successfully!', 'data-machine' )
				);
			} else {
				setError(
					response.message ||
						__( 'Failed to upload file', 'data-machine' )
				);
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Upload error:', err );
			setError(
				err.message ||
					__( 'An error occurred during upload', 'data-machine' )
			);
		}
	};

	/**
	 * Handle file deletion
	 *
	 * @param {string} fileId - File ID to delete
	 */
	const handleDelete = async ( fileId ) => {
		setError( null );
		setSuccess( null );

		try {
			const response = await deleteMutation.mutateAsync( fileId );

			if ( response.success ) {
				setSuccess(
					__( 'File deleted successfully!', 'data-machine' )
				);
			} else {
				setError(
					response.message ||
						__( 'Failed to delete file', 'data-machine' )
				);
			}
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Delete error:', err );
			setError(
				err.message ||
					__( 'An error occurred during deletion', 'data-machine' )
			);
		}
	};

	return (
		<div className="datamachine-pipeline-context-files">
			<div className="datamachine-context-files-wrapper">
				<h3
					style={ {
						margin: '0 0 8px 0',
						fontSize: '16px',
						fontWeight: '600',
					} }
				>
					{ __( 'Context Files', 'data-machine' ) }
				</h3>
				<p className="datamachine-context-files-helper">
					{ __(
						'Upload files for AI context retrieval during pipeline execution.',
						'data-machine'
					) }
				</p>
			</div>

			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					<p>{ error }</p>
				</Notice>
			) }

			{ success && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSuccess( null ) }
				>
					<p>{ success }</p>
				</Notice>
			) }

			<div className="datamachine-context-files-section">
				<FileUploadDropzone
					onFileSelected={ handleFileSelected }
					allowedTypes={ [ 'pdf', 'csv', 'txt', 'json' ] }
					maxSizeMB={ Math.round(
						( window.dataMachineConfig?.maxUploadSize ||
							10485760 ) /
							( 1024 * 1024 )
					) }
					disabled={ uploading || loading }
					uploadText={
						uploading ? __( 'Uploading…', 'data-machine' ) : null
					}
				/>
			</div>

			{ loading ? (
				<div
					style={ {
						textAlign: 'center',
						padding: '20px',
						color: '#757575',
					} }
				>
					{ __( 'Loading files…', 'data-machine' ) }
				</div>
			) : (
				<ContextFilesTable
					files={ files }
					onDelete={ handleDelete }
					isDeleting={ deleting }
				/>
			) }
		</div>
	);
}
