/**
 * Events Map Block Registration
 *
 * Server-side rendered block — editor shows a placeholder preview.
 *
 * @package DataMachineEvents
 * @since 0.13.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
} from '@wordpress/components';

registerBlockType( 'datamachine-events/events-map', {
	edit: function Edit( { attributes, setAttributes } ) {
		const { height, zoom, mapType } = attributes;
		const blockProps = useBlockProps( {
			className: 'datamachine-events-map-block',
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Map Settings', 'datamachine-events' ) } initialOpen>
						<RangeControl
							label={ __( 'Height (px)', 'datamachine-events' ) }
							value={ height }
							onChange={ ( value ) => setAttributes( { height: value } ) }
							min={ 200 }
							max={ 800 }
							step={ 50 }
						/>
						<RangeControl
							label={ __( 'Default Zoom', 'datamachine-events' ) }
							value={ zoom }
							onChange={ ( value ) => setAttributes( { zoom: value } ) }
							min={ 4 }
							max={ 18 }
						/>
						<SelectControl
							label={ __( 'Map Style', 'datamachine-events' ) }
							value={ mapType }
							options={ [
								{ label: 'OpenStreetMap', value: 'osm-standard' },
								{ label: 'CartoDB Positron', value: 'carto-positron' },
								{ label: 'CartoDB Voyager', value: 'carto-voyager' },
								{ label: 'CartoDB Dark', value: 'carto-dark' },
								{ label: 'Humanitarian', value: 'humanitarian' },
							] }
							onChange={ ( value ) => setAttributes( { mapType: value } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div
						className="datamachine-events-map"
						style={ { height: height + 'px', background: '#e5e7eb', display: 'flex', alignItems: 'center', justifyContent: 'center' } }
					>
						<span style={ { fontSize: '48px' } }>🗺️</span>
						<span style={ { marginLeft: '12px', color: '#6b7280' } }>
							{ __( 'Events Map — renders on the frontend', 'datamachine-events' ) }
						</span>
					</div>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
