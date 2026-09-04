import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

type ExampleManifest = {
  examples: Array<{
    file: string;
  }>;
};

const packageDirUrl = new URL('../../', import.meta.url);
const packageDir = fileURLToPath(packageDirUrl);
const readmeUrl = new URL('README.md', packageDirUrl);
const oneLinerUrl = new URL('examples/convert-one-liner.mjs', packageDirUrl);
const manifestUrl = new URL('examples/manifest.json', packageDirUrl);

function readRequiredFile(url: URL, label: string): string {
  expect(existsSync(url), `${label} must exist`).toBe(true);
  return readFileSync(url, 'utf8');
}

function normalizeLineEndings(value: string): string {
  return value.replace(/\r\n/g, '\n');
}

function firstJavaScriptCodeBlock(markdown: string): string {
  const match = normalizeLineEndings(markdown).match(
    /```(?:javascript|js)\s*\n([\s\S]*?\n)```/,
  );

  expect(match, 'README must include a JavaScript code block').not.toBeNull();
  return match?.[1] ?? '';
}

function firstHtmlCodeBlock(markdown: string): string {
  const match = normalizeLineEndings(markdown).match(/```html\s*\n([\s\S]*?\n)```/);

  expect(match, 'README must include an HTML output code block').not.toBeNull();
  return match?.[1] ?? '';
}

function runFrozenOneLiner(): string {
  return execFileSync(
    process.execPath,
    [
      '--conditions=source',
      '--import',
      'tsx',
      fileURLToPath(oneLinerUrl),
    ],
    {
      cwd: packageDir,
      encoding: 'utf8',
    },
  );
}

describe('documentation accuracy', () => {
  it('keeps the headline README convert example locked to the frozen one-liner', () => {
    const readme = readRequiredFile(readmeUrl, 'package README');
    const frozenExample = normalizeLineEndings(
      readRequiredFile(oneLinerUrl, 'convert-one-liner example'),
    );

    expect(firstJavaScriptCodeBlock(readme)).toBe(frozenExample);
  });

  it('runs the frozen one-liner through source exports and matches the shown output', () => {
    const readme = readRequiredFile(readmeUrl, 'package README');
    const stdout = runFrozenOneLiner();

    expect(stdout).toContain('<!-- wp:heading -->');
    expect(stdout).toContain('<!-- wp:paragraph -->');
    expect(firstHtmlCodeBlock(readme)).toBe(stdout);
  });

  it('lists every required example script in the examples manifest', () => {
    const manifest = JSON.parse(
      readRequiredFile(manifestUrl, 'examples manifest'),
    ) as ExampleManifest;
    const files = manifest.examples.map((example) => example.file);

    expect([...files].sort()).toEqual([
      'compose-function-converter.mjs',
      'convert-one-liner.mjs',
      'worker-pool-batch.mjs',
    ]);
    for (const file of files) {
      expect(existsSync(new URL(`examples/${file}`, packageDirUrl))).toBe(true);
    }
  });
});
