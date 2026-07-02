/**
 * Export Tab Component
 *
 * Pipeline selection table with CSV export functionality.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { exportPipelines } from '../../../utils/api';
import { useAsyncOperation } from '../../../hooks/useFormState';
import PipelineCheckboxTable from './PipelineCheckboxTable';

/**
 * Export Tab Component
 *
 * @param {Object}   props           - Component props
 * @param {Array}    props.pipelines - All available pipelines
 * @param {Function} props.onClose   - Close handler
 * @return {React.ReactElement} Export tab
 */
export default function ExportTab( { pipelines, onClose } ) {
	const [ selectedIds, setSelectedIds ] = useState( [] );

	const exportOperation = useAsyncOperation();

	/**
	 * Handle export action
	 */
	const handleExport = () => {
		if ( selectedIds.length === 0 ) {
			exportOperation.setError(
				__(
					'Please select at least one pipeline to export.',
					'data-machine'
				)
			);
			return;
		}

		exportOperation.execute( async () => {
			const response = await exportPipelines( selectedIds );

			if ( response.success && response.data.csv_content ) {
				// Create download link
				const blob = new Blob( [ response.data.csv_content ], {
					type: 'text/csv',
				} );
				const url = URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = `datamachine-pipelines-${ Date.now() }.csv`;
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				URL.revokeObjectURL( url );

				setSelectedIds( [] );
				return __( 'Pipelines exported successfully!', 'data-machine' );
			}
			throw new Error(
				response.message ||
					__( 'Failed to export pipelines', 'data-machine' )
			);
		} );
	};

	return (
		<div className="datamachine-export-tab">
			{ exportOperation.error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => exportOperation.setError( null ) }
				>
					<p>{ exportOperation.error }</p>
				</Notice>
			) }

			{ exportOperation.success && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => exportOperation.reset() }
				>
					<p>{ exportOperation.success }</p>
				</Notice>
			) }

			<p className="datamachine-import-export-description">
				{ __(
					'Select the pipelines you want to export to CSV:',
					'data-machine'
				) }
			</p>

			<PipelineCheckboxTable
				pipelines={ pipelines }
				selectedIds={ selectedIds }
				onSelectionChange={ setSelectedIds }
			/>

			<div className="datamachine-tab-actions">
				<Button
					variant="secondary"
					onClick={ onClose }
					disabled={ exportOperation.isLoading }
				>
					{ __( 'Cancel', 'data-machine' ) }
				</Button>

				<Button
					variant="primary"
					onClick={ handleExport }
					disabled={
						exportOperation.isLoading || selectedIds.length === 0
					}
					isBusy={ exportOperation.isLoading }
				>
					{ exportOperation.isLoading
						? __( 'Exporting…', 'data-machine' )
						: __( 'Export Selected', 'data-machine' ) }
				</Button>
			</div>
		</div>
	);
}
