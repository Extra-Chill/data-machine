/**
 * File Status Table Component
 *
 * Table displaying uploaded files with processing status indicators.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * File Status Table Component
 *
 * @param {Object} props       - Component props
 * @param {Array}  props.files - Array of file objects with status
 * @return {React.ReactElement} File status table
 */
export default function FileStatusTable( { files = [] } ) {
	/**
	 * Get status badge
	 * @param status
	 */
	const getStatusBadge = ( status ) => {
		const statusConfig = {
			processed: {
				label: __( 'Processed', 'data-machine' ),
				icon: '✓',
			},
			pending: {
				label: __( 'Pending', 'data-machine' ),
				icon: '●',
			},
		};

		const config = statusConfig[ status ] || statusConfig.pending;
		const className = `datamachine-status-badge datamachine-status-badge--${
			status || 'pending'
		}`;

		return (
			<span className={ className }>
				<span>{ config.icon }</span>
				{ config.label }
			</span>
		);
	};

	if ( files.length === 0 ) {
		return (
			<div className="datamachine-modal-empty-state datamachine-modal-empty-state--bordered">
				<p className="datamachine-text--margin-reset">
					{ __( 'No files uploaded yet.', 'data-machine' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="datamachine-table-container datamachine-table-container--small">
			<table className="datamachine-pipeline-table">
				<thead>
					<tr className="datamachine-table-sticky-header">
						<th>{ __( 'File Name', 'data-machine' ) }</th>
						<th className="datamachine-table-cell--center datamachine-table-col--compact">
							{ __( 'Status', 'data-machine' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ files.map( ( file, index ) => (
						<tr key={ index }>
							<td className="datamachine-table-cell--medium">
								{ file.file_name }
							</td>
							<td className="datamachine-table-cell--center">
								{ getStatusBadge( file.status ) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
