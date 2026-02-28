/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';

interface CalendarBlockAttributes {
	defaultView: string;
	showSearch: boolean;
	showPastEvents: boolean;
	showFilters: boolean;
	showDateFilter: boolean;
	defaultDateRange: string;
}

interface EditProps {
	attributes: CalendarBlockAttributes;
	setAttributes: ( attrs: Partial< CalendarBlockAttributes > ) => void;
}

registerBlockType( 'data-machine-events/calendar', {
	edit: function Edit( { attributes, setAttributes }: EditProps ) {
		const { defaultView, showSearch } = attributes;

		const blockProps = useBlockProps( {
			className: 'data-machine-events-calendar-editor',
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __(
							'Display Settings',
							'data-machine-events'
						) }
					>
						<SelectControl
							label={ __(
								'Default View',
								'data-machine-events'
							) }
							value={ defaultView }
							options={ [
								{
									label: __(
										'List View',
										'data-machine-events'
									),
									value: 'list',
								},
								{
									label: __(
										'Grid View',
										'data-machine-events'
									),
									value: 'grid',
								},
							] }
							onChange={ ( value ) =>
								setAttributes( { defaultView: value } )
							}
						/>

						<ToggleControl
							label={ __(
								'Show Search Box',
								'data-machine-events'
							) }
							checked={ showSearch }
							onChange={ ( value ) =>
								setAttributes( { showSearch: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div className="data-machine-events-calendar-placeholder">
						<div className="data-machine-events-calendar-icon">
							{ '\uD83D\uDCC5' }
						</div>
						<h3>
							{ __(
								'Data Machine Events Calendar',
								'data-machine-events'
							) }
						</h3>
						<p>
							{ __(
								'Displaying upcoming events in',
								'data-machine-events'
							) }{ ' ' }
							{ defaultView }{ ' ' }
							{ __(
								'view with chronological pagination',
								'data-machine-events'
							) }
						</p>
						{ showSearch && (
							<div className="data-machine-events-calendar-filters-preview">
								<p>
									<strong>
										{ __(
											'Search enabled for filtering events',
											'data-machine-events'
										) }
									</strong>
								</p>
							</div>
						) }
					</div>
				</div>
			</>
		);
	},
} );
