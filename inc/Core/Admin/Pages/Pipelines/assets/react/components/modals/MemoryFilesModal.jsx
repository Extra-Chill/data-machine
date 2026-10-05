/**
 * Memory Files Modal Component
 *
 * Modal for selecting agent memory files for pipeline AI context.
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import PipelineMemoryFiles from '../pipelines/PipelineMemoryFiles';

/**
 * Memory Files Modal Component
 *
 * @param {Object}   props            - Component props
 * @param {Function} props.onClose    - Close handler
 * @param {number}   props.pipelineId - Pipeline ID
 * @return {React.ReactElement} Memory files modal
 */
export default function MemoryFilesModal( { onClose, pipelineId } ) {
	return (
		<Modal
			title={ __( 'Pipeline Memory Files', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-memory-files-modal"
		>
			<div className="datamachine-modal-content">
				<PipelineMemoryFiles pipelineId={ pipelineId } />
			</div>
		</Modal>
	);
}
