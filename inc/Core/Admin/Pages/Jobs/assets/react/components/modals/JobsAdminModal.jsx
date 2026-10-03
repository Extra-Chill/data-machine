/**
 * JobsAdminModal Component
 *
 * Modal container for jobs administration forms.
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import ClearProcessedForm from './ClearProcessedForm';
import ClearJobsForm from './ClearJobsForm';

const JobsAdminModal = ( { onClose } ) => {
	return (
		<Modal
			title={ __( 'Jobs Administration', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-jobs-admin-modal"
		>
			<div className="datamachine-jobs-modal-content">
				<p className="datamachine-modal-description">
					{ __(
						'Administrative tools for managing job processing and testing workflows.',
						'data-machine'
					) }
				</p>

				<ClearProcessedForm />

				<ClearJobsForm />
			</div>
		</Modal>
	);
};

export default JobsAdminModal;
