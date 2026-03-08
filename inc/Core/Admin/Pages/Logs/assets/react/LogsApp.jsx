/**
 * LogsApp Component
 *
 * Root container for the Logs admin page.
 * Single filterable log viewer replacing the old tabbed agent-type interface.
 */

/**
 * Internal dependencies
 */
import LogsHeader from './components/LogsHeader';
import LogsFilters from './components/LogsFilters';
import LogsTable from './components/LogsTable';

const LogsApp = () => {
	return (
		<div className="datamachine-logs-app">
			<LogsHeader />
			<LogsFilters />
			<LogsTable />
		</div>
	);
};

export default LogsApp;
