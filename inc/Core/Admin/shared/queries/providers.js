/**
 * Providers Query Hook
 *
 * Shared TanStack Query hook for fetching AI providers and models.
 */

/**
 * External dependencies
 */
import { useQuery } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

export const PROVIDERS_KEY = [ 'config', 'providers' ];

/**
 * Fetch AI providers with their models and defaults
 *
 * @return {Object} Query result with providers data
 */
export const useProviders = () =>
	useQuery( {
		queryKey: PROVIDERS_KEY,
		queryFn: async () => {
			const result = await client.get( '/providers' );
			return result.success
				? result.data
				: { providers: {}, defaults: {} };
		},
		staleTime: 30 * 60 * 1000, // 30 minutes - providers don't change often
	} );
