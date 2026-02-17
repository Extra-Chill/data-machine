/**
 * Handler Selection Modal Component
 *
 * Modal for selecting handler type before configuring settings.
 * @pattern Presentational - Receives handlers data as props
 */

/**
 * WordPress dependencies
 */
import { Modal, Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useHandlerContext } from '../../context/HandlerProvider';

/**
 * Handler Selection Modal Component
 *
 * @param {Object}   props                 - Component props
 * @param {Function} props.onClose         - Close handler
 * @param {string}   props.stepType        - Step type (fetch, publish, update)
 * @param {Function} props.onSelectHandler - Handler selection callback
 * @param {Object}   props.handlers        - All available handlers
 * @return {React.ReactElement|null} Handler selection modal
 */
export default function HandlerSelectionModal( {
	onClose,
	stepType,
	onSelectHandler,
	handlers,
	existingHandlerSlugs = [],
} ) {
	const [ error, setError ] = useState( null );
	// Presentational: Receive handlers data as props

	/**
	 * Filter handlers by step type, excluding already-configured handlers.
	 */
	const { handlers: rawHandlers, getModel } = useHandlerContext() || {};

	const filteredHandlers = Object.entries( rawHandlers || handlers ).filter(
		( [ slug, handler ] ) =>
			handler.type === stepType &&
			! existingHandlerSlugs.includes( slug )
	);

	/**
	 * Handle handler selection
	 * @param handlerSlug
	 */
	const handleSelect = async ( handlerSlug ) => {
		if ( onSelectHandler ) {
			setError( null );
			try {
				await onSelectHandler( handlerSlug );
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.error( 'Handler selection error:', err );
				setError(
					err?.message ||
						'An error occurred while assigning the handler.'
				);
			}
		}
	};

	return (
		<Modal
			title={ __( 'Select Handler', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-handler-selection-modal"
		>
			<div className="datamachine-modal-content">
				<p className="datamachine-modal-header-text">
					{ __(
						'Choose the handler for this step:',
						'data-machine'
					) }
				</p>

				{ filteredHandlers.length === 0 && (
					<div className="datamachine-modal-empty-state datamachine-modal-empty-state--bordered">
						<p className="datamachine-text--margin-reset">
							{ __(
								'No handlers available for this step type.',
								'data-machine'
							) }
						</p>
					</div>
				) }

				{ filteredHandlers.length > 0 && (
					<div className="datamachine-modal-grid-2col">
						{ filteredHandlers.map( ( [ slug, handler ] ) => {
							const model = getModel
								? getModel( slug, null )
								: null;
							const label = model
								? model.getLabel()
								: handler.label || slug;
							const desc = model
								? model.getDescription()
								: handler.description || '';
							const auth = model
								? model.requiresAuth()
								: handler.requires_auth || handler.requiresAuth;

							return (
								<button
									key={ slug }
									type="button"
									className="datamachine-modal-card"
									onClick={ () => handleSelect( slug ) }
								>
									<strong>{ label }</strong>

									<p>{ desc }</p>

									{ auth && (
										<span className="datamachine-modal-badge">
											{ __(
												'Requires Auth',
												'data-machine'
											) }
										</span>
									) }
								</button>
							);
						} ) }
					</div>
				) }

				{ error && (
					<div className="datamachine-modal-error">
						<Notice status="error" isDismissible={ false }>
							<p>{ error }</p>
						</Notice>
					</div>
				) }

				<div className="datamachine-modal-actions">
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Cancel', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
