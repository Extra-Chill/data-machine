/**
 * LogsApp Component
 *
 * Root container for the Logs admin page.
 * Keeps the classic tabbed agent view, now backed by agent IDs.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
/**
 * Internal dependencies
 */
import LogsHeader from './components/LogsHeader';
import LogsAgentTabs from './components/LogsAgentTabs';
import LogsFilters from './components/LogsFilters';
import LogsTable from './components/LogsTable';

const LogsApp = () => {
	const [ filters, setFilters ] = useState( { level: '', search: '' } );

	return (
		<div className="datamachine-logs-app">
			<LogsHeader />
			<LogsAgentTabs />
			<LogsFilters filters={ filters } onFiltersChange={ setFilters } />
			<LogsTable filters={ filters } />
		</div>
	);
};

export default LogsApp;
