/**
 * HandlerDefaultsTab Component
 *
 * Displays all handlers grouped by step type with expandable config forms.
 * Allows setting site-wide default values for each handler.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
/**
 * Internal dependencies
 */
import {
	useHandlerDefaults,
	useUpdateHandlerDefaults,
} from '../../queries/handlerDefaults';
import StepTypeAccordion from '../StepTypeAccordion';

const HandlerDefaultsTab = () => {
	const { data, isLoading, error } = useHandlerDefaults();
	const updateMutation = useUpdateHandlerDefaults();
	const [ expandedHandler, setExpandedHandler ] = useState( null );
	const [ savingHandler, setSavingHandler ] = useState( null );

	const handleSave = async ( handlerSlug, defaults ) => {
		setSavingHandler( handlerSlug );
		try {
			await updateMutation.mutateAsync( { handlerSlug, defaults } );
		} finally {
			setSavingHandler( null );
		}
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-handler-defaults-loading">
				<span className="spinner is-active"></span>
				<span>Loading handlers...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading handler defaults: { error.message }</p>
			</div>
		);
	}

	if ( ! data || Object.keys( data ).length === 0 ) {
		return (
			<div className="notice notice-warning">
				<p>No step types registered.</p>
			</div>
		);
	}

	return (
		<div className="datamachine-handler-defaults-tab">
			<p className="description">
				Configure site-wide default values for handlers. These defaults
				apply when creating new flows and fields are not explicitly set.
				Existing flows are not affected.
			</p>

		<div className="datamachine-step-types-list">
			{ Object.entries( data )
				.filter(
					( [ , stepTypeData ] ) => stepTypeData.uses_handler
				)
				.map( ( [ stepTypeSlug, stepTypeData ] ) => (
					<StepTypeAccordion
						key={ stepTypeSlug }
						stepTypeSlug={ stepTypeSlug }
						stepTypeData={ stepTypeData }
						expandedHandler={ expandedHandler }
						setExpandedHandler={ setExpandedHandler }
						onSave={ handleSave }
						savingHandler={ savingHandler }
					/>
				) ) }
			</div>
		</div>
	);
};

export default HandlerDefaultsTab;
