/**
 * AgentFileEditor Component
 *
 * Markdown editor panel for agent memory files with save bar.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import SettingsSaveBar, {
	useSaveStatus,
} from '@shared/components/SettingsSaveBar';
import { useAgentFile, useSaveAgentFile } from '../queries/agentFiles';

const AgentFileEditor = ( { filename } ) => {
	const { data: file, isLoading, error } = useAgentFile( filename );
	const saveMutation = useSaveAgentFile();
	const [ content, setContent ] = useState( '' );

	// Sync content when file data loads or filename changes.
	useEffect( () => {
		if ( file?.content !== undefined ) {
			setContent( file.content );
		}
	}, [ file ] );

	const { saveStatus, hasChanges, markChanged, handleSave, setHasChanges } =
		useSaveStatus( {
			onSave: async () => {
				await saveMutation.mutateAsync( { filename, content } );
			},
		} );

	const handleContentChange = useCallback(
		( e ) => {
			setContent( e.target.value );
			markChanged();
		},
		[ markChanged ]
	);

	// Reset dirty state when switching files.
	useEffect( () => {
		setHasChanges( false );
	}, [ filename, setHasChanges ] );

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-loading">
					<Spinner />
					<span>Loading file...</span>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-error">
					Failed to load file: { error.message || 'Unknown error' }
				</div>
			</div>
		);
	}

	return (
		<div className="datamachine-agent-editor">
			<div className="datamachine-agent-editor-header">
				<h3>{ filename }</h3>
			</div>
			<textarea
				className="datamachine-agent-editor-textarea code"
				value={ content }
				onChange={ handleContentChange }
				spellCheck={ false }
			/>
			<SettingsSaveBar
				hasChanges={ hasChanges }
				saveStatus={ saveStatus }
				onSave={ handleSave }
			/>
		</div>
	);
};

export default AgentFileEditor;
