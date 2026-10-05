/**
 * StepTypeAccordion Component
 *
 * Collapsible section for a step type containing its handlers.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
/**
 * Internal dependencies
 */
import HandlerDefaultsForm from './HandlerDefaultsForm';

const StepTypeAccordion = ( {
	stepTypeData,
	expandedHandler,
	setExpandedHandler,
	onSave,
	savingHandler,
} ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const { label, uses_handler: usesHandler, handlers } = stepTypeData;
	const handlerCount = Object.keys( handlers || {} ).length;

	const toggleExpanded = () => {
		setIsExpanded( ! isExpanded );
	};

	const handleHandlerClick = ( handlerSlug ) => {
		setExpandedHandler(
			expandedHandler === handlerSlug ? null : handlerSlug
		);
	};

	return (
		<div className="datamachine-step-type-accordion">
			<button
				type="button"
				className={ `datamachine-step-type-header ${
					isExpanded ? 'is-expanded' : ''
				}` }
				onClick={ toggleExpanded }
				aria-expanded={ isExpanded }
			>
				<span className="datamachine-step-type-arrow">
					{ isExpanded ? '▼' : '▶' }
				</span>
				<span className="datamachine-step-type-label">
					{ label } Handlers
				</span>
				<span className="datamachine-step-type-count">
					{ usesHandler ? `(${ handlerCount })` : '' }
				</span>
			</button>

			{ isExpanded && (
				<div className="datamachine-step-type-content">
					{ ! usesHandler && (
						<p className="datamachine-step-type-note">
							This step type does not use handlers.
						</p>
					) }
					{ usesHandler && handlerCount === 0 && (
						<p className="datamachine-step-type-note">
							No handlers registered for this step type.
						</p>
					) }
					{ usesHandler && handlerCount > 0 && (
						<div className="datamachine-handlers-list">
							{ Object.entries( handlers || {} ).map(
								( [ handlerSlug, handlerData ] ) => (
									<div
										key={ handlerSlug }
										className="datamachine-handler-item"
									>
										<button
											type="button"
											className={ `datamachine-handler-header ${
												expandedHandler === handlerSlug
													? 'is-expanded'
													: ''
											}` }
											onClick={ () =>
												handleHandlerClick(
													handlerSlug
												)
											}
											aria-expanded={
												expandedHandler === handlerSlug
											}
										>
											<span className="datamachine-handler-arrow">
												{ expandedHandler ===
												handlerSlug
													? '▼'
													: '▶' }
											</span>
											<span className="datamachine-handler-slug">
												{ handlerSlug }
											</span>
											<span className="datamachine-handler-label">
												{ handlerData.label }
											</span>
										</button>

										{ expandedHandler === handlerSlug && (
											<HandlerDefaultsForm
												handlerSlug={ handlerSlug }
												handlerData={ handlerData }
												onSave={ onSave }
												isSaving={
													savingHandler ===
													handlerSlug
												}
											/>
										) }
									</div>
								)
							) }
						</div>
					) }
				</div>
			) }
		</div>
	);
};

export default StepTypeAccordion;
