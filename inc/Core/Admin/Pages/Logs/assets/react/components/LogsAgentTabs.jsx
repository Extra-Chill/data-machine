/**
 * LogsAgentTabs Component
 *
 * Tabbed agent selector for the Logs page.
 * Shows only agents the current user can access.
 */

/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { useAgents, AGENTS_KEY } from '@shared/queries/agents';
import { useAgentStore } from '@shared/stores/agentStore';

const LOGS_QUERY_KEY = [ 'logs' ];
const LOGS_METADATA_QUERY_KEY = [ 'log-metadata' ];

export default function LogsAgentTabs() {
	const { data: agents = [], isLoading } = useAgents();
	const { selectedAgentId, setSelectedAgentId } = useAgentStore();
	const queryClient = useQueryClient();

	if ( isLoading ) {
		return null;
	}

	const tabs = [
		{
			name: 'all',
			title: __( 'All Agents', 'data-machine' ),
		},
		...agents.map( ( agent ) => ( {
			name: String( agent.agent_id ),
			title:
				agent.agent_name ||
				agent.agent_slug ||
				__( 'Unnamed Agent', 'data-machine' ),
		} ) ),
	];

	const activeTabName =
		selectedAgentId !== null ? String( selectedAgentId ) : 'all';

	const handleSelect = ( tabName ) => {
		const newId = tabName === 'all' ? null : Number( tabName );
		if ( newId === selectedAgentId ) {
			return;
		}

		setSelectedAgentId( newId );

		queryClient.invalidateQueries( { queryKey: LOGS_QUERY_KEY } );
		queryClient.invalidateQueries( { queryKey: LOGS_METADATA_QUERY_KEY } );
	};

	return (
		<TabPanel
			className="datamachine-tabs datamachine-logs-agent-tabs"
			tabs={ tabs }
			activeClass="is-active"
			onSelect={ handleSelect }
			initialTabName={ activeTabName }
		>
			{ () => null }
		</TabPanel>
	);
}
