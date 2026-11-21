/**
 * Agent bundle lifecycle API.
 */

/**
 * External dependencies
 */
import { client } from '@shared/utils/api';

export const fetchBundles = () => client.get( '/agent-bundles' );

export const fetchBundleStatus = ( slug ) =>
	client.get( `/agent-bundles/${ slug }` );

export const planBundleUpgrade = ( data ) =>
	client.post( '/agent-bundles/plan', data );

export const rebaseBundleArtifacts = ( data ) =>
	client.post( '/agent-bundles/rebase', data );

export const applyBundleUpgrade = ( data ) =>
	client.post( '/agent-bundles/upgrade', data );

export const fetchBundlePendingActions = () =>
	client.get( '/actions', { kind: 'bundle_upgrade', status: 'pending' } );

export const resolvePendingAction = ( data ) =>
	client.post( '/actions/resolve', data );
