/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: './src/index.js',
		frontend: './src/frontend.js',
		leaflet: './src/leaflet.js',
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
	},
};
