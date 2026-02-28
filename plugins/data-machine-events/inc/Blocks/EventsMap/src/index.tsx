/**
 * Events Map Block Registration
 *
 * Server-side rendered block — editor shows a placeholder preview
 * with InspectorControls for height, zoom, map style, and dynamic mode.
 *
 * @package DataMachineEvents
 * @since 0.5.0
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
	ToggleControl,
} from '@wordpress/components';

import type { MapAttributes, MapType } from './types';

interface EditProps {
	attributes: MapAttributes;
	setAttributes: ( attrs: Partial<MapAttributes> ) => void;
}

const MAP_STYLE_OPTIONS: { label: string; value: MapType }[] = [
	{ label: 'OpenStreetMap', value: 'osm-standard' },
	{ label: 'CartoDB Positron', value: 'carto-positron' },
	{ label: 'CartoDB Voyager', value: 'carto-voyager' },
	{ label: 'CartoDB Dark', value: 'carto-dark' },
	{ label: 'Humanitarian', value: 'humanitarian' },
];

registerBlockType<MapAttributes>( 'datamachine-events/events-map', {
	edit: function Edit( { attributes, setAttributes }: EditProps ) {
		const { height, zoom, mapType, dynamic } = attributes;
		const blockProps = useBlockProps( {
			className: 'datamachine-events-map-block',
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Map Settings', 'datamachine-events' ) }
						initialOpen
					>
						<RangeControl
							label={ __( 'Height (px)', 'datamachine-events' ) }
							value={ height }
							onChange={ ( value ) =>
								setAttributes( { height: value } )
							}
							min={ 200 }
							max={ 800 }
							step={ 50 }
						/>
						<RangeControl
							label={ __( 'Default Zoom', 'datamachine-events' ) }
							value={ zoom }
							onChange={ ( value ) =>
								setAttributes( { zoom: value } )
							}
							min={ 4 }
							max={ 18 }
						/>
						<SelectControl
							label={ __( 'Map Style', 'datamachine-events' ) }
							value={ mapType }
							options={ MAP_STYLE_OPTIONS }
							onChange={ ( value ) =>
								setAttributes( { mapType: value as MapType } )
							}
						/>
						<ToggleControl
							label={ __( 'Dynamic Loading', 'datamachine-events' ) }
							help={ __(
								'Fetch venues via REST API as the user pans/zooms instead of loading all at once.',
								'datamachine-events',
							) }
							checked={ dynamic }
							onChange={ ( value ) =>
								setAttributes( { dynamic: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div
						className="datamachine-events-map"
						style={ {
							height: height + 'px',
							background: '#e5e7eb',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
						} }
					>
						<span style={ { fontSize: '48px' } }>🗺️</span>
						<span
							style={ {
								marginLeft: '12px',
								color: '#6b7280',
							} }
						>
							{ __(
								'Events Map — renders on the frontend',
								'datamachine-events',
							) }
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
