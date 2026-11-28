/**
 * WordPress dependencies
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        index: './src/index.tsx',
        frontend: './src/frontend.tsx',
    },
    output: {
        ...defaultConfig.output,
        filename: '[name].js',
    },
};
