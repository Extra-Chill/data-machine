import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';

import { describe, expect, it } from 'vitest';

import { runCli } from '../cli';
import { BlocksEngineError } from '../errors';
import type { SiteToThemeOptions, ThemeBuildResult } from '../theme/types';

function writableBuffer() {
  let output = '';

  return {
    stream: {
      write(chunk: string | Uint8Array) {
        output += typeof chunk === 'string' ? chunk : chunk.toString();
        return true;
      },
    },
    text: () => output,
  };
}

function themeResult(outDir: string, written: string[] = ['theme.json']): ThemeBuildResult {
  return {
    outDir,
    model: {
      styleCss: '',
      themeJson: {},
      templates: {},
      parts: {},
      patterns: {},
      assets: [],
    },
    written,
    tallies: {},
    warnings: [],
    diagnostics: {
      conversion: {
        pages: [],
        groups: [],
        occurrenceCount: 0,
        repairFamilyCount: 0,
        repairFamilyCountTruncated: false,
        unrepresentedFallbackOccurrenceCount: 0,
        unrepresentedFallbackDistinctCount: 0,
        totalFallbacks: 0,
        pagesWithFallbacks: 0,
        degradedPages: 0,
      },
      regionAudit: [],
    },
  };
}

describe('CLI one-shot conversion', () => {
  it('reads a file arg and writes block markup to stdout', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'blocks-engine-cli-'));

    try {
      const filePath = join(dir, 'sample.html');
      await writeFile(filePath, '<h2>Hello CLI</h2><p>Body copy.</p>');

      const stdout = writableBuffer();
      const stderr = writableBuffer();
      const exitCode = await runCli(['convert', filePath], {
        stdout: stdout.stream,
        stderr: stderr.stream,
      });

      expect(exitCode).toBe(0);
      expect(stdout.text()).toContain('<!-- wp:');
      expect(stderr.text()).toBe('');
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });

  it('prints a clear error when a convert file is missing', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];
    const filePath = '/tmp/blocks-engine-missing-file.html';

    const exitCode = await runCli(['convert', filePath], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => false,
      convertHtml: async (html) => {
        calls.push(html);
        return '<!-- wp:paragraph --><p>unused</p><!-- /wp:paragraph -->';
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain(`File ${filePath} not found.`);
  });

  it('reads stdin when convert has no file arg', async () => {
    let seenUrl: string | undefined;
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli(['convert'], {
      stdin: Readable.from(['<p>From stdin.</p>']),
      stdout: stdout.stream,
      stderr: stderr.stream,
      convertHtml: async (_html, ctx) => {
        seenUrl = ctx?.url;
        return '<!-- wp:paragraph --><p>From stdin.</p><!-- /wp:paragraph -->';
      },
    });

    expect(exitCode).toBe(0);
    expect(seenUrl).toMatch(/^file:\/\//);
    expect(seenUrl).toMatch(/stdin$/);
    expect(stdout.text()).toContain('<!-- wp:paragraph -->');
    expect(stderr.text()).toBe('');
  });

  it('prints BlocksEngineError message and hint to stderr', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli(['convert'], {
      stdin: Readable.from(['<p>Unsafe.</p>']),
      stdout: stdout.stream,
      stderr: stderr.stream,
      convertHtml: async () => {
        throw new BlocksEngineError('Cannot convert HTML.', {
          code: 'test-error',
          hint: 'Remove unsupported markup and try again.',
        });
      },
    });

    expect(exitCode).toBe(1);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain('Cannot convert HTML.');
    expect(stderr.text()).toContain('Remove unsupported markup and try again.');
  });
});

