import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

type PackageExport = {
  import?: string;
  require?: string;
  source?: string;
  types?: string;
};

type PackageJson = {
  exports: Record<string, PackageExport>;
};

function readPackageJson(): PackageJson {
  return JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
}

describe('package exports', () => {
  it('points public entries at built dist files and exposes built internals', () => {
    const { exports } = readPackageJson();

    expect(exports['.']).toEqual({
      import: './dist/index.js',
      require: './dist/index.cjs',
      source: './src/index.ts',
      types: './dist/index.d.ts',
    });
    expect(exports['.'].import).not.toContain('/src/');
    expect(exports['.'].types).not.toContain('/src/');

    expect(exports['./theme']).toEqual({
      types: './dist/theme/index.d.ts',
      source: './src/theme/index.ts',
      import: './dist/theme/index.js',
      require: './dist/theme/index.cjs',
    });
    expect(exports['./theme'].import).not.toContain('/src/');
    expect(exports['./theme'].types).not.toContain('/src/');

    expect(exports['./wp']).toEqual({
      import: './dist/wp/index.js',
      require: './dist/wp/index.cjs',
      source: './src/wp/index.ts',
      types: './dist/wp/index.d.ts',
    });
    expect(exports['./wp'].import).not.toContain('/src/');
    expect(exports['./wp'].types).not.toContain('/src/');

    expect(exports['./internals']).toEqual({
      types: './dist/internals/index.d.ts',
      source: './src/internals/index.ts',
      import: './dist/internals/index.js',
      require: './dist/internals/index.cjs',
    });
  });
});
