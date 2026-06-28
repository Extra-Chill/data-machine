/**
 * Jobs TanStack Query Hooks
 *
 * Query and mutation hooks for job operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import * as jobsApi from '../api/jobs';

/**
 * Query key factory for jobs
 */
export const jobsKeys = {
	all: [ 'jobs' ],
	list: ( params ) => [ ...jobsKeys.all, 'list', params ],
	children: ( parentJobId ) => [ ...jobsKeys.all, 'children', parentJobId ],
	pipelines: () => [ 'pipelines', 'dropdown' ],
	flows: ( pipelineId ) => [ 'flows', 'dropdown', pipelineId ],
};

/**
 * Fetch jobs list with pagination
 * @param root0
 * @param root0.page
 * @param root0.perPage
 * @param root0.status
 */
export const useJobs = ( { page = 1, perPage = 50, status } = {} ) =>
	useQuery( {
		queryKey: jobsKeys.list( { page, perPage, status } ),
		queryFn: async () => {
			const response = await jobsApi.fetchJobs( {
				page,
				perPage,
				status,
				hideChildren: true,
			} );
			if ( ! response.success ) {
				throw new Error( response.message || 'Failed to fetch jobs' );
			}
			return {
				jobs: response.data || [],
				total: response.total || 0,
				perPage: response.per_page || perPage,
				offset: response.offset || 0,
			};
		},
	} );

/**
 * Clear jobs mutation
 */
export const useClearJobs = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( { type, cleanupProcessed } ) =>
			jobsApi.clearJobs( type, cleanupProcessed ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: jobsKeys.all } );
		},
	} );
};

/**
 * Clear processed items mutation
 */
export const useClearProcessedItems = () => {
	return useMutation( {
		mutationFn: ( { clearType, targetId } ) =>
			jobsApi.clearProcessedItems( clearType, targetId ),
	} );
};

/**
 * Fetch child jobs for a batch parent (lazy-loaded on expand)
 *
 * @param {number|null} parentJobId Parent job ID (null = disabled)
 */
export const useChildJobs = ( parentJobId ) =>
	useQuery( {
		queryKey: jobsKeys.children( parentJobId ),
		queryFn: async () => {
			const response = await jobsApi.fetchChildJobs( parentJobId );
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch child jobs'
				);
			}
			return response.data || [];
		},
		enabled: !! parentJobId,
		staleTime: 30 * 1000,
	} );

/**
 * Fetch pipelines for dropdown
 */
export const usePipelinesForDropdown = () =>
	useQuery( {
		queryKey: jobsKeys.pipelines(),
		queryFn: async () => {
			const response = await jobsApi.fetchPipelines();
			if ( ! response.success ) {
				throw new Error(
					response.message || 'Failed to fetch pipelines'
				);
			}
			return response.data?.pipelines || [];
		},
		staleTime: 5 * 60 * 1000,
	} );

/**
 * Fetch flows for a specific pipeline
 * @param pipelineId
 */
export const useFlowsForDropdown = ( pipelineId ) =>
	useQuery( {
		queryKey: jobsKeys.flows( pipelineId ),
		queryFn: async () => {
			const response = await jobsApi.fetchFlowsForPipeline( pipelineId );
			if ( ! response.success ) {
				throw new Error( response.message || 'Failed to fetch flows' );
			}
			return response.data?.flows || [];
		},
		enabled: !! pipelineId,
		staleTime: 5 * 60 * 1000,
	} );
