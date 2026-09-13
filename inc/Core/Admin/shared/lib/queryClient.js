/**
 * TanStack Query Client Configuration
 *
 * Centralized query client with optimized defaults for Data Machine admin pages.
 */

/**
 * External dependencies
 */
import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 5 * 60 * 1000, // 5 minutes - data considered fresh
			gcTime: 10 * 60 * 1000, // 10 minutes - cache garbage collection
			refetchOnWindowFocus: true, // Refetch when user returns to tab
			retry: ( failureCount, error ) => {
				// Don't retry on 4xx client errors
				if ( error?.status >= 400 && error?.status < 500 ) {
					return false;
				}
				// Retry up to 3 times for other errors
				return failureCount < 3;
			},
		},
		mutations: {
			retry: false, // Don't retry mutations by default
		},
	},
} );
