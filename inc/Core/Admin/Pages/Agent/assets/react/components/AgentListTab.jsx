/**
 * AgentListTab Component
 *
 * Agent management list table with create and delete functionality.
 * WordPress admin-style list table showing all agents with status,
 * owner, and timestamps.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	useManageAgents,
	useDeleteAgent,
} from '../queries/agents';
import CreateAgentModal from '@shared/components/CreateAgentModal';
import { useAgentStore } from '@shared/stores/agentStore';

/**
 * Status badge colors.
 */
const STATUS_STYLES = {
	active: { background: '#d4edda', color: '#155724' },
	inactive: { background: '#fff3cd', color: '#856404' },
	archived: { background: '#f8d7da', color: '#721c24' },
};

/**
 * Format a date string for display.
 *
 * @param {string} dateStr ISO date string.
 * @return {string} Formatted date.
 */
const formatDate = ( dateStr ) => {
	if ( ! dateStr ) {
		return '—';
	}
	try {
		const date = new Date( dateStr );
		return date.toLocaleDateString( undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		} );
	} catch {
		return dateStr;
	}
};

/**
 * AgentListTab — main export
 *
 * @param {Object}   props              Component props.
 * @param {Function} props.onSelectAgent Callback when an agent row is clicked for editing.
 */
const AgentListTab = ( { onSelectAgent } ) => {
	const { data: agents, isLoading, error } = useManageAgents();
	const deleteMutation = useDeleteAgent();
	const [ showCreateModal, setShowCreateModal ] = useState( false );
	const [ deleteTarget, setDeleteTarget ] = useState( null );
	const { setSelectedAgentId } = useAgentStore();

	const handleDelete = async () => {
		if ( ! deleteTarget ) {
			return;
		}

		await deleteMutation.mutateAsync( {
			agentId: deleteTarget.agent_id,
		} );
		setDeleteTarget( null );
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-list-loading">
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error.message ||
					__( 'Failed to load agents.', 'data-machine' ) }
			</Notice>
		);
	}

	return (
		<div className="datamachine-agent-list-tab">
			<div className="datamachine-agent-list-header">
				<p className="datamachine-agent-list-count">
					{ agents?.length || 0 }{ ' ' }
					{ agents?.length === 1
						? __( 'agent', 'data-machine' )
						: __( 'agents', 'data-machine' ) }
				</p>
				<Button
					variant="primary"
					onClick={ () => setShowCreateModal( true ) }
				>
					{ __( 'Add New', 'data-machine' ) }
				</Button>
			</div>

			{ agents && agents.length > 0 ? (
				<table className="wp-list-table widefat fixed striped datamachine-agent-table">
					<thead>
						<tr>
							<th className="column-name">
								{ __( 'Name', 'data-machine' ) }
							</th>
							<th className="column-slug">
								{ __( 'Slug', 'data-machine' ) }
							</th>
							<th className="column-status">
								{ __( 'Status', 'data-machine' ) }
							</th>
							<th className="column-created">
								{ __( 'Created', 'data-machine' ) }
							</th>
							<th className="column-actions">
								{ __( 'Actions', 'data-machine' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ agents.map( ( agent ) => (
							<tr key={ agent.agent_id }>
								<td className="column-name">
									<button
										type="button"
										className="datamachine-agent-name-link"
										onClick={ () =>
											onSelectAgent?.( agent )
										}
									>
										<strong>
											{ agent.agent_name ||
												agent.agent_slug }
										</strong>
									</button>
								</td>
								<td className="column-slug">
									<code>{ agent.agent_slug }</code>
								</td>
								<td className="column-status">
									<span
										className="datamachine-status-badge"
										style={
											STATUS_STYLES[
												agent.status
											] || {}
										}
									>
										{ agent.status }
									</span>
								</td>
								<td className="column-created">
									{ formatDate( agent.created_at ) }
								</td>
								<td className="column-actions">
									<Button
										variant="tertiary"
										isDestructive
										size="small"
										onClick={ () =>
											setDeleteTarget( agent )
										}
									>
										{ __( 'Delete', 'data-machine' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<div className="datamachine-agent-empty">
					<p>
						{ __(
							'No agents yet. Create your first agent to get started.',
							'data-machine'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ () => setShowCreateModal( true ) }
					>
						{ __( 'Create Agent', 'data-machine' ) }
					</Button>
				</div>
			) }

			{ showCreateModal && (
				<CreateAgentModal
					onClose={ () => setShowCreateModal( false ) }
					onCreated={ ( agent ) => {
						if ( agent?.agent_id ) {
							setSelectedAgentId( agent.agent_id );
						}
					} }
				/>
			) }

			{ deleteTarget && (
				<ConfirmDialog
					onConfirm={ handleDelete }
					onCancel={ () => setDeleteTarget( null ) }
				>
					{ __(
						'Are you sure you want to delete agent',
						'data-machine'
					) }{ ' ' }
					<strong>"{ deleteTarget.agent_name || deleteTarget.agent_slug }"</strong>
					?
					{ __(
						' This will remove the agent record and access grants. Filesystem files will not be deleted.',
						'data-machine'
					) }
				</ConfirmDialog>
			) }
		</div>
	);
};

export default AgentListTab;
