/**
 * Memory Files Selector Component (Shared)
 *
 * Reusable component for selecting agent memory files.
 * Used by both pipeline-scoped and flow-scoped memory file UIs.
 *
 * @since 0.40.0 Added daily memory selector support.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { CheckboxControl, Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useAgentFiles } from '../../queries/pipelines';
import DailyMemorySelector from './DailyMemorySelector';

/**
 * Core registered files are always injected at Priority 20 by
 * CoreMemoryFilesDirective. The picker only shows additional files
 * that pipelines/flows can opt into. We filter using the `registered`
 * flag from the API response — registered files with `protected: true`
 * are always-injected core files and should be excluded from the picker.
 */

/**
 * Default daily memory config.
 */
const DEFAULT_DAILY_MEMORY = { mode: 'none' };

/**
 * Memory Files Selector Component
 *
 * @param {Object}   props                  - Component props
 * @param {string}   props.scopeLabel       - Label for the scope (e.g. 'pipeline', 'flow')
 * @param {Array}    props.selectedFiles    - Currently selected filenames
 * @param {Object}   props.dailyMemory      - Current daily memory config
 * @param {boolean}  props.isLoading        - Whether selected files are loading
 * @param {Object}   props.updateMutation   - TanStack mutation for saving
 * @param {boolean}  props.showDailyMemory  - Whether to show daily memory selector (default: true)
 * @return {React.ReactElement} Memory files selector
 */
export default function MemoryFilesSelector( {
	scopeLabel,
	selectedFiles = [],
	dailyMemory = DEFAULT_DAILY_MEMORY,
	isLoading: loadingSelected = false,
	updateMutation,
	showDailyMemory = true,
} ) {
	const { data: agentFiles = [], isLoading: loadingAgent } =
		useAgentFiles();

	const [ localSelected, setLocalSelected ] = useState( [] );
	const [ localDailyMemory, setLocalDailyMemory ] = useState( DEFAULT_DAILY_MEMORY );
	const [ success, setSuccess ] = useState( null );

	// Sync local state when server data loads.
	useEffect( () => {
		setLocalSelected( selectedFiles );
	}, [ selectedFiles ] );

	useEffect( () => {
		setLocalDailyMemory( dailyMemory || DEFAULT_DAILY_MEMORY );
	}, [ dailyMemory ] );

	const loading = loadingAgent || loadingSelected;

	// Filter out always-injected core files, non-text files, and daily_summary type.
	// Core files (registered + protected) are always injected at Priority 20.
	const availableFiles = agentFiles
		.filter( ( f ) => f.type !== 'daily_summary' )
		.filter( ( f ) => ! ( f.registered && f.protected ) )
		.map( ( f ) => ( typeof f === 'string' ? f : f.name || f.filename ) )
		.filter( Boolean );

	const handleToggle = ( filename, checked ) => {
		setLocalSelected( ( prev ) =>
			checked
				? [ ...prev, filename ]
				: prev.filter( ( f ) => f !== filename )
		);
	};

	const handleDailyMemoryChange = useCallback( ( newConfig ) => {
		setLocalDailyMemory( newConfig );
	}, [] );

	const handleSave = async () => {
		setSuccess( null );
		try {
			// Check if the mutation expects the new format.
			// The mutation function receives { memoryFiles, dailyMemory }.
			await updateMutation.mutateAsync( {
				memoryFiles: localSelected,
				dailyMemory: localDailyMemory,
			} );
			setSuccess(
				__( 'Memory files updated successfully!', 'data-machine' )
			);
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( 'Memory files save error:', err );
		}
	};

	// Check if either memory files or daily memory have changed.
	const isDirty =
		JSON.stringify( [ ...localSelected ].sort() ) !==
			JSON.stringify( [ ...selectedFiles ].sort() ) ||
		JSON.stringify( localDailyMemory ) !==
			JSON.stringify( dailyMemory || DEFAULT_DAILY_MEMORY );

	return (
		<div className="datamachine-memory-files-selector">
			<h3
				style={ {
					margin: '0 0 8px 0',
					fontSize: '16px',
					fontWeight: '600',
				} }
			>
				{ __( 'Agent Memory Files', 'data-machine' ) }
			</h3>
			<p
				style={ {
					margin: '0 0 16px 0',
					color: '#757575',
					fontSize: '13px',
				} }
			>
				{ __(
					`Select agent memory files to include as AI context for this ${ scopeLabel }.`,
					'data-machine'
				) }
			</p>

			{ success && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSuccess( null ) }
				>
					<p>{ success }</p>
				</Notice>
			) }

			{ updateMutation.isError && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ __(
							'Failed to save memory files.',
							'data-machine'
						) }
					</p>
				</Notice>
			) }

			{ loading ? (
				<div
					style={ {
						textAlign: 'center',
						padding: '20px',
						color: '#757575',
					} }
				>
					<Spinner />
				</div>
			) : (
				<>
					{/* Custom Memory Files Section */}
					{ availableFiles.length > 0 && (
						<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								gap: '8px',
								marginBottom: '16px',
							} }
						>
							{ availableFiles.map( ( filename ) => (
								<CheckboxControl
									key={ filename }
									label={ filename }
									checked={ localSelected.includes( filename ) }
									onChange={ ( checked ) =>
										handleToggle( filename, checked )
									}
								/>
							) ) }
						</div>
					) }

					{ availableFiles.length === 0 && (
						<p
							style={ {
								color: '#757575',
								fontStyle: 'italic',
								padding: '12px 0',
							} }
						>
							{ __(
								'No custom memory files available. Add .md files to the agent directory.',
								'data-machine'
							) }
						</p>
					) }

					{/* Daily Memory Selector Section */}
					{ showDailyMemory && (
						<div
							style={ {
								marginTop: '20px',
								paddingTop: '20px',
								borderTop: '1px solid #ddd',
							} }
						>
							<DailyMemorySelector
								config={ localDailyMemory }
								onChange={ handleDailyMemoryChange }
								disabled={ updateMutation.isPending }
							/>
						</div>
					) }

					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={ ! isDirty || updateMutation.isPending }
						isBusy={ updateMutation.isPending }
						style={ { marginTop: '16px' } }
					>
						{ updateMutation.isPending
							? __( 'Saving…', 'data-machine' )
							: __( 'Save Memory Files', 'data-machine' ) }
					</Button>
				</>
			) }
		</div>
	);
}
