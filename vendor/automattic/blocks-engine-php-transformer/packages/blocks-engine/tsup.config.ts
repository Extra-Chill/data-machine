import { defineConfig } from 'tsup';

export default defineConfig({
  entry: {
    cli: 'src/cli.ts',
    index: 'src/index.ts',
    'internals/index': 'src/internals/index.ts',
    'theme/index': 'src/theme/index.ts',
    'wp/index': 'src/wp/index.ts',
    'wp/worker-child': 'src/wp/worker-child.ts',
  },
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  noExternal: ['@wordpress/block-serialization-default-parser'],
});
