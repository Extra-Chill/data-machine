/**
 * Agent bundles tab.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	useAgentBundles,
	useAgentBundleStatus,
	useApplyBundleUpgrade,
	useBundlePendingActions,
	usePlanBundleUpgrade,
	useRebaseBundleArtifacts,
	useResolveBundlePendingAction,
} from '../queries/agentBundles';

const asArray = ( value ) => ( Array.isArray( value ) ? value : [] );

const countPlanItems = ( plan ) => {
	const counts = plan?.counts || {};
	return {
		autoApply: counts.auto_apply ?? asArray( plan?.auto_apply ).length,
		needsApproval:
			counts.needs_approval ?? asArray( plan?.needs_approval ).length,
		warnings: counts.warnings ?? asArray( plan?.warnings ).length,
		noOp: counts.no_op ?? asArray( plan?.no_op ).length,
	};
};

const artifactLabel = ( artifact ) =>
	artifact.artifact_key ||
	artifact.source_path ||
	[ artifact.artifact_type, artifact.artifact_id ].filter( Boolean ).join( ':' ) ||
	__( 'Artifact', 'data-machine' );

const ResultNotice = ( { result } ) => {
	if ( ! result ) {
		return null;
	}

	if ( result.success === false ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ result.message || result.error || __( 'Request failed.', 'data-machine' ) }
			</Notice>
		);
	}

	return (
		<Notice status="success" isDismissible={ false }>
			{ result.message || __( 'Request completed.', 'data-machine' ) }
		</Notice>
	);
};

export default function AgentBundlesTab() {
	const bundlesQuery = useAgentBundles();
	const pendingActionsQuery = useBundlePendingActions();
	const bundles = asArray( bundlesQuery.data?.bundles );
	const [ selectedSlug, setSelectedSlug ] = useState( '' );
	const selectedBundle = bundles.find(
		( bundle ) => bundle.agent_slug === selectedSlug
	);
	const [ source, setSource ] = useState( '' );
	const [ planResult, setPlanResult ] = useState( null );
	const [ rebaseResult, setRebaseResult ] = useState( null );
	const [ applyResult, setApplyResult ] = useState( null );

	const statusQuery = useAgentBundleStatus( selectedSlug );
	const planMutation = usePlanBundleUpgrade();
	const rebaseMutation = useRebaseBundleArtifacts();
	const applyMutation = useApplyBundleUpgrade();
	const resolveMutation = useResolveBundlePendingAction();

	useEffect( () => {
		if ( ! selectedSlug && bundles.length > 0 ) {
			setSelectedSlug( bundles[ 0 ].agent_slug );
		}
	}, [ bundles, selectedSlug ] );

	useEffect( () => {
		setSource( selectedBundle?.source_ref || '' );
		setPlanResult( null );
		setRebaseResult( null );
		setApplyResult( null );
	}, [ selectedBundle?.agent_slug, selectedBundle?.source_ref ] );

	const requestInput = () => ( {
		slug: selectedSlug,
		source,
	} );

	const plan = planResult?.plan;
	const planCounts = countPlanItems( plan );
	const planRows = [
		...asArray( plan?.auto_apply ),
		...asArray( plan?.needs_approval ),
		...asArray( plan?.warnings ),
		...asArray( plan?.no_op ),
	];
	const artifacts = asArray( statusQuery.data?.artifacts );
	const pendingActions = asArray( pendingActionsQuery.data?.actions );

	if ( bundlesQuery.isLoading ) {
		return <Spinner />;
	}

	if ( bundlesQuery.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ bundlesQuery.error.message }
			</Notice>
		);
	}

	return (
		<div className="datamachine-agent-bundles-tab">
			<h2>{ __( 'Installed Bundles', 'data-machine' ) }</h2>
			{ bundles.length === 0 ? (
				<p>{ __( 'No installed agent bundles found.', 'data-machine' ) }</p>
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Agent', 'data-machine' ) }</th>
							<th>{ __( 'Bundle', 'data-machine' ) }</th>
							<th>{ __( 'Version', 'data-machine' ) }</th>
							<th>{ __( 'Artifacts', 'data-machine' ) }</th>
							<th>{ __( 'Source', 'data-machine' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ bundles.map( ( bundle ) => (
							<tr key={ bundle.agent_slug }>
								<td>
									<Button
										variant={ selectedSlug === bundle.agent_slug ? 'primary' : 'link' }
										onClick={ () => setSelectedSlug( bundle.agent_slug ) }
									>
										{ bundle.agent_slug }
									</Button>
								</td>
								<td>{ bundle.bundle_slug }</td>
								<td>{ bundle.bundle_version || bundle.template_version || '-' }</td>
								<td>{ bundle.artifacts ?? '-' }</td>
								<td>{ bundle.source_ref || '-' }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ selectedBundle && (
				<div className="datamachine-agent-bundles-tab__detail">
					<h3>
						{ sprintf(
							/* translators: %s: agent slug */
							__( 'Bundle Status: %s', 'data-machine' ),
							selectedSlug
						) }
					</h3>

					<TextControl
						label={ __( 'Bundle source', 'data-machine' ) }
						value={ source }
						onChange={ setSource }
						help={ __( 'Path or URL used for planning, rebasing, and applying bundle upgrades.', 'data-machine' ) }
					/>

					<div className="datamachine-agent-bundles-tab__actions">
						<Button
							variant="secondary"
							disabled={ ! source || planMutation.isPending }
							onClick={ async () => setPlanResult( await planMutation.mutateAsync( requestInput() ) ) }
						>
							{ __( 'Plan Upgrade', 'data-machine' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ ! source || rebaseMutation.isPending }
							onClick={ async () => setRebaseResult( await rebaseMutation.mutateAsync( { ...requestInput(), policy: 'conservative' } ) ) }
						>
							{ __( 'Preview Rebase', 'data-machine' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ ! source || applyMutation.isPending }
							onClick={ async () => setApplyResult( await applyMutation.mutateAsync( requestInput() ) ) }
						>
							{ __( 'Apply Clean Updates', 'data-machine' ) }
						</Button>
					</div>

					<ResultNotice result={ planResult } />
					<ResultNotice result={ rebaseResult } />
					<ResultNotice result={ applyResult } />

					{ plan && (
						<div>
							<h4>{ __( 'Upgrade Plan', 'data-machine' ) }</h4>
							<p>
								{ sprintf(
									/* translators: 1: auto-apply count, 2: approval count, 3: warning count, 4: no-op count */
									__( 'Auto-apply: %1$d. Needs approval: %2$d. Warnings: %3$d. No-op: %4$d.', 'data-machine' ),
									planCounts.autoApply,
									planCounts.needsApproval,
									planCounts.warnings,
									planCounts.noOp
								) }
							</p>
							<ul>
								{ planRows.map( ( item, index ) => (
									<li key={ `${ artifactLabel( item ) }-${ index }` }>
										<strong>{ artifactLabel( item ) }</strong>{ ' ' }
										{ item.reason || item.status || item.summary || '' }
									</li>
								) ) }
							</ul>
						</div>
					) }

					<h4>{ __( 'Tracked Artifacts', 'data-machine' ) }</h4>
					{ statusQuery.isLoading ? (
						<Spinner />
					) : artifacts.length > 0 ? (
						<ul>
							{ artifacts.map( ( artifact, index ) => (
								<li key={ `${ artifactLabel( artifact ) }-${ index }` }>
									<strong>{ artifactLabel( artifact ) }</strong>{ ' ' }
									{ artifact.status || artifact.current_status || '' }
								</li>
							) ) }
						</ul>
					) : (
						<pre>{ JSON.stringify( statusQuery.data || {}, null, 2 ) }</pre>
					) }

					<h4>{ __( 'Pending Bundle Decisions', 'data-machine' ) }</h4>
					{ pendingActions.length === 0 ? (
						<p>{ __( 'No pending bundle upgrade actions.', 'data-machine' ) }</p>
					) : (
						<ul>
							{ pendingActions.map( ( action ) => (
								<li key={ action.action_id || action.id }>
									<strong>{ action.summary || action.action_id || action.id }</strong>{ ' ' }
									<Button
										variant="primary"
										disabled={ resolveMutation.isPending }
										onClick={ () => resolveMutation.mutate( { action_id: action.action_id || action.id, decision: 'accepted' } ) }
									>
										{ __( 'Approve', 'data-machine' ) }
									</Button>{ ' ' }
									<Button
										variant="secondary"
										disabled={ resolveMutation.isPending }
										onClick={ () => resolveMutation.mutate( { action_id: action.action_id || action.id, decision: 'rejected' } ) }
									>
										{ __( 'Keep Local', 'data-machine' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</div>
	);
}
