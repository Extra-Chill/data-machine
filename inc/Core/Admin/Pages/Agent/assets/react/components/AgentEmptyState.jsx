/**
 * AgentEmptyState Component
 *
 * Displayed when no file is selected or when no files exist.
 */

const AgentEmptyState = ( { hasFiles } ) => {
	return (
		<div className="datamachine-agent-empty-state">
			<p>
				{ hasFiles
					? 'Select a file to edit, or create a new one.'
					: "No memory files yet. Click 'Add New' to create your first file." }
			</p>
		</div>
	);
};

export default AgentEmptyState;
