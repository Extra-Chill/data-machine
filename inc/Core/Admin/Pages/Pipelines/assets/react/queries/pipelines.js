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
	updatePipelineTitle,
	deletePipeline,
	addPipelineStep,
	deletePipelineStep,
	reorderPipelineSteps,
	updateSystemPrompt,
	fetchContextFiles,
	uploadContextFile,
	deleteContextFile,
} from '../utils/api';
import { isSameId } from '../utils/ids';

// Queries
export const usePipelines = () =>
	useQuery( {
		queryKey: [ 'pipelines' ],
		queryFn: async () => {
			const response = await fetchPipelines();
			return response.success ? response.data.pipelines : [];
		},
	} );

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

export const useUpdatePipelineTitle = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { pipelineId, name } ) =>
			updatePipelineTitle( pipelineId, name ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
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
						if (
							! isSameId( pipeline.pipeline_id, pipelineId )
						) {
							return pipeline;
						}
						// Build config entry for AI steps
						const configEntry = {};
						if ( stepData.provider ) {
							configEntry.provider = stepData.provider;
						}
						if ( stepData.model ) {
							configEntry.model = stepData.model;
						}
						if ( stepData.enabled_tools ) {
							configEntry.enabled_tools = stepData.enabled_tools;
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

export const useReorderPipelineSteps = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { pipelineId, steps } ) =>
			reorderPipelineSteps( pipelineId, steps ),
		onSuccess: ( _, { pipelineId } ) => {
			queryClient.invalidateQueries( { queryKey: [ 'pipelines' ] } );
			queryClient.invalidateQueries( {
				queryKey: [ 'flows', pipelineId ],
			} );
		},
	} );
};

export const useUpdateSystemPrompt = () => {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( {
			stepId,
			prompt,
			provider,
			model,
			enabledTools,
			stepType,
			pipelineId,
			webhookUrl,
		} ) =>
			updateSystemPrompt(
				stepId,
				prompt,
				provider,
				model,
				enabledTools,
				stepType,
				pipelineId,
				webhookUrl
			),
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
