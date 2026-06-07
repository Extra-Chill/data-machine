/**
 * Context Files Modal Component
 *
 * Modal for managing pipeline context files (upload, view, delete).
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import PipelineContextFiles from '../pipelines/PipelineContextFiles';

/**
 * Context Files Modal Component
 *
 * @param {Object}   props            - Component props
 * @param {Function} props.onClose    - Close handler
 * @param {number}   props.pipelineId - Pipeline ID
 * @return {React.ReactElement|null} Context files modal
 */
export default function ContextFilesModal( { onClose, pipelineId } ) {
	return (
		<Modal
			title={ __( 'Pipeline Context Files', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-context-files-modal"
		>
			<div className="datamachine-modal-content">
				<PipelineContextFiles pipelineId={ pipelineId } />
			</div>
		</Modal>
	);
}
