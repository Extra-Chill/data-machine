import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const nodeRequire = createRequire(
  typeof __filename === 'string' ? __filename : import.meta.url,
);

type WpModule = Record<string, unknown>;

// Resolve the built bundle (dist/wp-runtime.cjs) relative to this module.
// An env override wins so the test:bundle script can point vitest (which runs from src/) at the
// pre-built dist/wp-runtime.cjs without MODULE_NOT_FOUND.
//
// Tsup may place this code in a shared chunk at dist/ (e.g. dist/chunk-XD5TZCO3.js) rather than
// in dist/wp/index.js.  In that case the file URL or __dirname points to dist/, not dist/wp/, so
// we must try both the parent-relative path (../wp-runtime.cjs from dist/wp/) and the same-dir
// path (./wp-runtime.cjs from dist/) and return whichever exists.
function bundlePath(): string {
  const override = process.env.BLOCKS_ENGINE_WP_RUNTIME_PATH;
  if (override) return override;
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = nodeRequire('node:path') as typeof import('node:path');
  if (typeof __dirname === 'string') {
    const fromParent = path.join(__dirname, '..', 'wp-runtime.cjs');
    const fromSelf = path.join(__dirname, 'wp-runtime.cjs');
    return existsSync(fromParent) ? fromParent : fromSelf;
  }
  const fromParent = fileURLToPath(new URL('../wp-runtime.cjs', import.meta.url));
  const fromSelf = fileURLToPath(new URL('./wp-runtime.cjs', import.meta.url));
  return existsSync(fromParent) ? fromParent : fromSelf;
}

let mode: 'bundle' | 'deps' | undefined;
let bundle: WpModule | undefined;

function resolveMode(): 'bundle' | 'deps' {
  if (mode) return mode;
  const forced = process.env.BLOCKS_ENGINE_WP_RUNTIME;
  if (forced === 'bundle' || forced === 'deps') {
    mode = forced;
  } else {
    mode = existsSync(bundlePath()) ? 'bundle' : 'deps';
  }
  return mode;
}

function loadBundle(): WpModule {
  bundle ??= nodeRequire(bundlePath()) as WpModule;
  return bundle;
}

export function requireWp(name: string): WpModule {
  if (resolveMode() === 'deps') {
    return nodeRequire(name) as WpModule;
  }
  const b = loadBundle();
  if (name === '@wordpress/block-serialization-default-parser') {
    return { parse: b.parseGrammar };
  }
  // @wordpress/blocks and @wordpress/block-library are both slices of the one bundle.
  return b;
}

/** Test-only: clear cached mode/bundle so a test can force a different mode. */
export function __resetRequireWpForTest(): void {
  mode = undefined;
  bundle = undefined;
}
