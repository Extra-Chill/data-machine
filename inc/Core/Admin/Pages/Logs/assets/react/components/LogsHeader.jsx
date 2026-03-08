/**
 * LogsHeader Component
 *
 * Page title and global actions for the logs page.
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
/**
 * Internal dependencies
 */
import { useClearAllLogs } from '../queries/logs';

const LogsHeader = () => {
	const clearAllMutation = useClearAllLogs();

	const handleClearAll = () => {
		if (
			window.confirm(
				__(
					'Are you sure you want to clear ALL logs? This action cannot be undone.',
					'data-machine'
				)
			)
		) {
			clearAllMutation.mutate();
		}
	};

	return (
		<div className="datamachine-logs-header">
			<h1 className="datamachine-logs-title">
				{ __( 'Logs', 'data-machine' ) }
			</h1>
			<div className="datamachine-logs-header-actions">
				<AgentSwitcher />
				<Button
					variant="secondary"
					isDestructive
					onClick={ handleClearAll }
					disabled={ clearAllMutation.isPending }
				>
					{ clearAllMutation.isPending
						? __( 'Clearing…', 'data-machine' )
						: __( 'Clear All Logs', 'data-machine' ) }
				</Button>
			</div>
		</div>
	);
};

export default LogsHeader;
