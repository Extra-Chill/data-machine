/**
 * Agent bundle lifecycle query hooks.
 */

/**
 * External dependencies
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import * as agentBundlesApi from '../api/agentBundles';

const BUNDLES_KEY = [ 'agent-bundles' ];
const BUNDLE_ACTIONS_KEY = [ 'agent-bundles', 'pending-actions' ];

const unwrap = ( result, fallback ) => {
	if ( result?.success === false ) {
		throw new Error( result.message || result.error || fallback );
	}
	return result;
};

export const useAgentBundles = () =>
	useQuery( {
		queryKey: BUNDLES_KEY,
		queryFn: async () => unwrap( await agentBundlesApi.fetchBundles(), 'Failed to fetch installed bundles' ),
		staleTime: 30_000,
	} );

export const useAgentBundleStatus = ( slug ) =>
	useQuery( {
		queryKey: [ ...BUNDLES_KEY, 'status', slug ],
		queryFn: async () => unwrap( await agentBundlesApi.fetchBundleStatus( slug ), 'Failed to fetch bundle status' ),
		enabled: !! slug,
	} );

export const useBundlePendingActions = () =>
	useQuery( {
		queryKey: BUNDLE_ACTIONS_KEY,
		queryFn: async () => unwrap( await agentBundlesApi.fetchBundlePendingActions(), 'Failed to fetch pending bundle actions' ),
		staleTime: 15_000,
	} );

export const usePlanBundleUpgrade = () =>
	useMutation( {
		mutationFn: agentBundlesApi.planBundleUpgrade,
	} );

export const useRebaseBundleArtifacts = () =>
	useMutation( {
		mutationFn: agentBundlesApi.rebaseBundleArtifacts,
	} );

export const useApplyBundleUpgrade = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: agentBundlesApi.applyBundleUpgrade,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: BUNDLES_KEY } );
			queryClient.invalidateQueries( { queryKey: BUNDLE_ACTIONS_KEY } );
		},
	} );
};

export const useResolveBundlePendingAction = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: agentBundlesApi.resolvePendingAction,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: BUNDLES_KEY } );
			queryClient.invalidateQueries( { queryKey: BUNDLE_ACTIONS_KEY } );
		},
	} );
};
