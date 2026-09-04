import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { slugFromRelPath } from '../theme/ingest.js';
import { sectionExtract } from '../theme/section-extract.js';
import { ingest } from '../theme/index.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');

describe('theme P0-2 path and fixture contracts', () => {
  it('freezes the ingest and section extraction module paths', () => {
    expect(ingest).toBeTypeOf('function');
    expect(ingest).toHaveLength(1);
    expect(slugFromRelPath).toBeTypeOf('function');
    expect(slugFromRelPath).toHaveLength(1);
    expect(sectionExtract).toBeTypeOf('function');
    expect(sectionExtract).toHaveLength(1);
  });

  it('freezes the static site fixture layout', () => {
    expect(existsSync(join(fixtureRoot, 'index.html'))).toBe(true);
    expect(existsSync(join(fixtureRoot, 'about.html'))).toBe(true);
    expect(existsSync(join(fixtureRoot, 'style.css'))).toBe(true);
  });
});
