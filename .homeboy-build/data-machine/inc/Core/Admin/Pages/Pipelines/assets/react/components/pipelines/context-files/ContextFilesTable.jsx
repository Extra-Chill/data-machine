/**
 * Context Files Table Component
 *
 * Table displaying uploaded context files with metadata and delete actions.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Context Files Table Component
 *
 * @param {Object}   props            - Component props
 * @param {Array}    props.files      - Array of file objects
 * @param {Function} props.onDelete   - Delete callback (fileId)
 * @param {boolean}  props.isDeleting - Deleting state
 * @return {React.ReactElement} Context files table
 */
export default function ContextFilesTable( {
	files = [],
	onDelete,
	isDeleting = false,
} ) {
	const [ deletingId, setDeletingId ] = useState( null );

	/**
	 * Format date for display
	 * @param dateString
	 */
	const formatDate = ( dateString ) => {
		const date = new Date( dateString );
		return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
	};

	/**
	 * Handle delete click
	 * @param fileId
	 */
	const handleDelete = async ( fileId ) => {
		setDeletingId( fileId );
		if ( onDelete ) {
			await onDelete( fileId );
		}
		setDeletingId( null );
	};

	if ( files.length === 0 ) {
		return (
			<div className="datamachine-modal-empty-state datamachine-modal-empty-state--bordered">
				<p className="datamachine-text--margin-reset">
					{ __( 'No context files uploaded yet.', 'data-machine' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="datamachine-table-container">
			<table className="datamachine-pipeline-table">
				<thead>
					<tr className="datamachine-table-sticky-header">
						<th>{ __( 'File Name', 'data-machine' ) }</th>
						<th className="datamachine-table-col--date">
							{ __( 'Uploaded', 'data-machine' ) }
						</th>
						<th className="datamachine-table-cell--center datamachine-table-col--compact">
							{ __( 'Actions', 'data-machine' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ files.map( ( file ) => {
						const isCurrentlyDeleting = deletingId === file.file_id;

						return (
							<tr key={ file.file_id }>
								<td className="datamachine-table-cell--medium">
									{ file.file_name }
								</td>
								<td className="datamachine-table-cell--muted datamachine-table-cell--small">
									{ formatDate( file.uploaded_at ) }
								</td>
								<td className="datamachine-table-cell--center">
									<Button
										variant="link"
										onClick={ () =>
											handleDelete( file.file_id )
										}
										disabled={
											isDeleting || isCurrentlyDeleting
										}
										isBusy={ isCurrentlyDeleting }
										className="datamachine-table-text-error"
									>
										{ isCurrentlyDeleting
											? __( 'Deleting…', 'data-machine' )
											: __( 'Delete', 'data-machine' ) }
									</Button>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}
