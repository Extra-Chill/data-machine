/**
 * LogsHeader Component
 *
 * Page title and clear button for current agent scope.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useClearLogs, useLogMetadata } from '../queries/logs';
import { useAgentStore } from '@shared/stores/agentStore';

const LogsHeader = () => {
	const clearMutation = useClearLogs();
	const selectedAgentId = useAgentStore( ( state ) => state.selectedAgentId );
	const { data: metadata } = useLogMetadata( selectedAgentId ?? undefined );

	const totalEntries = metadata?.total_entries || 0;

	const handleClearAll = () => {
		const scopeLabel = selectedAgentId
			? __( 'the selected agent', 'data-machine' )
			: __( 'ALL agents', 'data-machine' );

		if (
			window.confirm( // eslint-disable-line no-alert
				`${ __( 'Are you sure you want to clear logs for', 'data-machine' ) } ${ scopeLabel }? ${ __( 'This action cannot be undone.', 'data-machine' ) }`
			)
		) {
			clearMutation.mutate(
				selectedAgentId ? { agent_id: selectedAgentId } : {}
			);
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
