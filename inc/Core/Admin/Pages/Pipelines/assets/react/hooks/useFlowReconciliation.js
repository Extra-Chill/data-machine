/**
 * Flow Reconciliation Hook
 *
 * Polls for flow updates after a run is triggered, using exponential backoff.
 * Updates the TanStack Query cache when the flow's last_run changes,
 * confirming the run has been processed.
 */

/**
 * WordPress dependencies
 */
import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { fetchFlow } from '../utils/api';
import { isSameId } from '../utils/ids';

const BACKOFF_DELAYS = [ 500, 1000, 2000, 4000, 8000 ];

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Hook for reconciling flow state after a run.
 *
 * Polls the API with exponential backoff until the flow's last_run changes,
 * then updates the query cache. Uses a cancellation token to prevent stale
 * updates when a new run is triggered before the previous reconciliation completes.
 *
 * @return {Object} Reconciliation state and trigger function.
 * @return {string|null} return.optimisticLastRunDisplay - Temporary display text (e.g. "Queued") or null.
 * @return {Function}    return.reconcile                - Trigger reconciliation for a flow run.
 */
export default function useFlowReconciliation() {
	const queryClient = useQueryClient();
	const [ optimisticLastRunDisplay, setOptimisticLastRunDisplay ] =
		useState( null );
	const tokenRef = useRef( 0 );

	// Cleanup on unmount.
	useEffect( () => {
		return () => {
			tokenRef.current += 1;
		};
	}, [] );

	/**
	 * Poll for updated flow data after a run.
	 *
	 * @param {Object}      options
	 * @param {number}      options.flowId         - Flow ID to poll.
	 * @param {number}      options.pipelineId     - Pipeline ID for cache scoping.
	 * @param {string|null} options.baselineLastRun - last_run value before the run was triggered.
	 */
	const reconcile = useCallback(
		async ( { flowId, pipelineId, baselineLastRun } ) => {
			const token = tokenRef.current + 1;
			tokenRef.current = token;

			setOptimisticLastRunDisplay( __( 'Queued', 'data-machine' ) );

			for ( const delay of BACKOFF_DELAYS ) {
				await sleep( delay );

				// Cancelled — a newer reconciliation superseded this one.
				if ( tokenRef.current !== token ) {
					return;
				}

				try {
					const response = await fetchFlow( flowId );
					if ( ! response?.success || ! response?.data ) {
						continue;
					}

					const updatedFlow = response.data;

					// Update single-flow cache.
					queryClient.setQueryData(
						[ 'flows', 'single', flowId ],
						updatedFlow
					);

					// Update paginated flow list cache.
					if ( pipelineId ) {
						queryClient.setQueriesData(
							{
								queryKey: [ 'flows', pipelineId ],
								exact: false,
							},
							( oldData ) => {
								if (
									! oldData?.flows ||
									! Array.isArray( oldData.flows )
								) {
									return oldData;
								}

								return {
									...oldData,
									flows: oldData.flows.map(
										( existingFlow ) =>
											isSameId(
												existingFlow.flow_id,
												flowId
											)
												? updatedFlow
												: existingFlow
									),
								};
							}
						);
					}

					// If last_run changed, the run completed — clear optimistic display.
					if (
						updatedFlow.last_run &&
						updatedFlow.last_run !== baselineLastRun
					) {
						setOptimisticLastRunDisplay( null );
						return;
					}
				} catch ( err ) {
					// Network error — continue polling.
					continue;
				}
			}
		},
		[ queryClient ]
	);

	/**
	 * Cancel any in-flight reconciliation and clear optimistic display.
	 */
	const cancel = useCallback( () => {
		tokenRef.current += 1;
		setOptimisticLastRunDisplay( null );
	}, [] );

	return { optimisticLastRunDisplay, reconcile, cancel };
}
