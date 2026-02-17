/**
 * Configuration Queries
 *
 * TanStack Query hooks for configuration data (step types, global settings, tools).
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

export const useGlobalSettings = () =>
	useQuery( {
		queryKey: [ 'config', 'global-settings' ],
		queryFn: async () => {
			return window.datamachineConfig?.globalSettings || {};
		},
		staleTime: 10 * 60 * 1000, // 10 minutes
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

export const useTools = () =>
	useQuery( {
		queryKey: [ 'config', 'tools' ],
		queryFn: async () => {
			const result = await getTools();
			return result.success ? result.data : {};
		},
		staleTime: 30 * 60 * 1000, // 30 minutes - tools don't change often
	} );
