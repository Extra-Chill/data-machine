/**
 * AgentApp Component
 *
 * Root container for the Agent admin page.
 * Tabbed layout: Manage, Memory, System Tasks, Tools, and Configuration.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';

/**
 * External dependencies
 */
import AgentSwitcher from '@shared/components/AgentSwitcher';
/**
 * Internal dependencies
 */
import AgentFileList from './components/AgentFileList';
import AgentFileEditor from './components/AgentFileEditor';
import AgentEmptyState from './components/AgentEmptyState';
import AgentSettings from './components/AgentSettings';
import AgentToolsTab from './components/AgentToolsTab';
import AgentListTab from './components/AgentListTab';
import AgentEditView from './components/AgentEditView';
import SystemTasksTab from './components/SystemTasksTab';
import { useAgentFiles } from './queries/agentFiles';

const TABS = [
	{ name: 'manage', title: 'Manage' },
	{ name: 'memory', title: 'Memory' },
	{ name: 'system-tasks', title: 'System Tasks' },
	{ name: 'tools', title: 'Tools' },
	{ name: 'configuration', title: 'Configuration' },
];

/**
 * Selection model:
 * - Core file: { type: 'core', filename: 'MEMORY.md' }
 * - Daily file: { type: 'daily', year: '2026', month: '02', day: '24' }
 * - null: nothing selected
 */
const AgentApp = () => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ editingAgentId, setEditingAgentId ] = useState( null );
	const { data: files } = useAgentFiles();
	const hasFiles = files && files.length > 0;

	return (
		<div className="datamachine-agent-app">
			<div className="datamachine-agent-header">
				<h1 className="datamachine-agent-title">Agents</h1>
				<AgentSwitcher />
			</div>
			<TabPanel
				className="datamachine-tabs"
				tabs={ TABS }
				onSelect={ () => {
					// Reset edit view when switching tabs.
					setEditingAgentId( null );
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'manage' ) {
						if ( editingAgentId ) {
							return (
								<AgentEditView
									agentId={ editingAgentId }
									onBack={ () =>
										setEditingAgentId( null )
									}
								/>
							);
						}

						return (
							<AgentListTab
								onSelectAgent={ ( agent ) =>
									setEditingAgentId( agent.agent_id )
								}
							/>
						);
					}

					if ( tab.name === 'memory' ) {
						return (
							<div className="datamachine-agent-layout">
								<AgentFileList
									selectedFile={ selectedFile }
									onSelectFile={ setSelectedFile }
								/>
								<div className="datamachine-agent-editor-panel">
									{ selectedFile ? (
										<AgentFileEditor
											selectedFile={ selectedFile }
										/>
									) : (
										<AgentEmptyState
											hasFiles={ hasFiles }
										/>
									) }
								</div>
							</div>
						);
					}

					if ( tab.name === 'system-tasks' ) {
						return <SystemTasksTab />;
					}

					if ( tab.name === 'tools' ) {
						return <AgentToolsTab />;
					}

					return <AgentSettings />;
				} }
			</TabPanel>
		</div>
	);
};

export default AgentApp;
