/**
 * Import/Export Modal Component
 *
 * Two-tab modal for exporting pipelines to CSV and importing from CSV.
 */

/**
 * WordPress dependencies
 */
import { Modal, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import ExportTab from './import-export/ExportTab';
import ImportTab from './import-export/ImportTab';
import { usePipelines } from '../../queries/pipelines';

/**
 * Import/Export Modal Component
 *
 * Fetches the shared lightweight pipelines list (no flows embedded, flow_count
 * exposed per row) so the Export tab shows the same set the admin page sees.
 *
 * @param {Object}   props           - Component props
 * @param {Function} props.onClose   - Close handler
 * @param {Array}    [props.pipelines] - Optional preloaded pipelines (falls back to the list query)
 * @param {Function} props.onSuccess - Success callback
 * @return {React.ReactElement|null} Import/export modal
 */
export default function ImportExportModal( {
	onClose,
	pipelines: pipelinesProp,
	onSuccess,
} ) {
	const { data: queriedPipelines = [] } = usePipelines();
	const pipelines = pipelinesProp ?? queriedPipelines;

	/**
	 * Tab configuration
	 */
	const tabs = [
		{
			name: 'export',
			title: __( 'Export', 'data-machine' ),
			className: 'datamachine-import-export-tab',
		},
		{
			name: 'import',
			title: __( 'Import', 'data-machine' ),
			className: 'datamachine-import-export-tab',
		},
	];

	/**
	 * Handle success from either tab
	 */
	const handleSuccess = () => {
		if ( onSuccess ) {
			onSuccess();
		}
		onClose();
	};

	return (
		<Modal
			title={ __( 'Import / Export Pipelines', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-import-export-modal"
		>
			<div className="datamachine-modal-content">
				<TabPanel
					className="datamachine-import-export-tabs"
					activeClass="is-active"
					tabs={ tabs }
				>
					{ ( tab ) => (
						<div className="datamachine-tab-content">
							{ tab.name === 'export' && (
								<ExportTab
									pipelines={ pipelines }
									onClose={ onClose }
								/>
							) }

							{ tab.name === 'import' && (
								<ImportTab
									onSuccess={ handleSuccess }
									onClose={ onClose }
								/>
							) }
						</div>
					) }
				</TabPanel>
			</div>
		</Modal>
	);
}
