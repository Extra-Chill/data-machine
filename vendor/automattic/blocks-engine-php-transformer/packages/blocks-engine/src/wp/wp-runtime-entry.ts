// Entry compiled by scripts/build-wp-runtime.mjs into dist/wp-runtime.cjs.
// Re-exports exactly the WordPress symbols the engine calls. `parseGrammar`
// is renamed to avoid colliding with `@wordpress/blocks`' `parse`.
export { registerCoreBlocks } from '@wordpress/block-library';
export {
  rawHandler,
  serialize,
  parse,
  validateBlock,
  createBlock,
  getBlockAttributes,
} from '@wordpress/blocks';
export { parse as parseGrammar } from '@wordpress/block-serialization-default-parser';
