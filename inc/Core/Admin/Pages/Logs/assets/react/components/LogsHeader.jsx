/**
 * LogsHeader Component
 *
 * Page title, agent switcher, and global clear button.
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
import { useClearLogs, useLogMetadata } from '../queries/logs';

const LogsHeader = () => {
	const clearMutation = useClearLogs();
	const { data: metadata } = useLogMetadata();

	const totalEntries = metadata?.total_entries || 0;

	const handleClearAll = () => {
		if (
			window.confirm( // eslint-disable-line no-alert
				__(
					'Are you sure you want to clear ALL logs? This action cannot be undone.',
					'data-machine'
				)
			)
		) {
			clearMutation.mutate( {} );
		}
	};

	return (
		<div className="datamachine-logs-header">
			<div className="datamachine-logs-header-left">
				<h1 className="datamachine-logs-title">
					{ __( 'Logs', 'data-machine' ) }
				</h1>
				{ totalEntries > 0 && (
					<span className="datamachine-logs-count">
						{ totalEntries.toLocaleString() }{ ' ' }
						{ __( 'entries', 'data-machine' ) }
					</span>
				) }
			</div>
			<div className="datamachine-logs-header-actions">
				<AgentSwitcher />
				<Button
					variant="secondary"
					isDestructive
					onClick={ handleClearAll }
					disabled={ clearMutation.isPending || totalEntries === 0 }
				>
					{ clearMutation.isPending
						? __( 'Clearing...', 'data-machine' )
						: __( 'Clear All Logs', 'data-machine' ) }
				</Button>
			</div>
		</div>
	);
};

export default LogsHeader;
