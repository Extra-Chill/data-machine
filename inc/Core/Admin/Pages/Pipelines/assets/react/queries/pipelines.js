/**
 * Pipeline Queries and Mutations
 *
 * TanStack Query hooks for pipeline-related data operations.
 */

/**
 * External dependencies
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import {
	fetchPipelines,
	createPipeline,
	deletePipeline,
	addPipelineStep,
	deletePipelineStep,
	fetchContextFiles,
	uploadContextFile,
	deleteContextFile,
	fetchPipelineMemoryFiles,
	updatePipelineMemoryFiles,
	fetchAgentFiles,
} from '../utils/api';
import { isSameId } from '../utils/ids';

// Queries

/**
 * Lightweight pipelines list used by most of the admin UI.
 *
 * Fetches up to `perPage` pipelines without embedding flows. Each pipeline
 * carries a numeric `flow_count` for list-view displays. Mutations in this
 * file continue to write to the legacy `[ 'pipelines' ]` cache key so
 * optimistic updates stay in place.
 *
 * For scalable search/pagination (ComboboxControl-style), use
 * {@link usePipelineSearch}. For a single pipeline (including hydration
 * when the selected pipeline isn't in the list cache), use
 * {@link usePipeline}.
 */
export const usePipelines = () =>
	useQuery( {
		queryKey: [ 'pipelines' ],
		queryFn: async () => {
			const response = await fetchPipelines();
			if ( ! response.success ) {
				return [];
			}
			return response.data.pipelines ?? [];
		},
	} );

/**
 * Server-side search for pipelines — debounce the `search` argument in the
 * caller. Designed for the PipelineSelector and similar typeahead UIs.
 *
 * @param {Object} [options]
 * @param {string} [options.search]  Filter by pipeline_name substring.
 * @param {number} [options.perPage] Items per page (default 50).
 */
export const usePipelineSearch = ( { search = '', perPage = 50 } = {} ) => {
	const normalizedSearch = search ? search.trim() : '';

	return useQuery( {
		queryKey: [ 'pipelines', 'search', { search: normalizedSearch, perPage } ],
		queryFn: async () => {
			const response = await fetchPipelines( null, {
				perPage,
				offset: 0,
				includeFlows: false,
				search: normalizedSearch || null,
			} );
			if ( ! response.success ) {
				return { pipelines: [], total: 0 };
			}
			return {
				pipelines: response.data.pipelines ?? [],
				total: response.total ?? response.data.total ?? 0,
			};
		},
		keepPreviousData: true,
		staleTime: 5_000,
	} );
};

/**
 * Fetch a single pipeline by ID. Falls back to the `[ 'pipelines' ]` list
 * cache so reads are free when the pipeline is already in memory.
 *
 * @param {number|string|null} pipelineId
 */
export const usePipeline = ( pipelineId ) => {
	const queryClient = useQueryClient();

	return useQuery( {
		queryKey: [
			'pipelines',
			'single',
			pipelineId ? String( pipelineId ) : null,
		],
		queryFn: async () => {
			// Prefer the list cache when the pipeline is already there.
			const cachedList = queryClient.getQueryData( [ 'pipelines' ] );
			if ( Array.isArray( cachedList ) ) {
				const hit = cachedList.find( ( p ) =>
					isSameId( p.pipeline_id, pipelineId )
				);
				if ( hit ) {
					return hit;
				}
			}

			const response = await fetchPipelines( pipelineId );
			if ( ! response.success ) {
				return null;
			}
			const pipelineRecord = response.data?.pipeline ?? null;
			const flows = response.data?.flows ?? [];

			if ( ! pipelineRecord ) {
				return null;
			}

			// Match the shape the admin app expects when this pipeline comes
			// from the /pipelines list endpoint.
			return { ...pipelineRecord, flows };
		},
		enabled: !! pipelineId,
	} );
};

export const useContextFiles = ( pipelineId ) =>
	useQuery( {
		queryKey: [ 'context-files', pipelineId ],
		queryFn: async () => {
			const response = await fetchContextFiles( pipelineId );
			return response.success ? response.data : [];
		},
		enabled: !! pipelineId,
	} );

