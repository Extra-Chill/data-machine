/**
 * LogsApp Component
 *
 * Root container for the Logs admin page.
 * Keeps the classic tabbed agent view, now backed by agent IDs.
 */

/**
 * Internal dependencies
 */
import LogsHeader from './components/LogsHeader';
import LogsAgentTabs from './components/LogsAgentTabs';
import LogsFilters from './components/LogsFilters';
import LogsTable from './components/LogsTable';

const LogsApp = () => {
	return (
		<div className="datamachine-logs-app">
			<LogsHeader />
			<LogsAgentTabs />
			<LogsFilters />
			<LogsTable />
		</div>
	);
};

export default LogsApp;
