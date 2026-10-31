module.exports = [
	{
		name: 'data-machine/generated-admin-assets',
		ignores: [
			'**/node_modules/**',
			'**/vendor/**',
			'inc/Core/Admin/**/assets/build/**',
		],
	},
	{
		name: 'data-machine/admin-sources',
		files: [ 'inc/Core/Admin/**/*.{js,jsx}' ],
		languageOptions: {
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		settings: {
			// Bundled npm dependencies resolved through package.json + webpack.
			// Declared as core modules so the base-revision lint pass (which
			// runs without node_modules) does not flag them as unresolved.
			'import/core-modules': [ '@tanstack/react-query' ],
		},
	},
];
