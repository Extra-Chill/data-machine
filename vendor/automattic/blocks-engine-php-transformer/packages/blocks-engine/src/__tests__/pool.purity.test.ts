import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const forbiddenImports = [
  '@wordpress/blocks',
  'react',
  '../wp',
  '../wp/raw-convert',
  '../wp/canonicalize',
  '../wp/bootstrap',
];

function runtimeImports(source: string): string[] {
  return source
    .split('\n')
    .filter((line) => /^\s*import(?!\s+type)\b/.test(line))
    .map((line) => line.trim());
}

describe('worker pool parent purity', () => {
  it('keeps pool.ts and events.ts free of React and /wp runtime imports', () => {
    const poolSource = readFileSync(resolve('src/pool/pool.ts'), 'utf8');
    const eventsSource = readFileSync(resolve('src/pool/events.ts'), 'utf8');
    const imports = [...runtimeImports(poolSource), ...runtimeImports(eventsSource)];

    for (const source of forbiddenImports) {
      expect(imports, `runtime import of ${source}`).not.toEqual(
        expect.arrayContaining([expect.stringContaining(`'${source}'`)]),
      );
      expect(imports, `runtime import of ${source}`).not.toEqual(
        expect.arrayContaining([expect.stringContaining(`"${source}"`)]),
      );
    }
  });
});
