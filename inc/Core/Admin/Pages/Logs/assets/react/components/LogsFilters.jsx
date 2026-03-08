/**
 * LogsFilters Component
 *
 * Filter controls for the log viewer: level dropdown, search, refresh.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Button, SelectControl, SearchControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { logsKeys } from '../queries/logs';

const LEVEL_OPTIONS = [
	{ value: '', label: __( 'All Levels', 'data-machine' ) },
	{ value: 'critical', label: __( 'Critical', 'data-machine' ) },
	{ value: 'error', label: __( 'Error', 'data-machine' ) },
	{ value: 'warning', label: __( 'Warning', 'data-machine' ) },
	{ value: 'info', label: __( 'Info', 'data-machine' ) },
	{ value: 'debug', label: __( 'Debug', 'data-machine' ) },
];

const LogsFilters = () => {
	const queryClient = useQueryClient();
	const [ level, setLevel ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	const handleRefresh = useCallback( () => {
		queryClient.invalidateQueries( { queryKey: logsKeys.all } );
	}, [ queryClient ] );

	// Store filters on window for LogsTable to read.
	// This avoids prop-drilling through context for a simple page.
	window.__dmLogsFilters = { level, search };

	return (
		<div className="datamachine-logs-filters">
			<div className="datamachine-logs-filters-left">
				<SelectControl
					label={ __( 'Level', 'data-machine' ) }
					value={ level }
					options={ LEVEL_OPTIONS }
					onChange={ setLevel }
					__nextHasNoMarginBottom
				/>
				<SearchControl
					label={ __( 'Search', 'data-machine' ) }
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search messages...',
						'data-machine'
					) }
					__nextHasNoMarginBottom
				/>
			</div>
			<div className="datamachine-logs-filters-right">
				<Button variant="secondary" onClick={ handleRefresh }>
					{ __( 'Refresh', 'data-machine' ) }
				</Button>
			</div>
		</div>
	);
};

export default LogsFilters;
