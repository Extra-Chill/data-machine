import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';

const root = fileURLToPath(new URL('..', import.meta.url));
const STUB = [
  '@wordpress/icons', '@wordpress/ui', '@wordpress/dataviews',
  '@wordpress/image-cropper', '@wordpress/server-side-render',
  '@wordpress/commands', '@wordpress/preferences', '@wordpress/notices',
  '@wordpress/keyboard-shortcuts',
];
const stubPath = `${root}build/wp-empty-stub.cjs`;
const alias = Object.fromEntries(STUB.map((p) => [p, stubPath]));

await esbuild.build({
  entryPoints: [`${root}src/wp/wp-runtime-entry.ts`],
  outfile: `${root}dist/wp-runtime.cjs`,
  bundle: true,
  format: 'cjs',
  platform: 'node',
  target: 'node20',
  minify: true,
  // No sourcemap: the bundle is minified WP internals (~20 MB) and a map would
  // add another ~52 MB to the published package with no value for consumers.
  // Regenerate locally with `sourcemap: true` if needed for debugging.
  alias,
  external: ['jsdom', 'cheerio', 'domhandler'],
  // Force CJS resolution: @wordpress/block-library → @wordpress/block-editor CJS build references window/document at module init.
  conditions: ['require', 'node', 'default'],
  define: { 'process.env.NODE_ENV': '"production"' },
  logLevel: 'info',
});
console.log('built dist/wp-runtime.cjs');
