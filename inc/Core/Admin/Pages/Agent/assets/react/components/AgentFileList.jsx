/**
 * AgentFileList Component
 *
 * Sidebar listing agent memory files with add/delete controls.
 * SOUL.md is pinned to top and cannot be deleted from the UI.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useAgentFiles, useSaveAgentFile, useDeleteAgentFile } from '../queries/agentFiles';

const SOUL_FILE = 'SOUL.md';

/**
 * Format bytes to human-readable size.
 *
 * @param {number} bytes File size in bytes.
 * @return {string} Formatted size string.
 */
const formatSize = ( bytes ) => {
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	const kb = bytes / 1024;
	if ( kb < 1024 ) {
		return `${ kb.toFixed( 1 ) } KB`;
	}
	return `${ ( kb / 1024 ).toFixed( 1 ) } MB`;
};

/**
 * Format ISO date to human-readable relative/short date.
 *
 * @param {string} dateStr ISO date string.
 * @return {string} Formatted date.
 */
const formatDate = ( dateStr ) => {
	if ( ! dateStr ) {
		return '';
	}
	const date = new Date( dateStr );
	const now = new Date();
	const diffMs = now - date;
	const diffMins = Math.floor( diffMs / 60000 );

	if ( diffMins < 1 ) {
		return 'just now';
	}
	if ( diffMins < 60 ) {
		return `${ diffMins }m ago`;
	}
	const diffHours = Math.floor( diffMins / 60 );
	if ( diffHours < 24 ) {
		return `${ diffHours }h ago`;
	}
	const diffDays = Math.floor( diffHours / 24 );
	if ( diffDays < 7 ) {
		return `${ diffDays }d ago`;
	}
	return date.toLocaleDateString();
};

/**
 * Validate a filename for creation.
 *
 * @param {string} name Raw filename input.
 * @return {string|null} Error message or null if valid.
 */
