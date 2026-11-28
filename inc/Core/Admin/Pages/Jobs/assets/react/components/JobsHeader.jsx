/**
 * JobsHeader Component
 *
 * Page title and Admin button for the jobs page.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import AgentSwitcher from '@shared/components/AgentSwitcher';

const JobsHeader = ( { onOpenModal } ) => {
	return (
		<div className="datamachine-jobs-header">
			<h1 className="datamachine-jobs-title">
				{ __( 'Jobs', 'data-machine' ) }
			</h1>
			<div className="datamachine-jobs-header-actions">
				<AgentSwitcher />
				<Button variant="secondary" onClick={ onOpenModal }>
					{ __( 'Admin', 'data-machine' ) }
				</Button>
			</div>
		</div>
	);
};

export default JobsHeader;
