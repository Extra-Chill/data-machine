/**
 * LogsTable Component
 *
 * Data table displaying structured log entries with pagination.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { Spinner, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useLogs } from '../queries/logs';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Level badge color mapping.
 */
const LEVEL_COLORS = {
	critical: '#d63638',
	error: '#d63638',
	warning: '#dba617',
	info: '#2271b1',
	debug: '#757575',
};

const LogsTable = ( { filters = {} } ) => {
	const [ page, setPage ] = useState( 1 );
	const perPage = 50;
	const selectedAgentId = useAgentStore( ( state ) => state.selectedAgentId );

	const queryFilters = {
		per_page: perPage,
		page,
	};

	if ( selectedAgentId ) {
		queryFilters.agent_id = selectedAgentId;
	}

	if ( filters.level ) {
		queryFilters.level = filters.level;
	}
	if ( filters.search ) {
		queryFilters.search = filters.search;
	}

	const { data, isLoading, isError, error } = useLogs( queryFilters );

	if ( isLoading ) {
		return (
			<div className="datamachine-logs-table-loading">
				<Spinner />
				<span>{ __( 'Loading logs...', 'data-machine' ) }</span>
			</div>
		);
	}

	if ( isError ) {
		return (
			<div className="datamachine-logs-table-error">
				{ error?.message ||
					__( 'Failed to load logs.', 'data-machine' ) }
			</div>
		);
	}

	const items = data?.items || [];
	const total = data?.total || 0;
	const pages = data?.pages || 1;

	if ( items.length === 0 ) {
		return (
			<div className="datamachine-logs-table-empty">
				{ __( 'No log entries found.', 'data-machine' ) }
			</div>
		);
	}

	return (
		<div className="datamachine-logs-table-container">
			<table className="datamachine-logs-table wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th className="datamachine-logs-col-level">
							{ __( 'Level', 'data-machine' ) }
						</th>
						<th className="datamachine-logs-col-message">
							{ __( 'Message', 'data-machine' ) }
						</th>
						<th className="datamachine-logs-col-context">
							{ __( 'Context', 'data-machine' ) }
						</th>
						<th className="datamachine-logs-col-time">
							{ __( 'Time', 'data-machine' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr key={ item.id }>
							<td className="datamachine-logs-col-level">
								<span
									className="datamachine-logs-level-badge"
									style={ {
										color:
											LEVEL_COLORS[
												item.level
											] || '#757575',
									} }
								>
									{ item.level.toUpperCase() }
								</span>
							</td>
							<td className="datamachine-logs-col-message">
								{ item.message }
							</td>
							<td className="datamachine-logs-col-context">
								{ item.context &&
								Object.keys( item.context ).length > 0 ? (
									<code className="datamachine-logs-context-code">
										{ JSON.stringify(
											item.context
										).substring( 0, 100 ) }
										{ JSON.stringify( item.context )
											.length > 100
											? '...'
											: '' }
									</code>
								) : (
									'-'
								) }
							</td>
							<td className="datamachine-logs-col-time">
								{ item.created_at }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>

			{ pages > 1 && (
				<div className="datamachine-logs-pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'data-machine' ) }
					</Button>
					<span className="datamachine-logs-page-info">
						{ __( 'Page', 'data-machine' ) } { page }{ ' ' }
						{ __( 'of', 'data-machine' ) } { pages } ({ total }{ ' ' }
						{ __( 'total', 'data-machine' ) })
					</span>
					<Button
						variant="secondary"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'data-machine' ) }
					</Button>
				</div>
			) }
		</div>
	);
};

export default LogsTable;
