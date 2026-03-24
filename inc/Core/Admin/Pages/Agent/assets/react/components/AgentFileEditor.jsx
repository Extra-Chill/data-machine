/**
 * AgentFileEditor Component
 *
 * Markdown editor panel for agent memory files with save bar.
 * Handles both core files (SOUL.md, MEMORY.md) and daily memory files.
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
import {
	useAgentFile,
	useSaveAgentFile,
	useDailyFile,
	useSaveDailyFile,
	useContextFile,
	useSaveContextFile,
} from '../queries/agentFiles';

/**
 * Core file editor — uses existing agent file API.
 * Renders read-only for non-editable files (e.g. SITE.md, NETWORK.md).
 */
const CoreFileEditor = ( { filename, editable = true } ) => {
	const { data: file, isLoading, error } = useAgentFile( filename );
	const saveMutation = useSaveAgentFile();
	const [ content, setContent ] = useState( '' );

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
				{ ! editable && (
					<span className="datamachine-agent-editor-readonly-badge">
						Read-only
					</span>
				) }
			</div>
			{ ! editable && (
				<div className="datamachine-agent-editor-readonly-notice">
					This file is auto-generated and cannot be edited here.
					Use PHP filters to extend its content.
				</div>
			) }
			<textarea
				className="datamachine-agent-editor-textarea code"
				value={ content }
				onChange={ handleContentChange }
				spellCheck={ false }
				readOnly={ ! editable }
			/>
			{ editable && (
				<SettingsSaveBar
					hasChanges={ hasChanges }
					saveStatus={ saveStatus }
					onSave={ handleSave }
				/>
			) }
		</div>
	);
};

/**
 * Daily file editor — uses daily memory API routes.
 */
const DailyFileEditor = ( { year, month, day } ) => {
	const { data: file, isLoading, error } = useDailyFile( year, month, day );
	const saveMutation = useSaveDailyFile();
	const [ content, setContent ] = useState( '' );
	const dateLabel = `${ year }-${ month }-${ day }`;

	useEffect( () => {
		if ( file?.content !== undefined ) {
			setContent( file.content );
		}
	}, [ file ] );

	const { saveStatus, hasChanges, markChanged, handleSave, setHasChanges } =
		useSaveStatus( {
			onSave: async () => {
				await saveMutation.mutateAsync( {
					year,
					month,
					day,
					content,
				} );
			},
		} );

	const handleContentChange = useCallback(
		( e ) => {
			setContent( e.target.value );
			markChanged();
		},
		[ markChanged ]
	);

	useEffect( () => {
		setHasChanges( false );
	}, [ year, month, day, setHasChanges ] );

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-loading">
					<Spinner />
					<span>Loading daily memory...</span>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-error">
					Failed to load daily memory:{' '}
					{ error.message || 'Unknown error' }
				</div>
			</div>
		);
	}

	return (
		<div className="datamachine-agent-editor">
			<div className="datamachine-agent-editor-header">
				<h3>
					<span className="datamachine-agent-daily-badge">
						&#x1f4c5;
					</span>
					{ dateLabel }
				</h3>
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

/**
 * Context file editor — uses context memory API routes.
 */
const ContextFileEditor = ( { slug } ) => {
	const { data: file, isLoading, error } = useContextFile( slug );
	const saveMutation = useSaveContextFile();
	const [ content, setContent ] = useState( '' );

	useEffect( () => {
		if ( file?.content !== undefined ) {
			setContent( file.content );
		}
	}, [ file ] );

	const { saveStatus, hasChanges, markChanged, handleSave, setHasChanges } =
		useSaveStatus( {
			onSave: async () => {
				await saveMutation.mutateAsync( { slug, content } );
			},
		} );

	const handleContentChange = useCallback(
		( e ) => {
			setContent( e.target.value );
			markChanged();
		},
		[ markChanged ]
	);

	useEffect( () => {
		setHasChanges( false );
	}, [ slug, setHasChanges ] );

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-loading">
					<Spinner />
					<span>Loading context file...</span>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="datamachine-agent-editor">
				<div className="datamachine-agent-editor-error">
					Failed to load context file:{' '}
					{ error.message || 'Unknown error' }
				</div>
			</div>
		);
	}

	return (
		<div className="datamachine-agent-editor">
			<div className="datamachine-agent-editor-header">
				<h3>
					<span className="datamachine-agent-context-badge">
						ctx
					</span>
					{ slug }.md
				</h3>
			</div>
			<div className="datamachine-agent-editor-context-hint">
				This file is injected when the execution context is &ldquo;
				{ slug }&rdquo;.
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

/**
 * Router component — dispatches to core, daily, or context editor.
 */
const AgentFileEditor = ( { selectedFile } ) => {
	if ( ! selectedFile ) {
		return null;
	}

	if ( selectedFile.type === 'daily' ) {
		return (
			<DailyFileEditor
				year={ selectedFile.year }
				month={ selectedFile.month }
				day={ selectedFile.day }
			/>
		);
	}

	if ( selectedFile.type === 'context' ) {
		return <ContextFileEditor slug={ selectedFile.contextSlug } />;
	}

	return (
		<CoreFileEditor
			filename={ selectedFile.filename }
			editable={ selectedFile.editable !== false }
		/>
	);
};

export default AgentFileEditor;
