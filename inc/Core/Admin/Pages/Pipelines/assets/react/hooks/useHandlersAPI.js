/**
 * Handlers API Hook
 *
 * Fetch handlers from REST API with optional step type filtering.
 * Provides loading and error states for components.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { getHandlers } from '../utils/api';

/**
 * Fetch handlers from REST API
 *
 * @param {string|null} stepType - Optional step type filter (fetch, publish, upsert)
 * @return {Object} Handlers data with loading and error states
 */
export const useHandlersAPI = ( stepType = null ) => {
	const [ handlers, setHandlers ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const fetchHandlers = async () => {
			setLoading( true );
			setError( null );

			try {
				const response = await getHandlers( stepType );

				if ( response.success ) {
					// API returns handlers array directly in response.data
					setHandlers( response.data || {} );
				} else {
					throw new Error(
						response.message || 'Failed to fetch handlers'
					);
				}
			} catch ( err ) {
				console.error( 'Handlers fetch error:', err );
				setError(
					err.message || 'An error occurred while fetching handlers'
				);
			} finally {
				setLoading( false );
			}
		};

		fetchHandlers();
	}, [ stepType ] );

	return { handlers, loading, error };
};
