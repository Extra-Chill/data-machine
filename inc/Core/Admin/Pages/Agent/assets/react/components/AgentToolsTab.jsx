/**
 * AgentToolsTab Component
 *
 * Dedicated tab for global tool configuration and enable/disable controls.
 */

import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
import ToolConfigModal from '@shared/components/ToolConfigModal';
import { useFormState } from '@shared/hooks/useFormState';
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';

const DEFAULTS = {
	disabled_tools: {},
};

const AgentToolsTab = () => {
	const { data, isLoading, error } = useSettings();
	const updateMutation = useUpdateSettings();
	const [ openToolId, setOpenToolId ] = useState( null );

	const form = useFormState( {
		initialData: DEFAULTS,
		onSubmit: ( formData ) => updateMutation.mutateAsync( formData ),
	} );

	const save = useSaveStatus( {
		onSave: () => form.submit(),
	} );

	useEffect( () => {
		if ( data?.settings ) {
			form.reset( {
				disabled_tools: data.settings.disabled_tools || {},
			} );
			save.setHasChanges( false );
		}
	}, [ data ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleToolToggle = ( toolName, enabled ) => {
		const newTools = { ...form.data.disabled_tools };
		if ( enabled ) {
			delete newTools[ toolName ];
		} else {
			newTools[ toolName ] = true;
		}
		form.updateField( 'disabled_tools', newTools );
		save.markChanged();
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-settings-loading">
				<span className="spinner is-active"></span>
				<span>Loading tools...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading tools: { error.message }</p>
			</div>
		);
	}

	const globalTools = data?.global_tools || {};

	return (
		<div className="datamachine-agent-settings">
			<h2 className="datamachine-agent-settings-title">Tools</h2>

			{ openToolId && (
				<ToolConfigModal
					toolId={ openToolId }
					isOpen={ Boolean( openToolId ) }
					onRequestClose={ () => setOpenToolId( null ) }
				/>
			) }

			<table className="form-table">
				<tbody>
					<tr>
						<th scope="row">Tool Configuration</th>
						<td>
							{ Object.keys( globalTools ).length > 0 ? (
								<div className="datamachine-tool-config-grid">
									{ Object.entries( globalTools ).map(
										( [ toolName, toolConfig ] ) => {
											const isConfigured =
												toolConfig.is_configured;
											const isEnabled =
												! ( form.data.disabled_tools?.[
													toolName
												] ?? false );
											const toolLabel =
												toolConfig.label ||
												toolName.replace( /_/g, ' ' );

											return (
												<div
													key={ toolName }
													className="datamachine-tool-config-item"
												>
													<h4>{ toolLabel }</h4>
													{ toolConfig.description && (
														<p className="description">
															{ toolConfig.description }
														</p>
													) }
													<div className="datamachine-tool-controls">
														<span
															className={ `datamachine-config-status ${
																isConfigured
																	? 'configured'
																	: 'not-configured'
															}` }
														>
															{ isConfigured
																? 'Configured'
																: 'Not Configured' }
														</span>

														{ toolConfig.requires_configuration && (
															<Button
																variant="secondary"
																onClick={ () =>
																	setOpenToolId( toolName )
																}
															>
																Configure
															</Button>
														) }

														{ isConfigured ? (
															<label className="datamachine-tool-enabled-toggle">
																<input
																	type="checkbox"
																	checked={ isEnabled }
																	onChange={ ( e ) =>
																		handleToolToggle(
																			toolName,
																			e.target.checked
																		)
																	}
																/>
																Enable for agents
															</label>
														) : (
															<label className="datamachine-tool-enabled-toggle datamachine-tool-disabled">
																<input type="checkbox" disabled />
																<span className="description">
																	Configure to enable
																</span>
															</label>
														) }
													</div>
												</div>
											);
										}
									) }
								</div>
							) : (
								<p>No global tools are currently available.</p>
							) }
						</td>
					</tr>
				</tbody>
			</table>

			<SettingsSaveBar
				hasChanges={ save.hasChanges }
				saveStatus={ save.saveStatus }
				onSave={ save.handleSave }
			/>
		</div>
	);
};

export default AgentToolsTab;
