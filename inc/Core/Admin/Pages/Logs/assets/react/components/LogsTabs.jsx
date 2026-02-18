/**
 * LogsTabs Component
 *
 * Tabbed interface for per-agent-type log viewing.
 */

/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useAgentTypes } from '../queries/logs';
import LogsControls from './LogsControls';
import LogsViewer from './LogsViewer';

const LogsTabs = () => {
	const { data: agentTypes, isLoading, isError } = useAgentTypes();

	if ( isLoading ) {
		return (
			<div className="datamachine-logs-tabs-loading">
				{ __( 'Loading…', 'data-machine' ) }
			</div>
		);
	}

	if ( isError || ! agentTypes ) {
		return (
			<div className="datamachine-logs-tabs-error">
				{ __( 'Failed to load agent types.', 'data-machine' ) }
			</div>
		);
	}

	// Build tabs from agent types
	const tabs = Object.entries( agentTypes ).map( ( [ key, info ] ) => ( {
		name: key,
		title: info.label,
		className: `datamachine-logs-tab-${ key }`,
	} ) );

	return (
		<TabPanel className="datamachine-tabs" tabs={ tabs }>
			{ ( tab ) => (
				<div className="datamachine-logs-tab-content">
					<LogsControls
						agentType={ tab.name }
						agentLabel={ tab.title }
					/>
					<LogsViewer agentType={ tab.name } />
				</div>
			) }
		</TabPanel>
	);
};

export default LogsTabs;
