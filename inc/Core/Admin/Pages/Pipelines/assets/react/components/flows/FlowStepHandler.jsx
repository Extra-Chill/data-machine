/**
 * Flow step handler component.
 *
 * Supports displaying multiple handler badges when a step has more than one
 * handler assigned (multi-handler mode).
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useHandlers } from '../../queries/handlers';
import { useStepTypes } from '../../queries/config';

export default function FlowStepHandler( {
	handlerSlug,
	handlerSlugs,
	settingsDisplay,
	handlerSettingsDisplays,
	onConfigure,
	onAddHandler,
	showConfigureButton = true,
	showBadge = true,
} ) {
	const { data: handlers = {} } = useHandlers();
	const { data: stepTypes = {} } = useStepTypes();

	// Resolve to array — prefer handlerSlugs, fall back to single handlerSlug.
	const slugs = handlerSlugs || ( handlerSlug ? [ handlerSlug ] : [] );

	if ( slugs.length === 0 ) {
		return (
			<div className="datamachine-flow-step-handler datamachine-flow-step-handler--empty datamachine-handler-warning">
				<p className="datamachine-handler-warning-text">
					{ __( 'No handler configured', 'data-machine' ) }
				</p>
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onConfigure && onConfigure( null ) }
				>
					{ __( 'Configure Handler', 'data-machine' ) }
				</Button>
			</div>
		);
	}

	// Per-handler display map from API (keyed by slug, each an array of settings).
	const perHandlerDisplays = handlerSettingsDisplays || {};
	const hasPerHandler = Object.keys( perHandlerDisplays ).length > 0;

	// Legacy flat display — used when per-handler data isn't available.
	const flatDisplaySettings = ! hasPerHandler && Array.isArray( settingsDisplay )
		? settingsDisplay.reduce( ( acc, setting ) => {
				acc[ setting.key ] = {
					label: setting.label,
					value: setting.display_value ?? setting.value,
				};
				return acc;
		  }, {} )
		: {};
	const hasFlatSettings = Object.keys( flatDisplaySettings ).length > 0;

	/**
	 * Resolve a handler label from handlers registry, step types, or slug.
	 *
	 * @param {string} slug Handler slug.
	 * @return {string} Display label.
	 */
	const getLabel = ( slug ) =>
		handlers[ slug ]?.label || stepTypes[ slug ]?.label || slug;

	/**
	 * Convert a per-handler settings array into a display-ready object.
	 *
	 * @param {Array} settings Settings array from API.
	 * @return {Object} Keyed display settings.
	 */
	const toDisplayMap = ( settings ) =>
		Array.isArray( settings )
			? settings.reduce( ( acc, s ) => {
					acc[ s.key ] = {
						label: s.label,
						value: s.display_value ?? s.value,
					};
					return acc;
			  }, {} )
			: {};

	const isMultiHandler = slugs.length > 1;

	return (
		<div className="datamachine-flow-step-handler datamachine-handler-container">
			{ isMultiHandler && hasPerHandler ? (
				/* Multi-handler: each handler gets its own badge + settings row */
				<div className="datamachine-handler-stack">
					{ slugs.map( ( slug ) => {
						const display = toDisplayMap( perHandlerDisplays[ slug ] || [] );
						const hasDisplay = Object.keys( display ).length > 0;

						return (
							<div key={ slug } className="datamachine-handler-stack-row">
								<div className="datamachine-handler-badges">
									<div
										className="datamachine-handler-tag datamachine-handler-badge"
										onClick={ () => onConfigure && onConfigure( slug ) }
										role="button"
										tabIndex={ 0 }
										onKeyDown={ ( e ) => {
											if ( e.key === 'Enter' || e.key === ' ' ) {
												onConfigure && onConfigure( slug );
											}
										} }
									>
										{ getLabel( slug ) }
									</div>
								</div>
								{ hasDisplay && (
									<div className="datamachine-handler-settings-display datamachine-handler-settings-display--inline">
										{ Object.entries( display ).map(
											( [ key, setting ] ) => (
												<span
													key={ key }
													className="datamachine-handler-settings-entry datamachine-handler-settings-entry--inline"
												>
													<strong>{ setting.label }:</strong>{ ' ' }
													{ setting.value }
												</span>
											)
										) }
									</div>
								) }
							</div>
						);
					} ) }
					{ onAddHandler && (
						<button
							type="button"
							className="datamachine-handler-add-badge"
							onClick={ onAddHandler }
							title={ __( 'Add another handler', 'data-machine' ) }
						>
							+
						</button>
					) }
				</div>
			) : (
				/* Single handler (or legacy): badges row + flat settings */
				<>
					{ showBadge && (
						<div className="datamachine-handler-badges">
							{ slugs.map( ( slug ) => (
								<div
									key={ slug }
									className="datamachine-handler-tag datamachine-handler-badge"
									onClick={ () => onConfigure && onConfigure( slug ) }
									role="button"
									tabIndex={ 0 }
									onKeyDown={ ( e ) => {
										if ( e.key === 'Enter' || e.key === ' ' ) {
											onConfigure && onConfigure( slug );
										}
									} }
								>
									{ getLabel( slug ) }
								</div>
							) ) }
							{ onAddHandler && (
								<button
									type="button"
									className="datamachine-handler-add-badge"
									onClick={ onAddHandler }
									title={ __( 'Add another handler', 'data-machine' ) }
								>
									+
								</button>
							) }
						</div>
					) }

					{ hasFlatSettings && (
						<div className="datamachine-handler-settings-display">
							{ Object.entries( flatDisplaySettings ).map(
								( [ key, setting ] ) => (
									<div
										key={ key }
										className="datamachine-handler-settings-entry"
									>
										<strong>{ setting.label }:</strong>{ ' ' }
										{ setting.value }
									</div>
								)
							) }
						</div>
					) }
				</>
			) }

			{ showConfigureButton && (
				<Button
					variant="secondary"
					size="small"
					onClick={ () => onConfigure && onConfigure( slugs[ 0 ] ) }
				>
					{ __( 'Configure', 'data-machine' ) }
				</Button>
			) }
		</div>
	);
}
