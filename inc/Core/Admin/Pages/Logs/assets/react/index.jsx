/**
 * Data Machine Logs React Entry Point
 *
 * Initializes React application for logs admin interface.
 */

/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
/**
 * External dependencies
 */
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@shared/lib/queryClient';
/**
 * Shared boot — registers param interceptors (agent scoping, etc.)
 */
import '@shared/boot/agentInterceptor'; // eslint-disable-line no-unused-expressions
/**
 * Internal dependencies
 */
import LogsApp from './LogsApp';

/**
 * Initialize React app when DOM is ready
 */
domReady( () => {
	const rootElement = document.getElementById( 'datamachine-logs-root' );

	if ( ! rootElement ) {
		console.error( 'Data Machine Logs: React root element not found' );
		return;
	}

	// Verify WordPress globals are available
	if ( ! window.dataMachineLogsConfig ) {
		console.error( 'Data Machine Logs: Configuration not found' );
		return;
	}

	// Render React app
	render(
		<QueryClientProvider client={ queryClient }>
			<LogsApp />
		</QueryClientProvider>,
		rootElement
	);
} );
