/**
 * Auth Providers Query Hooks
 *
 * TanStack Query hooks for the auth providers list endpoint.
 *
 * @since 0.44.1
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@shared/utils/api';

/**
 * Query key for auth providers list
 */
export const AUTH_PROVIDERS_KEY = [ 'authProviders' ];

/**
 * Fetch all registered auth providers with status
 */
export const useAuthProviders = () => {
	return useQuery( {
		queryKey: AUTH_PROVIDERS_KEY,
		queryFn: async () => {
			const response = await client.get( '/auth/providers' );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch auth providers'
				);
			}
			return response.data;
		},
		staleTime: 30 * 1000,
	} );
};

/**
 * Save auth config for a provider
 */
export const useSaveAuthConfig = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( { providerKey, config } ) => {
			const response = await client.put(
				`/auth/${ providerKey }`,
				config
			);
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to save auth config'
				);
			}
			return response.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: AUTH_PROVIDERS_KEY } );
		},
	} );
};

/**
 * Disconnect an auth provider
 */
export const useDisconnectAuth = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: async ( providerKey ) => {
			const response = await client.delete(
				`/auth/${ providerKey }`
			);
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to disconnect account'
				);
			}
			return response.data;
		},
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: AUTH_PROVIDERS_KEY } );
		},
	} );
};
