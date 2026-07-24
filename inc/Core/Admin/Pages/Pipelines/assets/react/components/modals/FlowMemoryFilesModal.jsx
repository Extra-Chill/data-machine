/**
 * Flow Memory Files Modal Component
 *
 * Modal for selecting agent memory files for flow AI context.
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FlowMemoryFiles from '../flows/FlowMemoryFiles';

/**
 * Flow Memory Files Modal Component
 *
 * @param {Object}   props        - Component props
 * @param {Function} props.onClose - Close handler
 * @param {number}   props.flowId  - Flow ID
 * @return {React.ReactElement} Flow memory files modal
 */
export default function FlowMemoryFilesModal( { onClose, flowId } ) {
	return (
		<Modal
			title={ __( 'Flow Memory Files', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-memory-files-modal"
		>
			<div className="datamachine-modal-content">
				<FlowMemoryFiles flowId={ flowId } />
			</div>
		</Modal>
	);
}
