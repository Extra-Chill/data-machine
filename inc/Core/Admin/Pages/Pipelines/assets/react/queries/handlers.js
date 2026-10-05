/**
 * Handler Queries
 *
 * TanStack Query hooks for handler-related data operations.
 */

/**
 * External dependencies
 */
import { useQuery } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { fetchHandlerDetails, getHandlers } from '../utils/api';

export const useHandlers = () =>
	useQuery( {
		queryKey: [ 'handlers' ],
		queryFn: async () => {
			const result = await getHandlers();
			return result.success ? result.data : {};
		},
		staleTime: 30 * 60 * 1000, // 30 minutes - handlers don't change often
	} );

export const useHandlerDetails = ( handlerSlug ) =>
	useQuery( {
		queryKey: [ 'handlers', handlerSlug ],
		queryFn: async () => {
			const response = await fetchHandlerDetails( handlerSlug );
			return response.success ? response.data : null;
		},
		enabled: !! handlerSlug,
		staleTime: 30 * 60 * 1000, // 30 minutes
	} );
