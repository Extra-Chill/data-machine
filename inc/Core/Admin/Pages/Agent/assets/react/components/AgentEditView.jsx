/**
 * AgentEditView Component
 *
 * Agent detail/edit view with identity editing and access management.
 * Rendered inside the Manage tab when an agent is selected from the list.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	TextControl,
	SelectControl,
	Notice,
	Spinner,
	Card,
	CardHeader,
	CardBody,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	useAgent,
	useUpdateAgent,
	useAgentAccess,
	useGrantAccess,
	useRevokeAccess,
} from '../queries/agents';

/**
 * Role options for access grants.
 */
const ROLE_OPTIONS = [
	{ label: __( 'Viewer', 'data-machine' ), value: 'viewer' },
	{ label: __( 'Operator', 'data-machine' ), value: 'operator' },
	{ label: __( 'Admin', 'data-machine' ), value: 'admin' },
];

/**
 * Access Management Panel
 *
 * @param {Object} props         Component props.
 * @param {number} props.agentId Agent ID.
 * @param {number} props.ownerId Agent owner ID (cannot be revoked).
 */
const AccessPanel = ( { agentId, ownerId } ) => {
	const { data: grants, isLoading } = useAgentAccess( agentId );
	const grantMutation = useGrantAccess();
	const revokeMutation = useRevokeAccess();

	const [ newUserId, setNewUserId ] = useState( '' );
	const [ newRole, setNewRole ] = useState( 'viewer' );
	const [ grantError, setGrantError ] = useState( '' );

	const handleGrant = async () => {
		setGrantError( '' );
		const userId = parseInt( newUserId, 10 );

		if ( ! userId || userId <= 0 ) {
			setGrantError(
				__( 'Enter a valid WordPress user ID.', 'data-machine' )
			);
			return;
		}

		const result = await grantMutation.mutateAsync( {
			agentId,
			userId,
			role: newRole,
		} );

		if ( result.success ) {
			setNewUserId( '' );
			setNewRole( 'viewer' );
		} else {
			setGrantError(
				result.message ||
					__( 'Failed to grant access.', 'data-machine' )
			);
		}
	};

	const handleRevoke = async ( userId ) => {
		await revokeMutation.mutateAsync( { agentId, userId } );
	};

	return (
		<Card className="datamachine-access-panel">
			<CardHeader>
				<h3>{ __( 'Access Management', 'data-machine' ) }</h3>
			</CardHeader>
			<CardBody>
				{ isLoading ? (
					<Spinner />
				) : (
					<>
						{ grants && grants.length > 0 ? (
							<table className="wp-list-table widefat fixed striped datamachine-access-table">
								<thead>
									<tr>
										<th>
											{ __( 'User', 'data-machine' ) }
										</th>
										<th>
											{ __( 'Role', 'data-machine' ) }
										</th>
										<th>
											{ __(
												'Actions',
												'data-machine'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ grants.map( ( grant ) => (
										<tr key={ grant.user_id }>
											<td>
												{ grant.display_name }
												<br />
												<small className="datamachine-access-email">
													{ grant.user_email }
												</small>
											</td>
											<td>
												<span className="datamachine-role-badge">
													{ grant.role }
												</span>
											</td>
											<td>
												{ grant.user_id ===
												ownerId ? (
													<em className="datamachine-owner-label">
														{ __(
															'Owner',
															'data-machine'
														) }
													</em>
												) : (
													<Button
														variant="tertiary"
														isDestructive
														size="small"
														onClick={ () =>
															handleRevoke(
																grant.user_id
															)
														}
														isBusy={
															revokeMutation.isPending
														}
													>
														{ __(
															'Revoke',
															'data-machine'
														) }
													</Button>
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<p className="datamachine-no-grants">
								{ __(
									'No access grants configured.',
									'data-machine'
								) }
							</p>
						) }

						<div className="datamachine-grant-form">
							<h4>
								{ __( 'Grant Access', 'data-machine' ) }
							</h4>

							{ grantError && (
								<Notice
									status="error"
									isDismissible={ false }
								>
									{ grantError }
								</Notice>
							) }

							<div className="datamachine-grant-form-row">
								<TextControl
									label={ __(
										'User ID',
										'data-machine'
									) }
									type="number"
									value={ newUserId }
									onChange={ setNewUserId }
									placeholder="1"
								/>
								<SelectControl
									label={ __( 'Role', 'data-machine' ) }
									value={ newRole }
									options={ ROLE_OPTIONS }
									onChange={ setNewRole }
								/>
								<Button
									variant="secondary"
									onClick={ handleGrant }
									isBusy={ grantMutation.isPending }
									disabled={ grantMutation.isPending }
									className="datamachine-grant-button"
								>
									{ __( 'Grant', 'data-machine' ) }
								</Button>
							</div>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
};

/**
 * AgentEditView — main export
 *
 * @param {Object}   props          Component props.
 * @param {number}   props.agentId  Agent ID to edit.
 * @param {Function} props.onBack   Callback to return to the list.
 */
const AgentEditView = ( { agentId, onBack } ) => {
	const { data: agent, isLoading, error } = useAgent( agentId );
	const updateMutation = useUpdateAgent();

	const [ name, setName ] = useState( '' );
	const [ saveMessage, setSaveMessage ] = useState( null );

	// Sync form state when agent data loads.
	useEffect( () => {
		if ( agent ) {
			setName( agent.agent_name || '' );
		}
	}, [ agent ] );

	const handleSave = async () => {
		setSaveMessage( null );

		const result = await updateMutation.mutateAsync( {
			agentId,
			agent_name: name,
		} );

		if ( result.success ) {
			setSaveMessage( {
				type: 'success',
				text: __( 'Agent updated.', 'data-machine' ),
			} );
		} else {
			setSaveMessage( {
				type: 'error',
				text:
					result.message ||
					__( 'Failed to update agent.', 'data-machine' ),
			} );
		}
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-edit-loading">
				<Spinner />
			</div>
		);
	}

	if ( error || ! agent ) {
		return (
			<div className="datamachine-agent-edit-error">
				<Notice status="error" isDismissible={ false }>
					{ error?.message ||
						__( 'Agent not found.', 'data-machine' ) }
				</Notice>
				<Button variant="secondary" onClick={ onBack }>
					{ __( 'Back to list', 'data-machine' ) }
				</Button>
			</div>
		);
	}

	const isDirty = name !== ( agent.agent_name || '' );

	return (
		<div className="datamachine-agent-edit-view">
			<div className="datamachine-agent-edit-header">
				<Button
					variant="tertiary"
					onClick={ onBack }
					icon="arrow-left-alt2"
				>
					{ __( 'All Agents', 'data-machine' ) }
				</Button>
				<h2>{ agent.agent_name || agent.agent_slug }</h2>
			</div>

			{ saveMessage && (
				<Notice
					status={ saveMessage.type }
					isDismissible
					onDismiss={ () => setSaveMessage( null ) }
				>
					{ saveMessage.text }
				</Notice>
			) }

			<Card className="datamachine-agent-identity-card">
				<CardHeader>
					<h3>{ __( 'Identity', 'data-machine' ) }</h3>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Slug', 'data-machine' ) }
						value={ agent.agent_slug }
						disabled
						help={ __(
							'Slug cannot be changed after creation. Use CLI rename if needed.',
							'data-machine'
						) }
					/>

					<TextControl
						label={ __( 'Display Name', 'data-machine' ) }
						value={ name }
						onChange={ setName }
					/>

					<div className="datamachine-agent-meta">
						<p>
							<strong>
								{ __( 'Owner ID:', 'data-machine' ) }
							</strong>{ ' ' }
							{ agent.owner_id }
						</p>
						<p>
							<strong>
								{ __( 'Created:', 'data-machine' ) }
							</strong>{ ' ' }
							{ agent.created_at || '—' }
						</p>
						<p>
							<strong>
								{ __( 'Updated:', 'data-machine' ) }
							</strong>{ ' ' }
							{ agent.updated_at || '—' }
						</p>
						<p>
							<strong>
								{ __( 'Has Files:', 'data-machine' ) }
							</strong>{ ' ' }
							{ agent.has_files
								? __( 'Yes', 'data-machine' )
								: __( 'No', 'data-machine' ) }
						</p>
					</div>

					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={ ! isDirty || updateMutation.isPending }
						isBusy={ updateMutation.isPending }
					>
						{ __( 'Save Changes', 'data-machine' ) }
					</Button>
				</CardBody>
			</Card>

			<AccessPanel
				agentId={ agentId }
				ownerId={ agent.owner_id }
			/>
		</div>
	);
};

export default AgentEditView;
