/**
 * AgentFileList Component
 *
 * Sidebar listing agent memory files in two sections:
 * - Core files (SOUL.md, MEMORY.md, etc.) with add/delete controls
 * - Daily memory files grouped by month, collapsible
 *
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
import {
	useAgentFiles,
	useSaveAgentFile,
	useDeleteAgentFile,
} from '../queries/agentFiles';

const SOUL_FILE = 'SOUL.md';
const PROTECTED_FILES = [ 'SOUL.md', 'MEMORY.md' ];

/**
 * Format bytes to human-readable size.
 *
 * @param {number} bytes File size in bytes.
 * @return {string} Formatted size string.
 */
const formatSize = ( bytes ) => {
	if ( ! bytes && bytes !== 0 ) {
		return '';
	}
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
 * Format month key (YYYY/MM) to a readable label.
 *
 * @param {string} monthKey e.g. '2026/02'
 * @return {string} e.g. 'February 2026'
 */
const formatMonthLabel = ( monthKey ) => {
	const [ year, month ] = monthKey.split( '/' );
	const date = new Date( parseInt( year, 10 ), parseInt( month, 10 ) - 1 );
	return date.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'long',
	} );
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

/**
 * Check if a selection matches a given file.
 *
 * @param {Object|null} selected Current selection.
 * @param {string}      type     'core' or 'daily'.
 * @param {Object}      compare  Values to compare.
 * @return {boolean} True if selected.
 */
const isSelected = ( selected, type, compare ) => {
	if ( ! selected || selected.type !== type ) {
		return false;
	}
	if ( type === 'core' ) {
		return selected.filename === compare.filename;
	}
	if ( type === 'daily' ) {
		return (
			selected.year === compare.year &&
			selected.month === compare.month &&
			selected.day === compare.day
		);
	}
	return false;
};

