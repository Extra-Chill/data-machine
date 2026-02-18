/**
 * Data Machine Agent React Entry Point
 *
 * Initializes React application for agent admin interface.
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
 * Internal dependencies
 */
import AgentApp from './AgentApp';

/**
 * Initialize React app when DOM is ready
 */
domReady( () => {
	const rootElement = document.getElementById( 'datamachine-agent-root' );

	if ( ! rootElement ) {
		console.error( 'Data Machine Agent: React root element not found' );
		return;
	}

	if ( ! window.dataMachineAgentConfig ) {
		console.error( 'Data Machine Agent: Configuration not found' );
		return;
	}

	render(
		<QueryClientProvider client={ queryClient }>
			<AgentApp />
		</QueryClientProvider>,
		rootElement
	);
} );