// Mutations
export const useCreatePipeline = ( options = {} ) => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: createPipeline,
		onMutate: async ( name ) => {
			await queryClient.cancelQueries( { queryKey: [ 'pipelines' ] } );

			const previousPipelines = queryClient.getQueryData( [
				'pipelines',
			] );
			const optimisticPipelineId = `optimistic_${ Date.now() }`;

			queryClient.setQueryData( [ 'pipelines' ], ( old = [] ) => [
				{
					pipeline_id: optimisticPipelineId,
					pipeline_name: name,
					pipeline_config: {},
				},
				...old,
			] );

			return { previousPipelines, optimisticPipelineId };
		},
		onError: ( _err, _name, context ) => {
			if ( context?.previousPipelines ) {
				queryClient.setQueryData(
					[ 'pipelines' ],
					context.previousPipelines
				);
			}
		},
		onSuccess: ( response, _name, context ) => {
			const pipeline = response?.data?.pipeline_data;
			const pipelineId = response?.data?.pipeline_id;

			if ( pipeline && context?.optimisticPipelineId ) {
				queryClient.setQueryData( [ 'pipelines' ], ( old = [] ) =>
					old.map( ( p ) =>
						isSameId( p.pipeline_id, context.optimisticPipelineId )
							? pipeline
							: p
					)
				);
			}

			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );

			// Call external onSuccess callback with the new pipeline ID after cache is updated
			if ( options.onSuccess && pipelineId ) {
				options.onSuccess( pipelineId );
			}
		},
	} );
};

export const useDeletePipeline = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: deletePipeline,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
		},
	} );
};

export const useAddPipelineStep = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { pipelineId, stepType, executionOrder } ) =>
			addPipelineStep( pipelineId, stepType, executionOrder ),
		onSuccess: ( response, { pipelineId } ) => {
			// Update cache with response data immediately (API is source of truth)
			if ( response?.data?.step_data ) {
				const stepData = response.data.step_data;
				const pipelineStepId = response.data.pipeline_step_id;

				queryClient.setQueryData( [ 'pipelines' ], ( old ) => {
					if ( ! old ) {
						return old;
					}
					return old.map( ( pipeline ) => {
						if ( ! isSameId( pipeline.pipeline_id, pipelineId ) ) {
							return pipeline;
						}
						// Build config entry for AI steps.
						// Note: provider/model are resolved via the mode system
						// (PluginSettings::resolveModelForAgentMode on the server).
						// They are not carried on the pipeline step config.
						const configEntry = {};
						if ( stepData.disabled_tools ) {
							configEntry.disabled_tools =
								stepData.disabled_tools;
						}

						return {
							...pipeline,
							pipeline_steps: [
								...( pipeline.pipeline_steps || [] ),
								stepData,
							],
							pipeline_config: {
								...( pipeline.pipeline_config || {} ),
								[ pipelineStepId ]: {
									...( pipeline.pipeline_config?.[
										pipelineStepId
									] || {} ),
									...configEntry,
								},
							},
						};
					} );
				} );
			}
			// Still invalidate for eventual consistency
			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
			queryClient.invalidateQueries( {
				queryKey: [ 'flows', pipelineId ],
			} );
		},
	} );
};

export const useDeletePipelineStep = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { pipelineId, stepId } ) =>
			deletePipelineStep( pipelineId, stepId ),
		onSuccess: ( _, { pipelineId } ) => {
			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
			queryClient.invalidateQueries( {
				queryKey: [ 'flows', pipelineId ],
			} );
		},
	} );
};



export const useUploadContextFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { pipelineId, file } ) =>
			uploadContextFile( pipelineId, file ),
		onSuccess: ( _, { pipelineId } ) => {
			queryClient.invalidateQueries( {
				queryKey: [ 'context-files', pipelineId ],
			} );
		},
	} );
};

export const useDeleteContextFile = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: deleteContextFile,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'context-files' ] } );
		},
	} );
};

export const useAgentFiles = () =>
	useQuery( {
		queryKey: [ 'agent-files' ],
		queryFn: async () => {
			const response = await fetchAgentFiles();
			return response.success ? response.data : [];
		},
	} );

export const usePipelineMemoryFiles = ( pipelineId ) =>
	useQuery( {
		queryKey: [ 'pipeline-memory-files', pipelineId ],
		queryFn: async () => {
			const response = await fetchPipelineMemoryFiles( pipelineId );
			return response.success ? response.data : [];
		},
		enabled: !! pipelineId,
	} );

export const useUpdatePipelineMemoryFiles = ( pipelineId ) => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( data ) => {
			// Accept either a bare array of filenames or an object
			// with a memoryFiles key (matches the flow mutation shape).
			const filenames = Array.isArray( data ) ? data : data.memoryFiles;
			return updatePipelineMemoryFiles( pipelineId, filenames );
		},
		onSuccess: () => {
			queryClient.invalidateQueries( {
				queryKey: [ 'pipeline-memory-files', pipelineId ],
			} );
		},
	} );
};
