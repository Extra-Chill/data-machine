const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        index: './src/index.js'
    },
    output: {
        path: __dirname + '/build',
        filename: '[name].js'
    }
}; 