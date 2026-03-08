/**
 * Data Machine Jobs React Entry Point
 *
 * Initializes React application for jobs admin interface.
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
import JobsApp from './JobsApp';

domReady( () => {
	const rootElement = document.getElementById( 'datamachine-jobs-root' );

	if ( ! rootElement ) {
		return;
	}

	if ( ! window.dataMachineJobsConfig ) {
		return;
	}

	render(
		<QueryClientProvider client={ queryClient }>
			<JobsApp />
		</QueryClientProvider>,
		rootElement
	);
} );
