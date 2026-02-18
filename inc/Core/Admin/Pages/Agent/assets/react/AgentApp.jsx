/**
 * AgentApp Component
 *
 * Root container for the Agent admin page.
 * Two-panel layout: file list sidebar + editor.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AgentFileList from './components/AgentFileList';
import AgentFileEditor from './components/AgentFileEditor';
import AgentEmptyState from './components/AgentEmptyState';
import AgentSettings from './components/AgentSettings';
import { useAgentFiles } from './queries/agentFiles';

const AgentApp = () => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const { data: files } = useAgentFiles();
	const hasFiles = files && files.length > 0;

	return (
		<div className="datamachine-agent-app">
			<div className="datamachine-agent-header">
				<h1 className="datamachine-agent-title">Agent</h1>
			</div>
			<div className="datamachine-agent-layout">
				<AgentFileList
					selectedFile={ selectedFile }
					onSelectFile={ setSelectedFile }
				/>
				<div className="datamachine-agent-editor-panel">
					{ selectedFile ? (
						<AgentFileEditor filename={ selectedFile } />
					) : (
						<AgentEmptyState hasFiles={ hasFiles } />
					) }
				</div>
			</div>
			<AgentSettings />
		</div>
	);
};

export default AgentApp;
