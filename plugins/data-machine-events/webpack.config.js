const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const blocks = {
	Calendar: { index: 'index.tsx', frontend: 'frontend.ts' },
	EventDetails: {
		index: 'index.js',
		frontend: 'frontend.js',
		leaflet: 'leaflet.js',
	},
	EventsMap: { index: 'index.tsx', frontend: 'frontend.tsx' },
};

// Give each block its own output directory so webpack only cleans generated
// assets and WordPress can keep loading them relative to each block.json.
module.exports = Object.entries( blocks ).map( ( [ name, entries ] ) => {
	const block = path.join( __dirname, 'inc', 'Blocks', name );

	return {
		...defaultConfig,
		name,
		entry: Object.fromEntries(
			Object.entries( entries ).map( ( [ handle, file ] ) => [
				handle,
				path.join( block, 'src', file ),
			] )
		),
		output: {
			...defaultConfig.output,
			path: path.join( block, 'build' ),
		},
	};
} );
