/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
/**
 * External dependencies
 */
const path = require( 'path' );

const chatPackageSrc = path.resolve(
	__dirname,
	'node_modules/@extrachill/chat/src',
);

module.exports = {
	...defaultConfig,
	entry: {
		'pipelines-react':
			'./inc/Core/Admin/Pages/Pipelines/assets/react/index.jsx',
		'logs-react': './inc/Core/Admin/Pages/Logs/assets/react/index.jsx',
		'settings-react': './inc/Core/Admin/Settings/assets/react/index.jsx',
		'agent-react': './inc/Core/Admin/Pages/Agent/assets/react/index.jsx',
		'jobs-react': './inc/Core/Admin/Pages/Jobs/assets/react/index.jsx',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'inc/Core/Admin/assets/build' ),
		filename: '[name].js',
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'@shared': path.resolve( __dirname, 'inc/Core/Admin/shared' ),
			// Resolve @extrachill/chat to source (no dist/ in git-installed package)
			'@extrachill/chat': chatPackageSrc + '/index.ts',
		},
		extensions: [
			'.tsx', '.ts',
			...( defaultConfig.resolve?.extensions || [ '.jsx', '.js', '.json' ] ),
		],
	},
	module: {
		...defaultConfig.module,
		rules: [
			// TypeScript support for @extrachill/chat source files
			{
				test: /\.tsx?$/,
				include: [ chatPackageSrc ],
				use: [
					{
						loader: require.resolve( 'babel-loader' ),
						options: {
							presets: [
								require.resolve( '@babel/preset-typescript' ),
								require.resolve( '@babel/preset-react' ),
							],
						},
					},
				],
			},
			...( defaultConfig.module?.rules || [] ),
		],
	},
};
