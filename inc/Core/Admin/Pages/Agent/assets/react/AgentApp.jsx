/**
 * AgentApp Component
 *
 * Root container for the Agent admin page.
 * Tabbed layout: Memory, Manage, System Tasks, Tools, and Configuration.
 * Agent switcher at top left of tabs for quick agent selection.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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
import AgentEditView from './components/AgentEditView';
import SystemTasksTab from './components/SystemTasksTab';
import { useAgentFiles } from './queries/agentFiles';
import { useAgentStore } from '@shared/stores/agentStore';

const TABS = [
	{ name: 'memory', title: 'Memory' },
	{ name: 'manage', title: 'Manage' },
	{ name: 'system-tasks', title: 'System Tasks' },
	{ name: 'tools', title: 'Tools' },
	{ name: 'configuration', title: 'Configuration' },
];

/**
 * Empty state when no agent is selected.
 *
 * @param {string} message Message to display.
 * @return {React.ReactElement}
 */
const NoAgentSelectedMessage = ( { message } ) => (
	<div className="datamachine-agent-tab-empty">
		<p>{ message }</p>
	</div>
);

/**
 * Selection model:
 * - Core file: { type: 'core', filename: 'MEMORY.md' }
 * - Daily file: { type: 'daily', year: '2026', month: '02', day: '24' }
 * - null: nothing selected
 */
const AgentApp = () => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const { data: files } = useAgentFiles();
	const hasFiles = files && files.length > 0;

	// Get selected agent from store
	const { selectedAgentId } = useAgentStore();

	return (
		<div className="datamachine-agent-app">
			<div className="datamachine-agent-header">
				<h1 className="datamachine-agent-title">Agents</h1>
			</div>
			<div className="datamachine-agent-tabs-header">
				<AgentSwitcher />
			</div>
			<TabPanel
				className="datamachine-tabs"
				tabs={ TABS }
				onSelect={ () => {
					// No state to reset when switching tabs now.
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'manage' ) {
						// Manage tab: show selected agent's edit view,
						// or prompt to select an agent if none selected.
						if ( ! selectedAgentId ) {
							return (
								<NoAgentSelectedMessage
									message={ __(
										'Select an agent from the dropdown above to manage its settings.',
										'data-machine'
									) }
								/>
							);
						}

						return <AgentEditView agentId={ selectedAgentId } />;
					}

					if ( tab.name === 'memory' ) {
						// Memory tab: require an agent to be selected
						if ( ! selectedAgentId ) {
							return (
								<NoAgentSelectedMessage
									message={ __(
										'Select an agent from the dropdown above to view its memory files.',
										'data-machine'
									) }
								/>
							);
						}

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
						// System Tasks: require agent selection
						if ( ! selectedAgentId ) {
							return (
								<NoAgentSelectedMessage
									message={ __(
										'Select an agent from the dropdown above to view its system tasks.',
										'data-machine'
									) }
								/>
							);
						}
						return <SystemTasksTab />;
					}

					if ( tab.name === 'tools' ) {
						// Tools: require agent selection
						if ( ! selectedAgentId ) {
							return (
								<NoAgentSelectedMessage
									message={ __(
										'Select an agent from the dropdown above to view its tools configuration.',
										'data-machine'
									) }
								/>
							);
						}
						return <AgentToolsTab />;
					}

					// Configuration tab works without agent selection (global config)
					return <AgentSettings />;
				} }
			</TabPanel>
		</div>
	);
};

export default AgentApp;