const validateFilename = ( name ) => {
	if ( ! name || ! name.trim() ) {
		return 'Filename is required.';
	}
	if ( /[/\\:*?"<>|]/.test( name ) ) {
		return 'Filename contains invalid characters.';
	}
	if ( name.includes( '..' ) ) {
		return 'Invalid filename.';
	}
	return null;
};

const AgentFileList = ( { selectedFile, onSelectFile } ) => {
	const { data: files, isLoading, error } = useAgentFiles();
	const saveMutation = useSaveAgentFile();
	const deleteMutation = useDeleteAgentFile();

	const [ isAdding, setIsAdding ] = useState( false );
	const [ newFilename, setNewFilename ] = useState( '' );
	const [ addError, setAddError ] = useState( null );
	const [ deleteConfirm, setDeleteConfirm ] = useState( null );

	const handleAddNew = useCallback( () => {
		setIsAdding( true );
		setNewFilename( '' );
		setAddError( null );
	}, [] );

	const handleCancelAdd = useCallback( () => {
		setIsAdding( false );
		setNewFilename( '' );
		setAddError( null );
	}, [] );

	const handleCreateFile = useCallback( async () => {
		let name = newFilename.trim();
		const validationError = validateFilename( name );
		if ( validationError ) {
			setAddError( validationError );
			return;
		}
		if ( ! name.endsWith( '.md' ) ) {
			name += '.md';
		}

		// Check for duplicates.
		if ( files?.some( ( f ) => f.filename === name ) ) {
			setAddError( 'A file with this name already exists.' );
			return;
		}

		try {
			await saveMutation.mutateAsync( { filename: name, content: '' } );
			setIsAdding( false );
			setNewFilename( '' );
			setAddError( null );
			onSelectFile( name );
		} catch {
			setAddError( 'Failed to create file.' );
		}
	}, [ newFilename, files, saveMutation, onSelectFile ] );

	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Enter' ) {
				handleCreateFile();
			} else if ( e.key === 'Escape' ) {
				handleCancelAdd();
			}
		},
		[ handleCreateFile, handleCancelAdd ]
	);

	const handleDelete = useCallback(
		async ( filename ) => {
			try {
				await deleteMutation.mutateAsync( filename );
				setDeleteConfirm( null );
				if ( selectedFile === filename ) {
					onSelectFile( null );
				}
			} catch {
				// Mutation error handled by TanStack Query.
			}
		},
		[ deleteMutation, selectedFile, onSelectFile ]
	);

	// Sort files: SOUL.md first, then alphabetical.
	const sortedFiles = files
		? [ ...files ].sort( ( a, b ) => {
				if ( a.filename === SOUL_FILE ) {
					return -1;
				}
				if ( b.filename === SOUL_FILE ) {
					return 1;
				}
				return a.filename.localeCompare( b.filename );
		  } )
		: [];

	if ( isLoading ) {
		return (
			<div className="datamachine-agent-file-list">
				<div className="datamachine-agent-file-list-header">
					<h3>Memory Files</h3>
				</div>
				<div className="datamachine-agent-file-list-loading">
					<Spinner />
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="datamachine-agent-file-list">
				<div className="datamachine-agent-file-list-header">
					<h3>Memory Files</h3>
				</div>
				<div className="datamachine-agent-file-list-error">
					Failed to load files.
				</div>
			</div>
		);
	}

	return (
		<div className="datamachine-agent-file-list">
			<div className="datamachine-agent-file-list-header">
				<h3>Memory Files</h3>
				<Button
					variant="secondary"
					size="small"
					onClick={ handleAddNew }
					disabled={ isAdding }
				>
					Add New
				</Button>
			</div>

			{ isAdding && (
				<div className="datamachine-agent-file-add">
					<input
						type="text"
						placeholder="filename.md"
						value={ newFilename }
						onChange={ ( e ) => {
							setNewFilename( e.target.value );
							setAddError( null );
						} }
						onKeyDown={ handleKeyDown }
						autoFocus
					/>
					<div className="datamachine-agent-file-add-actions">
						<Button
							variant="primary"
							size="small"
							onClick={ handleCreateFile }
							disabled={ saveMutation.isPending }
						>
							{ saveMutation.isPending
								? 'Creating...'
								: 'Create' }
						</Button>
						<Button
							variant="tertiary"
							size="small"
							onClick={ handleCancelAdd }
						>
							Cancel
						</Button>
					</div>
					{ addError && (
						<div className="datamachine-agent-file-add-error">
							{ addError }
						</div>
					) }
				</div>
			) }

			<div className="datamachine-agent-file-items">
				{ sortedFiles.map( ( file ) => {
					const isSoul = file.filename === SOUL_FILE;
					const isSelected = selectedFile === file.filename;

					return (
						<div
							key={ file.filename }
							className={ `datamachine-agent-file-item${
								isSelected ? ' is-selected' : ''
							}${ isSoul ? ' is-soul' : '' }` }
							onClick={ () => onSelectFile( file.filename ) }
							role="button"
							tabIndex={ 0 }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									onSelectFile( file.filename );
								}
							} }
						>
							<div className="datamachine-agent-file-item-info">
								<span className="datamachine-agent-file-item-name">
									{ isSoul && (
										<span
											className="datamachine-agent-file-soul-badge"
											title="Agent identity"
										>
											🧠
										</span>
									) }
									{ file.filename }
								</span>
								<span className="datamachine-agent-file-item-meta">
									{ formatSize( file.size ) }
									{ file.modified &&
										` · ${ formatDate(
											file.modified
										) }` }
								</span>
							</div>
							{ ! isSoul && (
								<button
									className="datamachine-agent-file-item-delete"
									title={ `Delete ${ file.filename }` }
									onClick={ ( e ) => {
										e.stopPropagation();
										setDeleteConfirm( file.filename );
									} }
								>
									🗑
								</button>
							) }
						</div>
					);
				} ) }

				{ sortedFiles.length === 0 && ! isAdding && (
					<div className="datamachine-agent-file-list-empty">
						No files yet.
					</div>
				) }
			</div>

			{ deleteConfirm && (
				<div className="datamachine-agent-delete-confirm">
					<div className="datamachine-agent-delete-confirm-inner">
						<p>
							Delete <strong>{ deleteConfirm }</strong>? This
							cannot be undone.
						</p>
						<div className="datamachine-agent-delete-confirm-actions">
							<Button
								variant="primary"
								isDestructive
								size="small"
								onClick={ () => handleDelete( deleteConfirm ) }
								disabled={ deleteMutation.isPending }
							>
								{ deleteMutation.isPending
									? 'Deleting...'
									: 'Delete' }
							</Button>
							<Button
								variant="tertiary"
								size="small"
								onClick={ () => setDeleteConfirm( null ) }
							>
								Cancel
							</Button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
};

export default AgentFileList;