const AgentFileList = ( { selectedFile, onSelectFile } ) => {
	const { data: files, isLoading, error } = useAgentFiles();
	const saveMutation = useSaveAgentFile();
	const deleteMutation = useDeleteAgentFile();

	const [ isAdding, setIsAdding ] = useState( false );
	const [ newFilename, setNewFilename ] = useState( '' );
	const [ addError, setAddError ] = useState( null );
	const [ deleteConfirm, setDeleteConfirm ] = useState( null );
	const [ expandedMonths, setExpandedMonths ] = useState( {} );

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

		// Check for duplicates among core files.
		const coreFiles = files?.filter( ( f ) => f.type !== 'daily_summary' ) ?? [];
		if ( coreFiles.some( ( f ) => f.filename === name ) ) {
			setAddError( 'A file with this name already exists.' );
			return;
		}

		try {
			await saveMutation.mutateAsync( { filename: name, content: '' } );
			setIsAdding( false );
			setNewFilename( '' );
			setAddError( null );
			onSelectFile( { type: 'core', filename: name } );
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
				if (
					selectedFile?.type === 'core' &&
					selectedFile?.filename === filename
				) {
					onSelectFile( null );
				}
			} catch {
				// Mutation error handled by TanStack Query.
			}
		},
		[ deleteMutation, selectedFile, onSelectFile ]
	);

	const toggleMonth = useCallback( ( monthKey ) => {
		setExpandedMonths( ( prev ) => ( {
			...prev,
			[ monthKey ]: ! prev[ monthKey ],
		} ) );
	}, [] );

	// Separate core files from daily summary.
	const coreFiles = files
		? files.filter( ( f ) => f.type !== 'daily_summary' )
		: [];
	const dailySummary = files?.find( ( f ) => f.type === 'daily_summary' );

	// Sort core files: SOUL.md first, then alphabetical.
	const sortedCoreFiles = [ ...coreFiles ].sort( ( a, b ) => {
		if ( a.filename === SOUL_FILE ) {
			return -1;
		}
		if ( b.filename === SOUL_FILE ) {
			return 1;
		}
		return a.filename.localeCompare( b.filename );
	} );

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
				{ /* Core files section */ }
				{ sortedCoreFiles.map( ( file ) => {
					const isSoul = file.filename === SOUL_FILE;
					const selected = isSelected( selectedFile, 'core', {
						filename: file.filename,
					} );

					return (
						<div
							key={ file.filename }
							className={ `datamachine-agent-file-item${
								selected ? ' is-selected' : ''
							}${ isSoul ? ' is-soul' : '' }` }
							onClick={ () =>
								onSelectFile( {
									type: 'core',
									filename: file.filename,
								} )
							}
							role="button"
							tabIndex={ 0 }
							onKeyDown={ ( e ) => {
								if (
									e.key === 'Enter' ||
									e.key === ' '
								) {
									onSelectFile( {
										type: 'core',
										filename: file.filename,
									} );
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
											&#x1f9e0;
										</span>
									) }
									{ file.filename }
								</span>
								<span className="datamachine-agent-file-item-meta">
									{ formatSize( file.size ) }
									{ file.modified &&
										` \u00b7 ${ formatDate(
											file.modified
										) }` }
								</span>
							</div>
							{ ! PROTECTED_FILES.includes( file.filename ) && (
								<button
									className="datamachine-agent-file-item-delete"
									title={ `Delete ${ file.filename }` }
									onClick={ ( e ) => {
										e.stopPropagation();
										setDeleteConfirm( file.filename );
									} }
								>
									&#x1f5d1;
								</button>
							) }
						</div>
					);
				} ) }

				{ sortedCoreFiles.length === 0 && ! isAdding && (
					<div className="datamachine-agent-file-list-empty">
						No files yet.
					</div>
				) }

				{ /* Daily memory section */ }
				{ dailySummary &&
					dailySummary.months &&
					Object.keys( dailySummary.months ).length > 0 && (
						<div className="datamachine-agent-daily-section">
							<div className="datamachine-agent-daily-header">
								<span className="datamachine-agent-daily-title">
									&#x1f4c5; Daily Memory
								</span>
								<span className="datamachine-agent-daily-count">
									{ dailySummary.day_count } day
									{ dailySummary.day_count !== 1
										? 's'
										: '' }
								</span>
							</div>
							{ Object.entries( dailySummary.months ).map(
								( [ monthKey, days ] ) => {
									const isExpanded =
										expandedMonths[ monthKey ] ?? false;
									return (
										<div
											key={ monthKey }
											className="datamachine-agent-daily-month"
										>
											<button
												className="datamachine-agent-daily-month-toggle"
												onClick={ () =>
													toggleMonth( monthKey )
												}
											>
												<span
													className={ `datamachine-agent-daily-chevron${
														isExpanded
															? ' is-expanded'
															: ''
													}` }
												>
													&#x25b6;
												</span>
												<span>
													{ formatMonthLabel(
														monthKey
													) }
												</span>
												<span className="datamachine-agent-daily-month-count">
													{ days.length }
												</span>
											</button>
											{ isExpanded && (
												<div className="datamachine-agent-daily-days">
													{ [ ...days ]
														.reverse()
														.map( ( day ) => {
															const [
																year,
																month,
															] =
																monthKey.split(
																	'/'
																);
															const sel =
																isSelected(
																	selectedFile,
																	'daily',
																	{
																		year,
																		month,
																		day,
																	}
																);
															return (
																<div
																	key={ day }
																	className={ `datamachine-agent-file-item datamachine-agent-daily-item${
																		sel
																			? ' is-selected'
																			: ''
																	}` }
																	onClick={ () =>
																		onSelectFile(
																			{
																				type: 'daily',
																				year,
																				month,
																				day,
																			}
																		)
																	}
																	role="button"
																	tabIndex={
																		0
																	}
																	onKeyDown={ (
																		e
																	) => {
																		if (
																			e.key ===
																				'Enter' ||
																			e.key ===
																				' '
																		) {
																			onSelectFile(
																				{
																					type: 'daily',
																					year,
																					month,
																					day,
																				}
																			);
																		}
																	} }
																>
																	<span className="datamachine-agent-file-item-name">
																		{ year }-{ month }-{ day }
																	</span>
																</div>
															);
														} ) }
												</div>
											) }
										</div>
									);
								}
							) }
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
