/**
 * Flow step handler component.
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
	settingsDisplay,
	onConfigure,
	showConfigureButton = true,
	showBadge = true,
} ) {
	const { data: handlers = {} } = useHandlers();
	const { data: stepTypes = {} } = useStepTypes();

	if ( ! handlerSlug ) {
		return (
			<div className="datamachine-flow-step-handler datamachine-flow-step-handler--empty datamachine-handler-warning">
				<p className="datamachine-handler-warning-text">
					{ __( 'No handler configured', 'data-machine' ) }
				</p>
				<Button
					variant="secondary"
					size="small"
					onClick={ onConfigure }
				>
					{ __( 'Configure Handler', 'data-machine' ) }
				</Button>
			</div>
		);
	}

	const displaySettings = Array.isArray( settingsDisplay )
		? settingsDisplay.reduce( ( acc, setting ) => {
				acc[ setting.key ] = {
					label: setting.label,
					value: setting.display_value ?? setting.value,
				};
				return acc;
		  }, {} )
		: {};

	const hasSettings = Object.keys( displaySettings ).length > 0;

	// Look up label from handlers first, then step types, then fall back to slug
	const handlerLabel =
		handlers[ handlerSlug ]?.label ||
		stepTypes[ handlerSlug ]?.label ||
		handlerSlug;

	return (
		<div className="datamachine-flow-step-handler datamachine-handler-container">
			{ showBadge && (
				<div className="datamachine-handler-tag datamachine-handler-badge">
					{ handlerLabel }
				</div>
			) }

			{ hasSettings && (
				<div className="datamachine-handler-settings-display">
					{ Object.entries( displaySettings ).map(
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

			{ showConfigureButton && (
				<Button variant="secondary" size="small" onClick={ onConfigure }>
					{ __( 'Configure', 'data-machine' ) }
				</Button>
			) }
		</div>
	);
}
