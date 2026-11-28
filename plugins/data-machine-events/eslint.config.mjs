// Homeboy's WordPress runner prepends its own recommended preset. Only load
// ours for standalone linting so ESLint never sees two copies of the plugins.
const wordpress = process.env.HOMEBOY_ESLINT_COMPONENT_PATH
	? null
	: ( await import( '@wordpress/eslint-plugin' ) ).default;

export default wordpress ? [ ...wordpress.configs.recommended ] : [];
