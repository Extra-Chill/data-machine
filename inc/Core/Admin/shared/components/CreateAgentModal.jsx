/**
 * CreateAgentModal Component
 *
 * Shared modal for creating a new agent. Used by AgentSwitcher (all pages)
 * and AgentListTab (agents page). Accepts an onCreated callback that receives
 * the new agent data so callers can auto-select or navigate.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	TextControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Internal dependencies
 */
import { client } from '@shared/utils/api';
import { AGENTS_KEY } from '@shared/queries/agents';

/**
 * Create agent mutation hook (self-contained — no page-specific imports).
 *
 * @return {Object} TanStack Mutation result.
 */
const useCreateAgentMutation = () => {
	const queryClient = useQueryClient();

	return useMutation( {
		mutationFn: ( data ) => client.post( '/agents', data ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: AGENTS_KEY } );
		},
	} );
};

/**
 * CreateAgentModal
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onClose   Called when modal is dismissed.
 * @param {Function} props.onCreated Called after successful creation with the new agent data.
 * @return {React.ReactElement} Modal element.
 */
const CreateAgentModal = ( { onClose, onCreated } ) => {
	const [ slug, setSlug ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const createMutation = useCreateAgentMutation();

	const handleCreate = async () => {
		setError( '' );

		if ( ! slug.trim() ) {
			setError( __( 'Agent slug is required.', 'data-machine' ) );
			return;
		}

		const result = await createMutation.mutateAsync( {
			agent_slug: slug.trim(),
			agent_name: name.trim() || undefined,
		} );

		if ( result.success ) {
			onCreated?.( result.data );
			onClose();
		} else {
			setError(
				result.message ||
					__( 'Failed to create agent.', 'data-machine' )
			);
		}
	};

	return (
		<Modal
			title={ __( 'Create Agent', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-create-agent-modal"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<TextControl
				label={ __( 'Slug', 'data-machine' ) }
				help={ __(
					'Unique identifier (lowercase, hyphens). Cannot be changed later.',
					'data-machine'
				) }
				value={ slug }
				onChange={ setSlug }
				placeholder="my-agent"
			/>

			<TextControl
				label={ __( 'Display Name', 'data-machine' ) }
				help={ __(
					'Optional. Defaults to slug if empty.',
					'data-machine'
				) }
				value={ name }
				onChange={ setName }
				placeholder="My Agent"
			/>

			<div
				style={ {
					display: 'flex',
					justifyContent: 'flex-end',
					gap: '8px',
					marginTop: '16px',
				} }
			>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'data-machine' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleCreate }
					isBusy={ createMutation.isPending }
					disabled={ createMutation.isPending }
				>
					{ __( 'Create Agent', 'data-machine' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default CreateAgentModal;