describe('CLI theme builds', () => {
  it('runs the explicit theme subcommand with output and theme metadata options', async () => {
    const srcDir = '/tmp/source-site';
    const outDir = '/tmp/source-theme';
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: Array<{ srcDir: string; options: SiteToThemeOptions | undefined }> = [];

    const exitCode = await runCli(
      ['theme', srcDir, '--out', outDir, '--slug', 'demo', '--name', 'Demo'],
      {
        stdout: stdout.stream,
        stderr: stderr.stream,
        pathExists: () => true,
        isDirectory: () => true,
        siteToThemeImpl: async (calledSrcDir, options) => {
          calls.push({ srcDir: calledSrcDir, options });
          return {
            ...themeResult(outDir, ['theme.json', 'style.css']),
            warnings: ['kept source CSS'],
          };
        },
      }
    );

    expect(exitCode).toBe(0);
    expect(calls).toEqual([
      {
        srcDir,
        options: {
          outDir,
          themeMeta: {
            slug: 'demo',
            name: 'Demo',
          },
        },
      },
    ]);
    expect(stdout.text()).toBe(`wrote theme to ${outDir} (2 files)\n`);
    expect(stderr.text()).toBe('kept source CSS\n');
  });

  it('treats a bare path argument as theme shorthand', async () => {
    const srcDir = '/tmp/source-site';
    const outDir = '/tmp/bare-theme';
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: Array<{ srcDir: string; options: SiteToThemeOptions | undefined }> = [];

    const exitCode = await runCli([srcDir, `--out=${outDir}`], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => true,
      isDirectory: () => true,
      siteToThemeImpl: async (calledSrcDir, options) => {
        calls.push({ srcDir: calledSrcDir, options });
        return themeResult(outDir, ['theme.json']);
      },
    });

    expect(exitCode).toBe(0);
    expect(calls).toEqual([
      {
        srcDir,
        options: {
          outDir,
          themeMeta: {},
        },
      },
    ]);
    expect(stdout.text()).toBe(`wrote theme to ${outDir} (1 files)\n`);
    expect(stderr.text()).toBe('');
  });

  it('defaults the output dir to ./_block-theme when --out is omitted', async () => {
    const srcDir = '/tmp/source-site';
    const expectedOut = join(process.cwd(), '_block-theme');
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: Array<{ srcDir: string; options: SiteToThemeOptions | undefined }> = [];

    const exitCode = await runCli([srcDir], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: (path) => path === srcDir,
      isDirectory: (path) => path === srcDir,
      siteToThemeImpl: async (calledSrcDir, options) => {
        calls.push({ srcDir: calledSrcDir, options });
        return themeResult(expectedOut, ['theme.json']);
      },
    });

    expect(exitCode).toBe(0);
    expect(calls).toEqual([{ srcDir, options: { outDir: expectedOut, themeMeta: {} } }]);
    expect(stdout.text()).toBe(`wrote theme to ${expectedOut} (1 files)\n`);
  });

  it('exits and asks for --out when the default ./_block-theme already exists', async () => {
    const srcDir = '/tmp/source-site';
    const expectedOut = join(process.cwd(), '_block-theme');
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli([srcDir], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: (path) => path === srcDir || path === expectedOut,
      isDirectory: (path) => path === srcDir,
      siteToThemeImpl: async (calledSrcDir) => {
        calls.push(calledSrcDir);
        return themeResult(expectedOut);
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain('already exists');
    expect(stderr.text()).toContain('--out');
  });

  it('builds with an explicit --out even if that directory already exists', async () => {
    const srcDir = '/tmp/source-site';
    const outDir = '/tmp/existing-theme';
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli([srcDir, '--out', outDir], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => true,
      isDirectory: () => true,
      siteToThemeImpl: async (calledSrcDir) => {
        calls.push(calledSrcDir);
        return themeResult(outDir, ['theme.json']);
      },
    });

    expect(exitCode).toBe(0);
    expect(calls).toEqual([srcDir]);
    expect(stdout.text()).toBe(`wrote theme to ${outDir} (1 files)\n`);
  });

  it('prints help with both CLI verbs', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli(['--help'], {
      stdout: stdout.stream,
      stderr: stderr.stream,
    });

    expect(exitCode).toBe(0);
    expect(stdout.text()).toContain('blocks-engine theme <srcDir>');
    expect(stdout.text()).toContain('blocks-engine <srcDir>');
    expect(stdout.text()).toContain('blocks-engine convert [file]');
    expect(stderr.text()).toBe('');
  });

  it('prints help when no command or path is provided', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli([], {
      stdout: stdout.stream,
      stderr: stderr.stream,
    });

    expect(exitCode).toBe(0);
    expect(stdout.text()).toContain('blocks-engine theme <srcDir>');
    expect(stdout.text()).toContain('blocks-engine convert [file]');
    expect(stderr.text()).toBe('');
  });

  it('reprints usage when given an unknown option', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli(['/tmp/source-site', '--ot', '/tmp/out'], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => true,
      isDirectory: () => true,
      siteToThemeImpl: async (calledSrcDir) => {
        calls.push(calledSrcDir);
        return themeResult('/tmp/out');
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain('Unknown option: --ot');
    expect(stderr.text()).toContain('blocks-engine theme <srcDir>');
  });

  it('reprints usage when the explicit theme command omits srcDir', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli(['theme'], {
      stdout: stdout.stream,
      stderr: stderr.stream,
    });

    expect(exitCode).toBe(1);
    expect(stderr.text()).toContain('Missing <srcDir>');
    expect(stderr.text()).toContain('blocks-engine theme <srcDir>');
  });

  it('prints a clear error when the explicit theme command omits srcDir', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli(['theme'], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      siteToThemeImpl: async (srcDir) => {
        calls.push(srcDir);
        return themeResult('/tmp/unused');
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain('Missing <srcDir>');
  });

  it('prints a clear error when the theme source directory is missing', async () => {
    const srcDir = '/tmp/blocks-engine-missing-source';
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli(['theme', srcDir], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => false,
      siteToThemeImpl: async (calledSrcDir) => {
        calls.push(calledSrcDir);
        return themeResult('/tmp/unused');
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain(`Source directory ${srcDir} not found.`);
  });

  it('suggests convert when the theme source is a file', async () => {
    const srcDir = '/tmp/blocks-engine-page.html';
    const stdout = writableBuffer();
    const stderr = writableBuffer();
    const calls: string[] = [];

    const exitCode = await runCli([srcDir], {
      stdout: stdout.stream,
      stderr: stderr.stream,
      pathExists: () => true,
      isDirectory: () => false,
      siteToThemeImpl: async (calledSrcDir) => {
        calls.push(calledSrcDir);
        return themeResult('/tmp/unused');
      },
    });

    expect(exitCode).toBe(1);
    expect(calls).toEqual([]);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain(`${srcDir} is a file, not a directory.`);
    expect(stderr.text()).toContain(`blocks-engine convert ${srcDir}`);
  });
});
