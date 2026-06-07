/**
 * Flow Header Component
 *
 * Flow title with auto-save and action buttons.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useUpdateFlowTitle } from '../../queries/flows';
import { AUTO_SAVE_DELAY } from '../../utils/constants';

/**
 * Flow Header Component.
 *
 * @param {Object}   props              - Component props.
 * @param {number}   props.flowId       - Flow ID.
 * @param {string}   props.flowName     - Flow name.
 * @param {Function} props.onNameChange - Name change handler.
 * @param {Function} props.onDelete     - Delete handler.
 * @param {Function} props.onDuplicate  - Duplicate handler.
 * @param {Function} props.onRun        - Run handler.
 * @param {Function} props.onSchedule    - Schedule handler.
 * @param {Function} props.onMemoryFiles - Memory files handler.
 * @param {boolean}  props.runSuccess    - Whether run was just successful.
 * @return {JSX.Element} Flow header.
 */
export default function FlowHeader( {
	flowId,
	flowName,
	onNameChange,
	onDelete,
	onDuplicate,
	onRun,
	onSchedule,
	onMemoryFiles,
	runSuccess = false,
} ) {
	const [ localName, setLocalName ] = useState( flowName );
	const saveTimeout = useRef( null );
	const updateFlowTitleMutation = useUpdateFlowTitle();

	/**
	 * Sync local name with prop changes
	 */
	useEffect( () => {
		setLocalName( flowName );
	}, [ flowName ] );

	/**
	 * Save flow name to API (silent auto-save)
	 */
	const saveName = useCallback(
		async ( name ) => {
			if ( ! name || name === flowName ) {
				return;
			}

			try {
				const response = await updateFlowTitleMutation.mutateAsync( {
					flowId,
					name,
				} );

				if ( response?.success && onNameChange ) {
					onNameChange( flowId, name );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Flow title save failed:', err );
			}
		},
		[ flowId, flowName, onNameChange, updateFlowTitleMutation ]
	);

	/**
	 * Handle name change with debouncing
	 */
	const handleNameChange = useCallback(
		( value ) => {
			setLocalName( value );

			// Clear existing timeout
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			// Set new timeout for debounced save
			saveTimeout.current = setTimeout( () => {
				saveName( value );
			}, AUTO_SAVE_DELAY );
		},
		[ saveName ]
	);

	/**
	 * Handle delete with confirmation
	 */
	const handleDelete = useCallback( () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__( 'Are you sure you want to delete this flow?', 'data-machine' )
		);

		if ( confirmed && onDelete ) {
			onDelete( flowId );
		}
	}, [ flowId, onDelete ] );

	/**
	 * Cleanup timeout on unmount
	 */
	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	return (
		<div className="datamachine-flow-header">
			<div className="datamachine-flow-header__content">
				<TextControl
					value={ localName }
					onChange={ handleNameChange }
					placeholder={ __( 'Flow name…', 'data-machine' ) }
					className="datamachine-flow-header__title-input"
				/>

				<div className="datamachine-flow-header__primary-actions">
					<Button
						variant="primary"
						onClick={ () => onRun && onRun( flowId ) }
						disabled={ runSuccess }
					>
						{ runSuccess
							? __( 'Queued', 'data-machine' )
							: __( 'Run Now', 'data-machine' ) }
					</Button>
				</div>
			</div>

			<div className="datamachine-flow-header__secondary-actions">
				<Button
					variant="secondary"
					onClick={ () => onSchedule && onSchedule( flowId ) }
				>
					{ __( 'Schedule', 'data-machine' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ () => onMemoryFiles && onMemoryFiles( flowId ) }
				>
					{ __( 'Memory', 'data-machine' ) }
				</Button>

				<Button
					variant="secondary"
					onClick={ () => onDuplicate && onDuplicate( flowId ) }
				>
					{ __( 'Duplicate', 'data-machine' ) }
				</Button>

				<Button
					variant="secondary"
					isDestructive
					onClick={ handleDelete }
					icon="trash"
				/>
			</div>
		</div>
	);
}
