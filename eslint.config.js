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
	},
];
