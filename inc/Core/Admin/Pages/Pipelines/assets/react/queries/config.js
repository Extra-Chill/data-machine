/**
 * Configuration Queries
 *
 * TanStack Query hooks for configuration data (step types, tools, scheduling intervals).
 * Provider queries have been moved to @shared/queries/providers.
 */

/**
 * External dependencies
 */
import { useQuery } from '@tanstack/react-query';
/**
 * Internal dependencies
 */
import { getStepTypes, getTools, getSchedulingIntervals } from '../utils/api';

export const useStepTypes = () =>
	useQuery( {
		queryKey: [ 'config', 'step-types' ],
		queryFn: async () => {
			const result = await getStepTypes();
			return result.success ? result.data : {};
		},
		staleTime: Infinity, // Never refetch - step types don't change
	} );

export const useSchedulingIntervals = () =>
	useQuery( {
		queryKey: [ 'config', 'scheduling-intervals' ],
		queryFn: async () => {
			const result = await getSchedulingIntervals();
			return result.success ? result.data : [];
		},
		staleTime: Infinity, // Scheduling intervals don't change
	} );

export const useTools = ( context = 'pipeline' ) =>
	useQuery( {
		queryKey: [ 'config', 'tools', context ],
		queryFn: async () => {
			const result = await getTools( context );
			return result.success ? result.data : {};
		},
		staleTime: 30 * 60 * 1000, // 30 minutes - tools don't change often
	} );
