/**
 * ToolConfigModal
 *
 * Modal form for configuring a tool that requires configuration.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useToolConfig, useSaveToolConfig } from '@shared/queries/tools';

const ToolConfigModal = ( { toolId, isOpen, onRequestClose } ) => {
	const {
		data: toolConfig,
		isLoading,
		error,
		isFetching,
	} = useToolConfig( toolId, isOpen );

	const saveMutation = useSaveToolConfig();

	const [ formValues, setFormValues ] = useState( {} );
	const [ notice, setNotice ] = useState( null );

	const fields = useMemo( () => toolConfig?.fields || {}, [ toolConfig ] );

	useEffect( () => {
		if ( toolConfig?.config && toolId && isOpen ) {
			setFormValues( toolConfig.config );
			setNotice( null );
		}
	}, [ toolConfig, toolId, isOpen ] );

	const title = toolConfig?.label
		? `Configure ${ toolConfig.label }`
		: 'Configure Tool';

	const handleChange = ( fieldKey, value ) => {
		setFormValues( ( prev ) => ( { ...prev, [ fieldKey ]: value } ) );
	};

	const handleSave = async () => {
		setNotice( null );

		try {
			const result = await saveMutation.mutateAsync( {
				toolId,
				configData: formValues,
			} );

			setNotice( {
				status: 'success',
				message: result?.message || 'Configuration saved.',
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || 'Failed to save configuration.',
			} );
		}
	};

	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal title={ title } onRequestClose={ onRequestClose }>
			{ notice && (
				<Notice status={ notice.status } isDismissible={ true }>
					<p>{ notice.message }</p>
				</Notice>
			) }

			{ ( isLoading || isFetching ) && (
				<div
					style={ {
						display: 'flex',
						gap: '8px',
						alignItems: 'center',
					} }
				>
					<Spinner />
					<span>Loading tool configuration…</span>
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error.message }</p>
				</Notice>
			) }

			{ ! isLoading && ! error && (
				<div className="datamachine-tool-config-modal">
					{ Object.keys( fields ).length === 0 ? (
						<p>This tool has no configurable fields.</p>
					) : (
						Object.entries( fields ).map(
							( [ fieldKey, field ] ) => {
								const isSecret =
									field?.type === 'password' ||
									field?.type === 'secret';

								return (
									<div
										key={ fieldKey }
										style={ { marginBottom: '16px' } }
									>
										<TextControl
											label={ field?.label || fieldKey }
											help={ field?.description || '' }
											placeholder={
												field?.placeholder || ''
											}
											type={
												isSecret ? 'password' : 'text'
											}
											required={ Boolean(
												field?.required
											) }
											value={
												formValues?.[ fieldKey ] || ''
											}
											onChange={ ( value ) =>
												handleChange( fieldKey, value )
											}
										/>
									</div>
								);
							}
						)
					) }

					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							gap: '8px',
						} }
					>
						<Button variant="secondary" onClick={ onRequestClose }>
							Close
						</Button>
						<Button
							variant="primary"
							onClick={ handleSave }
							disabled={ saveMutation.isPending || ! toolId }
						>
							{ saveMutation.isPending
								? 'Saving…'
								: 'Save Configuration' }
						</Button>
					</div>
				</div>
			) }
		</Modal>
	);
};

export default ToolConfigModal;
