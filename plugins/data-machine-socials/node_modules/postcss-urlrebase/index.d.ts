declare module 'rebaseUrl' {
	import { PluginCreator } from 'postcss';

	interface RebaseUrlOptions {
		/**
		 * If set to true, the plugin will skip rewriting URLs that are already normalized and host-relative (e.g., `/images/image.jpg`, without `..`s).
		 * @default true
		 */
		skipHostRelativeUrls?: boolean;

		/**
		 * The base URL to use for rewriting relative URLs.
		 */
		rootUrl: string | URL;
	}

	const rebaseUrl: PluginCreator<RebaseUrlOptions>;

	export default rebaseUrl;
}
