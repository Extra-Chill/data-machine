/**
 * AgentApp Component
 *
 * Root container for the Agent admin page.
 * Tabbed layout: Memory, System Tasks, and Configuration.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';

/**
 * Internal dependencies
 */
import AgentFileList from './components/AgentFileList';
import AgentFileEditor from './components/AgentFileEditor';
import AgentEmptyState from './components/AgentEmptyState';
import AgentSettings from './components/AgentSettings';
import SystemTasksTab from './components/SystemTasksTab';
import { useAgentFiles } from './queries/agentFiles';

const TABS = [
	{ name: 'memory', title: 'Memory' },
	{ name: 'system-tasks', title: 'System Tasks' },
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
	const { data: files } = useAgentFiles();
	const hasFiles = files && files.length > 0;

	return (
		<div className="datamachine-agent-app">
			<div className="datamachine-agent-header">
				<h1 className="datamachine-agent-title">Agent</h1>
			</div>
			<TabPanel
				className="datamachine-tabs"
				tabs={ TABS }
			>
				{ ( tab ) => {
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

					return <AgentSettings />;
				} }
			</TabPanel>
		</div>
	);
};

export default AgentApp;
