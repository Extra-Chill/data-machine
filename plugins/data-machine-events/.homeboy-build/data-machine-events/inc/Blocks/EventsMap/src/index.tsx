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

registerBlockType<MapAttributes>( 'data-machine-events/events-map', {
	edit: function Edit( { attributes, setAttributes }: EditProps ) {
		const { height, zoom, mapType } = attributes;
		const blockProps = useBlockProps( {
			className: 'data-machine-events-map-block',
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Map Settings', 'data-machine-events' ) }
						initialOpen
					>
						<RangeControl
							label={ __( 'Height (px)', 'data-machine-events' ) }
							value={ height }
							onChange={ ( value ) =>
								setAttributes( { height: value } )
							}
							min={ 200 }
							max={ 800 }
							step={ 50 }
						/>
						<RangeControl
							label={ __( 'Default Zoom', 'data-machine-events' ) }
							value={ zoom }
							onChange={ ( value ) =>
								setAttributes( { zoom: value } )
							}
							min={ 4 }
							max={ 18 }
						/>
					<SelectControl
						label={ __( 'Map Style', 'data-machine-events' ) }
						value={ mapType }
						options={ MAP_STYLE_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { mapType: value as MapType } )
						}
					/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div
						className="data-machine-events-map"
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
								'data-machine-events',
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
